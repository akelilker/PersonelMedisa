<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Database\UsersSchema;
use Medisa\Api\Http\JsonResponse;
use PDO;

/**
 * Bound-user canonical username reconciliation owner.
 *
 * Scope: every login account with users.personel_id IS NOT NULL — any role
 * (PERSONEL, BIRIM_AMIRI, BOLUM_YONETICISI, SUBE_YONETICISI, …). Role is reported
 * for information only; this owner never demotes or changes rol.
 *
 * Expected username always comes from the bound personnel record through the
 * canonical PersonelAccountOnboardingService resolver (overrides / name
 * corrections included). Sicil-style legacy usernames (e.g. "017") surface as
 * mismatches when they differ from that expected value.
 *
 * scan() is structurally read-only: SELECT only. Auto-fix is never performed
 * by scan; collisions are blockers, not rewrite targets.
 */
final class BoundUserCanonicalUsernameReconciliationService
{
    public const SCHEMA_VERSION = '1';

    public const MODE = 'BOUND_USER_CANONICAL_USERNAME_SCAN';

    public const CLASS_MATCH = 'match';
    public const CLASS_MISMATCH = 'mismatch';
    public const CLASS_USER_NOT_ACTIVE = 'user_not_active';
    public const CLASS_BOUND_PERSONEL_MISSING = 'bound_personel_missing';
    public const CLASS_BOUND_PERSONEL_NOT_ACTIVE = 'bound_personel_not_active';
    public const CLASS_NAME_UNRESOLVED = 'name_unresolved';
    public const CLASS_PROTECTED_USERNAME = 'protected_username';

    public const ERR_SCHEMA = 'BOUND_USER_USERNAME_SCHEMA_NOT_READY';
    public const ERR_NOT_BOUND = 'BOUND_USER_PERSONEL_REQUIRED';
    public const ERR_PERSONEL_INACTIVE = 'BOUND_USER_PERSONEL_NOT_ACTIVE';
    public const ERR_PERSONEL_MISSING = 'BOUND_USER_PERSONEL_MISSING';
    public const ERR_NAME_UNRESOLVED = 'BOUND_USER_USERNAME_UNRESOLVED';
    public const ERR_COLLISION = 'BOUND_USER_USERNAME_COLLISION';
    public const ERR_PROTECTED = 'BOUND_USER_USERNAME_PROTECTED';
    public const ERR_MISMATCH = 'USERNAME_CANONICAL_MISMATCH';

    /**
     * Read-only scan of every personel-bound user.
     *
     * @return array<string, mixed>
     */
    public static function scan(PDO $pdo): array
    {
        if (!UsersSchema::hasPersonelId($pdo)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'mode' => self::MODE,
                'ready' => false,
                'blocker' => self::ERR_SCHEMA,
                'totals' => self::emptyTotals(),
                'by_role' => [],
                'mismatches' => [],
                'collision_blockers' => [],
                'passive_users' => [],
                'passive_personel' => [],
                'unresolved' => [],
                'protected' => [],
            ];
        }

        $rows = self::loadBoundUserRows($pdo);
        $takenByUsername = self::loadUsernameOwners($pdo);

        $mismatches = [];
        $passiveUsers = [];
        $passivePersonel = [];
        $unresolved = [];
        $protected = [];
        $collisionBlockers = [];
        $byRole = [];
        $totals = self::emptyTotals();
        $totals['bound_total'] = count($rows);

        foreach ($rows as $row) {
            $assessed = self::assessRow($row, $takenByUsername);
            $rol = (string) $assessed['rol'];
            if (!isset($byRole[$rol])) {
                $byRole[$rol] = self::emptyTotals();
            }
            $byRole[$rol]['bound_total']++;

            $class = (string) $assessed['classification'];
            $totalsBucket = self::totalsKeyForClass($class);
            $totals[$totalsBucket]++;
            $byRole[$rol][$totalsBucket]++;

            if (!empty($assessed['collision'])) {
                $totals['collision_blocker_count']++;
                $byRole[$rol]['collision_blocker_count']++;
                $collisionBlockers[] = self::publicRow($assessed);
            }

            if ($class === self::CLASS_MISMATCH) {
                $mismatches[] = self::publicRow($assessed);
            } elseif ($class === self::CLASS_USER_NOT_ACTIVE) {
                $passiveUsers[] = self::publicRow($assessed);
            } elseif ($class === self::CLASS_BOUND_PERSONEL_NOT_ACTIVE) {
                $passivePersonel[] = self::publicRow($assessed);
            } elseif ($class === self::CLASS_NAME_UNRESOLVED || $class === self::CLASS_BOUND_PERSONEL_MISSING) {
                $unresolved[] = self::publicRow($assessed);
            } elseif ($class === self::CLASS_PROTECTED_USERNAME) {
                $protected[] = self::publicRow($assessed);
            }
        }

