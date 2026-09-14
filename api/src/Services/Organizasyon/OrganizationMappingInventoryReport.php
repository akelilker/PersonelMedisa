<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use Medisa\Api\Services\Payroll\SgkKararPaketiAuthz;
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
 * interpolated into a query. The personnel evidence statement is the single
 * exception to the literal style: it binds parameters, and every one of them
 * comes from a class constant in this file (`REMEDIATION_PERSONNEL_IDS`,
 * `REMEDIATION_PERSONNEL_IDENTITIES`), so binding cannot widen the allowlist a
 * caller never supplies.
 *
 * PII: an organisation row (branch code, branch name, status) is reference data.
 * The operational closeout also permits a fixed, bounded evidence allowlist:
 * ten personnel ids and named actor candidates only. No request data, credential,
 * contact, national-id, salary or unbounded user/personnel result is published.
 * The scope summary and location×branch matrix remain anonymous aggregates.
 *
 * Personnel remediation evidence (`allowed_personnel` + `personnel_evidence`) is
 * the same bounded model, grown for one purpose: turning the approved business
 * truth into a mutation plan needs each remediation person's *current* org
 * relations, so the plan is written against a real preimage instead of a guess.
 * The publication boundary itself does not move — membership is decided by the
 * compiled id allowlist plus the business-truth identities whose canonical id is
 * not ledgered yet, every identity has to resolve to exactly one row, a manager
 * identity is only published for a user this owner already publishes in
 * `allowed_users`, reference labels are catalogue names rather than personal
 * data, and a row outside both keys is a blocker rather than a row.
 *
 * Determinism: rows are ordered by primary key, keys are emitted in a fixed
 * order, and the checksum covers the data section only — not `generated_at` —
 * so two collections of an unchanged database produce the same checksum. That is
 * what lets a mapping spec pin the inventory it was written against.
 */
final class OrganizationMappingInventoryReport
{
    public const SCHEMA_VERSION = '5';

    /**
     * The historical production baseline: the branch id set the postcheck
     * evidence of migration 079 recorded (10 rows, no id 3). Losing one of these
     * is still a blocker. It is never used to create, rename or map a branch.
     *
     * It is deliberately NOT the set of ids allowed to exist. A branch created
     * after the baseline is legitimate when the canonical create owner recorded
     * it; see branchSetReport(). Growing this list per new branch would make
     * every future legitimate branch a false blocker.
     */
    private const BASELINE_BRANCH_IDS = [1, 2, 4, 5, 6, 7, 8, 9, 10, 11];

    /** An id that never existed in production must never become an extension. */
    private const FORBIDDEN_BRANCH_IDS = [3];

    /**
     * The personnel evidence allowlist, part one: canonical ids.
     *
     * `FinalClosePackage::PERSONNEL` (200, 201, 203, 204, 205, 206, 209, 210,
     * 212, 217) plus the rows the business-truth lock already recorded a
     * canonical id for (160 Sedanur Bulut, 211 Zeynep Günal) and the final
     * preimage gap closed this phase (173 Sinem Hamaloğlu, 213 Abdullah Omar
     * Muhammed).
     *
     * The package constant itself is *not* reused and *not* grown: it is an
     * immutable apply authorization, while this list is a read scope. Widening an
     * evidence read must never widen what an apply gate is allowed to mutate, so
     * the two lists are kept deliberately separate — with the read scope the
     * larger one.
     */
    private const REMEDIATION_PERSONNEL_IDS = [160, 173, 200, 201, 203, 204, 205, 206, 209, 210, 211, 212, 213, 217];

