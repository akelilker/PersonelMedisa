import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync, readdirSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner,
} from "../scripts/disposable-mariadb.mjs";

const root = process.cwd();
const runnerPath = resolve(root, "tests/php/TestFixturePersonelArchiveMysqlTestRunner.php");
const serviceSrc = readFileSync(
  resolve(root, "api/src/Services/Personel/TestFixturePersonelArchiveService.php"),
  "utf8",
);
const classificationSrc = readFileSync(
  resolve(root, "api/src/Services/Personel/TestFixturePersonelClassificationService.php"),
  "utf8",
);
const controllerSrc = readFileSync(
  resolve(root, "api/src/Controllers/TestFixturePersonelArchiveController.php"),
  "utf8",
);
const routerSrc = readFileSync(resolve(root, "api/src/Router.php"), "utf8");
const migration073 = readFileSync(
  resolve(root, "api/migrations/073_test_fixture_personel_archive.sql"),
  "utf8",
);
const permissionsSrc = readFileSync(
  resolve(root, "api/src/Auth/RolePermissions.php"),
  "utf8",
);
const retentionCategories = readFileSync(
  resolve(root, "api/src/Services/Retention/RetentionCategories.php"),
  "utf8",
);
const archiveManifest = readFileSync(
  resolve(root, "api/src/Services/Retention/ArchiveManifestService.php"),
  "utf8",
);
const sureclerSrc = readFileSync(
  resolve(root, "api/src/Controllers/SureclerController.php"),
  "utf8",
);

describe("test fixture personel archive owner", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks canonical route + permission + lifecycle contract in source", () => {
    expect(routerSrc).toContain("TestFixturePersonelArchiveController::archive");
    expect(routerSrc).toContain("/personeller/(\\d+)/test-fixture-archive");
    expect(controllerSrc).toContain("personeller.test_fixture.archive");
    expect(controllerSrc).toContain("isten_cikis_tarihi");
    expect(controllerSrc).toContain("eligibility bypass");
    expect(permissionsSrc).toContain("'personeller.test_fixture.archive'");
    expect(permissionsSrc).toMatch(
      /'GENEL_YONETICI'[\s\S]*'personeller\.test_fixture\.archive'/,
    );
    expect(permissionsSrc).toMatch(
      /'IK_SORUMLUSU'[\s\S]*'personeller\.test_fixture\.archive'/,
    );
    expect(permissionsSrc).not.toMatch(
      /'PERSONEL'[\s\S]{0,400}'personeller\.test_fixture\.archive'/,
    );
    expect(serviceSrc).toContain("TEST_FIXTURE_ARCHIVE");
    expect(serviceSrc).toContain("termination_date");
    expect(serviceSrc).toContain("fake_employment_exit_created");
    expect(serviceSrc).not.toContain("P-0001");
    expect(serviceSrc).not.toContain("personel_id === 1");
    expect(classificationSrc).toContain("BORDRO_KAPSAM_DEMO_TEST_VERISI");
    expect(classificationSrc).toContain("TEST_FIXTURE");
    expect(classificationSrc).not.toMatch(/\bis_test\s*=/);
    expect(classificationSrc).not.toContain("is_test=true");
    expect(retentionCategories).toContain("TRIGGER_TEST_FIXTURE_ARCHIVE");
    expect(archiveManifest).toContain("createTestFixtureArchiveManifest");
    expect(archiveManifest).toContain("TRIGGER_TEST_FIXTURE_ARCHIVE");
    expect(sureclerSrc).toContain("ISTEN_AYRILMA");
    expect(sureclerSrc).toContain("createPersonelLifecycleManifests");
    expect(migration073).toContain("personel_test_fixture_siniflandirmalari");
    expect(migration073).toContain("personel_test_fixture_archive_kayitlari");
    expect(migration073).toContain("TEST_FIXTURE_ARCHIVE");
    expect(migration073).not.toMatch(/\bINSERT INTO personeller\b/i);

    const migrations = readdirSync(resolve(root, "api/migrations"))
      .filter((name) => /^\d+_.*\.sql$/.test(name))
      .sort();
    expect(migrations.at(-1)).toBe("073_test_fixture_personel_archive.sql");
  });

  it("runs focused MariaDB archive acceptance scenarios", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-test-fixture-personel-archive-mysql: OK");
    expect(result.stdout).toContain("[PASS] 1 valid fixture/no deps → PASS");
    expect(result.stdout).toContain("[PASS] 2 real employee → DENY");
    expect(result.stdout).toContain("[PASS] 3 unknown classification → DENY");
    expect(result.stdout).toContain("[PASS] 4 active bound user → DENY");
    expect(result.stdout).toContain("[PASS] 5 PASIF bound user → PASS");
    expect(result.stdout).toContain("[PASS] 6 historical puantaj preserved");
    expect(result.stdout).toContain("[PASS] 7 sealed payroll/SGK preserved (no rewrite)");
    expect(result.stdout).toContain("[PASS] 8 cancellable workflow cancelled (TASLAK→IPTAL)");
    expect(result.stdout).toContain("[PASS] 9 incompatible workflow fail closed");
    expect(result.stdout).toContain("[PASS] 10 future ucret safe cancel");
    expect(result.stdout).toContain("[PASS] 11 archive manifest created");
    expect(result.stdout).toContain("[PASS] 12 fake termination date absent");
    expect(result.stdout).toContain("[PASS] 13 idempotent second call");
    expect(result.stdout).toContain("[PASS] 14 active list exclusion");
    expect(result.stdout).toContain("[PASS] 15 QR/new workflow denial for PASIF personel");
    expect(result.stdout).toContain("[PASS] 16 historical read preserved");
    expect(result.stdout).toContain("[PASS] 17 unauthorized role denied");
    expect(result.stdout).toContain(
      "[PASS] 18 dependency failure transaction rollback (personel still AKTIF)",
    );
  });
});
