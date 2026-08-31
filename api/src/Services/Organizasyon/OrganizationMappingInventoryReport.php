<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use PDO;
use Throwable;

/**
 * Canonical SELECT-only row-level inventory of the organisation hierarchy.
 *
 * Why this is a separate owner from MigrationPreflightReport: that report answers
 * "may migration N be applied?" and its contract is deliberately aggregate-only —
 * counts, column names, versions, checksums. The company/branch mapping decision
 * needs the opposite shape: the exact `id`, `kod`, `ad`, `durum` and relation id
 * of every branch, payroll employer and work location, plus their dependent
 * counts. Bolting those rows onto the migration preflight would break a published
 * contract that CI, the apply gates and the worker all read.
 *
 * Read-only is structural, not a promise: every statement below is a SELECT, the
 * table names are literals in this file, and no caller can inject SQL — the only
 * inputs are a PDO handle and two opaque metadata strings that are never
 * interpolated into a query.
 *
 * PII: an organisation row (branch code, branch name, status) is reference data,
 * not personal data. Personnel rows are only ever counted, never selected, and no
 * user id, username or personnel column reaches the output. The scope summary
 * reports assignment totals per role, which is a count of grants, not of people.
 * The same rule holds for the location×branch matrix: it publishes relation ids
 * and a COUNT, so it says how many people share a combination and never who.
 *
 * Determinism: rows are ordered by primary key, keys are emitted in a fixed
 * order, and the checksum covers the data section only — not `generated_at` —
 * so two collections of an unchanged database produce the same checksum. That is
 * what lets a mapping spec pin the inventory it was written against.
 */
final class OrganizationMappingInventoryReport
{
    public const SCHEMA_VERSION = '2';

    /**
     * The branch id set the production postcheck evidence of migration 079
     * recorded (10 rows, no id 3). It is used for one thing only: reporting the
     * difference against what the database actually holds, so an unexpected or
     * missing branch becomes a blocker instead of a surprise during mapping. It
     * is never used to create, rename or map a branch.
     */
    private const DOCUMENTED_BRANCH_IDS = [1, 2, 4, 5, 6, 7, 8, 9, 10, 11];

