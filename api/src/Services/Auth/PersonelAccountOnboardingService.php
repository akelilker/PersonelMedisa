<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Auth\PasswordHasher;
use Medisa\Api\Auth\PasswordPolicy;
use Medisa\Api\Database\UsersSchema;
use Medisa\Api\Http\JsonResponse;
use PDO;
use PDOException;

/**
 * Canonical owner for future PERSONEL secure account onboarding + activation.
 * Does not replace generic management-user creation.
 */
class PersonelAccountOnboardingService
{
    public const EVENT_ACCOUNT_CREATED = 'PERSONEL_ACCOUNT_CREATED';
    public const EVENT_ACCOUNT_BOUND = 'PERSONEL_ACCOUNT_BOUND';
    public const EVENT_LINK_ISSUED = 'ACTIVATION_LINK_ISSUED';
    public const EVENT_LINK_REISSUED = 'ACTIVATION_LINK_REISSUED';
    public const EVENT_ACTIVATION_COMPLETED = 'ACTIVATION_COMPLETED';
    public const EVENT_ACTIVATION_REVOKED = 'ACTIVATION_REVOKED';

    public const ERR_NAME_REQUIRED = 'PERSONEL_NAME_REQUIRED_FOR_ACCOUNT';
    public const ERR_USERNAME_COLLISION = 'PERSONEL_USERNAME_COLLISION';
    public const ERR_USERNAME_INVALID = 'PERSONEL_USERNAME_INVALID';
    public const ERR_ALREADY_PROVISIONED = 'ALREADY_PROVISIONED';
    public const ERR_PERSONEL_INACTIVE = 'PERSONEL_INACTIVE';
    public const ERR_PERSONEL_ARCHIVED = 'PERSONEL_ARCHIVED_FIXTURE';
    public const ERR_USE_SECURE = 'PERSONEL_USE_SECURE_ONBOARDING';
    public const ERR_ACTIVATION_INVALID = 'ACTIVATION_LINK_INVALID';
    public const ERR_NOT_PENDING = 'ACTIVATION_NOT_REQUIRED';
    public const ERR_SCHEMA = 'SCHEMA_NOT_READY';

