<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use PDO;
use Throwable;

/**
 * Operations-only owner of the ONE-TIME initial organisation mapping.
 *
 * This is not a re-parenting feature and must never become one. The public
 * management API deliberately cannot move a branch between companies:
 * `OrganizasyonService::updateSube` writes only `ad`, `durum` and
 * `sgk_isveren_id`, `rejectPayloadSirketId` refuses a company in the payload and
 * the nested route asserts the branch already belongs to the company. Those rules
 * stay exactly as they are. What production still needed was a way to fill the
 * relations that migration 079 created as NULL for the first time — an operation,
 * not a feature — so it lives here, reachable only from the canonical control-plane
 * worker, with no controller, no route and no UI.
 *
 * The safety model is preimage + checksum, not trust:
 *   - the spec pins the inventory checksum it was written against;
 *   - every row it wants to change carries its expected current values;
 *   - the same gate runs in the preflight and again inside the apply transaction,
 *     so a database that moved in between fails closed rather than absorbing a
 *     decision made about different data;
 *   - only NULL relations are ever filled. A relation that already points at the
 *     spec's target is an idempotent no-op; one that points somewhere else is a
 *     blocker, never an overwrite.
 *
 * Never written here: any `personeller` column, any user scope row, `tam_ad`
 * (derived), or a branch `kod` (stable technical identity).
 */
final class OrganizationInitialMappingService
{
    public const SCHEMA_VERSION = '1';

