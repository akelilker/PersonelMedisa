<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use PDO;

/**
 * Append-only audit owner for the three organisation write paths introduced by
 * migration 080: permanent personnel branch moves, branch creation and user
 * organisation scope replacement.
 *
 * This is deliberately not a general event log. Each recorder takes the exact
 * values its own domain owns and writes one row into one table, so a reader
 * never has to interpret an untyped payload to learn what happened. Adding a
 * fourth audited write path means adding a fourth table and a fourth recorder,
 * not widening a generic sink.
 *
 * Every recorder must be called inside the caller's transaction. It performs a
 * plain INSERT and lets the exception propagate, which is what makes "the audit
 * failed, so the business write is rolled back" true rather than aspirational.
 *
 * No personnel or credential PII is written here. The recorders accept ids, the
 * justification the actor typed, and hashes — nothing else.
 */
final class OrganizasyonAuditWriter
{
    public const PERSONEL_SUBE_TABLE = 'personel_sube_degisiklik_auditleri';
    public const SUBE_CREATE_TABLE = 'sube_olusturma_auditleri';
    public const USER_SCOPE_TABLE = 'user_org_scope_auditleri';
    public const USER_ACCESS_REVOKE_TABLE = 'user_erisim_kaldirma_auditleri';
    public const USER_ACCESS_CHANGE_TABLE = 'user_erisim_degisiklik_auditleri';
    public const PERSONEL_ORG_CHANGE_TABLE = 'personel_organizasyon_degisiklik_auditleri';

    public const SCOPE_SUBE = 'SUBE';
    public const SCOPE_SIRKET = 'SIRKET';
    public const SCOPE_SGK_ISVEREN = 'SGK_ISVEREN';

    public const ACCESS_EVENT_RESTORE = 'ACCESS_RESTORE';
    public const ACCESS_EVENT_STATUS = 'STATUS_CHANGE';
    public const ACCESS_EVENT_ROLE = 'ROLE_CHANGE';
    public const ACCESS_EVENT_USERNAME = 'USERNAME_CHANGE';
    public const ACCESS_EVENT_BINDING = 'PERSONEL_BINDING_CHANGE';
    public const ACCESS_EVENT_COMBINED = 'COMBINED_ACCESS_CHANGE';

    public const SCHEMA_NOT_READY = 'ORGANIZASYON_AUDIT_SCHEMA_NOT_READY';
    public const ACCESS_CHANGE_SCHEMA_NOT_READY = 'USER_ACCESS_AUDIT_SCHEMA_NOT_READY';
    public const PERSONEL_ORG_CHANGE_SCHEMA_NOT_READY = 'PERSONEL_ORGANIZASYON_AUDIT_SCHEMA_NOT_READY';

    private const GEREKCE_MAX = 500;

    /** @var array<string, bool> */
    private static $readyCache = [];

    /**
     * Migration 080 gates every audited write. A missing table is a deployment
     * ordering error, not a reason to write an unaudited row.
     */
    public static function isReady(PDO $pdo, string $table): bool
    {
        $known = [
            self::PERSONEL_SUBE_TABLE,
            self::SUBE_CREATE_TABLE,
            self::USER_SCOPE_TABLE,
            self::USER_ACCESS_REVOKE_TABLE,
            self::USER_ACCESS_CHANGE_TABLE,
            self::PERSONEL_ORG_CHANGE_TABLE,
        ];
        if (!in_array($table, $known, true)) {
            return false;
        }
        if (array_key_exists($table, self::$readyCache)) {
            return self::$readyCache[$table];
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . $table . "'");
            $exists = $stmt !== false && $stmt->fetch(PDO::FETCH_NUM) !== false;
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
            self::$readyCache[$table] = $exists;
        } catch (\Throwable $e) {
            self::$readyCache[$table] = false;
        }

