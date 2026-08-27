<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Database\Connection;
use Medisa\Api\Database\UserOrgAssignmentSchema;
use Medisa\Api\Database\UsersSchema;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Personel\PersonelOrgStructureSchema;
use PDO;

class LoginController
{
    public static function login(Request $request)
    {
        if (!medisa_config_ready()) {
            JsonResponse::serverError('API yapilandirmasi tamamlanmamis.');
        }

        $body = $request->getJsonBody();
        $username = isset($body['username']) ? trim((string) $body['username']) : '';
        $password = isset($body['password']) ? (string) $body['password'] : '';

        if ($username === '' || $password === '') {
            JsonResponse::badRequest('Kullanici adi ve sifre zorunludur.', 'VALIDATION_ERROR', 'username');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $hasVarsayilan = UsersSchema::hasVarsayilanSubeId($pdo);
        $hasPersonelId = UsersSchema::hasPersonelId($pdo);
        $loginCols = ['id', 'username', 'password_hash', 'ad_soyad', 'rol', 'durum'];
        if ($hasVarsayilan) {
            $loginCols[] = 'varsayilan_sube_id';
        }
        if ($hasPersonelId) {
            $loginCols[] = 'personel_id';
        }
        $hasMustChange = UsersSchema::hasMustChangePassword($pdo);
        if ($hasMustChange) {
            $loginCols[] = 'must_change_password';
        }
        $hasActivationRequired = UsersSchema::hasActivationRequired($pdo);
        if ($hasActivationRequired) {
            $loginCols[] = 'activation_required';
        }
        $selectSql = 'SELECT ' . implode(', ', $loginCols) . ' FROM users WHERE username = :username LIMIT 1';
        $stmt = $pdo->prepare($selectSql);
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || ($user['durum'] ?? '') !== 'AKTIF') {
            JsonResponse::error(401, 'INVALID_CREDENTIALS', 'Kullanici adi veya sifre hatali.');
        }

        // Activation-pending accounts cannot log in. Opaque credentials message (no enumeration).
        if ($hasActivationRequired && ((int) ($user['activation_required'] ?? 0)) === 1) {
            JsonResponse::error(401, 'INVALID_CREDENTIALS', 'Kullanici adi veya sifre hatali.');
        }

        if (!PasswordHasher::verify($password, (string) ($user['password_hash'] ?? ''))) {
            JsonResponse::error(401, 'INVALID_CREDENTIALS', 'Kullanici adi veya sifre hatali.');
        }

        $subeIds = self::loadUserSubeIds($pdo, (int) $user['id']);
        $bolumIds = UserOrgAssignmentSchema::loadUserBolumIds($pdo, (int) $user['id']);
        $birimIds = UserOrgAssignmentSchema::loadUserBirimIds($pdo, (int) $user['id']);
        $rolRaw = (string) $user['rol'];
        $rol = RolePermissions::normalizeRole($rolRaw);
        if ($rol === '') {
            JsonResponse::error(
                403,
                'ROLE_UNRESOLVED',
                'Kullanici rolu canonical modele cozulemedi. Manuel rol eslemesi gerekir.'
            );
        }

        // PERSONEL self-service accounts: bound personel must be AKTIF (login fail-closed).
        // Management roles with optional personel_id binding keep login; SelfPersonelContext still denies /me.
        if ($rol === 'PERSONEL') {
            self::assertPersonelRoleLoginAllowed($pdo, $hasPersonelId, $user);
        }

        if ($rol === 'AUTH_SMOKE_READONLY' && count($subeIds) !== 1) {
            JsonResponse::error(
                403,
                'AUTH_SMOKE_SCOPE_INVALID',
                'AUTH_SMOKE_READONLY hesabi exact bir sube scope gerektirir.'
            );
        }
        // Effective branch list for selector: assigned subeler, else derived from bolum/birim personnel (narrow UX only).
        $selectorSubeIds = $subeIds;
        if (count($selectorSubeIds) === 0 && (count($bolumIds) > 0 || count($birimIds) > 0)) {
            $selectorSubeIds = self::deriveSubeIdsFromOrgAssignments($pdo, $bolumIds, $birimIds);
        }
        $subeList = self::loadSubeList($pdo, $selectorSubeIds, OrgScope::isUnrestricted(['rol' => $rol]));
        $preferredSubeId = null;
        if ($hasVarsayilan && array_key_exists('varsayilan_sube_id', $user) && $user['varsayilan_sube_id'] !== null && $user['varsayilan_sube_id'] !== '') {
            $preferred = (int) $user['varsayilan_sube_id'];
            $preferredSubeId = $preferred > 0 ? $preferred : null;
        }
        $activeSubeId = SubeScope::resolveInitialActiveSubeId($selectorSubeIds, $preferredSubeId);

        $ttl = (int) medisa_config('jwt_ttl_seconds', 86400);
        $token = Jwt::encode([
            'sub' => (int) $user['id'],
            'rol' => $rol,
            'iat' => time(),
            'exp' => time() + $ttl,
        ]);

        $personelIdPayload = null;
        if ($hasPersonelId && array_key_exists('personel_id', $user) && $user['personel_id'] !== null && $user['personel_id'] !== '') {
            $parsedPersonel = (int) $user['personel_id'];
            $personelIdPayload = $parsedPersonel > 0 ? $parsedPersonel : null;
        }

        $userPayload = [
            'id' => (int) $user['id'],
            'ad_soyad' => (string) $user['ad_soyad'],
            'rol' => $rol,
            'sube_ids' => $subeIds,
            'bolum_ids' => $bolumIds,
            'birim_ids' => $birimIds,
        ];
        if ($hasPersonelId) {
            $userPayload['personel_id'] = $personelIdPayload;
        }

        $response = [
            'token' => $token,
            'user' => $userPayload,
            'ui_profile' => $rol === 'BIRIM_AMIRI' ? 'birim_amiri' : 'yonetim',
            'sube_list' => $subeList,
            'active_sube_id' => $activeSubeId,
        ];
        if ($hasMustChange) {
            $response['must_change_password'] = ((int) ($user['must_change_password'] ?? 0)) === 1;
        }

        JsonResponse::success($response);
    }