    /**
     * Read-only gate. Returns a publishable report; never touches a row.
     *
     * @param array<string, mixed> $inventory the inventory report the spec pins
     * @return array<string, mixed>
     */
    public static function preflight(
        PDO $pdo,
        OrganizationMappingSpec $spec,
        string $deployedSha,
        string $productionTip,
        int $pendingMigrationCount,
        array $inventory
    ): array {
        $blockers = self::gate($pdo, $spec, $deployedSha, $productionTip, $pendingMigrationCount, $inventory);
        $plan = self::plan($pdo, $spec);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'operation_id' => $spec->operationId(),
            'deployed_sha' => strtolower($deployedSha),
            'production_migration_tip' => $productionTip,
            'spec_checksum' => $spec->checksum(),
            'inventory_checksum' => $spec->inventoryChecksum(),
            'planned_changes' => $plan,
            'blockers' => $blockers,
            'result' => $blockers === [] ? 'PASS' : 'BLOCKED',
        ];
    }

    /**
     * Apply the mapping in a single transaction, or leave the database untouched.
     *
     * The caller must have completed a verified backup first; this owner refuses
     * to start without the evidence reference so "backup was skipped" cannot be a
     * silent path.
     *
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $backupEvidence
     * @return array<string, mixed>
     */
    public static function apply(
        PDO $pdo,
        OrganizationMappingSpec $spec,
        string $deployedSha,
        string $productionTip,
        int $pendingMigrationCount,
        array $inventory,
        array $backupEvidence
    ): array {
        self::assertBackupEvidence($backupEvidence);

        // Gate before the transaction so a blocked operation never even opens one.
        $blockers = self::gate($pdo, $spec, $deployedSha, $productionTip, $pendingMigrationCount, $inventory);
        if ($blockers !== []) {
            throw OrganizationMappingFailure::of('MAPPING_PREFLIGHT_BLOCKED', implode(',', $blockers));
        }

        $pdo->beginTransaction();
        try {
            // Re-gate inside the transaction: between the read above and this
            // point another writer could have changed a row the spec asserted.
            $inTransaction = self::gate($pdo, $spec, $deployedSha, $productionTip, $pendingMigrationCount, $inventory);
            if ($inTransaction !== []) {
                throw OrganizationMappingFailure::of('MAPPING_STATE_CHANGED', implode(',', $inTransaction));
            }

            $applied = [
                'companies_created' => 0,
                'companies_reused' => 0,
                'sgk_mapped' => 0,
                'sgk_already_mapped' => 0,
                'branches_mapped' => 0,
                'branches_already_mapped' => 0,
                'branches_renamed' => 0,
                'locations_mapped' => 0,
                'locations_already_mapped' => 0,
                'locations_deferred' => 0,
            ];

            $companyIds = self::ensureCompanies($pdo, $spec, $applied);
            self::mapSgkEmployers($pdo, $spec, $companyIds, $applied);
            self::mapBranches($pdo, $spec, $companyIds, $applied);
            self::mapLocations($pdo, $spec, $applied);

            $postconditions = self::assertPostconditions($pdo, $spec, $companyIds);

            $pdo->commit();

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'operation_id' => $spec->operationId(),
                'spec_checksum' => $spec->checksum(),
                'inventory_checksum' => $spec->inventoryChecksum(),
                'backup_file' => (string) $backupEvidence['file'],
                'backup_sha256' => (string) $backupEvidence['sha256'],
                'applied' => $applied,
                'postconditions' => $postconditions,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception instanceof OrganizationMappingFailure
                ? $exception
                : OrganizationMappingFailure::of('MAPPING_APPLY_FAILED', $exception->getMessage());
        }
    }

    /**
     * Read-only proof of what the database now holds. Runs after the commit and
     * after a rollback alike, so a failed operation is also evidenced.
     *
     * @param array<string, mixed> $backupEvidence
     * @return array<string, mixed>
     */
    public static function postcheck(
        PDO $pdo,
        OrganizationMappingSpec $spec,
        array $backupEvidence
    ): array {
        OrganizasyonSchema::resetCache();
        $readiness = OrganizasyonSchema::report($pdo);
        $companyIds = self::companyIdsByKod($pdo);

        $branchState = [];
        $branchDeltas = [];
        foreach (self::rows($pdo, 'SELECT id, kod, ad, sirket_id, sgk_isveren_id FROM subeler ORDER BY id ASC') as $row) {
            $branchState[(int) $row['id']] = $row;
        }
        foreach ($spec->branchMappings() as $mapping) {
            $id = $mapping['sube_id'];
            $row = $branchState[$id] ?? null;
            $expectedCompanyId = $companyIds[$mapping['target_company_kod']] ?? null;
            $expectedAd = $mapping['approved_ad'] ?? $mapping['expected_ad'];
            $branchDeltas[] = [
                'sube_id' => $id,
                'present' => $row !== null,
                'kod_preserved' => $row !== null && (string) $row['kod'] === $mapping['expected_kod'],
                'company_matches_spec' => $row !== null
                    && $expectedCompanyId !== null
                    && (int) $row['sirket_id'] === $expectedCompanyId,
                'ad_matches_spec' => $row !== null && (string) $row['ad'] === $expectedAd,
                'sgk_preserved' => $row !== null
                    && self::nullableInt($row['sgk_isveren_id']) === $mapping['expected_sgk_isveren_id'],
            ];
        }

        $sgkDeltas = [];
        $sgkState = [];
        foreach (self::rows($pdo, 'SELECT id, kod, sirket_id FROM sgk_isverenler ORDER BY id ASC') as $row) {
            $sgkState[(int) $row['id']] = $row;
        }
        foreach ($spec->sgkMappings() as $mapping) {
            $row = $sgkState[$mapping['sgk_isveren_id']] ?? null;
            $expectedCompanyId = $companyIds[$mapping['target_company_kod']] ?? null;
            $sgkDeltas[] = [
                'sgk_isveren_id' => $mapping['sgk_isveren_id'],
                'present' => $row !== null,
                'kod_preserved' => $row !== null && (string) $row['kod'] === $mapping['expected_kod'],
                'company_matches_spec' => $row !== null
                    && $expectedCompanyId !== null
                    && (int) $row['sirket_id'] === $expectedCompanyId,
            ];
        }

        $locationDeltas = [];
        $locationState = [];
        foreach (self::rows($pdo, 'SELECT id, kod, sube_id FROM calisma_lokasyonlari ORDER BY id ASC') as $row) {
            $locationState[(int) $row['id']] = $row;
        }
        foreach ($spec->locationMappings() as $mapping) {
            $row = $locationState[$mapping['calisma_lokasyonu_id']] ?? null;
            $current = $row === null ? null : self::nullableInt($row['sube_id']);
            $locationDeltas[] = [
                'calisma_lokasyonu_id' => $mapping['calisma_lokasyonu_id'],
                'present' => $row !== null,
                'kod_preserved' => $row !== null && (string) $row['kod'] === $mapping['expected_kod'],
                'deferred' => $mapping['target_sube_id'] === null,
                'branch_matches_spec' => $mapping['target_sube_id'] === null
                    ? $current === $mapping['expected_sube_id']
                    : $current === $mapping['target_sube_id'],
            ];
        }

        $rowCounts = self::rowCounts($pdo);
        $preservation = $spec->preservation();

        $unexpected = [];
        foreach ($branchDeltas as $delta) {
            if (!$delta['present'] || !$delta['kod_preserved'] || !$delta['company_matches_spec']
                || !$delta['ad_matches_spec'] || !$delta['sgk_preserved']) {
                $unexpected[] = 'BRANCH_' . $delta['sube_id'];
            }
        }
        foreach ($sgkDeltas as $delta) {
            if (!$delta['present'] || !$delta['kod_preserved'] || !$delta['company_matches_spec']) {
                $unexpected[] = 'SGK_' . $delta['sgk_isveren_id'];
            }
        }
        foreach ($locationDeltas as $delta) {
            if (!$delta['present'] || !$delta['kod_preserved'] || !$delta['branch_matches_spec']) {
                $unexpected[] = 'LOCATION_' . $delta['calisma_lokasyonu_id'];
            }
        }
        if ($rowCounts['personeller'] !== $preservation['expected_personel_count']) {
            $unexpected[] = 'PERSONEL_ROW_DELTA';
        }
        if ($rowCounts['user_subeler'] !== $preservation['expected_user_sube_count']) {
            $unexpected[] = 'USER_SUBE_ROW_DELTA';
        }
        if ($rowCounts['user_sirketler'] !== $preservation['expected_user_sirket_count']) {
            $unexpected[] = 'USER_SIRKET_ROW_DELTA';
        }
        if ($rowCounts['user_sgk_isverenler'] !== $preservation['expected_user_sgk_isveren_count']) {
            $unexpected[] = 'USER_SGK_ISVEREN_ROW_DELTA';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'operation_id' => $spec->operationId(),
            'spec_checksum' => $spec->checksum(),
            'inventory_checksum' => $spec->inventoryChecksum(),
            'company_rows' => $rowCounts['sirketler'],
            'company_codes' => array_keys($companyIds),
            'branch_company_mappings' => $branchDeltas,
            'sgk_company_mappings' => $sgkDeltas,
            'location_branch_mappings' => $locationDeltas,
            'row_counts' => $rowCounts,
            'readiness' => [
                'schema_ready' => (bool) $readiness['schema_ready'],
                'data_ready' => (bool) $readiness['data_ready'],
                'blocker_count' => count($readiness['blockers']),
                'blockers' => $readiness['blockers'],
                'sube_sgk_sirket_mismatch_count' => (int) $readiness['counts']['sube_sgk_sirket_mismatch_count'],
            ],
            'unexpected_deltas' => array_values(array_unique($unexpected)),
            'backup_reference' => [
                'file' => (string) ($backupEvidence['file'] ?? ''),
                'sha256' => (string) ($backupEvidence['sha256'] ?? ''),
                'readback' => (string) ($backupEvidence['readback'] ?? ''),
            ],
            'result' => $unexpected === [] ? 'PASS' : 'BLOCKED',
        ];
    }

    // --------------------------------------------------------------------- gate

    /**
     * The single gate both the preflight and the apply run. Returns reason codes;
     * an empty list is the only thing that authorises a write.
     *
     * @param array<string, mixed> $inventory
     * @return list<string>
     */
    private static function gate(
        PDO $pdo,
        OrganizationMappingSpec $spec,
        string $deployedSha,
        string $productionTip,
        int $pendingMigrationCount,
        array $inventory
    ): array {
        $blockers = [];

        if (!hash_equals($spec->authorizedDeploySha(), strtolower($deployedSha))) {
            $blockers[] = 'MAPPING_DEPLOY_SHA_MISMATCH';
        }
        if ($spec->expectedProductionTip() !== $productionTip) {
            $blockers[] = 'MAPPING_PROD_TIP_UNEXPECTED';
        }
        if ($pendingMigrationCount !== 0) {
            $blockers[] = 'MAPPING_PENDING_MIGRATION_PRESENT';
        }

        // The checksum is recomputed from the inventory payload rather than read
        // from its own field: a report that was edited in transit must not pass.
        $inventoryData = is_array($inventory['data'] ?? null) ? $inventory['data'] : null;
        if ($inventoryData === null) {
            $blockers[] = 'MAPPING_INVENTORY_UNREADABLE';
        } elseif (!hash_equals(
            $spec->inventoryChecksum(),
            OrganizationMappingInventoryReport::checksum($inventoryData)
        )) {
            $blockers[] = 'MAPPING_INVENTORY_CHECKSUM_MISMATCH';
        }
        if (($inventory['result'] ?? '') !== 'PASS') {
            $blockers[] = 'MAPPING_INVENTORY_NOT_PASS';
        }

        OrganizasyonSchema::resetCache();
        if (!OrganizasyonSchema::isSchemaReady($pdo)) {
            $blockers[] = 'MAPPING_SCHEMA_NOT_READY';
        }

        $rowCounts = self::rowCounts($pdo);
        foreach ($spec->expectedRowCounts() as $table => $expected) {
            if (($rowCounts[$table] ?? -1) !== $expected) {
                $blockers[] = 'MAPPING_ROW_COUNT_MISMATCH';
                break;
            }
        }

        $preservation = $spec->preservation();
        if ($rowCounts['personeller'] !== $preservation['expected_personel_count']
            || $rowCounts['user_subeler'] !== $preservation['expected_user_sube_count']
            || $rowCounts['user_sirketler'] !== $preservation['expected_user_sirket_count']
            || $rowCounts['user_sgk_isverenler'] !== $preservation['expected_user_sgk_isveren_count']) {
            $blockers[] = 'MAPPING_PRESERVATION_COUNT_MISMATCH';
        }

        $branches = [];
        foreach (self::rows($pdo, 'SELECT id, kod, ad, durum, sirket_id, sgk_isveren_id FROM subeler ORDER BY id ASC') as $row) {
            $branches[(int) $row['id']] = $row;
        }
        $actualIds = array_keys($branches);
        sort($actualIds);
        $expectedIds = $spec->expectedBranchIds();
        sort($expectedIds);
        if ($actualIds !== $expectedIds) {
            $blockers[] = 'MAPPING_BRANCH_ID_SET_MISMATCH';
        }

        $companyIds = self::companyIdsByKod($pdo);
        $companyNames = self::companyNamesById($pdo);
        foreach ($spec->companies() as $company) {
            $existingId = $companyIds[$company['kod']] ?? null;
            if ($existingId !== null) {
                // Reusing a code whose name differs would silently rename a
                // company that other data already references.
                if (($companyNames[$existingId] ?? null) !== $company['ad']) {
                    $blockers[] = 'MAPPING_COMPANY_CODE_NAME_CONFLICT';
                }
                continue;
            }
            foreach ($companyNames as $id => $name) {
                if (SubeReadModel::normalizeName($name) === SubeReadModel::normalizeName($company['ad'])) {
                    $blockers[] = 'MAPPING_COMPANY_NAME_TAKEN';
                    break;
                }
            }
        }

        foreach ($spec->branchMappings() as $mapping) {
            $row = $branches[$mapping['sube_id']] ?? null;
            if ($row === null) {
                $blockers[] = 'MAPPING_BRANCH_MISSING';
                continue;
            }
            if ((string) $row['kod'] !== $mapping['expected_kod']
                || (string) $row['ad'] !== $mapping['expected_ad']
                || self::nullableInt($row['sgk_isveren_id']) !== $mapping['expected_sgk_isveren_id']) {
                $blockers[] = 'MAPPING_BRANCH_PREIMAGE_MISMATCH';
                continue;
            }
            $currentCompany = self::nullableInt($row['sirket_id']);
            if ($currentCompany !== $mapping['expected_sirket_id']) {
                $blockers[] = 'MAPPING_BRANCH_PREIMAGE_MISMATCH';
                continue;
            }
            if ($currentCompany !== null) {
                // Already mapped: identical target is a no-op, anything else is a
                // re-parent and this owner refuses to perform one.
                $target = $companyIds[$mapping['target_company_kod']] ?? null;
                if ($target === null || $target !== $currentCompany) {
                    $blockers[] = 'MAPPING_BRANCH_COMPANY_CONFLICT';
                }
            }
        }

        $employers = [];
        foreach (self::rows($pdo, 'SELECT id, kod, ad, sirket_id FROM sgk_isverenler ORDER BY id ASC') as $row) {
            $employers[(int) $row['id']] = $row;
        }
        foreach ($spec->sgkMappings() as $mapping) {
            $row = $employers[$mapping['sgk_isveren_id']] ?? null;
            if ($row === null) {
                $blockers[] = 'MAPPING_SGK_MISSING';
                continue;
            }
            if ((string) $row['kod'] !== $mapping['expected_kod'] || (string) $row['ad'] !== $mapping['expected_ad']) {
                $blockers[] = 'MAPPING_SGK_PREIMAGE_MISMATCH';
                continue;
            }
            $currentCompany = self::nullableInt($row['sirket_id']);
            if ($currentCompany !== $mapping['expected_sirket_id']) {
                $blockers[] = 'MAPPING_SGK_PREIMAGE_MISMATCH';
                continue;
            }
            if ($currentCompany !== null) {
                $target = $companyIds[$mapping['target_company_kod']] ?? null;
                if ($target === null || $target !== $currentCompany) {
                    $blockers[] = 'MAPPING_SGK_COMPANY_CONFLICT';
                }
            }
        }

        $locations = [];
        foreach (self::rows($pdo, 'SELECT id, kod, ad, sube_id FROM calisma_lokasyonlari ORDER BY id ASC') as $row) {
            $locations[(int) $row['id']] = $row;
        }
        foreach ($spec->locationMappings() as $mapping) {
            $row = $locations[$mapping['calisma_lokasyonu_id']] ?? null;
            if ($row === null) {
                $blockers[] = 'MAPPING_LOCATION_MISSING';
                continue;
            }
            if ((string) $row['kod'] !== $mapping['expected_kod'] || (string) $row['ad'] !== $mapping['expected_ad']) {
                $blockers[] = 'MAPPING_LOCATION_PREIMAGE_MISMATCH';
                continue;
            }
            $current = self::nullableInt($row['sube_id']);
            if ($current !== $mapping['expected_sube_id']) {
                $blockers[] = 'MAPPING_LOCATION_PREIMAGE_MISMATCH';
                continue;
            }
            if ($current !== null && $mapping['target_sube_id'] !== null && $current !== $mapping['target_sube_id']) {
                $blockers[] = 'MAPPING_LOCATION_BRANCH_CONFLICT';
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * What the apply would change, derived from the spec against live rows. Used
     * by the preflight report so an operator sees the delta before approving it.
     *
     * @return array<string, mixed>
     */
    private static function plan(PDO $pdo, OrganizationMappingSpec $spec): array
    {
        $companyIds = self::companyIdsByKod($pdo);
        $companiesToCreate = [];
        foreach ($spec->companies() as $company) {
            if (!isset($companyIds[$company['kod']])) {
                $companiesToCreate[] = $company['kod'];
            }
        }

        $branchesToMap = [];
        $renames = [];
        $state = [];
        foreach (self::rows($pdo, 'SELECT id, ad, sirket_id FROM subeler ORDER BY id ASC') as $row) {
            $state[(int) $row['id']] = $row;
        }
        foreach ($spec->branchMappings() as $mapping) {
            $row = $state[$mapping['sube_id']] ?? null;
            if ($row !== null && self::nullableInt($row['sirket_id']) === null) {
                $branchesToMap[] = $mapping['sube_id'];
            }
            if ($mapping['approved_ad'] !== null && $row !== null && (string) $row['ad'] !== $mapping['approved_ad']) {
                $renames[] = $mapping['sube_id'];
            }
        }

        $sgkToMap = [];
        $sgkState = [];
        foreach (self::rows($pdo, 'SELECT id, sirket_id FROM sgk_isverenler ORDER BY id ASC') as $row) {
            $sgkState[(int) $row['id']] = $row;
        }
        foreach ($spec->sgkMappings() as $mapping) {
            $row = $sgkState[$mapping['sgk_isveren_id']] ?? null;
            if ($row !== null && self::nullableInt($row['sirket_id']) === null) {
                $sgkToMap[] = $mapping['sgk_isveren_id'];
            }
        }

        $locationsToMap = [];
        $deferred = [];
        foreach ($spec->locationMappings() as $mapping) {
            if ($mapping['target_sube_id'] === null) {
                $deferred[] = $mapping['calisma_lokasyonu_id'];
                continue;
            }
            $locationsToMap[] = $mapping['calisma_lokasyonu_id'];
        }

        return [
            'companies_to_create' => $companiesToCreate,
            'branches_to_map' => $branchesToMap,
            'approved_renames' => $renames,
            'sgk_to_map' => $sgkToMap,
            'locations_to_map' => $locationsToMap,
            'locations_deferred' => $deferred,
        ];
    }

    // -------------------------------------------------------------------- write

    /**
     * @param array<string, int> $applied
     * @return array<string, int> company id by kod
     */
    private static function ensureCompanies(PDO $pdo, OrganizationMappingSpec $spec, array &$applied): array
    {
        $existing = self::companyIdsByKod($pdo);
        foreach ($spec->companies() as $company) {
            if (isset($existing[$company['kod']])) {
                $applied['companies_reused']++;
                continue;
            }
            $statement = $pdo->prepare('INSERT INTO sirketler (kod, ad, durum) VALUES (:kod, :ad, :durum)');
            $statement->execute([
                'kod' => $company['kod'],
                'ad' => $company['ad'],
                'durum' => $company['durum'],
            ]);
            $existing[$company['kod']] = (int) $pdo->lastInsertId();
            $applied['companies_created']++;
        }

        return $existing;
    }

    /**
     * @param array<string, int> $companyIds
     * @param array<string, int> $applied
     */
    private static function mapSgkEmployers(
        PDO $pdo,
        OrganizationMappingSpec $spec,
        array $companyIds,
        array &$applied
    ): void {
        // Payroll employers first: a branch's company must be able to agree with
        // the company of the employer it already points at.
        $statement = $pdo->prepare(
            'UPDATE sgk_isverenler SET sirket_id = :sirket_id WHERE id = :id AND sirket_id IS NULL'
        );
        foreach ($spec->sgkMappings() as $mapping) {
            $companyId = $companyIds[$mapping['target_company_kod']] ?? null;
            if ($companyId === null) {
                throw OrganizationMappingFailure::of(
                    'MAPPING_COMPANY_UNRESOLVED',
                    'sgk_isveren_id=' . $mapping['sgk_isveren_id']
                );
            }
            $statement->execute(['sirket_id' => $companyId, 'id' => $mapping['sgk_isveren_id']]);
            if ($statement->rowCount() > 0) {
                $applied['sgk_mapped']++;
            } else {
                $applied['sgk_already_mapped']++;
            }
        }
    }

    /**
     * @param array<string, int> $companyIds
     * @param array<string, int> $applied
     */
    private static function mapBranches(
        PDO $pdo,
        OrganizationMappingSpec $spec,
        array $companyIds,
        array &$applied
    ): void {
        $mapStatement = $pdo->prepare(
            'UPDATE subeler SET sirket_id = :sirket_id WHERE id = :id AND sirket_id IS NULL'
        );
        // The rename is guarded by the expected name, so a branch whose name moved
        // after the inventory cannot be renamed on a stale assumption.
        $renameStatement = $pdo->prepare('UPDATE subeler SET ad = :ad WHERE id = :id AND ad = :expected_ad');

        foreach ($spec->branchMappings() as $mapping) {
            $companyId = $companyIds[$mapping['target_company_kod']] ?? null;
            if ($companyId === null) {
                throw OrganizationMappingFailure::of(
                    'MAPPING_COMPANY_UNRESOLVED',
                    'sube_id=' . $mapping['sube_id']
                );
            }
            $mapStatement->execute(['sirket_id' => $companyId, 'id' => $mapping['sube_id']]);
            if ($mapStatement->rowCount() > 0) {
                $applied['branches_mapped']++;
            } else {
                $applied['branches_already_mapped']++;
            }

            if ($mapping['approved_ad'] === null || $mapping['approved_ad'] === $mapping['expected_ad']) {
                continue;
            }
            $renameStatement->execute([
                'ad' => $mapping['approved_ad'],
                'id' => $mapping['sube_id'],
                'expected_ad' => $mapping['expected_ad'],
            ]);
            if ($renameStatement->rowCount() > 0) {
                $applied['branches_renamed']++;
            }
        }
    }

    /** @param array<string, int> $applied */
    private static function mapLocations(PDO $pdo, OrganizationMappingSpec $spec, array &$applied): void
    {
        $statement = $pdo->prepare(
            'UPDATE calisma_lokasyonlari SET sube_id = :sube_id WHERE id = :id AND sube_id IS NULL'
        );
        foreach ($spec->locationMappings() as $mapping) {
            if ($mapping['target_sube_id'] === null) {
                $applied['locations_deferred']++;
                continue;
            }
            $statement->execute(['sube_id' => $mapping['target_sube_id'], 'id' => $mapping['calisma_lokasyonu_id']]);
            if ($statement->rowCount() > 0) {
                $applied['locations_mapped']++;
            } else {
                $applied['locations_already_mapped']++;
            }
        }
    }

    /**
     * In-transaction readback. Every postcondition must hold before the commit;
     * one that does not rolls the whole operation back.
     *
     * @param array<string, int> $companyIds
     * @return array<string, mixed>
     */
    private static function assertPostconditions(
        PDO $pdo,
        OrganizationMappingSpec $spec,
        array $companyIds
    ): array {
        $branches = [];
        foreach (self::rows($pdo, 'SELECT id, kod, ad, sirket_id, sgk_isveren_id FROM subeler ORDER BY id ASC') as $row) {
            $branches[(int) $row['id']] = $row;
        }
        foreach ($spec->branchMappings() as $mapping) {
            $row = $branches[$mapping['sube_id']] ?? null;
            if ($row === null) {
                throw OrganizationMappingFailure::of('MAPPING_POST_BRANCH_MISSING', (string) $mapping['sube_id']);
            }
            if ((string) $row['kod'] !== $mapping['expected_kod']) {
                throw OrganizationMappingFailure::of('MAPPING_POST_BRANCH_KOD_CHANGED', (string) $mapping['sube_id']);
            }
            $expectedCompanyId = $companyIds[$mapping['target_company_kod']] ?? null;
            if ($expectedCompanyId === null || (int) $row['sirket_id'] !== $expectedCompanyId) {
                throw OrganizationMappingFailure::of('MAPPING_POST_BRANCH_UNMAPPED', (string) $mapping['sube_id']);
            }
            $expectedAd = $mapping['approved_ad'] ?? $mapping['expected_ad'];
            if ((string) $row['ad'] !== $expectedAd) {
                throw OrganizationMappingFailure::of('MAPPING_POST_BRANCH_AD_UNEXPECTED', (string) $mapping['sube_id']);
            }
            if (self::nullableInt($row['sgk_isveren_id']) !== $mapping['expected_sgk_isveren_id']) {
                throw OrganizationMappingFailure::of('MAPPING_POST_BRANCH_SGK_CHANGED', (string) $mapping['sube_id']);
            }
        }

        $employers = [];
        foreach (self::rows($pdo, 'SELECT id, sirket_id FROM sgk_isverenler ORDER BY id ASC') as $row) {
            $employers[(int) $row['id']] = $row;
        }
        foreach ($spec->sgkMappings() as $mapping) {
            $row = $employers[$mapping['sgk_isveren_id']] ?? null;
            $expectedCompanyId = $companyIds[$mapping['target_company_kod']] ?? null;
            if ($row === null || $expectedCompanyId === null || (int) $row['sirket_id'] !== $expectedCompanyId) {
                throw OrganizationMappingFailure::of(
                    'MAPPING_POST_SGK_UNMAPPED',
                    (string) $mapping['sgk_isveren_id']
                );
            }
        }

        $locations = [];
        foreach (self::rows($pdo, 'SELECT id, sube_id FROM calisma_lokasyonlari ORDER BY id ASC') as $row) {
            $locations[(int) $row['id']] = $row;
        }
        foreach ($spec->locationMappings() as $mapping) {
            if ($mapping['target_sube_id'] === null) {
                continue;
            }
            $row = $locations[$mapping['calisma_lokasyonu_id']] ?? null;
            if ($row === null || self::nullableInt($row['sube_id']) !== $mapping['target_sube_id']) {
                throw OrganizationMappingFailure::of(
                    'MAPPING_POST_LOCATION_UNMAPPED',
                    (string) $mapping['calisma_lokasyonu_id']
                );
            }
        }

        $rowCounts = self::rowCounts($pdo);
        $expectedCounts = $spec->expectedRowCounts();
        foreach (['subeler', 'sgk_isverenler', 'calisma_lokasyonlari', 'personeller', 'user_subeler', 'user_sirketler', 'user_sgk_isverenler'] as $table) {
            if ($rowCounts[$table] !== $expectedCounts[$table]) {
                throw OrganizationMappingFailure::of('MAPPING_POST_ROW_COUNT_CHANGED', $table);
            }
        }
        if ($rowCounts['sirketler'] !== count($spec->companies())) {
            throw OrganizationMappingFailure::of('MAPPING_POST_COMPANY_COUNT_UNEXPECTED');
        }

        $unmappedBranches = self::scalar($pdo, 'SELECT COUNT(*) FROM subeler WHERE sirket_id IS NULL');
        if ($unmappedBranches !== 0) {
            throw OrganizationMappingFailure::of('MAPPING_POST_BRANCH_UNMAPPED_PRESENT');
        }
        $unmappedSgk = self::scalar($pdo, 'SELECT COUNT(*) FROM sgk_isverenler WHERE sirket_id IS NULL');
        if ($unmappedSgk !== 0) {
            throw OrganizationMappingFailure::of('MAPPING_POST_SGK_UNMAPPED_PRESENT');
        }
        $mismatch = self::scalar(
            $pdo,
            'SELECT COUNT(*) FROM subeler s
             INNER JOIN sgk_isverenler e ON e.id = s.sgk_isveren_id
             WHERE s.sirket_id IS NOT NULL AND e.sirket_id IS NOT NULL AND e.sirket_id <> s.sirket_id'
        );
        if ($mismatch !== 0) {
            throw OrganizationMappingFailure::of('MAPPING_POST_BRANCH_SGK_MISMATCH');
        }

        return [
            'unmapped_sube_count' => $unmappedBranches,
            'unmapped_sgk_isveren_count' => $unmappedSgk,
            'sube_sgk_sirket_mismatch_count' => $mismatch,
            'row_counts' => $rowCounts,
        ];
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $evidence */
    private static function assertBackupEvidence(array $evidence): void
    {
        $file = (string) ($evidence['file'] ?? '');
        $sha256 = (string) ($evidence['sha256'] ?? '');
        if ($file === '' || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw OrganizationMappingFailure::of('MAPPING_BACKUP_EVIDENCE_MISSING');
        }
        if (($evidence['readback'] ?? '') !== 'VERIFIED') {
            throw OrganizationMappingFailure::of('MAPPING_BACKUP_NOT_VERIFIED');
        }
    }

    /** @return array<string, int> */
    private static function companyIdsByKod(PDO $pdo): array
    {
        $map = [];
        foreach (self::rows($pdo, 'SELECT id, kod FROM sirketler ORDER BY id ASC') as $row) {
            $map[(string) $row['kod']] = (int) $row['id'];
        }

        return $map;
    }

    /** @return array<int, string> */
    private static function companyNamesById(PDO $pdo): array
    {
        $map = [];
        foreach (self::rows($pdo, 'SELECT id, ad FROM sirketler ORDER BY id ASC') as $row) {
            $map[(int) $row['id']] = (string) $row['ad'];
        }

        return $map;
    }

    /** @return array<string, int> */
    private static function rowCounts(PDO $pdo): array
    {
        return [
            'sirketler' => self::scalar($pdo, 'SELECT COUNT(*) FROM sirketler'),
            'subeler' => self::scalar($pdo, 'SELECT COUNT(*) FROM subeler'),
            'sgk_isverenler' => self::scalar($pdo, 'SELECT COUNT(*) FROM sgk_isverenler'),
            'calisma_lokasyonlari' => self::scalar($pdo, 'SELECT COUNT(*) FROM calisma_lokasyonlari'),
            'personeller' => self::scalar($pdo, 'SELECT COUNT(*) FROM personeller'),
            'user_subeler' => self::scalar($pdo, 'SELECT COUNT(*) FROM user_subeler'),
            'user_sirketler' => self::scalar($pdo, 'SELECT COUNT(*) FROM user_sirketler'),
            'user_sgk_isverenler' => self::scalar($pdo, 'SELECT COUNT(*) FROM user_sgk_isverenler'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            return [];
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return is_array($rows) ? array_values($rows) : [];
    }

    private static function scalar(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            return -1;
        }
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value === false ? -1 : (int) $value;
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