    /**
     * The personnel evidence allowlist, part two: remediation identities whose
     * canonical id is not ledgered yet.
     *
     * They are used exactly once per read, to resolve a real id from the approved
     * business truth, because a mutation plan must not be written against a
     * guessed id. Each pair is matched as the canonical `(ad, soyad)` tuple; the
     * database collation decides that match, and this list only ever *narrows*
     * what may be published:
     *
     *  - a pair that matches one row resolves that row;
     *  - a pair that matches no row is reported unresolved;
     *  - a pair that matches several rows is reported ambiguous and publishes
     *    nothing — an identity is never guessed, not even for evidence.
     *
     * Once the resolved id is ledgered in the business truth, the pair belongs in
     * `REMEDIATION_PERSONNEL_IDS` and is deleted here; the list is expected to
     * shrink to empty, never to drift.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const REMEDIATION_PERSONNEL_IDENTITIES = [
        ['Ahmet', 'Kaçar'],
        ['Aysun', 'Özdemir'],
        ['Esat', 'Kaçar'],
        ['Melih', 'Güler'],
        ['Mustafa', 'Mahmud'],
    ];

    /** Hard ceiling on the personnel evidence statement, in rows. */
    private const PERSONNEL_EVIDENCE_ROW_LIMIT = 64;

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

        // The personnel evidence links each bounded row to the actor it reports
        // to, so the user allowlist is collected first and reused: a manager
        // identity is published only for a user this owner already publishes.
        $allowedUsers = self::allowedUsers($pdo);
        $personnelEvidence = self::allowedPersonnel($pdo, $allowedUsers);