    /**
     * Create/bind PERSONEL account for eligible personel and issue one-time activation URL.
     *
     * @param array<string, mixed> $actorUser
     * @param string|null $usernameOverride Çakışma durumunda yetkili tarafından verilen alternatif kullanıcı adı
     * @return array<string, mixed>
     */
    public static function onboardAndIssue(PDO $pdo, $personelId, array $actorUser, $usernameOverride = null)
    {
        self::assertSchemaReady($pdo);
        $personelId = (int) $personelId;
        $actorId = isset($actorUser['id']) ? (int) $actorUser['id'] : 0;
        if ($personelId <= 0 || $actorId <= 0) {
            JsonResponse::badRequest('Gecersiz istek.', 'VALIDATION_ERROR');
        }

        $pdo->beginTransaction();
        try {
            $personel = self::lockPersonelRow($pdo, $personelId);
            self::assertEligibleForOnboarding($pdo, $personel);

            $suggested = self::buildPersonelUsernameFromNames(
                $personel['ad'] ?? null,
                $personel['soyad'] ?? null
            );
            $username = $usernameOverride !== null && trim((string) $usernameOverride) !== ''
                ? self::normalizeOverrideUsername($usernameOverride)
                : $suggested;

            $existing = self::findBoundUserForPersonel($pdo, $personelId);

            if ($existing !== null) {
                $pdo->commit();
                JsonResponse::error(
                    409,
                    self::ERR_ALREADY_PROVISIONED,
                    'Bu personelin hesabi zaten mevcut.',
                    'personel_id'
                );
            }

            self::assertUsernameAvailableForNewAccount($pdo, $username, $personelId, $suggested);

            $adSoyad = trim((string) ($personel['ad'] ?? '') . ' ' . (string) ($personel['soyad'] ?? ''));
            if ($adSoyad === '') {
                $adSoyad = $username;
            }

            $internalSecret = self::generateUnusableInternalSecret();
            $passwordHash = PasswordHasher::hash($internalSecret);
            // Discard plaintext immediately (do not return/log).
            $internalSecret = null;

            $insertCols = 'username, password_hash, ad_soyad, rol, durum';
            $insertVals = ':username, :password_hash, :ad_soyad, :rol, :durum';
            $params = [
                'username' => $username,
                'password_hash' => $passwordHash,
                'ad_soyad' => $adSoyad,
                'rol' => 'PERSONEL',
                'durum' => 'AKTIF',
            ];

            if (UsersSchema::hasMustChangePassword($pdo)) {
                $insertCols .= ', must_change_password';
                $insertVals .= ', 1';
            }
            $insertCols .= ', activation_required';
            $insertVals .= ', 1';
            if (UsersSchema::hasVarsayilanSubeId($pdo)) {
                $subeId = isset($personel['sube_id']) && $personel['sube_id'] !== null && $personel['sube_id'] !== ''
                    ? (int) $personel['sube_id']
                    : null;
                $insertCols .= ', varsayilan_sube_id';
                $insertVals .= ', :varsayilan_sube_id';
                $params['varsayilan_sube_id'] = $subeId !== null && $subeId > 0 ? $subeId : null;
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO users ($insertCols) VALUES ($insertVals)");
                $stmt->execute($params);
            } catch (PDOException $e) {
                if (self::isUniqueViolation($e)) {
                    JsonResponse::error(
                        409,
                        self::ERR_USERNAME_COLLISION,
                        'Bu kullanici adi zaten kullaniliyor. Farkli bir kullanici adi belirleyin.',
                        'username',
                        ['suggested_username' => $suggested]
                    );
                }
                throw $e;
            }

            $userId = (int) $pdo->lastInsertId();
            self::writeAudit($pdo, self::EVENT_ACCOUNT_CREATED, $userId, $personelId, $actorId, null, [
                'username' => $username,
                'suggested_username' => $suggested,
                'rol' => 'PERSONEL',
            ]);

            $bindAction = UserPersonelBindingService::applyBinding($pdo, $userId, $personelId, $actorId);
            self::writeAudit($pdo, self::EVENT_ACCOUNT_BOUND, $userId, $personelId, $actorId, null, [
                'binding_action' => $bindAction,
            ]);

            $invitation = self::issueInvitationLocked($pdo, $userId, $actorId, null, false);
            self::writeAudit($pdo, self::EVENT_LINK_ISSUED, $userId, $personelId, $actorId, (int) $invitation['id'], []);

            $pdo->commit();

            return self::buildIssueResponse($pdo, $userId, $username, $invitation, false);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reissue activation for an activation-pending user.
     *
     * @param array<string, mixed> $actorUser
     * @return array<string, mixed>
     */
    public static function reissueActivation(PDO $pdo, $userId, array $actorUser)
    {
        self::assertSchemaReady($pdo);
        $userId = (int) $userId;
        $actorId = isset($actorUser['id']) ? (int) $actorUser['id'] : 0;
        if ($userId <= 0 || $actorId <= 0) {
            JsonResponse::badRequest('Gecersiz istek.', 'VALIDATION_ERROR');
        }

        $pdo->beginTransaction();
        try {
            $user = self::lockUserRow($pdo, $userId);
            if (((int) ($user['activation_required'] ?? 0)) !== 1) {
                JsonResponse::error(
                    409,
                    self::ERR_NOT_PENDING,
                    'Hesap aktivasyon bekleyen durumda degil.',
                    'user_id'
                );
            }
            if (strtoupper((string) ($user['durum'] ?? '')) !== 'AKTIF') {
                JsonResponse::error(409, 'USER_INACTIVE', 'Kullanici aktif degil.', 'user_id');
            }

            $personelId = isset($user['personel_id']) && $user['personel_id'] !== null && $user['personel_id'] !== ''
                ? (int) $user['personel_id']
                : 0;
            if ($personelId > 0) {
                $personel = self::lockPersonelRow($pdo, $personelId);
                self::assertPersonelStillActiveForActivation($pdo, $personel);
            }

            $invitation = self::issueInvitationLocked($pdo, $userId, $actorId, null, true);
            self::writeAudit($pdo, self::EVENT_LINK_REISSUED, $userId, $personelId > 0 ? $personelId : null, $actorId, (int) $invitation['id'], []);
            $pdo->commit();

            return self::buildIssueResponse(
                $pdo,
                $userId,
                (string) $user['username'],
                $invitation,
                true
            );
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Public activation complete — identity solely from verified token.
     *
     * @return array<string, mixed>
     */
    public static function completeActivation(PDO $pdo, $rawToken, $newPassword, $newPasswordConfirmation = null)
    {
        self::assertSchemaReady($pdo);
        $rawToken = is_string($rawToken) ? trim($rawToken) : '';
        if ($rawToken === '' || strlen($rawToken) < 32) {
            JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisi gecersiz veya suresi dolmus.');
        }

        PasswordPolicy::assertValidNewPassword($newPassword, $newPasswordConfirmation);

        $tokenHash = self::hashToken($rawToken);
        // Clear local copy of raw token from variables we control after hash.
        $rawToken = '';

        $pdo->beginTransaction();
        try {
            $inv = self::lockInvitationByHash($pdo, $tokenHash);
            if ($inv === null) {
                JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisi gecersiz veya suresi dolmus.');
            }

            self::assertInvitationRedeemable($inv);

            $userId = (int) $inv['user_id'];
            $user = self::lockUserRow($pdo, $userId);
            if (strtoupper((string) ($user['durum'] ?? '')) !== 'AKTIF') {
                JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisi gecersiz veya suresi dolmus.');
            }
            if (((int) ($user['activation_required'] ?? 0)) !== 1) {
                JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisi gecersiz veya suresi dolmus.');
            }

            $personelId = isset($user['personel_id']) && $user['personel_id'] !== null && $user['personel_id'] !== ''
                ? (int) $user['personel_id']
                : 0;
            if ($personelId <= 0) {
                JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisi gecersiz veya suresi dolmus.');
            }
            $personel = self::lockPersonelRow($pdo, $personelId);
            self::assertPersonelStillActiveForActivation($pdo, $personel);

            $now = self::utcNow();
            $passwordHash = PasswordHasher::hash((string) $newPassword);

            $setParts = [
                'password_hash = :password_hash',
                'activation_required = 0',
            ];
            $params = [
                'password_hash' => $passwordHash,
                'id' => $userId,
            ];
            if (UsersSchema::hasMustChangePassword($pdo)) {
                $setParts[] = 'must_change_password = 0';
            }
            if (UsersSchema::hasActivatedAtUtc($pdo)) {
                $setParts[] = 'activated_at_utc = :activated_at_utc';
                $params['activated_at_utc'] = $now;
            }

            $upd = $pdo->prepare('UPDATE users SET ' . implode(', ', $setParts) . ' WHERE id = :id');
            $upd->execute($params);

            $consume = $pdo->prepare(
                'UPDATE personel_account_activation_invitations
                 SET consumed_at_utc = :consumed
                 WHERE id = :id AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL'
            );
            $consume->execute(['consumed' => $now, 'id' => (int) $inv['id']]);
            if ($consume->rowCount() !== 1) {
                JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisi gecersiz veya suresi dolmus.');
            }

            self::revokeSiblingLiveInvitations($pdo, $userId, (int) $inv['id'], $now, null);

            self::writeAudit($pdo, self::EVENT_ACTIVATION_COMPLETED, $userId, $personelId, null, (int) $inv['id'], []);

            $pdo->commit();

            return [
                'activated' => true,
                'message' => 'Hesabiniz basariyla etkinlestirildi.',
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Soft status check for activation page (does not reveal sensitive personel data).
     *
     * @return array<string, mixed>
     */
    public static function activationStatus(PDO $pdo, $rawToken)
    {
        self::assertSchemaReady($pdo);
        $rawToken = is_string($rawToken) ? trim($rawToken) : '';
        if ($rawToken === '' || strlen($rawToken) < 32) {
            return ['valid' => false, 'reason' => 'invalid'];
        }
        $tokenHash = self::hashToken($rawToken);
        $stmt = $pdo->prepare(
            'SELECT i.id, i.user_id, i.expires_at_utc, i.consumed_at_utc, i.revoked_at_utc,
                    u.durum AS user_durum, u.activation_required, u.personel_id
             FROM personel_account_activation_invitations i
             INNER JOIN users u ON u.id = i.user_id
             WHERE i.token_hash = :hash
             LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['valid' => false, 'reason' => 'invalid'];
        }
        if ($row['revoked_at_utc'] !== null && $row['revoked_at_utc'] !== '') {
            return ['valid' => false, 'reason' => 'revoked'];
        }
        if ($row['consumed_at_utc'] !== null && $row['consumed_at_utc'] !== '') {
            return ['valid' => false, 'reason' => 'consumed'];
        }
        if (strtotime((string) $row['expires_at_utc'] . ' UTC') < time()) {
            return ['valid' => false, 'reason' => 'expired'];
        }
        if (strtoupper((string) ($row['user_durum'] ?? '')) !== 'AKTIF'
            || ((int) ($row['activation_required'] ?? 0)) !== 1
        ) {
            return ['valid' => false, 'reason' => 'invalid'];
        }
        $personelId = (int) ($row['personel_id'] ?? 0);
        if ($personelId <= 0) {
            return ['valid' => false, 'reason' => 'invalid'];
        }
        $pstmt = $pdo->prepare('SELECT aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $pstmt->execute(['id' => $personelId]);
        $p = $pstmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($p) || strtoupper(trim((string) ($p['aktif_durum'] ?? ''))) !== 'AKTIF') {
            return ['valid' => false, 'reason' => 'personel_inactive'];
        }

        return [
            'valid' => true,
            'expires_at_utc' => (string) $row['expires_at_utc'],
        ];
    }

    /**
     * Reject generic create of PERSONEL + personel_id (secure onboarding owns that path).
     *
     * @param array<string, mixed> $body
     */
    public static function rejectGenericPersonelBoundCreate(array $body)
    {
        $rol = strtoupper(trim((string) ($body['rol'] ?? '')));
        if ($rol !== 'PERSONEL') {
            return;
        }
        if (!array_key_exists('personel_id', $body)) {
            // PERSONEL without personel_id still fails existing role org rules.
            return;
        }
        $pid = $body['personel_id'];
        if ($pid === null || $pid === '' || (int) $pid <= 0) {
            return;
        }
        JsonResponse::error(
            409,
            self::ERR_USE_SECURE,
            'Personel baglantili hesap olusturma guvenli onboarding uzerinden yapilmalidir.',
            'personel_id'
        );
    }

    /**
     * Pending invitation summary for management UI (no raw token).
     *
     * @return array<string, mixed>|null
     */
    public static function getPendingInvitationMeta(PDO $pdo, $userId)
    {
        if (!self::hasInvitationTable($pdo)) {
            return null;
        }
        $userId = (int) $userId;
        $stmt = $pdo->prepare(
            'SELECT id, created_at_utc, expires_at_utc, consumed_at_utc, revoked_at_utc
             FROM personel_account_activation_invitations
             WHERE user_id = :uid
               AND consumed_at_utc IS NULL
               AND revoked_at_utc IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $expires = (string) $row['expires_at_utc'];
        $expired = strtotime($expires . ' UTC') < time();

        return [
            'invitation_id' => (int) $row['id'],
            'created_at_utc' => (string) $row['created_at_utc'],
            'expires_at_utc' => $expires,
            'is_expired' => $expired,
            'is_valid' => !$expired,
        ];
    }

    /**
     * İlk ad (küçük Latin) + soyadın ilk harfi (büyük Latin).
     * Sicil numarası katılmaz. Otomatik sayı eklenmez.
     */
    public static function buildPersonelUsernameFromNames($adRaw, $soyadRaw)
    {
        $ad = trim((string) $adRaw);
        $soyad = trim((string) $soyadRaw);
        if ($ad === '' || $soyad === '') {
            JsonResponse::badRequest(
                'Personel hesabi icin ad ve soyad zorunludur.',
                self::ERR_NAME_REQUIRED,
                'ad'
            );
        }

        $adParts = preg_split('/\s+/u', $ad);
        $firstAd = is_array($adParts) && isset($adParts[0]) ? trim((string) $adParts[0]) : '';
        $namePart = self::foldToLowerAsciiToken($firstAd);
        $initial = self::foldSurnameInitial($soyad);
        if ($namePart === '' || $initial === '') {
            JsonResponse::badRequest(
                'Personel hesabi icin gecerli ad ve soyad zorunludur.',
                self::ERR_NAME_REQUIRED,
                'ad'
            );
        }

        return $namePart . $initial;
    }

    public static function normalizeOverrideUsername($raw)
    {
        $username = trim((string) $raw);
        if ($username === '' || !preg_match('/^[A-Za-z0-9]{2,64}$/', $username)) {
            JsonResponse::badRequest(
                'Kullanici adi gecersiz. Yalniz harf ve rakam kullanin.',
                self::ERR_USERNAME_INVALID,
                'username'
            );
        }

        return $username;
    }

    public static function foldToLowerAsciiToken($value)
    {
        $folded = self::foldTurkishChars((string) $value);
        $lower = strtolower($folded);

        return preg_replace('/[^a-z0-9]/', '', $lower) ?? '';
    }

    public static function foldSurnameInitial($soyad)
    {
        $soyad = trim((string) $soyad);
        if ($soyad === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            $first = mb_substr($soyad, 0, 1, 'UTF-8');
        } else {
            $first = substr($soyad, 0, 1);
        }
        $map = [
            'ç' => 'C', 'Ç' => 'C',
            'ğ' => 'G', 'Ğ' => 'G',
            'ı' => 'I', 'İ' => 'I', 'I' => 'I', 'i' => 'I',
            'ö' => 'O', 'Ö' => 'O',
            'ş' => 'S', 'Ş' => 'S',
            'ü' => 'U', 'Ü' => 'U',
        ];
        if (isset($map[$first])) {
            return $map[$first];
        }
        $ascii = strtoupper(self::foldTurkishChars($first));
        if (!preg_match('/^[A-Z]$/', $ascii)) {
            return '';
        }

        return $ascii;
    }

    public static function foldTurkishChars($value)
    {
        $map = [
            'ç' => 'c', 'Ç' => 'C',
            'ğ' => 'g', 'Ğ' => 'G',
            'ı' => 'i', 'İ' => 'I',
            'ö' => 'o', 'Ö' => 'O',
            'ş' => 's', 'Ş' => 'S',
            'ü' => 'u', 'Ü' => 'U',
        ];

        return strtr((string) $value, $map);
    }

    public static function hashToken($rawToken)
    {
        return hash('sha256', (string) $rawToken);
    }

    public static function generateActivationToken()
    {
        // 32 bytes = 256 bits entropy; hex encoding for URL-safe transport.
        return bin2hex(random_bytes(32));
    }

    public static function generateUnusableInternalSecret()
    {
        return bin2hex(random_bytes(32));
    }

    public static function activationTtlMinutes()
    {
        $ttl = (int) medisa_config('personel_activation_ttl_minutes', 1440);
        if ($ttl < 5) {
            $ttl = 1440;
        }
        if ($ttl > 10080) {
            $ttl = 10080;
        }

        return $ttl;
    }

    public static function buildActivationUrl($rawToken)
    {
        $base = rtrim(trim((string) medisa_config('app_public_url', '')), '/');
        if ($base === '' || strpos($base, 'CHANGE_ME') === 0) {
            // Dev/test fallback: relative path only (management UI can prefix with window origin).
            return '/personel-aktivasyon#token=' . rawurlencode((string) $rawToken);
        }

        return $base . '/personel-aktivasyon#token=' . rawurlencode((string) $rawToken);
    }

    public static function sendNoStoreHeaders()
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
        }
    }

    private static function assertSchemaReady(PDO $pdo)
    {
        if (!UsersSchema::hasPersonelId($pdo)
            || !UsersSchema::hasActivationRequired($pdo)
            || !self::hasInvitationTable($pdo)
        ) {
            JsonResponse::error(
                409,
                self::ERR_SCHEMA,
                'Personel hesap aktivasyon semasi hazir degil.'
            );
        }
    }

    private static function hasInvitationTable(PDO $pdo)
    {
        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'personel_account_activation_invitations'"
            );
            $count = $stmt ? (int) $stmt->fetchColumn() : 0;

            return $count > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function lockPersonelRow(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, ad, soyad, sicil_no, aktif_durum, sube_id, calisan_kapsami
             FROM personeller WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            JsonResponse::error(404, 'PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 'personel_id');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private static function lockUserRow(PDO $pdo, $userId)
    {
        $cols = 'id, username, password_hash, ad_soyad, rol, durum, personel_id, activation_required';
        $stmt = $pdo->prepare("SELECT $cols FROM users WHERE id = :id LIMIT 1 FOR UPDATE");
        $stmt->execute(['id' => (int) $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            JsonResponse::error(404, 'USER_NOT_FOUND', 'Kullanici bulunamadi.', 'user_id');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $personel
     */
    private static function assertEligibleForOnboarding(PDO $pdo, array $personel)
    {
        $aktif = strtoupper(trim((string) ($personel['aktif_durum'] ?? '')));
        if ($aktif !== 'AKTIF') {
            JsonResponse::error(
                409,
                self::ERR_PERSONEL_INACTIVE,
                'Pasif personele hesap olusturulamaz.',
                'personel_id'
            );
        }

        if (self::isArchivedTestFixture($pdo, (int) $personel['id'])) {
            JsonResponse::error(
                409,
                self::ERR_PERSONEL_ARCHIVED,
                'Arsivlenmis test fixture personeline hesap olusturulamaz.',
                'personel_id'
            );
        }

        $kapsam = strtoupper(trim((string) ($personel['calisan_kapsami'] ?? 'IC_PERSONEL')));
        if ($kapsam !== 'IC_PERSONEL' && $kapsam !== 'DIS_KAYNAK' && $kapsam !== '') {
            JsonResponse::error(
                409,
                'PERSONEL_TYPE_NOT_ELIGIBLE',
                'Bu personel tipi icin hesap olusturma desteklenmiyor.',
                'calisan_kapsami'
            );
        }

        self::buildPersonelUsernameFromNames($personel['ad'] ?? null, $personel['soyad'] ?? null);
    }

    /**
     * @param array<string, mixed> $personel
     */
    private static function assertPersonelStillActiveForActivation(PDO $pdo, array $personel)
    {
        $aktif = strtoupper(trim((string) ($personel['aktif_durum'] ?? '')));
        if ($aktif !== 'AKTIF') {
            JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisi gecersiz veya suresi dolmus.');
        }
        if (self::isArchivedTestFixture($pdo, (int) $personel['id'])) {
            JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisi gecersiz veya suresi dolmus.');
        }
    }

    private static function isArchivedTestFixture(PDO $pdo, $personelId)
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT id FROM personel_test_fixture_archive_kayitlari WHERE personel_id = :id LIMIT 1'
            );
            $stmt->execute(['id' => (int) $personelId]);

            return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findBoundUserForPersonel(PDO $pdo, $personelId)
    {
        $cols = 'id, username, rol, durum, activation_required, personel_id';
        $stmt = $pdo->prepare(
            "SELECT $cols FROM users WHERE personel_id = :pid LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['pid' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function assertUsernameAvailableForNewAccount(PDO $pdo, $username, $personelId, $suggestedUsername)
    {
        $stmt = $pdo->prepare(
            'SELECT id, personel_id FROM users WHERE username = :u LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }
        $boundPid = isset($row['personel_id']) && $row['personel_id'] !== null && $row['personel_id'] !== ''
            ? (int) $row['personel_id']
            : 0;
        if ($boundPid === (int) $personelId) {
            JsonResponse::error(
                409,
                self::ERR_ALREADY_PROVISIONED,
                'Bu personelin hesabi zaten mevcut.',
                'personel_id'
            );
        }
        JsonResponse::error(
            409,
            self::ERR_USERNAME_COLLISION,
            'Bu kullanici adi zaten kullaniliyor. Farkli bir kullanici adi belirleyin.',
            'username',
            ['suggested_username' => $suggestedUsername]
        );
    }

    /**
     * @return array{id:int, raw_token:string, created_at_utc:string, expires_at_utc:string}
     */
    private static function issueInvitationLocked(
        PDO $pdo,
        $userId,
        $actorId,
        $reissueOfId,
        $isReissue
    ) {
        $now = self::utcNow();
        self::revokeSiblingLiveInvitations($pdo, (int) $userId, null, $now, (int) $actorId);

        $rawToken = self::generateActivationToken();
        $tokenHash = self::hashToken($rawToken);
        $ttl = self::activationTtlMinutes();
        $expires = gmdate('Y-m-d H:i:s', time() + ($ttl * 60));

        $stmt = $pdo->prepare(
            'INSERT INTO personel_account_activation_invitations
                (user_id, token_hash, created_at_utc, expires_at_utc, issued_by_user_id, reissue_of_invitation_id)
             VALUES
                (:user_id, :token_hash, :created, :expires, :issued_by, :reissue_of)'
        );
        $stmt->execute([
            'user_id' => (int) $userId,
            'token_hash' => $tokenHash,
            'created' => $now,
            'expires' => $expires,
            'issued_by' => (int) $actorId,
            'reissue_of' => $reissueOfId,
        ]);
        $id = (int) $pdo->lastInsertId();

        // Ensure at most one live invitation (defense in depth).
        $live = $pdo->prepare(
            'SELECT COUNT(*) FROM personel_account_activation_invitations
             WHERE user_id = :uid AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL'
        );
        $live->execute(['uid' => (int) $userId]);
        if ((int) $live->fetchColumn() !== 1) {
            throw new \RuntimeException('Activation invitation uniqueness invariant violated.');
        }

        return [
            'id' => $id,
            'raw_token' => $rawToken,
            'created_at_utc' => $now,
            'expires_at_utc' => $expires,
            'is_reissue' => $isReissue,
        ];
    }

    private static function revokeSiblingLiveInvitations(
        PDO $pdo,
        $userId,
        $exceptInvitationId,
        $nowUtc,
        $actorId
    ) {
        $sql = 'UPDATE personel_account_activation_invitations
                SET revoked_at_utc = :now
                WHERE user_id = :uid
                  AND consumed_at_utc IS NULL
                  AND revoked_at_utc IS NULL';
        $params = ['now' => $nowUtc, 'uid' => (int) $userId];
        if ($exceptInvitationId !== null) {
            $sql .= ' AND id <> :except_id';
            $params['except_id'] = (int) $exceptInvitationId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() > 0 && $actorId !== null) {
            self::writeAudit(
                $pdo,
                self::EVENT_ACTIVATION_REVOKED,
                (int) $userId,
                null,
                (int) $actorId,
                null,
                ['revoked_count' => $stmt->rowCount()]
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function lockInvitationByHash(PDO $pdo, $tokenHash)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM personel_account_activation_invitations
             WHERE token_hash = :hash
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $inv
     */
    private static function assertInvitationRedeemable(array $inv)
    {
        if ($inv['revoked_at_utc'] !== null && $inv['revoked_at_utc'] !== '') {
            JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisi gecersiz veya suresi dolmus. Yoneticiniz veya IK ile iletisime gecerek yeni baglanti isteyin.');
        }
        if ($inv['consumed_at_utc'] !== null && $inv['consumed_at_utc'] !== '') {
            JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisi gecersiz veya suresi dolmus.');
        }
        if (strtotime((string) $inv['expires_at_utc'] . ' UTC') < time()) {
            JsonResponse::error(400, self::ERR_ACTIVATION_INVALID, 'Aktivasyon baglantisinin suresi dolmus. Yoneticiniz veya IK ile iletisime gecerek yeni baglanti isteyin.');
        }
    }

    /**
     * @param array<string, mixed> $invitation
     * @return array<string, mixed>
     */
    private static function buildIssueResponse(PDO $pdo, $userId, $username, array $invitation, $reissued)
    {
        $url = self::buildActivationUrl($invitation['raw_token']);
        // Do not retain raw token in response beyond URL construction.
        unset($invitation['raw_token']);

        $userCols = 'id, username, rol, durum, personel_id, activation_required';
        if (UsersSchema::hasMustChangePassword($pdo)) {
            $userCols .= ', must_change_password';
        }
        if (UsersSchema::hasActivatedAtUtc($pdo)) {
            $userCols .= ', activated_at_utc';
        }
        $stmt = $pdo->prepare("SELECT $userCols FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => (int) $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'user' => [
                'id' => (int) ($user['id'] ?? $userId),
                'username' => (string) ($user['username'] ?? $username),
                'rol' => (string) ($user['rol'] ?? 'PERSONEL'),
                'durum' => (string) ($user['durum'] ?? 'AKTIF'),
                'personel_id' => isset($user['personel_id']) ? (int) $user['personel_id'] : null,
                'activation_required' => true,
                'must_change_password' => isset($user['must_change_password'])
                    ? ((int) $user['must_change_password']) === 1
                    : true,
                'activated_at_utc' => $user['activated_at_utc'] ?? null,
            ],
            'activation' => [
                'activation_url' => $url,
                'created_at_utc' => $invitation['created_at_utc'],
                'expires_at_utc' => $invitation['expires_at_utc'],
                'reissued' => $reissued,
            ],
            'message' => $reissued
                ? 'Yeni aktivasyon baglantisi olusturuldu.'
                : 'Hesap olusturuldu. Aktivasyon baglantisi olusturuldu.',
        ];
    }

    /**
     * @param array<string, mixed>|null $detail
     */
    private static function writeAudit(
        PDO $pdo,
        $eventType,
        $userId,
        $personelId,
        $actorUserId,
        $invitationId,
        array $detail = null
    ) {
        // Never include secrets in detail.
        if (is_array($detail)) {
            unset(
                $detail['token'],
                $detail['raw_token'],
                $detail['activation_url'],
                $detail['password'],
                $detail['password_hash'],
                $detail['new_password']
            );
        }
        $stmt = $pdo->prepare(
            'INSERT INTO personel_account_onboarding_audit
                (event_type, user_id, personel_id, actor_user_id, invitation_id, detail_json, created_at_utc)
             VALUES
                (:event_type, :user_id, :personel_id, :actor_user_id, :invitation_id, :detail_json, :created)'
        );
        $stmt->execute([
            'event_type' => (string) $eventType,
            'user_id' => $userId !== null && (int) $userId > 0 ? (int) $userId : null,
            'personel_id' => $personelId !== null && (int) $personelId > 0 ? (int) $personelId : null,
            'actor_user_id' => $actorUserId !== null && (int) $actorUserId > 0 ? (int) $actorUserId : null,
            'invitation_id' => $invitationId !== null && (int) $invitationId > 0 ? (int) $invitationId : null,
            'detail_json' => $detail !== null && count($detail) > 0
                ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'created' => self::utcNow(),
        ]);
    }

    private static function utcNow()
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function isUniqueViolation(PDOException $e)
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($driverCode === 1062) {
            return true;
        }
        $message = strtolower($e->getMessage());

        return strpos($message, '1062') !== false || strpos($message, 'duplicate') !== false;
    }
}