    /**
     * Collect the full inventory.
     *
     * @param string $deployedSha exact deploy SHA the collecting worker is pinned to
     * @param string $migrationTip production migration tip as read from the ledger
     * @return array<string, mixed>
     */
    public static function collect(PDO $pdo, string $deployedSha, string $migrationTip): array
    {
        $readiness = OrganizasyonSchema::report($pdo);

        $data = [
            'expected_branch_ids' => self::DOCUMENTED_BRANCH_IDS,
            'branches' => self::branches($pdo),
            'sgk_employers' => self::sgkEmployers($pdo),
            'work_locations' => self::workLocations($pdo),
            'scope_summary' => self::scopeSummary($pdo),
            'personnel_location_branch_matrix' => self::personnelLocationBranchMatrix($pdo),
            'personnel_without_location_by_branch' => self::personnelWithoutLocationByBranch($pdo),
            'row_counts' => self::rowCounts($pdo),
            'orphan_counts' => [
                'orphan_sube_sirket_count' => (int) $readiness['counts']['orphan_sube_sirket_count'],
                'orphan_lokasyon_sube_count' => (int) $readiness['counts']['orphan_lokasyon_sube_count'],
                'sube_sgk_sirket_mismatch_count' => (int) $readiness['counts']['sube_sgk_sirket_mismatch_count'],
                'unmapped_sube_count' => (int) $readiness['counts']['unmapped_sube_count'],
                'unmapped_sgk_isveren_count' => (int) $readiness['counts']['unmapped_sgk_isveren_count'],
            ],
        ];

        $branchIds = array_map(static fn (array $row): int => $row['id'], $data['branches']);
        $data['branch_ids'] = $branchIds;
        $data['id_3_present'] = in_array(3, $branchIds, true);
        $data['unexpected_branch_ids'] = array_values(
            array_diff($branchIds, self::DOCUMENTED_BRANCH_IDS)
        );
        $data['missing_branch_ids'] = array_values(
            array_diff(self::DOCUMENTED_BRANCH_IDS, $branchIds)
        );

        $matrixTotal = self::sumPersonelCount($data['personnel_location_branch_matrix']);
        $withoutLocationTotal = self::sumPersonelCount($data['personnel_without_location_by_branch']);
        $data['personnel_location_matrix_total'] = $matrixTotal;
        $data['personnel_without_location_total'] = $withoutLocationTotal;
        // The two aggregates partition `personeller` by "has a work location" and
        // nothing else, so their sum must be the table count. If it is not, the
        // matrix is not a provable projection of the personnel table and no
        // location decision may be taken from it.
        $data['personnel_matrix_reconciled'] = $data['row_counts']['personeller'] >= 0
            && ($matrixTotal + $withoutLocationTotal) === $data['row_counts']['personeller'];

        $blockers = self::blockers($data, $readiness);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'deployed_sha' => strtolower($deployedSha),
            'production_migration_tip' => $migrationTip,
            'schema_ready' => (bool) $readiness['schema_ready'],
            'data_ready' => (bool) $readiness['data_ready'],
            'readiness_blockers' => $readiness['blockers'],
            'data' => $data,
            'inventory_checksum' => self::checksum($data),
            'blockers' => $blockers,
            'result' => $blockers === [] ? 'PASS' : 'BLOCKED',
        ];
    }

    /**
     * SHA-256 over the canonical JSON of the data section. Public so the mapping
     * preflight recomputes the digest from the payload it received instead of
     * trusting a field that travelled with it.
     *
     * @param array<string, mixed> $data
     */
    public static function checksum(array $data): string
    {
        return hash('sha256', self::canonicalJson($data));
    }

    /**
     * Recursively key-sorted, slash-unescaped JSON: the same logical data must
     * hash identically no matter which order the collector happened to build it.
     *
     * @param mixed $value
     */
    public static function canonicalJson($value): string
    {
        $json = json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return $json === false ? '' : $json;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function branches(PDO $pdo): array
    {
        $rows = self::query(
            $pdo,
            'SELECT s.id, s.kod, s.ad, s.durum, s.sirket_id, s.sgk_isveren_id,
                    (SELECT COUNT(*) FROM personeller p WHERE p.sube_id = s.id) AS personel_count,
                    (SELECT COUNT(*) FROM calisma_lokasyonlari l WHERE l.sube_id = s.id) AS calisma_lokasyonu_count,
                    (SELECT COUNT(*) FROM user_subeler us WHERE us.sube_id = s.id) AS user_sube_assignment_count
             FROM subeler s
             ORDER BY s.id ASC'
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'kod' => (string) $row['kod'],
                'ad' => (string) $row['ad'],
                'durum' => (string) $row['durum'],
                'sirket_id' => self::nullableInt($row['sirket_id'] ?? null),
                'sgk_isveren_id' => self::nullableInt($row['sgk_isveren_id'] ?? null),
                'personel_count' => (int) $row['personel_count'],
                'calisma_lokasyonu_count' => (int) $row['calisma_lokasyonu_count'],
                'user_sube_assignment_count' => (int) $row['user_sube_assignment_count'],
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function sgkEmployers(PDO $pdo): array
    {
        $rows = self::query(
            $pdo,
            'SELECT e.id, e.kod, e.ad, e.durum, e.sirket_id,
                    (SELECT COUNT(*) FROM personeller p WHERE p.sgk_isveren_id = e.id) AS personel_count
             FROM sgk_isverenler e
             ORDER BY e.id ASC'
        );

        // Branch links are read from the branch table itself: the only provable
        // relation is subeler.sgk_isveren_id. Nothing is inferred from names.
        $linkRows = self::query(
            $pdo,
            'SELECT sgk_isveren_id, id FROM subeler
             WHERE sgk_isveren_id IS NOT NULL
             ORDER BY sgk_isveren_id ASC, id ASC'
        );
        $linkedBranches = [];
        foreach ($linkRows as $link) {
            $linkedBranches[(int) $link['sgk_isveren_id']][] = (int) $link['id'];
        }

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $branchIds = $linkedBranches[$id] ?? [];
            $items[] = [
                'id' => $id,
                'kod' => (string) $row['kod'],
                'ad' => (string) $row['ad'],
                'durum' => (string) $row['durum'],
                'sirket_id' => self::nullableInt($row['sirket_id'] ?? null),
                'personel_count' => (int) $row['personel_count'],
                'linked_branch_ids' => $branchIds,
                'linked_branch_count' => count($branchIds),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function workLocations(PDO $pdo): array
    {
        $rows = self::query(
            $pdo,
            'SELECT l.id, l.kod, l.ad, l.durum, l.sube_id,
                    (SELECT COUNT(*) FROM personeller p WHERE p.calisma_lokasyonu_id = l.id) AS personel_count
             FROM calisma_lokasyonlari l
             ORDER BY l.id ASC'
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'kod' => (string) $row['kod'],
                'ad' => (string) $row['ad'],
                'durum' => (string) $row['durum'],
                'sube_id' => self::nullableInt($row['sube_id'] ?? null),
                'personel_count' => (int) $row['personel_count'],
            ];
        }

        return $items;
    }

    /**
     * The `calisma_lokasyonu_id × sube_id` intersection of the personnel table,
     * for the rows that actually carry a work location.
     *
     * Why this is published: the per-branch and per-location personnel counts are
     * marginals, and equal marginals are not a mapping proof — they cannot show
     * whether a location's people sit under one branch or several. Only the
     * intersection can. It stays anonymous: relation ids and a COUNT, never a
     * personnel row, id or column.
     *
     * @return list<array<string, mixed>>
     */
    private static function personnelLocationBranchMatrix(PDO $pdo): array
    {
        $rows = self::query(
            $pdo,
            'SELECT p.calisma_lokasyonu_id AS calisma_lokasyonu_id, p.sube_id AS sube_id,
                    COUNT(*) AS personel_count
             FROM personeller p
             WHERE p.calisma_lokasyonu_id IS NOT NULL
             GROUP BY p.calisma_lokasyonu_id, p.sube_id
             ORDER BY p.calisma_lokasyonu_id ASC, p.sube_id ASC'
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'calisma_lokasyonu_id' => (int) $row['calisma_lokasyonu_id'],
                'sube_id' => self::nullableInt($row['sube_id'] ?? null),
                'personel_count' => (int) $row['personel_count'],
            ];
        }

        return $items;
    }

    /**
     * The complement of the matrix: personnel with no work location at all,
     * grouped by branch. Published so the matrix can be reconciled against the
     * personnel row count instead of being trusted.
     *
     * @return list<array<string, mixed>>
     */
    private static function personnelWithoutLocationByBranch(PDO $pdo): array
    {
        $rows = self::query(
            $pdo,
            'SELECT p.sube_id AS sube_id, COUNT(*) AS personel_count
             FROM personeller p
             WHERE p.calisma_lokasyonu_id IS NULL
             GROUP BY p.sube_id
             ORDER BY p.sube_id ASC'
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'sube_id' => self::nullableInt($row['sube_id'] ?? null),
                'personel_count' => (int) $row['personel_count'],
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function sumPersonelCount(array $rows): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['personel_count'];
        }

        return $total;
    }

    /**
     * Authorization scope totals. Roles are grouped, users are not listed: the
     * mapping decision needs to know how much scope exists, never whose it is.
     *
     * @return array<string, mixed>
     */
    private static function scopeSummary(PDO $pdo): array
    {
        $rows = self::query(
            $pdo,
            'SELECT u.rol AS rol, COUNT(*) AS assignment_count
             FROM user_subeler us
             INNER JOIN users u ON u.id = us.user_id
             GROUP BY u.rol
             ORDER BY u.rol ASC'
        );

        $byRole = [];
        foreach ($rows as $row) {
            $byRole[] = [
                'rol' => (string) $row['rol'],
                'assignment_count' => (int) $row['assignment_count'],
            ];
        }

        return [
            'user_sube_total' => self::count($pdo, 'SELECT COUNT(*) FROM user_subeler'),
            'user_sube_by_role' => $byRole,
            'user_sirket_total' => self::count($pdo, 'SELECT COUNT(*) FROM user_sirketler'),
            'user_sgk_isveren_total' => self::count($pdo, 'SELECT COUNT(*) FROM user_sgk_isverenler'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function rowCounts(PDO $pdo): array
    {
        return [
            'sirketler' => self::count($pdo, 'SELECT COUNT(*) FROM sirketler'),
            'subeler' => self::count($pdo, 'SELECT COUNT(*) FROM subeler'),
            'sgk_isverenler' => self::count($pdo, 'SELECT COUNT(*) FROM sgk_isverenler'),
            'calisma_lokasyonlari' => self::count($pdo, 'SELECT COUNT(*) FROM calisma_lokasyonlari'),
            'personeller' => self::count($pdo, 'SELECT COUNT(*) FROM personeller'),
            'user_subeler' => self::count($pdo, 'SELECT COUNT(*) FROM user_subeler'),
            'user_sirketler' => self::count($pdo, 'SELECT COUNT(*) FROM user_sirketler'),
            'user_sgk_isverenler' => self::count($pdo, 'SELECT COUNT(*) FROM user_sgk_isverenler'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $readiness
     * @return list<string>
     */
    private static function blockers(array $data, array $readiness): array
    {
        $blockers = [];
        if (!$readiness['schema_ready']) {
            $blockers[] = 'INVENTORY_SCHEMA_NOT_READY';
        }
        if ($data['id_3_present']) {
            $blockers[] = 'UNEXPECTED_BRANCH_ID_3_PRESENT';
        }
        if ($data['unexpected_branch_ids'] !== []) {
            $blockers[] = 'UNEXPECTED_BRANCH_IDS_PRESENT';
        }
        if ($data['missing_branch_ids'] !== []) {
            $blockers[] = 'DOCUMENTED_BRANCH_IDS_MISSING';
        }
        if ($data['orphan_counts']['orphan_sube_sirket_count'] > 0) {
            $blockers[] = 'BRANCH_COMPANY_ORPHAN';
        }
        if ($data['orphan_counts']['orphan_lokasyon_sube_count'] > 0) {
            $blockers[] = 'LOCATION_BRANCH_ORPHAN';
        }
        if ($data['orphan_counts']['sube_sgk_sirket_mismatch_count'] > 0) {
            $blockers[] = 'BRANCH_SGK_COMPANY_MISMATCH';
        }
        if ($data['personnel_matrix_reconciled'] !== true) {
            $blockers[] = 'INVENTORY_PERSONNEL_MATRIX_COUNT_MISMATCH';
        }
        foreach (['subeler', 'sgk_isverenler', 'calisma_lokasyonlari', 'personeller'] as $table) {
            if ($data['row_counts'][$table] < 0) {
                $blockers[] = 'INVENTORY_COUNT_UNEVALUABLE';
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function query(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            return [];
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * An unevaluable count returns -1 instead of 0, so a broken probe becomes a
     * blocker rather than a plausible-looking empty table.
     */
    private static function count(PDO $pdo, string $sql): int
    {
        try {
            $statement = $pdo->query($sql);
            if ($statement === false) {
                return -1;
            }
            $value = $statement->fetchColumn();
            $statement->closeCursor();

            return $value === false ? -1 : (int) $value;
        } catch (Throwable $exception) {
            return -1;
        }
    }

    /** @param mixed $value */
    private static function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }
}
