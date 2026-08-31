import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const read = (relativePath: string) => readFileSync(resolve(process.cwd(), relativePath), 'utf8');

const inventoryOwner = read('api/src/Services/Organizasyon/OrganizationMappingInventoryReport.php');
const specOwner = read('api/src/Services/Organizasyon/OrganizationMappingSpec.php');
const mappingOwner = read('api/src/Services/Organizasyon/OrganizationInitialMappingService.php');
const backupOwner = read('api/src/Database/MigrationBackupService.php');
const worker = read('api/bin/cpanel-migration-cron.php');
const inventoryWorkflow = read('.github/workflows/ops-organization-inventory.yml');
const mappingWorkflow = read('.github/workflows/apply-organization-mapping.yml');
const migrationsWorkflow = read('.github/workflows/apply-cpanel-migrations.yml');
const diagnosticsWorkflow = read('.github/workflows/ops-migration-worker-diagnostics.yml');
const publicService = read('api/src/Services/Organizasyon/OrganizasyonService.php');
const router = read('api/src/Router.php');

describe('read-only organization inventory owner', () => {
  it('never issues a write statement of any kind', () => {
    for (const forbidden of [
      /\bINSERT\s+INTO\b/i,
      /\bUPDATE\s+[a-z_]+\s+SET\b/i,
      /\bDELETE\s+FROM\b/i,
      /\bALTER\s+TABLE\b/i,
      /\bCREATE\s+(?:TABLE|INDEX|DATABASE)\b/i,
      /\bDROP\s+(?:TABLE|INDEX|DATABASE)\b/i,
      /\bTRUNCATE\b/i,
      /\bCALL\s+\w+\s*\(/i,
      /\bREPLACE\s+INTO\b/i,
      /\bGRANT\b/i,
    ]) {
      expect(inventoryOwner).not.toMatch(forbidden);
    }
    expect(inventoryOwner).not.toContain('->exec(');
    expect(inventoryOwner).not.toContain('beginTransaction');
  });

  it('accepts no caller-supplied SQL, table name or filter', () => {
    // Every query is a literal; the only interpolation a collector could abuse
    // would show up as a variable inside a query string.
    const queries = inventoryOwner.match(/self::(?:query|count)\(\s*\$pdo,\s*'[^']*'/gs) ?? [];
    expect(queries.length).toBeGreaterThan(8);
    for (const query of queries) {
      expect(query).not.toMatch(/\$(?!pdo)/);
    }
    expect(inventoryOwner).not.toMatch(/\$sql\s*=\s*['"].*\.\s*\$/);
  });

  it('publishes the allowlisted row fields for each owner', () => {
    for (const field of [
      "'sirket_id' =>",
      "'sgk_isveren_id' =>",
      "'personel_count' =>",
      "'calisma_lokasyonu_count' =>",
      "'user_sube_assignment_count' =>",
      "'linked_branch_ids' =>",
      "'linked_branch_count' =>",
      "'sube_id' =>",
    ]) {
      expect(inventoryOwner).toContain(field);
    }
    for (const field of [
      'branch_ids',
      'id_3_present',
      'baseline_branch_ids',
      'audited_extension_branch_ids',
      'unaudited_extension_branch_ids',
      'duplicate_extension_audit_branch_ids',
      'mismatched_extension_audit_branch_ids',
      'missing_baseline_branch_ids',
      'expected_branch_count',
      'branch_set_valid',
      'unexpected_branch_ids',
      'missing_branch_ids',
    ]) {
      expect(inventoryOwner).toContain(field);
    }
  });

  it('treats the 079 branch ids as a baseline, not as the set allowed to exist', () => {
    expect(inventoryOwner).toContain('private const BASELINE_BRANCH_IDS = [1, 2, 4, 5, 6, 7, 8, 9, 10, 11]');
    expect(inventoryOwner).not.toContain('DOCUMENTED_BRANCH_IDS = [');
    // 12 and 13 exist in production as audited extensions; hardcoding them would
    // reintroduce the same false blocker for the next legitimate branch.
    for (const forbidden of [/BRANCH_IDS = \[[^\]]*\b12\b/, /BRANCH_IDS = \[[^\]]*\b13\b/]) {
      expect(inventoryOwner).not.toMatch(forbidden);
    }
    // The expected set and the expected count are derived, never pinned.
    expect(inventoryOwner).toContain('$expected = array_merge(self::BASELINE_BRANCH_IDS, $audited)');
    expect(inventoryOwner).toContain("'expected_branch_count' => count($expected)");
  });

  it('proves an extension branch from the canonical create audit only', () => {
    expect(inventoryOwner).toContain('FROM sube_olusturma_auditleri a');
    expect(inventoryOwner).toContain('GROUP BY a.sube_id');
    // Exactly one audit row, and only the immutable identity columns are compared.
    expect(inventoryOwner).toContain("if ($audit['audit_rows'] > 1)");
    expect(inventoryOwner).toContain("$audit['kod'] !== $liveKod[$id]");
    expect(inventoryOwner).toContain("$audit['actor_user_id'] <= 0");
    // A missing audit owner cannot make an extension provable.
    expect(inventoryOwner).toContain('return null;');
    expect(inventoryOwner).toContain('$auditReady = $audits !== null;');
    expect(inventoryOwner).toContain('FORBIDDEN_BRANCH_IDS = [3]');
    // The justification text and the request hash stay out of the payload.
    expect(inventoryOwner).not.toContain('gerekce');
    expect(inventoryOwner).not.toContain('request_hash');
  });

  it('counts personnel and never selects a personal column', () => {
    expect(inventoryOwner).not.toContain('ad_soyad');
    expect(inventoryOwner).not.toContain('sicil_no');
    expect(inventoryOwner).not.toContain('tckn');
    expect(inventoryOwner).not.toContain('iban');
    expect(inventoryOwner).not.toMatch(/SELECT\s+\*/i);
    expect(inventoryOwner).toContain('SELECT COUNT(*) FROM personeller p WHERE p.sube_id = s.id');
  });

  it('summarises scope by role without naming a single user', () => {
    expect(inventoryOwner).toContain('GROUP BY u.rol');
    expect(inventoryOwner).toContain("'user_sirket_total' =>");
    expect(inventoryOwner).toContain("'user_sgk_isveren_total' =>");
    expect(inventoryOwner).not.toContain('u.username');
    // user_id may appear as a join key; what must never happen is a user
    // identifier reaching a published field.
    expect(inventoryOwner).not.toMatch(/'user_id'\s*=>/);
    expect(inventoryOwner).not.toMatch(/'username'\s*=>/);
    expect(inventoryOwner).not.toMatch(/SELECT\s+[^']*\bus\.user_id\b[^']*FROM/);
  });

  it('is deterministic: stable ordering plus a canonical-JSON checksum', () => {
    const orderedQueries = inventoryOwner.match(/ORDER BY/g) ?? [];
    expect(orderedQueries.length).toBeGreaterThanOrEqual(5);
    expect(inventoryOwner).toContain('ORDER BY s.id ASC');
    expect(inventoryOwner).toContain('ORDER BY e.id ASC');
    expect(inventoryOwner).toContain('ORDER BY l.id ASC');
    expect(inventoryOwner).toContain('ksort($value, SORT_STRING)');
    expect(inventoryOwner).toContain("hash('sha256', self::canonicalJson($data))");
  });

  it('keeps the volatile timestamp out of the checksummed section', () => {
    // generated_at sits beside `data`, never inside it: otherwise an unchanged
    // database would produce a new checksum on every read and no spec could pin it.
    expect(inventoryOwner).toMatch(/'generated_at' => gmdate/);
    expect(inventoryOwner).toContain("'inventory_checksum' => self::checksum($data)");
    const dataBlock = inventoryOwner.slice(
      inventoryOwner.indexOf('$data = ['),
      inventoryOwner.indexOf('$branchIds = '),
    );
    expect(dataBlock).not.toContain('generated_at');
  });

  it('fails closed on an unevaluable count instead of reporting zero', () => {
    expect(inventoryOwner).toContain('return -1;');
    expect(inventoryOwner).toContain('INVENTORY_COUNT_UNEVALUABLE');
    for (const blocker of [
      'INVENTORY_SCHEMA_NOT_READY',
      'UNEXPECTED_BRANCH_ID_3_PRESENT',
      'UNEXPECTED_BRANCH_IDS_PRESENT',
      'DUPLICATE_BRANCH_CREATE_AUDIT',
      'BRANCH_CREATE_AUDIT_MISMATCH',
      'BRANCH_CREATE_AUDIT_UNREADABLE',
      'BRANCH_SET_NOT_PROVABLE',
      'DOCUMENTED_BRANCH_IDS_MISSING',
      'BRANCH_SGK_COMPANY_MISMATCH',
    ]) {
      expect(inventoryOwner).toContain(blocker);
    }
  });

  it('publishes the anonymous location x branch personnel matrix', () => {
    expect(inventoryOwner).toContain("'personnel_location_branch_matrix' => self::personnelLocationBranchMatrix($pdo)");
    expect(inventoryOwner).toContain(
      "'personnel_without_location_by_branch' => self::personnelWithoutLocationByBranch($pdo)",
    );
    expect(inventoryOwner).toContain('WHERE p.calisma_lokasyonu_id IS NOT NULL');
    expect(inventoryOwner).toContain('GROUP BY p.calisma_lokasyonu_id, p.sube_id');
    expect(inventoryOwner).toContain('ORDER BY p.calisma_lokasyonu_id ASC, p.sube_id ASC');
    expect(inventoryOwner).toContain('WHERE p.calisma_lokasyonu_id IS NULL');
    expect(inventoryOwner).toContain('GROUP BY p.sube_id');
    expect(inventoryOwner).toContain('ORDER BY p.sube_id ASC');
  });

  it('emits only relation ids and a count in both new aggregates', () => {
    const matrixBlock = inventoryOwner.slice(
      inventoryOwner.indexOf('private static function personnelLocationBranchMatrix('),
      inventoryOwner.indexOf('private static function sumPersonelCount('),
    );
    expect(matrixBlock.length).toBeGreaterThan(0);
    const published = matrixBlock.match(/'[a-z_]+' =>/g) ?? [];
    expect([...new Set(published)].sort()).toEqual([
      "'calisma_lokasyonu_id' =>",
      "'personel_count' =>",
      "'sube_id' =>",
    ]);
    // The aggregate selects the two relation columns and COUNT(*), nothing else.
    expect(matrixBlock).not.toMatch(/SELECT\s+\*/i);
    for (const forbidden of ['p.id', 'ad_soyad', 'sicil', 'tckn', 'telefon', 'iban', 'user_id', 'username']) {
      expect(matrixBlock).not.toContain(forbidden);
    }
  });

  it('reconciles the matrix against the personnel row count and fails closed', () => {
    expect(inventoryOwner).toContain("'personnel_location_matrix_total'");
    expect(inventoryOwner).toContain("'personnel_without_location_total'");
    expect(inventoryOwner).toContain("'personnel_matrix_reconciled'");
    expect(inventoryOwner).toContain("=== $data['row_counts']['personeller']");
    expect(inventoryOwner).toContain('INVENTORY_PERSONNEL_MATRIX_COUNT_MISMATCH');
  });

  it('publishes the extended contract as inventory schema version 3', () => {
    expect(inventoryOwner).toContain("public const SCHEMA_VERSION = '3'");
  });

  it('publishes the branch set classification in the workflow log summary', () => {
    for (const label of [
      "emit_scalar BRANCH_CREATE_AUDIT_READY '.data.branch_create_audit_ready'",
      "emit_scalar BRANCH_SET_VALID '.data.branch_set_valid'",
      "emit_scalar EXPECTED_BRANCH_COUNT '.data.expected_branch_count'",
      "emit_list BASELINE_BRANCH_IDS '.data.baseline_branch_ids'",
      "emit_list AUDITED_EXTENSION_BRANCH_IDS '.data.audited_extension_branch_ids'",
      "emit_list UNAUDITED_EXTENSION_BRANCH_IDS '.data.unaudited_extension_branch_ids'",
      "emit_list DUPLICATE_EXTENSION_AUDIT_BRANCH_IDS '.data.duplicate_extension_audit_branch_ids'",
      "emit_list MISSING_BASELINE_BRANCH_IDS '.data.missing_baseline_branch_ids'",
    ]) {
      expect(inventoryWorkflow).toContain(label);
    }
    // The workflow stays a transport and log owner: the rule lives in the report.
    expect(inventoryWorkflow).not.toContain('sube_olusturma_auditleri');
    expect(inventoryWorkflow).not.toMatch(/BASELINE_BRANCH_IDS=\s*"?1,2,4/);
  });

  it('leaves the mapping and spec owner schema versions untouched', () => {
    expect(specOwner).toContain("public const SCHEMA_VERSION = '1'");
    expect(mappingOwner).toContain("public const SCHEMA_VERSION = '1'");
    const fixture = JSON.parse(read('tests/fixtures/organization-mapping-spec.test-only.json'));
    expect(fixture.schema_version).toBe('1');
  });

  it('keeps the matrix out of the workflow log and inside the private artifact', () => {
    for (const field of [
      'personnel_location_branch_matrix',
      'personnel_without_location_by_branch',
      'personnel_location_matrix_total',
      'personnel_without_location_total',
    ]) {
      expect(inventoryWorkflow).not.toContain(field);
    }
    expect(inventoryWorkflow).not.toContain('cat "$report"');
    expect(inventoryWorkflow).toContain('actions/upload-artifact@v6');
    expect(inventoryWorkflow).toContain('if: always() && github.event.repository.private == true');
  });

  it('does not extend the migration preflight report contract', () => {
    const preflight = read('api/src/Database/MigrationPreflightReport.php');
    expect(preflight).not.toContain('OrganizationMappingInventoryReport');
    expect(preflight).not.toContain('linked_branch_ids');
    expect(preflight).not.toContain('scope_summary');
  });
});

describe('mapping spec owner', () => {
  it('rejects an unknown field anywhere in the document', () => {
    expect(specOwner).toContain('SPEC_UNKNOWN_FIELD');
    const rejectCalls = specOwner.match(/self::rejectUnknown\(/g) ?? [];
    // top level, metadata, expected_row_counts, company, sgk, branch, location, preservation
    expect(rejectCalls.length).toBeGreaterThanOrEqual(8);
  });

  it('refuses duplicates on every axis', () => {
    for (const reason of [
      'SPEC_DUPLICATE_COMPANY_KOD',
      'SPEC_DUPLICATE_COMPANY_AD',
      'SPEC_DUPLICATE_SGK_MAPPING',
      'SPEC_DUPLICATE_BRANCH_MAPPING',
      'SPEC_DUPLICATE_LOCATION_MAPPING',
      'SPEC_DUPLICATE_BRANCH_ID',
    ]) {
      expect(specOwner).toContain(reason);
    }
  });

  it('refuses branch id 3 and an incomplete branch decision', () => {
    expect(specOwner).toContain('FORBIDDEN_BRANCH_IDS = [3]');
    expect(specOwner).toContain('SPEC_FORBIDDEN_BRANCH_ID');
    expect(specOwner).toContain('SPEC_BRANCH_MAPPING_INCOMPLETE');
    expect(specOwner).toContain('SPEC_BRANCH_MAPPING_UNEXPECTED');
  });

  it('keeps branch and payroll company decisions consistent', () => {
    expect(specOwner).toContain('SPEC_SGK_MAPPING_INCOMPLETE');
    expect(specOwner).toContain('SPEC_BRANCH_SGK_COMPANY_CONFLICT');
  });

  it('supports a deferred, explicitly unresolved work location', () => {
    expect(specOwner).toContain("'target_sube_id' => self::nullableId($row, 'target_sube_id', $path)");
    expect(specOwner).toContain('deferred, verified, and explicitly not guessed');
    // location_mappings is the one optional section: a spec without it is valid.
    expect(specOwner).toContain("array_key_exists('location_mappings', $raw)");
  });

  it('requires a pinned inventory checksum, sha and tip', () => {
    expect(specOwner).toContain("'/^[a-f0-9]{64}$/'");
    expect(specOwner).toContain("'/^[a-f0-9]{40}$/'");
    expect(specOwner).toContain("'/^[0-9]{3}$/'");
    expect(specOwner).toContain('operation_id');
  });

  it('carries no production value and no personnel or scope field', () => {
    // `Medisa\Api\...` is the vendor namespace, so company names are checked
    // against the body below the namespace declaration.
    const body = specOwner.slice(specOwner.indexOf('final class'));
    for (const forbidden of ['Medisa', 'Karyapı', 'Şenay', 'personel_id', 'user_id', 'tam_ad']) {
      expect(body).not.toContain(forbidden);
    }
    // Companies are addressed by their stable code, never by a raw id the spec
    // author would have to guess.
    expect(specOwner).toContain('target_company_kod');
    expect(specOwner).not.toContain('target_company_id');
  });
});

describe('operations-only initial mapping owner', () => {
  it('fills only NULL relations, so a mapped row is never overwritten', () => {
    expect(mappingOwner).toContain('UPDATE sgk_isverenler SET sirket_id = :sirket_id WHERE id = :id AND sirket_id IS NULL');
    expect(mappingOwner).toContain('UPDATE subeler SET sirket_id = :sirket_id WHERE id = :id AND sirket_id IS NULL');
    expect(mappingOwner).toContain('UPDATE calisma_lokasyonlari SET sube_id = :sube_id WHERE id = :id AND sube_id IS NULL');
  });

  it('refuses to re-parent a branch that already belongs to another company', () => {
    expect(mappingOwner).toContain('MAPPING_BRANCH_COMPANY_CONFLICT');
    expect(mappingOwner).toContain('MAPPING_SGK_COMPANY_CONFLICT');
    expect(mappingOwner).toContain('MAPPING_LOCATION_BRANCH_CONFLICT');
    expect(mappingOwner).toContain('this owner refuses to perform one');
  });

  it('renames only under an approved short name and an exact expected name', () => {
    expect(mappingOwner).toContain('UPDATE subeler SET ad = :ad WHERE id = :id AND ad = :expected_ad');
    expect(mappingOwner).toContain("if (\$mapping['approved_ad'] === null");
  });

  it('never writes a personnel column, a user scope row, tam_ad or a branch code', () => {
    expect(mappingOwner).not.toMatch(/UPDATE personeller/i);
    expect(mappingOwner).not.toMatch(/INSERT INTO personeller/i);
    expect(mappingOwner).not.toMatch(/INSERT INTO user_sirketler/i);
    expect(mappingOwner).not.toMatch(/INSERT INTO user_sgk_isverenler/i);
    expect(mappingOwner).not.toMatch(/INSERT INTO user_subeler/i);
    expect(mappingOwner).not.toMatch(/DELETE FROM/i);
    // The docblock names tam_ad to say it is never written; no statement may.
    expect(mappingOwner).not.toMatch(/tam_ad\s*=/);
    expect(mappingOwner).not.toMatch(/SET kod =/);
    // The only INSERT is the company row the spec approved.
    const inserts = mappingOwner.match(/INSERT INTO \w+/g) ?? [];
    expect(inserts).toEqual(['INSERT INTO sirketler']);
  });

  it('gates on sha, tip, pending count, inventory checksum and preimage', () => {
    for (const reason of [
      'MAPPING_DEPLOY_SHA_MISMATCH',
      'MAPPING_PROD_TIP_UNEXPECTED',
      'MAPPING_PENDING_MIGRATION_PRESENT',
      'MAPPING_INVENTORY_CHECKSUM_MISMATCH',
      'MAPPING_INVENTORY_NOT_PASS',
      'MAPPING_SCHEMA_NOT_READY',
      'MAPPING_ROW_COUNT_MISMATCH',
      'MAPPING_PRESERVATION_COUNT_MISMATCH',
      'MAPPING_BRANCH_ID_SET_MISMATCH',
      'MAPPING_BRANCH_PREIMAGE_MISMATCH',
      'MAPPING_SGK_PREIMAGE_MISMATCH',
      'MAPPING_LOCATION_PREIMAGE_MISMATCH',
      'MAPPING_COMPANY_CODE_NAME_CONFLICT',
      'MAPPING_COMPANY_NAME_TAKEN',
    ]) {
      expect(mappingOwner).toContain(reason);
    }
    expect(mappingOwner).toContain('hash_equals($spec->authorizedDeploySha()');
  });

  it('recomputes the inventory checksum instead of trusting the reported field', () => {
    expect(mappingOwner).toContain('OrganizationMappingInventoryReport::checksum($inventoryData)');
    expect(mappingOwner).not.toContain("$inventory['inventory_checksum']");
  });

  it('runs the same gate again inside the transaction', () => {
    const applyBlock = mappingOwner.slice(
      mappingOwner.indexOf('$pdo->beginTransaction();'),
      mappingOwner.indexOf('$pdo->commit();'),
    );
    expect(applyBlock).toContain('self::gate(');
    expect(applyBlock).toContain('MAPPING_STATE_CHANGED');
  });

  it('reads back every postcondition before the commit and rolls back otherwise', () => {
    const commitIndex = mappingOwner.indexOf('$pdo->commit();');
    const assertIndex = mappingOwner.indexOf('$postconditions = self::assertPostconditions(');
    expect(assertIndex).toBeGreaterThan(0);
    expect(commitIndex).toBeGreaterThan(assertIndex);
    expect(mappingOwner).toContain('$pdo->rollBack();');
    for (const reason of [
      'MAPPING_POST_BRANCH_UNMAPPED',
      'MAPPING_POST_BRANCH_KOD_CHANGED',
      'MAPPING_POST_BRANCH_AD_UNEXPECTED',
      'MAPPING_POST_BRANCH_SGK_CHANGED',
      'MAPPING_POST_SGK_UNMAPPED',
      'MAPPING_POST_LOCATION_UNMAPPED',
      'MAPPING_POST_ROW_COUNT_CHANGED',
      'MAPPING_POST_BRANCH_SGK_MISMATCH',
    ]) {
      expect(mappingOwner).toContain(reason);
    }
  });

  it('cannot start without verified backup evidence', () => {
    expect(mappingOwner).toContain('self::assertBackupEvidence($backupEvidence);');
    expect(mappingOwner).toContain('MAPPING_BACKUP_EVIDENCE_MISSING');
    expect(mappingOwner).toContain('MAPPING_BACKUP_NOT_VERIFIED');
    const applyBody = mappingOwner.slice(mappingOwner.indexOf('public static function apply('));
    expect(applyBody.indexOf('assertBackupEvidence')).toBeLessThan(applyBody.indexOf('beginTransaction'));
  });

  it('proves readiness and the zero mismatch count in the postcheck', () => {
    expect(mappingOwner).toContain("'data_ready' => (bool) \$readiness['data_ready']");
    expect(mappingOwner).toContain('sube_sgk_sirket_mismatch_count');
    expect(mappingOwner).toContain("'unexpected_deltas' =>");
    expect(mappingOwner).toContain("'backup_reference' =>");
  });

  it('has no controller, no route and no UI entry point', () => {
    expect(router).not.toContain('OrganizationInitialMappingService');
    expect(router).not.toContain('organization-mapping');
    const controller = read('api/src/Controllers/OrganizasyonController.php');
    expect(controller).not.toContain('OrganizationInitialMappingService');
    expect(controller).not.toContain('OrganizationMappingSpec');
  });
});

describe('the public management API keeps its re-parenting ban', () => {
  it('still refuses a company in the payload and a cross-company branch', () => {
    expect(publicService).toContain('rejectPayloadSirketId');
    expect(publicService).toContain('Şirket bağlamı route üzerinden gelir; payload içinde sirket_id gönderilemez.');
    expect(publicService).toContain('assertBelongsToSirket');
    expect(publicService).toContain('Şube bu şirkete bağlı değil.');
  });

  it('still never writes sirket_id on a branch update', () => {
    const updateSube = publicService.slice(
      publicService.indexOf('public static function updateSube('),
      publicService.indexOf('public static function deleteSube('),
    );
    expect(updateSube).toContain("$sets = ['ad = :ad', 'durum = :durum'];");
    expect(updateSube).not.toContain("$sets[] = 'sirket_id");
    expect(updateSube).toContain('UPDATE subeler SET ');
  });

  it('does not gain a mapping owner import', () => {
    expect(publicService).not.toContain('OrganizationInitialMappingService');
    expect(publicService).not.toContain('OrganizationMappingSpec');
  });
});

describe('mapping backup owner', () => {
  it('reuses the existing dump owner instead of a parallel system', () => {
    expect(backupOwner).toContain('createForOrganizationMapping');
    expect(backupOwner).toContain('MAPPING_BACKED_UP_TABLES');
    for (const table of [
      "'sirketler'",
      "'subeler'",
      "'sgk_isverenler'",
      "'calisma_lokasyonlari'",
      "'user_subeler'",
      "'user_sirketler'",
      "'user_sgk_isverenler'",
      "'medisa_schema_migrations'",
    ]) {
      expect(backupOwner).toContain(table);
    }
  });

  it('keeps the webroot-outside, 0600, checksum and readback rules', () => {
    expect(backupOwner).toContain('BACKUP_LOCATION_INSIDE_WEBROOT');
    expect(backupOwner).toContain('@chmod($path, 0600)');
    expect(backupOwner).toContain('BACKUP_CHECKSUM_MISMATCH');
    expect(backupOwner).toContain('BACKUP_READBACK_INCOMPLETE');
    expect(backupOwner).toContain("'readback' => 'VERIFIED'");
  });

  it('pins the operation, the authorized sha and both checksums in the manifest', () => {
    for (const field of [
      "'operation' => 'ORGANIZATION_INITIAL_MAPPING'",
      "'operation_id' => $operationId",
      "'authorized_deploy_sha' => strtolower($authorizedSha)",
      "'inventory_checksum' => $inventoryChecksum",
      "'spec_checksum' => $specChecksum",
      "'schema_objects' =>",
    ]) {
      expect(backupOwner).toContain(field);
    }
  });

  it('never deletes a dump', () => {
    expect(backupOwner).not.toMatch(/unlink\([^)]*\$path/);
  });
});

describe('control-plane worker modes', () => {
  it('accepts exactly the five canonical modes', () => {
    expect(worker).toContain('/^(APPLY|READ_ONLY_PREFLIGHT|READ_ONLY_ORGANIZATION_INVENTORY');
    expect(worker).toContain("|ORGANIZATION_MAPPING_PREFLIGHT|ORGANIZATION_MAPPING_APPLY)$/'");
  });

  it('pins every mode to the exact deployed sha with hash_equals', () => {
    expect(worker).toContain('hash_equals($publishedSha, $deployedSha)');
    expect(worker).toContain('DEPLOY_SHA_MISMATCH');
  });

  it('exits the inventory mode before any backup or mutation stage exists', () => {
    const inventoryBlock = worker.slice(
      worker.indexOf("if ($mode === 'READ_ONLY_ORGANIZATION_INVENTORY') {"),
      worker.indexOf("if ($mode === 'ORGANIZATION_MAPPING_PREFLIGHT') {"),
    );
    expect(inventoryBlock).toContain('OrganizationMappingInventoryReport::collect');
    expect(inventoryBlock).toContain('exit(0);');
    expect(inventoryBlock).not.toContain('MigrationBackupService');
    expect(inventoryBlock).not.toContain('MigrationExecutionService::apply');
    expect(inventoryBlock).not.toContain('OrganizationInitialMappingService::apply');
  });

  it('never mutates on the mapping preflight mode either', () => {
    const preflightBlock = worker.slice(
      worker.indexOf("if ($mode === 'ORGANIZATION_MAPPING_PREFLIGHT') {"),
      worker.indexOf("if ($mode === 'ORGANIZATION_MAPPING_APPLY') {"),
    );
    expect(preflightBlock).toContain('OrganizationInitialMappingService::preflight');
    expect(preflightBlock).not.toContain('OrganizationInitialMappingService::apply');
    expect(preflightBlock).not.toContain('MigrationBackupService');
  });

  it('orders the mapping apply as preflight then backup then apply then postcheck', () => {
    const applyBlock = worker.slice(worker.indexOf("if ($mode === 'ORGANIZATION_MAPPING_APPLY') {"));
    const preflight = applyBlock.indexOf('OrganizationInitialMappingService::preflight');
    const backup = applyBlock.indexOf('MigrationBackupService::createForOrganizationMapping');
    const apply = applyBlock.indexOf('OrganizationInitialMappingService::apply');
    const postcheck = applyBlock.indexOf('OrganizationInitialMappingService::postcheck');

    expect(preflight).toBeGreaterThan(0);
    expect(backup).toBeGreaterThan(preflight);
    expect(apply).toBeGreaterThan(backup);
    expect(postcheck).toBeGreaterThan(apply);
  });

  it('reads the pinned inventory artifact instead of collecting a fresh one', () => {
    expect(worker).toContain('readPublishedInventory($inventoryPath)');
    expect(worker).toContain('MAPPING_INVENTORY_ARTIFACT_MISSING');
    const applyBlock = worker.slice(worker.indexOf("if ($mode === 'ORGANIZATION_MAPPING_APPLY') {"));
    expect(applyBlock).not.toContain('OrganizationMappingInventoryReport::collect');
  });

  it('keeps the idle/concurrency guard and the untouched worker.lock contract', () => {
    expect(worker).toContain('flock($lockHandle, LOCK_EX | LOCK_NB)');
    expect(worker).toContain('STALE_PROCESSING_REQUEST');
    expect(worker).not.toMatch(/unlink\(\$lockPath\)/);
  });

  it('is replay-safe: a claimed request is renamed once and archived by id', () => {
    expect(worker).toContain("'/request.processing.'");
    expect(worker).toContain("'/request.completed.' . safeId($requestId)");
    expect(worker).toContain("'/request.failed.' . safeId($requestId)");
  });

  it('publishes a precise reason code for every new stage', () => {
    for (const reason of [
      'ORGANIZATION_INVENTORY_FAILED',
      'ORGANIZATION_MAPPING_PREFLIGHT_FAILED',
      'ORGANIZATION_MAPPING_BACKUP_FAILED',
      'ORGANIZATION_MAPPING_APPLY_FAILED',
      'ORGANIZATION_MAPPING_POSTCHECK_FAILED',
    ]) {
      expect(worker).toContain(reason);
    }
    expect(worker).toContain('$exception instanceof OrganizationMappingFailure');
  });

  it('accepts no SQL and no table name from the request', () => {
    expect(worker).not.toMatch(/requireString\(\$request,\s*'(?:sql|table|query)'/);
    expect(worker).not.toMatch(/\$pdo->(?:exec|query)\([^)]*\$request/);
  });
});

describe('workflow separation', () => {
  it('gives the inventory workflow one read-only mode and no apply authority', () => {
    expect(inventoryWorkflow).toContain('MODE: READ_ONLY_ORGANIZATION_INVENTORY');
    expect(inventoryWorkflow).toContain('mode: "READ_ONLY_ORGANIZATION_INVENTORY"');
    expect(inventoryWorkflow).toContain('test "$CONFIRMATION" = "$MODE"');
    expect(inventoryWorkflow).not.toContain('ORGANIZATION_MAPPING_APPLY');
    expect(inventoryWorkflow).not.toContain('MigrationBackupService');
    expect(inventoryWorkflow).not.toMatch(/\b(?:mysql|psql|sqlite3|phpmyadmin)\b/i);
    expect(inventoryWorkflow).not.toMatch(/put[^\n]*api\/migrations/);
  });

  it('keeps the row-level inventory out of the log and in an artifact', () => {
    expect(inventoryWorkflow).toContain('actions/upload-artifact@v6');
    expect(inventoryWorkflow).toContain('name: organization-inventory');
    expect(inventoryWorkflow).not.toContain('cat "$report"');
    expect(inventoryWorkflow).not.toContain('.data.branches');
    expect(inventoryWorkflow).not.toContain('.data.work_locations');
    expect(inventoryWorkflow).toContain('emit_scalar INVENTORY_CHECKSUM');
    expect(inventoryWorkflow).toContain('UNPRINTABLE');
  });

  it('shares the single control-plane concurrency group', () => {
    for (const workflow of [inventoryWorkflow, mappingWorkflow]) {
      expect(workflow).toContain('group: cpanel-canonical-migration-control');
      expect(workflow).toContain('cancel-in-progress: false');
      expect(workflow).toContain('MIGRATION_CONTROL_PLANE_BUSY');
    }
  });

  it('requires an explicit confirmation, sha, spec path and checksum to map', () => {
    expect(mappingWorkflow).toContain('test "$CONFIRMATION" = "$MODE"');
    expect(mappingWorkflow).toContain('DEPLOYED_SHA_INPUT_INVALID');
    expect(mappingWorkflow).toContain('INVENTORY_CHECKSUM_INPUT_INVALID');
    expect(mappingWorkflow).toContain('^ops/organization-mapping/[A-Za-z0-9._-]+\\.json$');
    expect(mappingWorkflow).toContain('SPEC_PATH_NOT_ALLOWED');
  });

  it('cannot map unless the code is deployed and the pinned inventory matches', () => {
    for (const reason of [
      'DEPLOY_SHA_MISMATCH',
      'REMOTE_WORKER_PARITY_MISMATCH',
      'WORKER_MAPPING_BACKUP_STAGE_MISSING',
      'INVENTORY_ARTIFACT_MISSING',
      'INVENTORY_CHECKSUM_MISMATCH',
      'INVENTORY_NOT_PASS',
      'INVENTORY_SHA_MISMATCH',
      'SPEC_DEPLOY_SHA_MISMATCH',
      'SPEC_INVENTORY_CHECKSUM_MISMATCH',
      'SPEC_FORBIDDEN_FIELD',
      'SPEC_FORBIDDEN_BRANCH_ID',
      'MAPPING_BACKUP_NOT_VERIFIED',
      'MAPPING_POSTCHECK_NOT_PASS',
    ]) {
      expect(mappingWorkflow).toContain(reason);
    }
  });

  it('proves the preflight mode mutates nothing', () => {
    expect(mappingWorkflow).toContain('MAPPING_RESULT=PREFLIGHT_PASS');
    expect(mappingWorkflow).toContain('PRODUCTION_MUTATION_COUNT=0');
  });

  it('never carries direct SQL or edits files on the server', () => {
    expect(mappingWorkflow).not.toMatch(/\b(?:mysql|psql|sqlite3|phpmyadmin)\b/i);
    expect(mappingWorkflow).not.toMatch(/put[^\n]*api\/migrations/);
    expect(mappingWorkflow).not.toMatch(/put[^\n]*api\/src/);
  });
});

describe('first-party action runtime pins', () => {
  const checkoutOwners = [
    migrationsWorkflow,
    diagnosticsWorkflow,
    inventoryWorkflow,
    mappingWorkflow,
  ];

  it('checks out with the Node 24 major on every control-plane workflow', () => {
    for (const workflow of checkoutOwners) {
      expect(workflow).toContain('uses: actions/checkout@v6');
      expect(workflow).not.toContain('actions/checkout@v4');
      expect(workflow).not.toContain('actions/checkout@v5');
    }
  });

  it('uploads artifacts with the Node 24 major on both publication owners', () => {
    for (const workflow of [inventoryWorkflow, mappingWorkflow]) {
      expect(workflow).toContain('uses: actions/upload-artifact@v6');
      expect(workflow).not.toContain('actions/upload-artifact@v4');
    }
  });
});

describe('publication boundary (repository visibility)', () => {
  const ownersDoc = read('docs/guncel/129-organization-mapping-owners.md');
  const opsReadme = read('ops/organization-mapping/README.md');
  const guardStep = '- name: Validate repository publication boundary';
  const guardCheck = 'if [ "${REPOSITORY_PRIVATE:-}" != "true" ]; then';

  it('reads the canonical repository visibility instead of an input or a hardcoded value', () => {
    for (const workflow of [inventoryWorkflow, mappingWorkflow]) {
      expect(workflow).toContain('REPOSITORY_PRIVATE: ${{ github.event.repository.private }}');
      expect(workflow).toContain(guardCheck);
      expect(workflow).not.toContain('inputs.repository_private');
      expect(workflow).not.toContain('inputs.allow_public');
      expect(workflow).not.toContain('accept_public_exposure');
      expect(workflow).not.toMatch(/REPOSITORY_PRIVATE:\s*(?:"?true"?|"?false"?)\s*$/m);
    }
  });

  it('stops the whole inventory operation before any control-plane request', () => {
    const guardAt = inventoryWorkflow.indexOf(guardStep);
    expect(guardAt).toBeGreaterThan(-1);
    expect(inventoryWorkflow).toContain('INVENTORY_REASON=PUBLIC_REPOSITORY_ARTIFACT_EXPOSURE');
    for (const later of [
      '- name: Checkout repository',
      '- name: Collect row-level organization inventory',
      'request.pending.${REQUEST_ID}.json',
      'actions/upload-artifact@v6',
    ]) {
      expect(inventoryWorkflow.indexOf(later)).toBeGreaterThan(guardAt);
    }
  });

  it('stops the whole mapping operation before the spec reaches production', () => {
    const guardAt = mappingWorkflow.indexOf(guardStep);
    expect(guardAt).toBeGreaterThan(-1);
    expect(mappingWorkflow).toContain('MAPPING_REASON=PUBLIC_REPOSITORY_SPEC_TRANSPORT_UNSAFE');
    for (const later of [
      '- name: Checkout repository',
      '- name: Verify the approved mapping spec',
      'request.pending.${REQUEST_ID}.json',
      'actions/upload-artifact@v6',
    ]) {
      expect(mappingWorkflow.indexOf(later)).toBeGreaterThan(guardAt);
    }
  });

  it('keeps the artifact upload unreachable once the boundary blocks', () => {
    for (const workflow of [inventoryWorkflow, mappingWorkflow]) {
      expect(workflow).toContain('if: always() && github.event.repository.private == true');
      expect(workflow).not.toMatch(/^\s{8}if: always\(\)\s*$/m);
    }
  });

  it('preserves the controlled artifact path for a private repository', () => {
    expect(inventoryWorkflow).toContain('name: organization-inventory');
    expect(inventoryWorkflow).toContain('retention-days: 7');
    expect(mappingWorkflow).toContain('name: organization-mapping-evidence');
    expect(mappingWorkflow).toContain('retention-days: 14');
  });

  it('does not treat retention as the security control', () => {
    for (const workflow of [inventoryWorkflow, mappingWorkflow]) {
      expect(workflow).not.toMatch(/retention-days: [01]\s*$/m);
    }
  });

  it('adds no raw row-data log fallback while blocking', () => {
    for (const workflow of [inventoryWorkflow, mappingWorkflow]) {
      expect(workflow).not.toContain('cat "$report"');
      expect(workflow).not.toContain('cat "$SPEC_PATH"');
      expect(workflow).not.toContain('.data.branches');
      expect(workflow).not.toContain('.data.sgk_employers');
      expect(workflow).not.toContain('.data.work_locations');
    }
  });

  it('never publishes the backup dump or an absolute backup path', () => {
    expect(mappingWorkflow).not.toMatch(/get[^\n]*\.sql/);
    expect(mappingWorkflow).not.toContain('backup_path');
    expect(mappingWorkflow).toContain("emit_scalar MAPPING_BACKUP_FILE \"$status_file\" '.backup_file' '^[A-Za-z0-9._-]{1,160}$'");
    expect(backupOwner).not.toContain("'path' => $absolutePath");
  });

  it('documents that confidentiality depends on repository visibility', () => {
    expect(inventoryWorkflow).not.toContain('private repository erisim');
    for (const doc of [ownersDoc, opsReadme]) {
      expect(doc).toContain('PUBLIC_REPOSITORY_ARTIFACT_EXPOSURE');
      expect(doc).toContain('PUBLIC_REPOSITORY_SPEC_TRANSPORT_UNSAFE');
      expect(doc).toContain('repository visibility');
    }
  });

  it('keeps real production inventory rows and checksums out of the repository docs', () => {
    for (const doc of [ownersDoc, opsReadme]) {
      expect(doc).not.toMatch(/\b[0-9a-f]{64}\b/);
      expect(doc).not.toMatch(/personel_count["`]?\s*[:=]\s*\d/);
      expect(doc).not.toMatch(/linked_branch_ids["`]?\s*[:=]/);
      expect(doc).not.toMatch(/"kod"\s*:\s*"[^"]+"/);
    }
  });
});

describe('test-only mapping spec fixture', () => {
  const fixture = JSON.parse(read('tests/fixtures/organization-mapping-spec.test-only.json'));

  it('is valid in shape and carries no production value', () => {
    expect(fixture.schema_version).toBe('1');
    expect(fixture.metadata.operation_id).toContain('TEST-ONLY');
    const serialized = JSON.stringify(fixture);
    for (const production of ['Medisa', 'Karyapı', 'Şenay']) {
      expect(serialized).not.toContain(production);
    }
    for (const company of fixture.companies) {
      expect(company.kod).toMatch(/^TESTCO-/);
    }
  });

  it('covers exactly the expected branch ids, gap included, and never id 3', () => {
    const mapped = fixture.branch_mappings.map((entry: { sube_id: number }) => entry.sube_id);
    expect(mapped).toEqual(fixture.metadata.expected_branch_ids);
    expect(mapped).not.toContain(3);
  });

  it('exercises both an approved rename and a deferred location', () => {
    expect(fixture.branch_mappings.some((entry: { approved_ad: string | null }) => entry.approved_ad !== null)).toBe(true);
    expect(fixture.branch_mappings.some((entry: { approved_ad: string | null }) => entry.approved_ad === null)).toBe(true);
    expect(fixture.location_mappings.some((entry: { target_sube_id: number | null }) => entry.target_sube_id === null)).toBe(true);
  });
});

describe('the approved production decision is documented, not coded', () => {
  const readme = read('ops/organization-mapping/README.md');

  it('records the binding company and branch targets', () => {
    for (const company of ['Medisa', 'Karyapı', 'Şenay Mobilya']) {
      expect(readme).toContain(company);
    }
    for (const target of ['`Fabrika`', '`Giresun`', '`Kayseri`', '`Ankara`', '`İstanbul`']) {
      expect(readme).toContain(target);
    }
  });

  it('records what must not be guessed', () => {
    expect(readme).toContain('ID 3 **oluşturulmaz**');
    expect(readme).toContain('ID 7 ve 11 için kısa ad **tahmin edilmez**');
    expect(readme).toContain('deferred');
    expect(readme).toContain('User scope');
  });

  it('holds no real production spec yet', () => {
    expect(readme).toContain('**gerçek bir production spec içermez**');
  });
});