        return self::$readyCache[$table];
    }

    public static function assertReady(PDO $pdo, string $table): void
    {
        if (self::isReady($pdo, $table)) {
            return;
        }

        throw new OrganizasyonException(
            409,
            self::SCHEMA_NOT_READY,
            'Organizasyon denetim şeması bu ortamda henüz hazır değil; denetlenemeyen yazma reddedildi.'
        );
    }

    /**
     * @param array{
     *   personel_id:int,
     *   onceki_sube_id:int|null,
     *   yeni_sube_id:int,
     *   korunan_calisma_lokasyonu_id:int|null,
     *   korunan_sgk_isveren_id:int|null,
     *   gerekce:string
     * } $entry
     */
    public static function recordPersonelSubeDegisikligi(
        PDO $pdo,
        array $entry,
        OrganizasyonAuditContext $context
    ): int {
        self::assertReady($pdo, self::PERSONEL_SUBE_TABLE);

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::PERSONEL_SUBE_TABLE . ' ('
            . 'personel_id, onceki_sube_id, yeni_sube_id, korunan_calisma_lokasyonu_id,'
            . ' korunan_sgk_isveren_id, gerekce, actor_user_id, request_hash, idempotency_key'
            . ') VALUES ('
            . ':personel_id, :onceki_sube_id, :yeni_sube_id, :korunan_calisma_lokasyonu_id,'
            . ' :korunan_sgk_isveren_id, :gerekce, :actor_user_id, :request_hash, :idempotency_key)'
        );
        $stmt->execute([
            'personel_id' => (int) $entry['personel_id'],
            'onceki_sube_id' => self::nullableId($entry['onceki_sube_id'] ?? null),
            'yeni_sube_id' => (int) $entry['yeni_sube_id'],
            'korunan_calisma_lokasyonu_id' => self::nullableId($entry['korunan_calisma_lokasyonu_id'] ?? null),
            'korunan_sgk_isveren_id' => self::nullableId($entry['korunan_sgk_isveren_id'] ?? null),
            'gerekce' => self::clampGerekce((string) $entry['gerekce']),
            'actor_user_id' => $context->actorUserId(),
            'request_hash' => $context->requestHash(),
            'idempotency_key' => $context->idempotencyKey(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{
     *   sube_id:int,
     *   sirket_id:int|null,
     *   kod:string,
     *   ad:string,
     *   durum:string,
     *   sgk_isveren_id:int|null,
     *   departman_ids:array<int, int>
     * } $entry
     */
    public static function recordSubeOlusturma(
        PDO $pdo,
        array $entry,
        OrganizasyonAuditContext $context
    ): int {
        self::assertReady($pdo, self::SUBE_CREATE_TABLE);

        $departmanIds = self::normalizeIds($entry['departman_ids'] ?? []);

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::SUBE_CREATE_TABLE . ' ('
            . 'sube_id, sirket_id, kod, ad, durum, sgk_isveren_id, departman_ids, departman_ids_hash,'
            . ' actor_user_id, request_hash'
            . ') VALUES ('
            . ':sube_id, :sirket_id, :kod, :ad, :durum, :sgk_isveren_id, :departman_ids, :departman_ids_hash,'
            . ' :actor_user_id, :request_hash)'
        );
        $stmt->execute([
            'sube_id' => (int) $entry['sube_id'],
            'sirket_id' => self::nullableId($entry['sirket_id'] ?? null),
            'kod' => (string) $entry['kod'],
            'ad' => (string) $entry['ad'],
            'durum' => (string) $entry['durum'],
            'sgk_isveren_id' => self::nullableId($entry['sgk_isveren_id'] ?? null),
            'departman_ids' => self::canonicalIdList($departmanIds),
            'departman_ids_hash' => self::hashIdList($departmanIds),
            'actor_user_id' => $context->actorUserId(),
            'request_hash' => $context->requestHash(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Writes one row per scope axis that actually changed.
     *
     * An unchanged axis produces no row: a PUT that resends the same branch set
     * is not a scope event, and recording it would bury the real grants in
     * noise. The CHECK constraint on the table enforces the same rule.
     *
     * @param array<int, int> $before
     * @param array<int, int> $after
     * @return int|null audit row id, or null when the axis did not change
     */
    public static function recordUserOrgScopeChange(
        PDO $pdo,
        int $targetUserId,
        string $scopeTuru,
        array $before,
        array $after,
        OrganizasyonAuditContext $context,
        ?string $gerekce = null
    ): ?int {
        if (!in_array($scopeTuru, [self::SCOPE_SUBE, self::SCOPE_SIRKET, self::SCOPE_SGK_ISVEREN], true)) {
            throw OrganizasyonException::validation('Bilinmeyen yetki kapsamı türü.');
        }

        $beforeList = self::canonicalIdList(self::normalizeIds($before));
        $afterList = self::canonicalIdList(self::normalizeIds($after));
        if ($beforeList === $afterList) {
            return null;
        }

        self::assertReady($pdo, self::USER_SCOPE_TABLE);

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::USER_SCOPE_TABLE . ' ('
            . 'target_user_id, scope_turu, onceki_ids, yeni_ids, actor_user_id, gerekce, request_hash'
            . ') VALUES ('
            . ':target_user_id, :scope_turu, :onceki_ids, :yeni_ids, :actor_user_id, :gerekce, :request_hash)'
        );
        $stmt->execute([
            'target_user_id' => $targetUserId,
            'scope_turu' => $scopeTuru,
            'onceki_ids' => $beforeList,
            'yeni_ids' => $afterList,
            'actor_user_id' => $context->actorUserId(),
            'gerekce' => $gerekce === null ? null : self::clampGerekce($gerekce),
            'request_hash' => $context->requestHash(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Login access revocation. The users row is kept so every historical audit
     * actor stays resolvable, which is exactly why the revocation itself has to
     * be recorded here rather than inferred from the surviving row.
     *
     * @param array{
     *   target_user_id:int,
     *   target_username:string,
     *   onceki_durum:string,
     *   korunan_personel_id:int|null,
     *   temizlenen_scope_satiri:int
     * } $entry
     */
    public static function recordUserAccessRevoke(
        PDO $pdo,
        array $entry,
        OrganizasyonAuditContext $context,
        ?string $gerekce = null
    ): int {
        self::assertReady($pdo, self::USER_ACCESS_REVOKE_TABLE);

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::USER_ACCESS_REVOKE_TABLE . ' ('
            . 'target_user_id, target_username, onceki_durum, yeni_durum, korunan_personel_id,'
            . ' temizlenen_scope_satiri, gerekce, actor_user_id, request_hash'
            . ') VALUES ('
            . ':target_user_id, :target_username, :onceki_durum, :yeni_durum, :korunan_personel_id,'
            . ' :temizlenen_scope_satiri, :gerekce, :actor_user_id, :request_hash)'
        );
        $stmt->execute([
            'target_user_id' => (int) $entry['target_user_id'],
            'target_username' => (string) $entry['target_username'],
            'onceki_durum' => (string) $entry['onceki_durum'],
            'yeni_durum' => 'PASIF',
            'korunan_personel_id' => $entry['korunan_personel_id'] === null
                ? null
                : (int) $entry['korunan_personel_id'],
            'temizlenen_scope_satiri' => (int) $entry['temizlenen_scope_satiri'],
            'gerekce' => $gerekce === null ? null : self::clampGerekce($gerekce),
            'actor_user_id' => $context->actorUserId(),
            'request_hash' => $context->requestHash(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Migration 082 gates security-impacting user access changes. A dedicated
     * error code keeps this distinguishable from the 080 owners: an operator
     * seeing it needs to apply 082, not one of the organisation migrations.
     */
    public static function assertAccessChangeReady(PDO $pdo): void
    {
        if (self::isReady($pdo, self::USER_ACCESS_CHANGE_TABLE)) {
            return;
        }

        throw new OrganizasyonException(
            409,
            self::ACCESS_CHANGE_SCHEMA_NOT_READY,
            'Kullanıcı erişim denetim şeması bu ortamda henüz hazır değil; denetlenemeyen erişim değişikliği reddedildi.'
        );
    }

    /**
     * Which access event a before/after pair represents, or null when nothing
     * security-impacting moved.
     *
     * Deterministic and total, so the controller never has to decide: several
     * axes in one request are always one COMBINED_ACCESS_CHANGE carrying every
     * pair, never several rows that a reader would have to stitch back
     * together. A lone reactivation is typed ACCESS_RESTORE because that is the
     * event a reviewer searches for; any other single axis is typed by itself.
     *
     * @param array{durum:string, rol:string, username:string, personel_id:int|null} $before
     * @param array{durum:string, rol:string, username:string, personel_id:int|null} $after
     */
    public static function resolveAccessEventType(array $before, array $after): ?string
    {
        $changed = [];
        if ((string) $before['durum'] !== (string) $after['durum']) {
            $changed[] = 'durum';
        }
        if ((string) $before['rol'] !== (string) $after['rol']) {
            $changed[] = 'rol';
        }
        if ((string) $before['username'] !== (string) $after['username']) {
            $changed[] = 'username';
        }
        if (self::nullableId($before['personel_id'] ?? null) !== self::nullableId($after['personel_id'] ?? null)) {
            $changed[] = 'personel_id';
        }

        if (count($changed) === 0) {
            return null;
        }
        if (count($changed) > 1) {
            return self::ACCESS_EVENT_COMBINED;
        }

        switch ($changed[0]) {
            case 'rol':
                return self::ACCESS_EVENT_ROLE;
            case 'username':
                return self::ACCESS_EVENT_USERNAME;
            case 'personel_id':
                return self::ACCESS_EVENT_BINDING;
            default:
                return ((string) $before['durum'] === 'PASIF' && (string) $after['durum'] === 'AKTIF')
                    ? self::ACCESS_EVENT_RESTORE
                    : self::ACCESS_EVENT_STATUS;
        }
    }

    /**
     * Records one security-impacting access change.
     *
     * Only the axis that actually moved is written; an untouched axis stays
     * NULL/NULL rather than repeating its own value, which is what makes the
     * row readable without consulting the users table. Returns null when
     * nothing changed, so an ordinary profile edit produces no row and does not
     * need migration 082 to be present.
     *
     * Must run inside the caller's transaction: the INSERT is plain and the
     * exception propagates, so a failed audit rolls the access change back.
     *
     * @param array{durum:string, rol:string, username:string, personel_id:int|null} $before
     * @param array{durum:string, rol:string, username:string, personel_id:int|null} $after
     */
    public static function recordUserAccessChange(
        PDO $pdo,
        int $targetUserId,
        array $before,
        array $after,
        OrganizasyonAuditContext $context,
        ?string $gerekce = null
    ): ?int {
        $eventType = self::resolveAccessEventType($before, $after);
        if ($eventType === null) {
            return null;
        }

        self::assertAccessChangeReady($pdo);

        $durumChanged = (string) $before['durum'] !== (string) $after['durum'];
        $rolChanged = (string) $before['rol'] !== (string) $after['rol'];
        $usernameChanged = (string) $before['username'] !== (string) $after['username'];
        $beforePersonelId = self::nullableId($before['personel_id'] ?? null);
        $afterPersonelId = self::nullableId($after['personel_id'] ?? null);
        $personelChanged = $beforePersonelId !== $afterPersonelId;

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::USER_ACCESS_CHANGE_TABLE . ' ('
            . 'actor_user_id, target_user_id, event_type, eski_durum, yeni_durum, eski_rol, yeni_rol,'
            . ' eski_username, yeni_username, eski_personel_id, yeni_personel_id, gerekce, request_hash'
            . ') VALUES ('
            . ':actor_user_id, :target_user_id, :event_type, :eski_durum, :yeni_durum, :eski_rol, :yeni_rol,'
            . ' :eski_username, :yeni_username, :eski_personel_id, :yeni_personel_id, :gerekce, :request_hash)'
        );
        $stmt->execute([
            'actor_user_id' => $context->actorUserId(),
            'target_user_id' => $targetUserId,
            'event_type' => $eventType,
            'eski_durum' => $durumChanged ? (string) $before['durum'] : null,
            'yeni_durum' => $durumChanged ? (string) $after['durum'] : null,
            'eski_rol' => $rolChanged ? (string) $before['rol'] : null,
            'yeni_rol' => $rolChanged ? (string) $after['rol'] : null,
            'eski_username' => $usernameChanged ? (string) $before['username'] : null,
            'yeni_username' => $usernameChanged ? (string) $after['username'] : null,
            'eski_personel_id' => $personelChanged ? $beforePersonelId : null,
            'yeni_personel_id' => $personelChanged ? $afterPersonelId : null,
            'gerekce' => $gerekce === null ? null : self::clampGerekce($gerekce),
            'request_hash' => $context->requestHash(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Ascending, de-duplicated, comma separated. Two audit rows are only
     * comparable if both sides were produced by this one definition.
     *
     * @param array<int, int> $ids
     */
    public static function canonicalIdList(array $ids): string
    {
        return implode(',', self::normalizeIds($ids));
    }

    /** @param array<int, int> $ids */
    public static function hashIdList(array $ids): string
    {
        return hash('sha256', self::canonicalIdList($ids));
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    public static function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $normalized[] = $value;
            }
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    public static function assertPersonelOrganizasyonReady(PDO $pdo): void
    {
        if (self::isReady($pdo, self::PERSONEL_ORG_CHANGE_TABLE)) {
            return;
        }

        throw new OrganizasyonException(
            409,
            self::PERSONEL_ORG_CHANGE_SCHEMA_NOT_READY,
            'Personel organizasyon denetim şeması bu ortamda henüz hazır değil; denetlenemeyen organizasyon değişikliği reddedildi.'
        );
    }

    /**
     * @param array{
     *   personel_id:int,
     *   olay_tipi:string,
     *   degisen_alanlar:array<int, string>,
     *   eski_degerler:array<string, mixed>,
     *   yeni_degerler:array<string, mixed>,
     *   gerekce:string
     * } $entry
     */
    public static function recordPersonelOrganizasyonDegisikligi(
        PDO $pdo,
        array $entry,
        OrganizasyonAuditContext $context
    ): int {
        self::assertPersonelOrganizasyonReady($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::PERSONEL_ORG_CHANGE_TABLE . ' ('
            . 'personel_id, olay_tipi, degisen_alanlar, eski_degerler, yeni_degerler,'
            . ' gerekce, actor_user_id, request_hash, idempotency_key'
            . ') VALUES ('
            . ':personel_id, :olay_tipi, :degisen_alanlar, :eski_degerler, :yeni_degerler,'
            . ' :gerekce, :actor_user_id, :request_hash, :idempotency_key)'
        );
        $stmt->execute([
            'personel_id' => (int) $entry['personel_id'],
            'olay_tipi' => (string) $entry['olay_tipi'],
            'degisen_alanlar' => json_encode(array_values($entry['degisen_alanlar']), JSON_UNESCAPED_UNICODE),
            'eski_degerler' => json_encode($entry['eski_degerler'], JSON_UNESCAPED_UNICODE),
            'yeni_degerler' => json_encode($entry['yeni_degerler'], JSON_UNESCAPED_UNICODE),
            'gerekce' => self::clampGerekce((string) $entry['gerekce']),
            'actor_user_id' => $context->actorUserId(),
            'request_hash' => $context->requestHash(),
            'idempotency_key' => $context->idempotencyKey(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** Test helper — clear process cache. */
    public static function resetCache(): void
    {
        self::$readyCache = [];
    }

    /** @param int|string|null $value */
    private static function nullableId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function clampGerekce(string $gerekce): string
    {
        $trimmed = trim($gerekce);
        if (function_exists('mb_substr')) {
            return mb_substr($trimmed, 0, self::GEREKCE_MAX);
        }

        return substr($trimmed, 0, self::GEREKCE_MAX);
    }
}