        ksort($byRole);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'ready' => true,
            'blocker' => null,
            'totals' => $totals,
            'by_role' => $byRole,
            'mismatches' => $mismatches,
            'collision_blockers' => $collisionBlockers,
            'passive_users' => $passiveUsers,
            'passive_personel' => $passivePersonel,
            'unresolved' => $unresolved,
            'protected' => $protected,
        ];
    }

    /**
     * Assess one stored user row for mismatch / fix eligibility.
     * Used by Yönetim update guards and the dedicated fix intent.
     *
     * @param array<string, mixed> $userRow users.* row (must include personel_id when bound)
     * @return array<string, mixed>
     */
    public static function assessUser(PDO $pdo, array $userRow): array
    {
        $personelId = isset($userRow['personel_id']) && $userRow['personel_id'] !== null && $userRow['personel_id'] !== ''
            ? (int) $userRow['personel_id']
            : 0;
        if ($personelId <= 0) {
            return [
                'bound' => false,
                'mismatch' => false,
                'fixable' => false,
                'classification' => null,
                'actual_username' => (string) ($userRow['username'] ?? ''),
                'expected_username' => null,
                'rol' => (string) ($userRow['rol'] ?? ''),
                'personel_id' => null,
                'collision' => false,
                'collision_user_id' => null,
            ];
        }

        $bound = self::loadBoundContext($pdo, (int) $userRow['id'], $personelId);
        $takenByUsername = self::loadUsernameOwners($pdo);
        $assessed = self::assessRow($bound, $takenByUsername);

        return [
            'bound' => true,
            'mismatch' => $assessed['classification'] === self::CLASS_MISMATCH
                || ($assessed['expected_username'] !== null
                    && (string) $assessed['actual_username'] !== (string) $assessed['expected_username']
                    && $assessed['classification'] !== self::CLASS_MATCH),
            'fixable' => !empty($assessed['fixable']),
            'classification' => $assessed['classification'],
            'actual_username' => (string) $assessed['actual_username'],
            'expected_username' => $assessed['expected_username'],
            'rol' => (string) $assessed['rol'],
            'personel_id' => (int) $assessed['personel_id'],
            'collision' => !empty($assessed['collision']),
            'collision_user_id' => $assessed['collision_user_id'],
            'user_durum' => (string) $assessed['user_durum'],
            'personel_aktif_durum' => $assessed['personel_aktif_durum'],
        ];
    }

    /**
     * Resolve the username that a safe canonical fix must write.
     * Fails closed via JsonResponse when the fix is not allowed.
     *
     * @param array<string, mixed> $userRow
     * @return array{username: string, personel_id: int, previous_username: string}
     */
    public static function requireCanonicalUsernameFix(PDO $pdo, array $userRow): array
    {
        $assessed = self::assessUser($pdo, $userRow);
        if (empty($assessed['bound'])) {
            JsonResponse::badRequest(
                'Canonical kullanici adi duzeltmesi yalniz personel baglantili hesaplar icin gecerlidir.',
                self::ERR_NOT_BOUND,
                'personel_id'
            );
        }

        $class = (string) ($assessed['classification'] ?? '');
        if ($class === self::CLASS_PROTECTED_USERNAME) {
            JsonResponse::error(
                409,
                self::ERR_PROTECTED,
                'Korunan kullanici adi canonical duzeltmeye kapali.',
                'username'
            );
        }
        if ($class === self::CLASS_BOUND_PERSONEL_MISSING) {
            JsonResponse::error(
                409,
                self::ERR_PERSONEL_MISSING,
                'Bagli personel kaydi bulunamadi.',
                'personel_id'
            );
        }
        if ($class === self::CLASS_BOUND_PERSONEL_NOT_ACTIVE) {
            JsonResponse::error(
                409,
                self::ERR_PERSONEL_INACTIVE,
                'Bagli personel aktif degil; kullanici adi duzeltilmez.',
                'personel_id'
            );
        }
        if ($class === self::CLASS_NAME_UNRESOLVED || $assessed['expected_username'] === null) {
            JsonResponse::error(
                409,
                self::ERR_NAME_UNRESOLVED,
                'Canonical kullanici adi personel ad/soyadindan uretilemedi.',
                'username'
            );
        }
        if (!empty($assessed['collision'])) {
            JsonResponse::error(
                409,
                self::ERR_COLLISION,
                'Canonical kullanici adi baska bir hesapta kayitli; otomatik duzeltme yapilmaz.',
                'username'
            );
        }

        $expected = (string) $assessed['expected_username'];
        $actual = (string) $assessed['actual_username'];
        if ($actual === $expected) {
            return [
                'username' => $expected,
                'personel_id' => (int) $assessed['personel_id'],
                'previous_username' => $actual,
            ];
        }

        // Passive users may still be fixed so a later return to AKTIF does not keep a
        // legacy sicil login — but only when the bound personel is active and
        // there is no collision (already gated above).
        return [
            'username' => $expected,
            'personel_id' => (int) $assessed['personel_id'],
            'previous_username' => $actual,
        ];
    }

    /**
     * Fail closed when an initial-password reset is requested while the bound
     * username is not canonical. Does not mutate username.
     *
     * @param array<string, mixed> $userRow
     */
    public static function assertUsernameCanonicalForInitialPasswordReset(PDO $pdo, array $userRow): void
    {
        $assessed = self::assessUser($pdo, $userRow);
        if (empty($assessed['bound'])) {
            return;
        }
        $expected = $assessed['expected_username'];
        if ($expected === null) {
            return;
        }
        if ((string) $assessed['actual_username'] === (string) $expected) {
            return;
        }

        JsonResponse::error(
            409,
            self::ERR_MISMATCH,
            'Kullanici adi canonical personel sablonuyla uyusmuyor. Once kullanici adini canonical sablona duzeltin.',
            'username'
        );
    }

    /**
     * Pure classification helper (no DB). Used by scan and by focused tests.
     *
     * @param array{
     *   user_id:int,
     *   username:string,
     *   rol:string,
     *   user_durum:string,
     *   personel_id:int,
     *   personel_row_id:?int,
     *   personel_ad:?string,
     *   personel_soyad:?string,
     *   personel_aktif_durum:?string
     * } $row
     * @param array<string, int> $takenByUsername lowercase username => owning user id
     * @return array<string, mixed>
     */
    public static function classifyBoundRow(array $row, array $takenByUsername): array
    {
        $userId = (int) $row['user_id'];
        $actual = (string) $row['username'];
        $personelId = (int) $row['personel_id'];
        $protected = [];
        foreach (PersonelAccountOnboardingService::PROTECTED_USERNAMES as $reserved) {
            $protected[strtolower((string) $reserved)] = true;
        }

        $base = [
            'user_id' => $userId,
            'actual_username' => $actual,
            'expected_username' => null,
            'rol' => (string) $row['rol'],
            'user_durum' => (string) $row['user_durum'],
            'personel_id' => $personelId,
            'personel_aktif_durum' => $row['personel_aktif_durum'] !== null
                ? (string) $row['personel_aktif_durum']
                : null,
            'collision' => false,
            'collision_user_id' => null,
            'fixable' => false,
        ];

        if (isset($protected[strtolower($actual)])) {
            return $base + ['classification' => self::CLASS_PROTECTED_USERNAME];
        }

        if ($row['personel_row_id'] === null) {
            return $base + ['classification' => self::CLASS_BOUND_PERSONEL_MISSING];
        }

        $personelAktif = strtoupper(trim((string) ($row['personel_aktif_durum'] ?? '')));
        $expected = PersonelAccountOnboardingService::resolvePersonelCanonicalUsername(
            $personelId,
            $row['personel_ad'] ?? null,
            $row['personel_soyad'] ?? null
        );
        $base['expected_username'] = $expected;

        if ($expected === null) {
            return $base + ['classification' => self::CLASS_NAME_UNRESOLVED];
        }

        $collisionUserId = null;
        $key = strtolower($expected);
        if (isset($takenByUsername[$key]) && (int) $takenByUsername[$key] !== $userId) {
            $collisionUserId = (int) $takenByUsername[$key];
            $base['collision'] = true;
            $base['collision_user_id'] = $collisionUserId;
        }

        if ($personelAktif !== 'AKTIF') {
            return $base + ['classification' => self::CLASS_BOUND_PERSONEL_NOT_ACTIVE];
        }

        if (strtoupper(trim((string) $row['user_durum'])) !== 'AKTIF') {
            $base['fixable'] = $collisionUserId === null && $actual !== $expected;
            return $base + ['classification' => self::CLASS_USER_NOT_ACTIVE];
        }

        if ($actual === $expected) {
            return $base + ['classification' => self::CLASS_MATCH];
        }

        $base['fixable'] = $collisionUserId === null;

        return $base + ['classification' => self::CLASS_MISMATCH];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $takenByUsername
     * @return array<string, mixed>
     */
    private static function assessRow(array $row, array $takenByUsername): array
    {
        return self::classifyBoundRow(
            [
                'user_id' => (int) $row['user_id'],
                'username' => (string) $row['username'],
                'rol' => (string) $row['rol'],
                'user_durum' => (string) $row['user_durum'],
                'personel_id' => (int) $row['personel_id'],
                'personel_row_id' => $row['personel_row_id'] !== null ? (int) $row['personel_row_id'] : null,
                'personel_ad' => $row['personel_ad'] ?? null,
                'personel_soyad' => $row['personel_soyad'] ?? null,
                'personel_aktif_durum' => $row['personel_aktif_durum'] ?? null,
            ],
            $takenByUsername
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadBoundUserRows(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT u.id AS user_id, u.username, u.rol, u.durum AS user_durum, u.personel_id,
                    p.id AS personel_row_id, p.ad AS personel_ad, p.soyad AS personel_soyad,
                    p.aktif_durum AS personel_aktif_durum
               FROM users u
               LEFT JOIN personeller p ON p.id = u.personel_id
              WHERE u.personel_id IS NOT NULL
              ORDER BY u.id ASC"
        );

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadBoundContext(PDO $pdo, int $userId, int $personelId): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.id AS user_id, u.username, u.rol, u.durum AS user_durum, u.personel_id,
                    p.id AS personel_row_id, p.ad AS personel_ad, p.soyad AS personel_soyad,
                    p.aktif_durum AS personel_aktif_durum
               FROM users u
               LEFT JOIN personeller p ON p.id = :personel_id
              WHERE u.id = :user_id
              LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'personel_id' => $personelId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'user_id' => $userId,
                'username' => '',
                'rol' => '',
                'user_durum' => '',
                'personel_id' => $personelId,
                'personel_row_id' => null,
                'personel_ad' => null,
                'personel_soyad' => null,
                'personel_aktif_durum' => null,
            ];
        }

        return $row;
    }

    /**
     * @return array<string, int>
     */
    private static function loadUsernameOwners(PDO $pdo): array
    {
        $taken = [];
        $stmt = $pdo->query('SELECT id, username FROM users');
        foreach (($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : []) as $other) {
            $taken[strtolower((string) $other['username'])] = (int) $other['id'];
        }

        return $taken;
    }

    /**
     * @param array<string, mixed> $assessed
     * @return array<string, mixed>
     */
    private static function publicRow(array $assessed): array
    {
        return [
            'user_id' => (int) $assessed['user_id'],
            'actual_username' => (string) $assessed['actual_username'],
            'expected_username' => $assessed['expected_username'],
            'rol' => (string) $assessed['rol'],
            'user_durum' => (string) $assessed['user_durum'],
            'personel_id' => (int) $assessed['personel_id'],
            'personel_aktif_durum' => $assessed['personel_aktif_durum'],
            'classification' => (string) $assessed['classification'],
            'collision' => !empty($assessed['collision']),
            'collision_user_id' => $assessed['collision_user_id'],
            'fixable' => !empty($assessed['fixable']),
        ];
    }

    /** @return array<string, int> */
    private static function emptyTotals(): array
    {
        return [
            'bound_total' => 0,
            'match' => 0,
            'mismatch' => 0,
            'user_not_active' => 0,
            'bound_personel_missing' => 0,
            'bound_personel_not_active' => 0,
            'name_unresolved' => 0,
            'protected_username' => 0,
            'collision_blocker_count' => 0,
        ];
    }

    private static function totalsKeyForClass(string $class): string
    {
        switch ($class) {
            case self::CLASS_MATCH:
                return 'match';
            case self::CLASS_MISMATCH:
                return 'mismatch';
            case self::CLASS_USER_NOT_ACTIVE:
                return 'user_not_active';
            case self::CLASS_BOUND_PERSONEL_MISSING:
                return 'bound_personel_missing';
            case self::CLASS_BOUND_PERSONEL_NOT_ACTIVE:
                return 'bound_personel_not_active';
            case self::CLASS_NAME_UNRESOLVED:
                return 'name_unresolved';
            case self::CLASS_PROTECTED_USERNAME:
                return 'protected_username';
            default:
                return 'name_unresolved';
        }
    }
}
