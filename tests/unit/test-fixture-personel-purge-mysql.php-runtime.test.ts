import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner,
} from "../scripts/disposable-mariadb.mjs";

const root = process.cwd();
const runnerPath = resolve(root, "tests/php/TestFixturePersonelPurgeMysqlTestRunner.php");

beforeAll(async () => {
  await ensureDisposableMariaDbEnv();
});

describe("test fixture personel purge owner", () => {
  it("locks source contracts for fail-closed purge + create-PASIF deny", () => {
    const purgeSrc = readFileSync(
      resolve(root, "api/src/Services/Personel/TestFixturePersonelPurgeService.php"),
      "utf8",
    );
    const controllerSrc = readFileSync(
      resolve(root, "api/src/Controllers/TestFixturePersonelPurgeController.php"),
      "utf8",
    );
    const routerSrc = readFileSync(resolve(root, "api/src/Router.php"), "utf8");
    const permissionsSrc = readFileSync(resolve(root, "api/src/Auth/RolePermissions.php"), "utf8");
    const validatorSrc = readFileSync(
      resolve(root, "api/src/Services/Personel/PersonelCanonicalValidator.php"),
      "utf8",
    );
    const incompleteSrc = readFileSync(
      resolve(root, "api/src/Services/Personel/PersonelIncompleteCreateService.php"),
      "utf8",
    );

    expect(routerSrc).toContain("/personeller/(\\d+)/test-fixture-purge");
    expect(controllerSrc).toContain("personeller.test_fixture.purge");
    expect(controllerSrc).toContain("dry_run");
    expect(controllerSrc).not.toContain("/personeller/delete");
    expect(permissionsSrc).toContain("'personeller.test_fixture.purge'");
    expect(permissionsSrc).toMatch(
      /'GENEL_YONETICI'[\s\S]*'personeller\.test_fixture\.purge'/,
    );
    expect(permissionsSrc).toMatch(
      /'IK_SORUMLUSU'[\s\S]*'personeller\.test_fixture\.purge'/,
    );
    expect(permissionsSrc).not.toMatch(
      /'MUHASEBE'[\s\S]{0,800}'personeller\.test_fixture\.purge'/,
    );
    expect(purgeSrc).toContain("REAL_EMPLOYEE_OR_UNCLASSIFIED");
    expect(purgeSrc).toContain("SHARED_OR_UNKNOWN_DEPENDENCY");
    expect(purgeSrc).toContain("USER_NOT_FIXTURE");
    // Unverifiable dependency counts are FAIL_CLOSED with their own explicit blocker code.
    expect(purgeSrc).toContain("DEPENDENCY_COUNT_UNVERIFIED");
    expect(purgeSrc).not.toContain("$byTable[$table]");
    expect(purgeSrc).toContain("PURGE_TEST_FIXTURE");
    expect(purgeSrc).not.toMatch(/personel_id === 1/);
    // Owner-attributed relation classification (audit / sealed / closed period).
    expect(purgeSrc).toContain("AUDIT_APPEND_ONLY_RETENTION_REQUIRED");
    expect(purgeSrc).toContain("CLASS_AUDIT_APPEND_ONLY");
    expect(purgeSrc).toContain("CLASS_SEALED_HISTORICAL");
    expect(purgeSrc).toContain("CLASS_CLOSED_PERIOD_ARTIFACT");
    expect(purgeSrc).toContain("append_only_archive_access_audit");
    expect(purgeSrc).toContain("closed_period_summary_artifact");
    expect(purgeSrc).toContain("ArchiveAccessService");
    expect(purgeSrc).toContain("PuantajDestructionHandler");
    expect(purgeSrc).toContain("MaasHesaplamaSnapshotService");
    expect(purgeSrc).toContain("HANDOFF_RETENTION_IMHA");
    expect(purgeSrc).toContain("RetentionCategories::PUANTAJ");
    expect(purgeSrc).toContain("RetentionCategories::BORDRO");
    expect(validatorSrc).toContain("CREATE_PASIF_FORBIDDEN");
    expect(validatorSrc).toContain("requireCreateAktifDurum");
    expect(incompleteSrc).toContain("requireCreateAktifDurum");
    // Retention-safe tombstone mode (de-identify, no hard delete) on the same owner.
    expect(purgeSrc).toContain("TOMBSTONE_TEST_FIXTURE");
    expect(purgeSrc).toContain("MODE_RETENTION_SAFE_TOMBSTONE");
    expect(purgeSrc).toContain("TEST_FIXTURE_TOMBSTONE");
    expect(purgeSrc).toContain("PersonelOzlukDestructionHandler::tombstonePersonelIdentity");
    expect(purgeSrc).toContain("'hard_delete' => false");
    expect(controllerSrc).toContain("resolveMode");
    expect(controllerSrc).toContain("retention_safe_tombstone");
    expect(controllerSrc).toContain("TestFixturePersonelPurgeService::tombstone(");
  });

  it("keeps the operational read exclusion in one canonical owner", () => {
    const destructiveSrc = readFileSync(
      resolve(root, "api/src/Services/Retention/PhysicalDestruction/Handlers/PersonelOzlukDestructionHandler.php"),
      "utf8",
    );
    const classificationSrc = readFileSync(
      resolve(root, "api/src/Services/Personel/TestFixturePersonelClassificationService.php"),
      "utf8",
    );
    const archiveGateSrc = readFileSync(
      resolve(root, "api/src/Services/Retention/PersonelArchiveGate.php"),
      "utf8",
    );
    const personellerSrc = readFileSync(
      resolve(root, "api/src/Controllers/PersonellerController.php"),
      "utf8",
    );
    const arsivSrc = readFileSync(resolve(root, "api/src/Controllers/ArsivController.php"), "utf8");
    const raporlarSrc = readFileSync(resolve(root, "api/src/Controllers/RaporlarController.php"), "utf8");
    const exportSrc = readFileSync(
      resolve(root, "api/src/Services/Personel/PersonelExportService.php"),
      "utf8",
    );

    // Canonical PERSONEL_OZLUK de-identify primitive is shared, not duplicated.
    expect(destructiveSrc).toContain("public static function tombstonePersonelIdentity");
    expect(destructiveSrc).toContain("self::tombstonePersonelIdentity($pdo, $personelId)");
    expect(classificationSrc).toContain("sqlOperationalVisibilityExclusion");
    expect(classificationSrc).toContain("isOperationallyHidden");
    expect(archiveGateSrc).toContain("appendOperationalExclusion");

    // Read surfaces call the single owner; only that owner knows the classification table.
    expect(personellerSrc).toContain("PersonelArchiveGate::appendOperationalExclusion($pdo, $where)");
    expect(personellerSrc).toContain("PersonelArchiveGate::isOperationallyHidden($pdo, $personelId)");
    expect(arsivSrc).toContain("PersonelArchiveGate::appendOperationalExclusion($pdo, $where)");
    expect(arsivSrc).toContain("PersonelArchiveGate::isOperationallyHidden($pdo, $personelId)");
    expect(raporlarSrc).toContain("PersonelArchiveGate::appendOperationalExclusion($pdo, $where, $personelAlias)");
    expect(exportSrc).toContain("PersonelArchiveGate::appendOperationalExclusion($pdo, $where)");
    expect(personellerSrc).not.toContain("personel_test_fixture_siniflandirmalari");
    expect(arsivSrc).not.toContain("personel_test_fixture_siniflandirmalari");
    expect(raporlarSrc).not.toContain("personel_test_fixture_siniflandirmalari");
    expect(exportSrc).not.toContain("personel_test_fixture_siniflandirmalari");
    // Retention/audit read owners stay unfiltered by design.
    expect(destructiveSrc).not.toContain("appendOperationalExclusion");
  });

  it("runs focused MariaDB purge + archive-invariant scenarios", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-test-fixture-personel-purge-mysql: OK");
    expect(result.stdout).toContain("[PASS] unclassified personel purge DENY");
    expect(result.stdout).toContain("[PASS] real personel purge DENY");
    expect(result.stdout).toContain("[PASS] confirmed fixture + only demo deps dry-run PASS");
    expect(result.stdout).toContain("[PASS] shared dependency → FAIL CLOSED");
    expect(result.stdout).toContain("[PASS] user not fixture → user preserved");
    expect(result.stdout).toContain("[PASS] historical real dependency → purge blocked");
    expect(result.stdout).toContain("[PASS] purge plan deterministic");
    expect(result.stdout).toContain("[PASS] real personel no exit → archive DENY (create PASIF)");
    expect(result.stdout).toContain("[PASS] archive access audit → purge FAIL CLOSED");
    expect(result.stdout).toContain("[PASS] audit blocker names archive access audit owner");
    expect(result.stdout).toContain("[PASS] audit row preserved (audit integrity)");
    expect(result.stdout).toContain("[PASS] open period summary is fixture-owned → purge PASS");
    expect(result.stdout).toContain("[PASS] other personel summary row preserved");
    expect(result.stdout).toContain("[PASS] closed period summary → purge FAIL CLOSED");
    expect(result.stdout).toContain("[PASS] closed period blocker hands off to aylik kapanis owner");
    expect(result.stdout).toContain("[PASS] sealed muhur line → purge FAIL CLOSED");
    expect(result.stdout).toContain("[PASS] sealed muhur line blocker is retention-owned (PUANTAJ)");
    expect(result.stdout).toContain("[PASS] payroll snapshot ledger → purge FAIL CLOSED");
    expect(result.stdout).toContain(
      "[PASS] payroll snapshot blocker is sealed ledger owned by snapshot service (BORDRO)",
    );
    expect(result.stdout).toContain("[PASS] tombstone dry-run deletes nothing");
    expect(result.stdout).toContain("[PASS] tombstone with hard-purge confirm token DENY");
    expect(result.stdout).toContain("[PASS] real personel tombstone DENY");
    expect(result.stdout).toContain("[PASS] tombstone de-identifies PII and forces PASIF");
    expect(result.stdout).toContain("[PASS] tombstone keeps the personel row (no hard delete)");
    expect(result.stdout).toContain("[PASS] classification evidence stays AKTIF after tombstone");
    expect(result.stdout).toContain("[PASS] tombstone writes id-preserving lifecycle evidence");
    expect(result.stdout).toContain("[PASS] sealed puantaj + payroll snapshot evidence preserved");
    expect(result.stdout).toContain("[PASS] tombstone is idempotent");
    expect(result.stdout).toContain("[PASS] tombstoned fixture missing from operational list/count");
    expect(result.stdout).toContain("[PASS] real personel NOT excluded from operational surfaces");
    expect(result.stdout).toContain("[PASS] unclassified personel NOT excluded from operational surfaces");
    expect(result.stdout).toContain("[PASS] tombstoned fixture missing from archive search");
    expect(result.stdout).toContain("[PASS] detail exclusion applies to fixture only");
    // Reference inventory: one entry per independent reference, fail-closed counting.
    expect(result.stdout).toContain("[PASS] empty dependencies (row_count 0) do not block purge");
    expect(result.stdout).toContain("[PASS] both same-table FK references inventoried separately");
    expect(result.stdout).toContain("[PASS] FK-less candidate column kept next to an FK on the same table");
    expect(result.stdout).toContain("[PASS] composite FK inventoried once with all of its columns");
    expect(result.stdout).toContain("[PASS] safe fixture with empty extra relations still purges");
    expect(result.stdout).toContain("[PASS] same-table reference old_personel_id counted and blocks");
    expect(result.stdout).toContain("[PASS] same-table reference new_personel_id counted and blocks");
    expect(result.stdout).toContain(
      "[PASS] unknown dependency on new_personel_id is an explicit SHARED_OR_UNKNOWN blocker",
    );
    expect(result.stdout).toContain("[PASS] FK-less candidate reference counted and blocks");
    expect(result.stdout).toContain("[PASS] composite FK reference counted and blocks");
    expect(result.stdout).toContain("[PASS] failed dependency count makes purge FAIL_CLOSED (no delete)");
    expect(result.stdout).toContain(
      "[PASS] failed dependency count reported as explicit DEPENDENCY_COUNT_UNVERIFIED blocker",
    );
    expect(result.stdout).toContain("[PASS] failed-count fixture row preserved");
  });
});