    /**
     * @param array<int, int> $bolumIds
     * @param array<int, int> $birimIds
     * @return array<int, int>
     */
    private static function deriveSubeIdsFromOrgAssignments(PDO $pdo, array $bolumIds, array $birimIds)
    {
        // Fail-closed: never reference personeller.bolum_id / birim_id until both exist.
        // Do not widen authority via user_subeler or unrestricted global branch lists.
        if (!PersonelOrgStructureSchema::hasPersonelScopeColumns($pdo)) {
            return [];
        }

        $ids = [];
        if (count($birimIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($birimIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT DISTINCT p.sube_id FROM personeller p WHERE p.birim_id IN ($placeholders) AND p.sube_id IS NOT NULL"
            );
            $stmt->execute(array_values($birimIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sid = (int) $row['sube_id'];
                if ($sid > 0) {
                    $ids[$sid] = $sid;
                }
            }
        }
        if (count($bolumIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($bolumIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT DISTINCT p.sube_id FROM personeller p WHERE p.bolum_id IN ($placeholders) AND p.sube_id IS NOT NULL"
            );
            $stmt->execute(array_values($bolumIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sid = (int) $row['sube_id'];
                if ($sid > 0) {
                    $ids[$sid] = $sid;
                }
            }
        }

        $list = array_values($ids);
        sort($list);

        return $list;
    }

    /** @return array<int, int> */
    private static function loadUserSubeIds(PDO $pdo, $userId)
    {
        $stmt = $pdo->prepare('SELECT sube_id FROM user_subeler WHERE user_id = :user_id ORDER BY sube_id ASC');
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['sube_id'];
        }

        return $ids;
    }

    /**
     * @param array<int, int> $subeIds
     * @return array<int, array<string, mixed>>
     */
    private static function loadSubeList(PDO $pdo, array $subeIds, $unrestrictedEmpty = false)
    {
        if (count($subeIds) === 0) {
            if (!$unrestrictedEmpty) {
                return [];
            }
            $stmt = $pdo->query('SELECT id, ad FROM subeler WHERE durum = "AKTIF" ORDER BY id ASC');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            $placeholders = implode(',', array_fill(0, count($subeIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, ad FROM subeler WHERE id IN ($placeholders) AND durum = 'AKTIF' ORDER BY id ASC"
            );
            $stmt->execute($subeIds);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => (int) $row['id'],
                'ad' => (string) $row['ad'],
            ];
        }

        return $list;
    }

    /**
     * PERSONEL role login requires an AKTIF bound personel record.
     *
     * @param array<string, mixed> $user
     */
    private static function assertPersonelRoleLoginAllowed(PDO $pdo, $hasPersonelIdColumn, array $user)
    {
        if (!$hasPersonelIdColumn) {
            JsonResponse::error(
                403,
                'PERSONEL_BINDING_REQUIRED',
                'Personel hesabi baglantisi hazir degil.'
            );
        }

        $raw = $user['personel_id'] ?? null;
        $personelId = ($raw === null || $raw === '') ? 0 : (int) $raw;
        if ($personelId <= 0) {
            JsonResponse::error(
                403,
                'PERSONEL_BINDING_REQUIRED',
                'Personel hesabiniz personel kaydiyla eslestirilmemis.'
            );
        }

        $stmt = $pdo->prepare('SELECT aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            JsonResponse::error(
                403,
                'PERSONEL_INACTIVE',
                'Bagli personel kaydi bulunamadi.'
            );
        }
        $aktif = strtoupper(trim((string) ($row['aktif_durum'] ?? '')));
        if ($aktif !== 'AKTIF') {
            JsonResponse::error(
                403,
                'PERSONEL_INACTIVE',
                'Personel hesabiniz aktif degil.'
            );
        }
    }
}
