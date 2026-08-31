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

    public const SCOPE_SUBE = 'SUBE';
    public const SCOPE_SIRKET = 'SIRKET';
    public const SCOPE_SGK_ISVEREN = 'SGK_ISVEREN';

    public const SCHEMA_NOT_READY = 'ORGANIZASYON_AUDIT_SCHEMA_NOT_READY';

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
