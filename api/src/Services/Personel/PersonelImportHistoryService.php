<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Scope\SubeScope;
use PDO;

/**
 * S97-C: read-only personel import history / evidence owner.
 * No run/row mutations. No personel/salary/bordro/SGK writes.
 */
final class PersonelImportHistoryService
{
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT = 100;
    public const MAX_DETAIL_ROWS = 500;
    public const CURSOR_VERSION = 1;
    public const MAX_CURSOR_LEN = 512;

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @param int|null $scopeSubeId
     * @param array<int, int> $allowedSubeIds
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string}
     */
    public static function listRuns(
        PDO $pdo,
        array $user,
        array $query,
        $scopeSubeId,
        array $allowedSubeIds
    ) {
        if (!PersonelImportApplyService::schemaReady($pdo)) {
            throw new PersonelImportException(
                'SCHEMA_NOT_READY',
                'Personel import apply semasi henuz hazir degil. Migration 046 uygulanmalidir.',
                409
            );
        }

        $limit = self::parseLimit($query['limit'] ?? null);
        $statusFilter = self::parseStatusFilter($query['status'] ?? null);
        $dateFrom = self::parseDate($query['date_from'] ?? null, 'date_from');
        $dateTo = self::parseDate($query['date_to'] ?? null, 'date_to');
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_DATE_INVALID',
                'date_from date_to degerinden sonra olamaz.',
                400
            );
        }

        $filterSig = self::filterSignature($statusFilter, $dateFrom, $dateTo, $scopeSubeId);
        $cursor = self::decodeCursor(
            isset($query['cursor']) ? (string) $query['cursor'] : null,
            $filterSig
        );

        $where = ['1=1'];
        $params = [];

        if ($statusFilter !== null) {
            $where[] = 'r.status = :status';
            $params['status'] = $statusFilter;
        } else {
            $placeholders = [];
            foreach (PersonelImportHistoryStatus::DEFAULT_LIST_STATUSES as $index => $status) {
                $key = 'default_status_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $status;
            }
            $where[] = 'r.status IN (' . implode(', ', $placeholders) . ')';
        }

        if ($dateFrom !== null) {
            $where[] = 'r.started_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00.000';
        }
        if ($dateTo !== null) {
            $where[] = 'r.started_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59.999';
        }

        SubeScope::appendSubeFilter($where, $params, $scopeSubeId, $allowedSubeIds, 'r.active_sube_id');

        if ($cursor !== null) {
            $where[] = '(r.started_at < :cursor_started_at OR (r.started_at = :cursor_started_at_eq AND r.id < :cursor_id))';
            $params['cursor_started_at'] = $cursor['started_at'];
            $params['cursor_started_at_eq'] = $cursor['started_at'];
            $params['cursor_id'] = $cursor['id'];
        }

        $whereSql = implode(' AND ', $where);
        // Raw idempotency_key is never projected; fingerprint computed in SQL.
        $sql = "
            SELECT
                r.id,
                r.status,
                r.schema_version,
                r.actor_id,
                r.actor_rol,
                r.active_sube_id,
                r.toplam_satir,
                r.gecerli_satir,
                r.created_count,
                r.source_sha256,
                r.manifest_hash,
                LOWER(LEFT(SHA2(r.idempotency_key, 256), 12)) AS idempotency_fingerprint,
                r.error_code,
                r.started_at,
                r.finished_at,
                u.ad_soyad AS actor_ad_soyad,
                s.ad AS sube_adi
            FROM personel_import_runs r
            LEFT JOIN users u ON u.id = r.actor_id
            LEFT JOIN subeler s ON s.id = r.active_sube_id
            WHERE {$whereSql}
            ORDER BY r.started_at DESC, r.id DESC
            LIMIT :fetch_limit
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = self::mapRunSummary($row);
        }

        $nextCursor = null;
        if ($hasMore && count($rows) > 0) {
            $last = $rows[count($rows) - 1];
            $nextCursor = self::encodeCursor(
                (string) $last['started_at'],
                (int) $last['id'],
                $filterSig
            );
        }

        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param int|null $scopeSubeId
     * @param array<int, int> $allowedSubeIds
     * @return array<string, mixed>
     */
    public static function getRun(
        PDO $pdo,
        array $user,
        $importId,
        $scopeSubeId,
        array $allowedSubeIds
    ) {
        if (!PersonelImportApplyService::schemaReady($pdo)) {
            throw new PersonelImportException(
                'SCHEMA_NOT_READY',
                'Personel import apply semasi henuz hazir degil. Migration 046 uygulanmalidir.',
                409
            );
        }

        $importId = (int) $importId;
        if ($importId <= 0) {
            throw new PersonelImportException('NOT_FOUND', 'Import kaydi bulunamadi.', 404);
        }

        $run = self::fetchScopedRun($pdo, $importId, $scopeSubeId, $allowedSubeIds);
        if ($run === null) {
            throw new PersonelImportException('NOT_FOUND', 'Import kaydi bulunamadi.', 404);
        }

        $stmt = $pdo->prepare(
            'SELECT
                satir_no,
                personel_id,
                sicil_no,
                tc_kimlik_no_masked,
                row_hash,
                ad,
                soyad
             FROM personel_import_run_satirlari
             WHERE import_run_id = :import_run_id
             ORDER BY satir_no ASC
             LIMIT ' . (int) self::MAX_DETAIL_ROWS
        );
        $stmt->execute(['import_run_id' => $importId]);
        $satirRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = self::mapRunSummary($run);
        $timestamps = PersonelImportHistoryStatus::timestamps($run);
        $counts = PersonelImportHistoryStatus::counts($run);
        $durationMs = self::durationMs($timestamps['created_at'], $run['finished_at'] ?? null);

        $satirlar = [];
        foreach ($satirRows as $satir) {
            $satirlar[] = self::mapRunRow($satir);
        }

        return array_merge($summary, [
            'failed_row_count' => $counts['failed_row_count'],
            'duration_ms' => $durationMs,
            'failure_code' => self::scrubFailureCode($run['error_code'] ?? null),
            'failure_message' => PersonelImportHistoryStatus::failureMessage($run['error_code'] ?? null),
            'idempotent_replay' => null,
            'satirlar' => $satirlar,
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param int|null $scopeSubeId
     * @param array<int, int> $allowedSubeIds
     * @return array{filename: string, csv: string}
     */
    public static function buildEvidenceCsv(
        PDO $pdo,
        array $user,
        $importId,
        $scopeSubeId,
        array $allowedSubeIds
    ) {
        $detail = self::getRun($pdo, $user, $importId, $scopeSubeId, $allowedSubeIds);
        $importId = (int) $detail['import_id'];
        $columns = [
            'import_id',
            'status',
            'created_at',
            'completed_at',
            'actor',
            'scope',
            'source_sha256',
            'manifest_hash',
            'idempotency_fingerprint',
            'row_number',
            'personel_id',
            'sicil_no',
            'ad_soyad',
            'tc_kimlik_no_masked',
            'row_hash',
            'row_status',
        ];

        $rows = [];
        $satirlar = isset($detail['satirlar']) && is_array($detail['satirlar']) ? $detail['satirlar'] : [];
        $base = [
            'import_id' => $importId,
            'status' => (string) $detail['status'],
            'created_at' => (string) ($detail['created_at'] ?? ''),
            'completed_at' => (string) ($detail['completed_at'] ?? ''),
            'actor' => (string) ($detail['actor_display_name'] ?? ''),
            'scope' => (string) ($detail['scope_summary'] ?? ''),
            'source_sha256' => (string) ($detail['source_sha256'] ?? ''),
            'manifest_hash' => (string) ($detail['manifest_hash'] ?? ''),
            'idempotency_fingerprint' => (string) ($detail['idempotency_fingerprint'] ?? ''),
        ];
        if (count($satirlar) === 0) {
            $rows[] = array_merge($base, [
                'row_number' => '',
                'personel_id' => '',
                'sicil_no' => '',
                'ad_soyad' => '',
                'tc_kimlik_no_masked' => '',
                'row_hash' => '',
                'row_status' => '',
            ]);
        } else {
            foreach ($satirlar as $satir) {
                $rows[] = array_merge($base, [
                    'row_number' => (int) ($satir['row_number'] ?? 0),
                    'personel_id' => $satir['personel_id'] ?? '',
                    'sicil_no' => (string) ($satir['sicil_no'] ?? ''),
                    'ad_soyad' => (string) ($satir['ad_soyad'] ?? $satir['personel_display_name'] ?? ''),
                    'tc_kimlik_no_masked' => (string) ($satir['tc_kimlik_no_masked'] ?? ''),
                    'row_hash' => (string) ($satir['row_hash'] ?? ''),
                    'row_status' => (string) ($satir['row_status'] ?? ''),
                ]);
            }
        }

        $csv = self::buildSemicolonCsv($columns, $rows);
        if (
            preg_match('/idempotency_key/i', $csv)
            || preg_match('/\btc_sha256\b/i', $csv)
            || preg_match('/(^|;|\n)tc_kimlik_no(;|\n|$)/i', $csv)
        ) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_SCRUB_FAILED',
                'Import kanit CSV scrub hatasi.',
                500
            );
        }

        return [
            'filename' => 'personel-import-kaniti-' . $importId . '.csv',
            'csv' => $csv,
        ];
    }

    /** Fallback fingerprint helper for non-SQL contexts; prefer SQL SHA2 projection. */
    public static function fingerprintIdempotencyKey($rawKey): string
    {
        return strtolower(substr(hash('sha256', (string) $rawKey), 0, 12));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRunSummary(array $row): array
    {
        $timestamps = PersonelImportHistoryStatus::timestamps($row);
        $counts = PersonelImportHistoryStatus::counts($row);
        $status = (string) ($row['status'] ?? '');
        $actorName = trim((string) ($row['actor_ad_soyad'] ?? ''));
        if ($actorName === '') {
            $actorName = 'Kullanıcı #' . (int) ($row['actor_id'] ?? 0);
        }

        $subeId = isset($row['active_sube_id']) && $row['active_sube_id'] !== null
            ? (int) $row['active_sube_id']
            : null;
        $subeAdi = trim((string) ($row['sube_adi'] ?? ''));
        $scopeSummary = $subeId === null
            ? 'Kapsam belirtilmedi'
            : ($subeAdi !== '' ? $subeAdi : ('Şube #' . $subeId));

        $durationMs = self::durationMs($timestamps['created_at'], $row['finished_at'] ?? null);
        $fingerprint = isset($row['idempotency_fingerprint'])
            ? strtolower((string) $row['idempotency_fingerprint'])
            : '';
        if ($fingerprint !== '' && !preg_match('/^[0-9a-f]{12}$/', $fingerprint)) {
            $fingerprint = '';
        }

        return [
            'import_id' => (int) $row['id'],
            'status' => $status,
            'status_label' => PersonelImportHistoryStatus::label($status),
            'schema_version' => (string) ($row['schema_version'] ?? PersonelImportDryRunService::SCHEMA_VERSION),
            'import_mode' => PersonelImportDryRunService::IMPORT_MODE,
            'row_count' => $counts['row_count'],
            'valid_row_count' => $counts['valid_row_count'],
            'created_count' => $counts['created_count'],
            'actor_id' => (int) ($row['actor_id'] ?? 0),
            'actor_display_name' => $actorName,
            'scope_summary' => $scopeSummary,
            'active_sube_id' => $subeId,
            'source_sha256' => self::safeSha64($row['source_sha256'] ?? ''),
            'manifest_hash' => self::safeSha64($row['manifest_hash'] ?? ''),
            'idempotency_fingerprint' => $fingerprint,
            'created_at' => $timestamps['created_at'],
            'completed_at' => $timestamps['completed_at'],
            'failed_at' => $timestamps['failed_at'],
            'duration_ms' => $durationMs,
            'failure_code' => self::scrubFailureCode($row['error_code'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRunRow(array $row): array
    {
        $personelId = isset($row['personel_id']) && $row['personel_id'] !== null && $row['personel_id'] !== ''
            ? (int) $row['personel_id']
            : null;
        $ad = (string) ($row['ad'] ?? '');
        $soyad = (string) ($row['soyad'] ?? '');
        // Space-only edge trim so leading tab/=/+/-/@ survive for CSV formula guarding.
        if ($ad !== '' && $soyad !== '') {
            $display = preg_replace('/^ +| +$/u', '', $ad . ' ' . $soyad);
        } elseif ($ad !== '') {
            $display = preg_replace('/^ +| +$/u', '', $ad);
        } elseif ($soyad !== '') {
            $display = preg_replace('/^ +| +$/u', '', $soyad);
        } else {
            $display = '';
        }
        $display = is_string($display) ? $display : '';

        return [
            'row_number' => (int) ($row['satir_no'] ?? 0),
            'personel_id' => $personelId,
            'sicil_no' => (string) ($row['sicil_no'] ?? ''),
            'ad_soyad' => $display !== '' ? $display : null,
            'personel_display_name' => $display !== '' ? $display : null,
            'tc_kimlik_no_masked' => (string) ($row['tc_kimlik_no_masked'] ?? ''),
            'row_hash' => (string) ($row['row_hash'] ?? ''),
            'row_status' => $personelId !== null ? 'CREATED' : 'FAILED',
            'personel_detail_path' => $personelId !== null ? '/personeller/' . $personelId : null,
        ];
    }

    /**
     * @param int|null $scopeSubeId
     * @param array<int, int> $allowedSubeIds
     * @return array<string, mixed>|null
     */
    private static function fetchScopedRun(PDO $pdo, $importId, $scopeSubeId, array $allowedSubeIds)
    {
        $where = ['r.id = :import_id'];
        $params = ['import_id' => (int) $importId];
        SubeScope::appendSubeFilter($where, $params, $scopeSubeId, $allowedSubeIds, 'r.active_sube_id');

        $sql = '
            SELECT
                r.id,
                r.status,
                r.schema_version,
                r.actor_id,
                r.actor_rol,
                r.active_sube_id,
                r.toplam_satir,
                r.gecerli_satir,
                r.created_count,
                r.source_sha256,
                r.manifest_hash,
                LOWER(LEFT(SHA2(r.idempotency_key, 256), 12)) AS idempotency_fingerprint,
                r.error_code,
                r.started_at,
                r.finished_at,
                u.ad_soyad AS actor_ad_soyad,
                s.ad AS sube_adi
            FROM personel_import_runs r
            LEFT JOIN users u ON u.id = r.actor_id
            LEFT JOIN subeler s ON s.id = r.active_sube_id
            WHERE ' . implode(' AND ', $where) . '
            LIMIT 1
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param mixed $raw */
    private static function parseLimit($raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        if (!preg_match('/^\d+$/', (string) $raw)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_LIMIT_INVALID',
                'limit gecersiz.',
                400
            );
        }
        $limit = (int) $raw;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_LIMIT_INVALID',
                'limit 1-' . self::MAX_LIMIT . ' arasinda olmalidir.',
                400
            );
        }

        return $limit;
    }

    /** @param mixed $raw */
    private static function parseStatusFilter($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $status = strtoupper(trim((string) $raw));
        if (!PersonelImportHistoryStatus::isCanonical($status)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_STATUS_INVALID',
                'status gecersiz.',
                400
            );
        }

        return $status;
    }

    /** @param mixed $raw */
    private static function parseDate($raw, string $field): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = trim((string) $raw);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$dt
            || $dt->format('Y-m-d') !== $value
            || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        ) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_DATE_INVALID',
                $field . ' gecersiz.',
                400
            );
        }

        return $value;
    }

    /** @param int|null $scopeSubeId */
    private static function filterSignature($status, $dateFrom, $dateTo, $scopeSubeId): string
    {
        return substr(hash('sha256', json_encode([
            'status' => $status,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'scope' => $scopeSubeId,
        ], JSON_UNESCAPED_SLASHES) ?: ''), 0, 16);
    }

    /**
     * @return array{started_at: string, id: int}|null
     */
    private static function decodeCursor($raw, string $expectedFilterSig): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (strlen((string) $raw) > self::MAX_CURSOR_LEN) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_CURSOR_INVALID',
                'cursor gecersiz.',
                400
            );
        }
        $decoded = self::base64UrlDecode((string) $raw);
        if ($decoded === null) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_CURSOR_INVALID',
                'cursor gecersiz.',
                400
            );
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_CURSOR_INVALID',
                'cursor gecersiz.',
                400
            );
        }
        $version = isset($payload['v']) ? (int) $payload['v'] : 0;
        $startedAt = isset($payload['t']) ? (string) $payload['t'] : '';
        $id = isset($payload['i']) ? (int) $payload['i'] : 0;
        $filterSig = isset($payload['f']) ? (string) $payload['f'] : '';
        if ($version !== self::CURSOR_VERSION || $startedAt === '' || $id <= 0 || $filterSig === '') {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_CURSOR_INVALID',
                'cursor gecersiz.',
                400
            );
        }
        if (!hash_equals($expectedFilterSig, $filterSig)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_CURSOR_INVALID',
                'cursor filtre ile uyumsuz.',
                400
            );
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $startedAt)) {
            throw new PersonelImportException(
                'PERSONEL_IMPORT_HISTORY_CURSOR_INVALID',
                'cursor gecersiz.',
                400
            );
        }

        return ['started_at' => $startedAt, 'id' => $id];
    }

    private static function encodeCursor(string $startedAt, int $id, string $filterSig): string
    {
        $json = json_encode(
            [
                'v' => self::CURSOR_VERSION,
                't' => $startedAt,
                'i' => $id,
                'f' => $filterSig,
            ],
            JSON_UNESCAPED_SLASHES
        );

        return self::base64UrlEncode((string) $json);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /** @param mixed $started @param mixed $finished */
    private static function durationMs($started, $finished): ?int
    {
        if ($started === null || $started === '' || $finished === null || $finished === '') {
            return null;
        }
        try {
            $a = new \DateTimeImmutable((string) $started);
            $b = new \DateTimeImmutable((string) $finished);
        } catch (\Exception $e) {
            return null;
        }
        $diff = $b->getTimestamp() - $a->getTimestamp();

        return max(0, $diff * 1000);
    }

    /** @param mixed $code */
    private static function scrubFailureCode($code): ?string
    {
        $value = trim((string) $code);
        if ($value === '') {
            return null;
        }
        if (preg_match('/\b(SQLSTATE|PDO|stack|trace|mysqli)\b/i', $value)) {
            return 'PERSONEL_IMPORT_TRANSACTION_FAILED';
        }

        return $value;
    }

    /** @param mixed $value */
    private static function safeSha64($value): string
    {
        $hash = strtolower(trim((string) $value));
        if ($hash === '' || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return '';
        }

        return $hash;
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     */
    private static function buildSemicolonCsv(array $columns, array $rows): string
    {
        $lines = [];
        $lines[] = implode(';', array_map([self::class, 'csvCell'], $columns));
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = self::csvCell($row[$column] ?? '');
            }
            $lines[] = implode(';', $cells);
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    /** @param mixed $value */
    private static function csvCell($value): string
    {
        if ($value === null) {
            $text = '';
        } elseif (is_bool($value)) {
            $text = $value ? '1' : '0';
        } elseif (is_scalar($value)) {
            $text = (string) $value;
        } else {
            $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        // Formula injection: = + - @ tab CR
        $first = $text !== '' ? $text[0] : '';
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r") {
            $text = "'" . $text;
        }

        $needsQuote = strpbrk($text, ";\"\n\r") !== false;
        $escaped = str_replace('"', '""', $text);

        return $needsQuote ? '"' . $escaped . '"' : $escaped;
    }
}