        $data = [
            'baseline_branch_ids' => self::BASELINE_BRANCH_IDS,
            'branches' => self::branches($pdo),
            'sgk_employers' => self::sgkEmployers($pdo),
            'work_locations' => self::workLocations($pdo),
            'scope_summary' => self::scopeSummary($pdo),
            'allowed_personnel' => $personnelEvidence['rows'],
            'personnel_evidence' => $personnelEvidence['summary'],
            'allowed_users' => $allowedUsers,
            'manager_evidence' => self::managerEvidence($pdo),
            'a1_policy_evidence' => self::a1PolicyEvidence($pdo),
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
        $data += self::branchSetReport($pdo, $data['branches']);

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
     * Decide, per live branch, whether it is provably allowed to exist.
     *
     * Two classes of branch are legitimate and they are proven differently:
     *
     *  - a baseline branch (migration 079 production evidence) needs no audit,
     *    because it predates the audited create owner; its disappearance is a
     *    blocker;
     *  - an extension branch needs a canonical create audit row in
     *    `sube_olusturma_auditleri`, exactly one, whose branch id and immutable
     *    `kod` match the live row. Only those two columns are used: `ad`,
     *    `durum`, `sirket_id` and `sgk_isveren_id` may legitimately change after
     *    creation, so requiring them to match would turn a lawful later update
     *    into a fake tamper signal.
     *
     * Anything else is a blocker: an unaudited extension, a duplicated create
     * audit, an audit whose branch identity disagrees with the live row, and a
     * missing baseline branch. Fail-closed: when the audit owner cannot be read,
     * no extension can be proven, so every extension is reported unaudited.
     *
     * @param list<array<string, mixed>> $branches
     * @return array<string, mixed>
     */
    private static function branchSetReport(PDO $pdo, array $branches): array
    {
        $branchIds = array_map(static fn (array $row): int => (int) $row['id'], $branches);
        $liveKod = [];
        foreach ($branches as $branch) {
            $liveKod[(int) $branch['id']] = (string) $branch['kod'];
        }

        $audits = self::branchCreateAudits($pdo);
        $auditReady = $audits !== null;

        $audited = [];
        $unaudited = [];
        $duplicate = [];
        $mismatched = [];
        foreach ($branchIds as $id) {
            if (in_array($id, self::BASELINE_BRANCH_IDS, true)) {
                continue;
            }
            $audit = $auditReady ? ($audits[$id] ?? null) : null;
            if ($audit === null || in_array($id, self::FORBIDDEN_BRANCH_IDS, true)) {
                $unaudited[] = $id;
                continue;
            }
            if ($audit['audit_rows'] > 1) {
                $duplicate[] = $id;
                $unaudited[] = $id;
                continue;
            }
            if ($audit['kod'] !== $liveKod[$id] || $audit['actor_user_id'] <= 0) {
                $mismatched[] = $id;
                $unaudited[] = $id;
                continue;
            }
            $audited[] = $id;
        }

        $missingBaseline = array_values(array_diff(self::BASELINE_BRANCH_IDS, $branchIds));

        // The expected set — and therefore the expected branch count — is derived,
        // never pinned to a number: baseline plus the extensions that carry proof.
        $expected = array_merge(self::BASELINE_BRANCH_IDS, $audited);
        sort($expected);
        $unexpected = array_values(array_diff($branchIds, $expected));

        return [
            'branch_create_audit_ready' => $auditReady,
            'audited_extension_branch_ids' => $audited,
            'unaudited_extension_branch_ids' => array_values(array_unique($unaudited)),
            'duplicate_extension_audit_branch_ids' => $duplicate,
            'mismatched_extension_audit_branch_ids' => $mismatched,
            'missing_baseline_branch_ids' => $missingBaseline,
            'expected_branch_ids' => $expected,
            'expected_branch_count' => count($expected),
            'unexpected_branch_ids' => $unexpected,
            'missing_branch_ids' => $missingBaseline,
            'branch_set_valid' => $unexpected === []
                && $missingBaseline === []
                && $duplicate === []
                && $mismatched === []
                && !in_array(3, $branchIds, true),
        ];
    }

    /**
     * Create-audit evidence per branch id, or null when the audit owner cannot be
     * read at all (migration 080 not applied on this database, for instance).
     *
     * Grouped rather than listed: the inventory needs the count, the recorded
     * `kod` and the fact that an actor is attached — never the justification text
     * or the request hash.
     *
     * @return array<int, array{audit_rows:int, kod:string, actor_user_id:int}>|null
     */
    private static function branchCreateAudits(PDO $pdo): ?array
    {
        try {
            $statement = $pdo->query(
                'SELECT a.sube_id AS sube_id, COUNT(*) AS audit_rows,
                        MIN(a.kod) AS kod, MIN(a.actor_user_id) AS actor_user_id
                 FROM sube_olusturma_auditleri a
                 GROUP BY a.sube_id
                 ORDER BY a.sube_id ASC'
            );
            if ($statement === false) {
                return null;
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
        } catch (Throwable $exception) {
            return null;
        }

        $audits = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $audits[(int) $row['sube_id']] = [
                'audit_rows' => (int) $row['audit_rows'],
                'kod' => (string) $row['kod'],
                'actor_user_id' => (int) $row['actor_user_id'],
            ];
        }

        return $audits;
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
     * The operational closeout remediation scope: the exact personnel records
     * whose current org relations an approved mutation plan has to be written
     * against.
     *
     * Membership comes from `REMEDIATION_PERSONNEL_IDS` and
     * `REMEDIATION_PERSONNEL_IDENTITIES` only — both are literals in this file and
     * neither is reachable from a caller. The statement binds parameters (the id
     * allowlist, and the identity pairs twice: once to select, once so the
     * database itself states that a returned row matched an allowlisted
     * identity), because the boundary must be the statement's own filter and not
     * a PHP-side re-derivation of it.
     *
     * Resolution is fail-closed per identity: no matching row, or more than one,
     * publishes nothing for that identity and is reported instead. A returned row
     * that is neither id-allowlisted nor identity-matched is impossible by
     * construction, so it is reported as a boundary violation and turned into a
     * blocker rather than published.
     *
     * `bagli_amir_id` keeps its canonical semantics — `users.id`, never a
     * personnel id; `ReferansController` states the same write contract
     * (`bagli_amir_id` → `users.id`). The manager's identity is copied from the
     * user allowlist this owner already publishes, so a manager outside that list
     * contributes the relation id and nothing else.
     *
     * @param list<array<string, mixed>> $publishedUsers
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private static function allowedPersonnel(PDO $pdo, array $publishedUsers): array
    {
        $identities = self::REMEDIATION_PERSONNEL_IDENTITIES;
        $summary = [
            'available' => false,
            'id_allowlist' => self::REMEDIATION_PERSONNEL_IDS,
            'id_allowlist_matched_ids' => [],
            'id_allowlist_missing_ids' => self::REMEDIATION_PERSONNEL_IDS,
            'identity_allowlist' => array_map(
                static fn (array $identity): string => trim($identity[0] . ' ' . $identity[1]),
                $identities
            ),
            'identity_resolution' => [],
            'identity_matched_row_ids' => [],
            'identity_unattributed_row_ids' => [],
            'unresolved_identities' => [],
            'ambiguous_identities' => [],
            'published_count' => 0,
            'boundary_violation_ids' => [],
            'evidence_complete' => false,
        ];

        if (!self::columnsExist($pdo, 'personeller', [
            'ad', 'soyad', 'aktif_durum', 'calisan_kapsami', 'calisma_lokasyonu_id', 'sube_id',
            'sgk_isveren_id', 'personel_tipi_id', 'departman_id', 'bolum_id', 'birim_id', 'gorev_id',
            'pozisyon_id', 'bagli_amir_id',
        ])) {
            return ['rows' => [], 'summary' => $summary];
        }

        $idPlaceholders = implode(', ', array_fill(0, count(self::REMEDIATION_PERSONNEL_IDS), '?'));
        $identityTuplePlaceholders = implode(', ', array_fill(0, count($identities), '(?, ?)'));
        $identityParameters = [];
        foreach ($identities as $identity) {
            $identityParameters[] = $identity[0];
            $identityParameters[] = $identity[1];
        }

        $statement = $pdo->prepare(
            'SELECT p.id, p.ad, p.soyad, p.aktif_durum AS durum, p.calisan_kapsami,
                    p.sube_id, s.sirket_id, p.sgk_isveren_id,
                    p.calisma_lokasyonu_id, l.ad AS calisma_lokasyonu_label,
                    e.ad AS sgk_isveren_label,
                    p.personel_tipi_id, pt.ad AS personel_tipi_label,
                    p.departman_id, d.ad AS departman_label,
                    p.bolum_id, b.ad AS bolum_label,
                    p.birim_id, br.ad AS birim_label,
                    p.gorev_id, g.ad AS gorev_unvan,
                    p.pozisyon_id, pz.ad AS pozisyon_label,
                    p.bagli_amir_id,
                    CASE WHEN (p.ad, p.soyad) IN (' . $identityTuplePlaceholders . ') THEN 1 ELSE 0 END
                        AS identity_match
             FROM personeller p
             LEFT JOIN subeler s ON s.id = p.sube_id
             LEFT JOIN calisma_lokasyonlari l ON l.id = p.calisma_lokasyonu_id
             LEFT JOIN sgk_isverenler e ON e.id = p.sgk_isveren_id
             LEFT JOIN personel_tipleri pt ON pt.id = p.personel_tipi_id
             LEFT JOIN departmanlar d ON d.id = p.departman_id
             LEFT JOIN bolumler b ON b.id = p.bolum_id
             LEFT JOIN birimler br ON br.id = p.birim_id
             LEFT JOIN gorevler g ON g.id = p.gorev_id
             LEFT JOIN pozisyonlar pz ON pz.id = p.pozisyon_id
             WHERE p.id IN (' . $idPlaceholders . ')
                OR (p.ad, p.soyad) IN (' . $identityTuplePlaceholders . ')
             ORDER BY p.id ASC
             LIMIT ' . self::PERSONNEL_EVIDENCE_ROW_LIMIT
        );

        try {
            // Placeholder order follows the statement text: the projection's CASE
            // selector first, then the id key, then the identity key.
            $statement->execute(array_merge(
                $identityParameters,
                self::REMEDIATION_PERSONNEL_IDS,
                $identityParameters
            ));
            $fetched = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
        } catch (Throwable $exception) {
            return ['rows' => [], 'summary' => $summary];
        }

        $idAllowlist = array_flip(self::REMEDIATION_PERSONNEL_IDS);
        $identityStates = [];
        foreach ($identities as $identity) {
            $identityStates[self::identityKey($identity[0], $identity[1])] = [
                'key' => trim($identity[0] . ' ' . $identity[1]),
                'matched_row_ids' => [],
            ];
        }

        $identityMatchedIds = [];
        $unattributed = [];
        foreach (is_array($fetched) ? $fetched : [] as $row) {
            $rowId = (int) $row['id'];
            if (array_key_exists($rowId, $idAllowlist) || (int) $row['identity_match'] !== 1) {
                continue;
            }
            $identityMatchedIds[] = $rowId;
            $key = self::identityKey((string) $row['ad'], (string) $row['soyad']);
            if (isset($identityStates[$key])) {
                $identityStates[$key]['matched_row_ids'][] = $rowId;
            } else {
                // The database matched an allowlisted pair but this process could
                // not fold the row's own name to it. The row stays unpublished:
                // an identity that cannot be attributed is not evidence.
                $unattributed[] = $rowId;
            }
        }

        $usersById = [];
        foreach ($publishedUsers as $user) {
            $userId = self::nullableInt($user['user_id'] ?? null);
            if ($userId !== null) {
                $usersById[$userId] = $user;
            }
        }

        $rows = [];
        $idMatched = [];
        $violations = [];
        foreach (is_array($fetched) ? $fetched : [] as $row) {
            $rowId = (int) $row['id'];
            $viaId = array_key_exists($rowId, $idAllowlist);
            if (!$viaId) {
                if ((int) $row['identity_match'] !== 1) {
                    // Off both allowlist keys: the boundary failed, so nothing is
                    // published from this row and the read is blocked.
                    $violations[] = $rowId;
                    continue;
                }
                $key = self::identityKey((string) $row['ad'], (string) $row['soyad']);
                if (count($identityStates[$key]['matched_row_ids'] ?? []) !== 1) {
                    // Ambiguous or unattributable: reported above, never published.
                    continue;
                }
            }
            if ($viaId) {
                $idMatched[] = $rowId;
            }

            $managerId = self::nullableInt($row['bagli_amir_id'] ?? null);
            $manager = $managerId === null ? null : ($usersById[$managerId] ?? null);
            $rows[] = [
                'id' => $rowId,
                'ad' => (string) $row['ad'],
                'soyad' => (string) $row['soyad'],
                'durum' => (string) $row['durum'],
                'calisan_kapsami' => (string) $row['calisan_kapsami'],
                'sirket_id' => self::nullableInt($row['sirket_id'] ?? null),
                'sube_id' => self::nullableInt($row['sube_id'] ?? null),
                'personel_tipi_id' => self::nullableInt($row['personel_tipi_id'] ?? null),
                'personel_tipi_label' => self::nullableString($row['personel_tipi_label'] ?? null),
                'calisma_lokasyonu_id' => self::nullableInt($row['calisma_lokasyonu_id'] ?? null),
                'calisma_lokasyonu_label' => self::nullableString($row['calisma_lokasyonu_label'] ?? null),
                'sgk_isveren_id' => self::nullableInt($row['sgk_isveren_id'] ?? null),
                'sgk_isveren_label' => self::nullableString($row['sgk_isveren_label'] ?? null),
                'departman_id' => self::nullableInt($row['departman_id'] ?? null),
                'departman_label' => self::nullableString($row['departman_label'] ?? null),
                'bolum_id' => self::nullableInt($row['bolum_id'] ?? null),
                'bolum_label' => self::nullableString($row['bolum_label'] ?? null),
                'birim_id' => self::nullableInt($row['birim_id'] ?? null),
                'birim_label' => self::nullableString($row['birim_label'] ?? null),
                // `gorev_unvan` is the canonical label of `gorev_id` (gorevler.ad);
                // migration 065 records that unvan stays owned by gorev_id.
                'gorev_unvan' => self::nullableString($row['gorev_unvan'] ?? null),
                'pozisyon_id' => self::nullableInt($row['pozisyon_id'] ?? null),
                'pozisyon_label' => self::nullableString($row['pozisyon_label'] ?? null),
                // Canonical semantics: bagli_amir_id is users.id, never a personnel id.
                'bagli_amir_id' => $managerId,
                'bagli_amir_user_id' => $manager === null ? null : (int) $manager['user_id'],
                'bagli_amir_username' => $manager === null ? null : (string) $manager['username'],
                'bagli_amir_personel_id' => $manager === null
                    ? null
                    : self::nullableInt($manager['personel_id'] ?? null),
                'bagli_amir_ad_soyad' => $manager === null
                    ? null
                    : self::nullableString($manager['ad_soyad'] ?? null),
                'bagli_amir_identity_published' => $manager !== null,
                'provenance' => $viaId ? 'id_allowlist' : 'identity_resolution',
            ];
        }

        $identityResolution = [];
        $unresolved = [];
        $ambiguous = [];
        $attributedCount = 0;
        foreach ($identityStates as $state) {
            $count = count($state['matched_row_ids']);
            $attributedCount += $count;
            $resolution = $count === 1 ? 'RESOLVED' : ($count === 0 ? 'UNRESOLVED' : 'AMBIGUOUS');
            if ($resolution === 'UNRESOLVED') {
                $unresolved[] = $state['key'];
            }
            if ($resolution === 'AMBIGUOUS') {
                $ambiguous[] = $state['key'];
            }
            $identityResolution[] = [
                'key' => $state['key'],
                'state' => $resolution,
                'matched_row_ids' => $state['matched_row_ids'],
            ];
        }
        $missingIds = array_values(array_diff(self::REMEDIATION_PERSONNEL_IDS, $idMatched));

        $summary['available'] = true;
        $summary['id_allowlist_matched_ids'] = $idMatched;
        $summary['id_allowlist_missing_ids'] = $missingIds;
        $summary['identity_resolution'] = $identityResolution;
        $summary['identity_matched_row_ids'] = $identityMatchedIds;
        $summary['identity_unattributed_row_ids'] = $unattributed;
        $summary['unresolved_identities'] = $unresolved;
        $summary['ambiguous_identities'] = $ambiguous;
        $summary['published_count'] = count($rows);
        $summary['boundary_violation_ids'] = array_values(array_unique($violations));
        $summary['evidence_complete'] = $violations === []
            && $missingIds === []
            && $unresolved === []
            && $ambiguous === []
            && $unattributed === []
            && $attributedCount === count($identityMatchedIds);

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * Case-folded `ad`+`soyad` key. It exists only to attribute a row the
     * database already matched to its allowlist entry, so it is never published
     * and never decides membership.
     */
    private static function identityKey(string $ad, string $soyad): string
    {
        return self::normalizeIdentityPart($ad) . "\n" . self::normalizeIdentityPart($soyad);
    }

    private static function normalizeIdentityPart(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function allowedUsers(PDO $pdo): array
    {
        if (!self::tableExists($pdo, 'actor_identities')
            || !self::columnsExist(
                $pdo,
                'users',
                ['username', 'ad_soyad', 'personel_id', 'durum', 'rol', 'actor_identity_id']
            )) {
            return [];
        }

        $rows = self::query(
            $pdo,
            "SELECT u.id AS user_id, u.username, u.ad_soyad, u.personel_id, u.durum,
                    u.rol, u.actor_identity_id, ai.status AS actor_identity_status
             FROM users u
             LEFT JOIN actor_identities ai ON ai.id = u.actor_identity_id
             WHERE LOWER(u.username) = 'sedanurb'
                OR u.ad_soyad IN ('Sinem Hamaloğlu', 'Halil Şenay')
                OR LOWER(u.ad_soyad) LIKE '%kübra%'
             ORDER BY u.id ASC
             LIMIT 8"
        );

        $items = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $scope = self::userScope($pdo, $userId);
            $actor = [
                'id' => $userId,
                'username' => (string) $row['username'],
                'durum' => (string) $row['durum'],
                'rol' => (string) $row['rol'],
                'actor_identity_id' => self::nullableInt($row['actor_identity_id'] ?? null),
                'actor_identity_status' => (string) ($row['actor_identity_status'] ?? ''),
            ];
            $readiness = SgkKararPaketiAuthz::formalActorReadiness($pdo, $actor);
            $items[] = [
                'user_id' => $userId,
                'username' => (string) $row['username'],
                // Published because the personnel evidence names the manager a
                // bounded row reports to, and it may only ever name a user this
                // section already publishes.
                'ad_soyad' => (string) $row['ad_soyad'],
                'personel_id' => self::nullableInt($row['personel_id'] ?? null),
                'durum' => (string) $row['durum'],
                'rol' => (string) $row['rol'],
                'actor_identity_status' => (string) ($row['actor_identity_status'] ?? 'NONE'),
                'user_subeler' => $scope,
                'user_sirketler' => self::userSirketScope($pdo, $userId),
                'user_sgk_isverenler' => self::userSgkIsverenScope($pdo, $userId),
                'can_prepare' => (bool) ($readiness['can_prepare'] ?? false),
                'can_approve' => (bool) ($readiness['can_approve'] ?? false),
                'scope_12' => in_array(12, $scope, true),
                'scope_13' => in_array(13, $scope, true),
            ];
        }

        return $items;
    }

    /**
     * @return array{exists: bool, row_count: int, rows: list<array<string, int>>}
     */
    private static function managerEvidence(PDO $pdo): array
    {
        if (!self::tableExists($pdo, 'sube_sorumlu_yoneticiler')) {
            return ['exists' => false, 'row_count' => 0, 'rows' => []];
        }

        $rows = self::query(
            $pdo,
            'SELECT sube_id, user_id
             FROM sube_sorumlu_yoneticiler
             ORDER BY sube_id ASC, user_id ASC'
        );

        return [
            'exists' => true,
            'row_count' => count($rows),
            'rows' => array_map(static fn (array $row): array => [
                'sube_id' => (int) $row['sube_id'],
                'user_id' => (int) $row['user_id'],
            ], $rows),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function a1PolicyEvidence(PDO $pdo): array
    {
        if (!self::tableExists($pdo, 'sgk_sirket_politika_surumleri')
            || !self::tableExists($pdo, 'sgk_sirket_politika_degerleri')) {
            return [];
        }

        $rows = self::query(
            $pdo,
            "SELECT p.sube_id, p.surum_kodu, p.bildirim_donem_tipi,
                    p.gecerlilik_baslangic, p.gecerlilik_bitis, p.state,
                    p.hazirlayan_id, p.onaylayan_id, p.politika_hash,
                    v.deger AS mahsup_mode
             FROM sgk_sirket_politika_surumleri p
             LEFT JOIN sgk_sirket_politika_degerleri v
               ON v.politika_surum_id = p.id
              AND v.politika_kodu = 'SGK_ODENEK_MAHSUP_MODU'
             WHERE p.sube_id IN (12, 13)
             ORDER BY p.sube_id ASC, p.gecerlilik_baslangic ASC, p.id ASC"
        );

        return array_map(static fn (array $row): array => [
            'sube_id' => (int) $row['sube_id'],
            'surum_kodu' => (string) $row['surum_kodu'],
            'bildirim_donem_tipi' => (string) $row['bildirim_donem_tipi'],
            'effective_start' => $row['gecerlilik_baslangic'],
            'effective_end' => $row['gecerlilik_bitis'],
            'mahsup_mode' => $row['mahsup_mode'],
            'lifecycle_state' => (string) $row['state'],
            'prepare_actor' => self::nullableInt($row['hazirlayan_id'] ?? null),
            'submit_actor' => null,
            'approve_actor' => self::nullableInt($row['onaylayan_id'] ?? null),
            'policy_hash' => (string) $row['politika_hash'],
        ], $rows);
    }

    /** @return list<int> */
    private static function userScope(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            'SELECT sube_id FROM user_subeler WHERE user_id = :user_id ORDER BY sube_id ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn ($value): int => (int) $value, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private static function userSirketScope(PDO $pdo, int $userId): array
    {
        if (!self::tableExists($pdo, 'user_sirketler')) {
            return [];
        }
        $statement = $pdo->prepare(
            'SELECT sirket_id FROM user_sirketler WHERE user_id = :user_id ORDER BY sirket_id ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn ($value): int => (int) $value, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private static function userSgkIsverenScope(PDO $pdo, int $userId): array
    {
        if (!self::tableExists($pdo, 'user_sgk_isverenler')) {
            return [];
        }
        $statement = $pdo->prepare(
            'SELECT sgk_isveren_id FROM user_sgk_isverenler WHERE user_id = :user_id ORDER BY sgk_isveren_id ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn ($value): int => (int) $value, $statement->fetchAll(PDO::FETCH_COLUMN));
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
        // A branch that is neither baseline nor a provably audited extension.
        if ($data['unexpected_branch_ids'] !== []) {
            $blockers[] = 'UNEXPECTED_BRANCH_IDS_PRESENT';
        }
        if ($data['duplicate_extension_audit_branch_ids'] !== []) {
            $blockers[] = 'DUPLICATE_BRANCH_CREATE_AUDIT';
        }
        if ($data['mismatched_extension_audit_branch_ids'] !== []) {
            $blockers[] = 'BRANCH_CREATE_AUDIT_MISMATCH';
        }
        // An extension exists but its proof cannot be read: unprovable, not fine.
        if ($data['branch_create_audit_ready'] !== true && $data['unaudited_extension_branch_ids'] !== []) {
            $blockers[] = 'BRANCH_CREATE_AUDIT_UNREADABLE';
        }
        if ($data['missing_baseline_branch_ids'] !== []) {
            $blockers[] = 'DOCUMENTED_BRANCH_IDS_MISSING';
        }
        if ($data['branch_set_valid'] !== true) {
            $blockers[] = 'BRANCH_SET_NOT_PROVABLE';
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
        // A personnel row that is on neither allowlist key proves the evidence
        // statement no longer matches its own boundary. Fail closed: the read is
        // blocked instead of publishing a row the allowlist does not cover.
        if (($data['personnel_evidence']['boundary_violation_ids'] ?? []) !== []) {
            $blockers[] = 'REMEDIATION_PERSONNEL_ALLOWLIST_BOUNDARY_VIOLATION';
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

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name
             LIMIT 1'
        );
        $statement->execute(['table_name' => $table]);
        $exists = $statement->fetchColumn() !== false;
        $statement->closeCursor();

        return $exists;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name
             LIMIT 1'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        $exists = $statement->fetchColumn() !== false;
        $statement->closeCursor();

        return $exists;
    }

    /**
     * @param list<string> $columns
     */
    private static function columnsExist(PDO $pdo, string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!self::columnExists($pdo, $table, $column)) {
                return false;
            }
        }

        return true;
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

    /**
     * A reference label: NULL and an empty label both mean "no label", so a row
     * without the relation never carries a phantom name.
     *
     * @param mixed $value
     */
    private static function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = (string) $value;

        return $text === '' ? null : $text;
    }
}
