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
const classifyControllerSrc = readFileSync(
  resolve(root, "api/src/Controllers/TestFixturePersonelClassificationController.php"),
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
const bildirimSrc = readFileSync(
  resolve(root, "api/src/Controllers/BildirimlerController.php"),
  "utf8",
);
const pack5Src = readFileSync(
  resolve(root, "tests/php/FinalCodeGapPack5MysqlTestRunner.php"),
  "utf8",
);

beforeAll(async () => {
  await ensureDisposableMariaDbEnv();
});

describe("test fixture personel archive owner", () => {
  it("locks source contracts for classification + archive + tip-lock", () => {
    expect(routerSrc).toContain("/personeller/(\\d+)/test-fixture-archive");
    expect(routerSrc).toContain("/personeller/(\\d+)/test-fixture-classification");
    expect(controllerSrc).toContain("personeller.test_fixture.archive");
    expect(classifyControllerSrc).toContain("personeller.test_fixture.classify");
    expect(permissionsSrc).toContain("'personeller.test_fixture.archive'");
    expect(permissionsSrc).toContain("'personeller.test_fixture.classify'");
    expect(permissionsSrc).toMatch(
      /'GENEL_YONETICI'[\s\S]*'personeller\.test_fixture\.classify'[\s\S]*'personeller\.test_fixture\.archive'/,
    );
    expect(permissionsSrc).toMatch(
      /'IK_SORUMLUSU'[\s\S]*'personeller\.test_fixture\.classify'[\s\S]*'personeller\.test_fixture\.archive'/,
    );
    expect(permissionsSrc).not.toMatch(
      /'PERSONEL'[\s\S]{0,400}'personeller\.test_fixture\.(classify|archive)'/,
    );
    expect(permissionsSrc).not.toMatch(
      /'MUHASEBE'[\s\S]{0,800}'personeller\.test_fixture\.(classify|archive)'/,
    );

    expect(classificationSrc).toContain("BORDRO_KAPSAM_DEMO_TEST_VERISI");
    expect(classificationSrc).toContain("allowedHttpEvidenceKodlari");
    expect(classificationSrc).toContain("EVIDENCE_UNVERIFIABLE");
    expect(classificationSrc).toContain("classifyViaHttp");
    expect(classificationSrc).not.toMatch(/\bis_test\s*=/);

    expect(serviceSrc).toContain("HAFTALIK_MUTABAKATA_ALINDI");
    expect(serviceSrc).toContain("TEST_FIXTURE_ARCHIVE");
    expect(serviceSrc).toContain("Non-real fixture workflow withdrawn");
    expect(bildirimSrc).toContain("haftalık mutabakata alındığı için doğrudan değiştirilemez");

    expect(migration073).toContain("personel_test_fixture_siniflandirmalari");
    expect(migration073).toContain("personel_test_fixture_archive_kayitlari");
    expect(migration073).not.toMatch(/\bINSERT\s+INTO\s+personeller\b/i);
    expect(migration073).not.toContain("personel_id = 1");

    expect(retentionCategories).toContain("TRIGGER_TEST_FIXTURE_ARCHIVE");
    expect(archiveManifest).toContain("createTestFixtureArchiveManifest");
    expect(archiveManifest).toContain("CODE_TERMINATION_DATE_MISSING");
    expect(sureclerSrc).toContain("ISTEN_AYRILMA");

    // Tip-lock: both 072 and 073 must remain excluded so filtered tip ends at 066.
    expect(pack5Src).toContain("072_org_reference_short_codes.sql");
    expect(pack5Src).toContain("073_test_fixture_personel_archive.sql");
    expect(pack5Src).toContain("066_personel_calisan_kapsami.sql");
    const excluded = [
      "067_personel_canonical_reference_gate.sql",
      "068_sgk_actor_identity_lifecycle_audit.sql",
      "069_personel_credential_onboarding.sql",
      "070_offline_mutation_idempotency.sql",
      "071_org_hierarchy_authorization.sql",
      "072_org_reference_short_codes.sql",
      "073_test_fixture_personel_archive.sql",
    ];
    const migrations = readdirSync(resolve(root, "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name) && !excluded.includes(name))
      .sort();
    expect(migrations.at(-1)).toBe("066_personel_calisan_kapsami.sql");
  });

  it("runs focused MariaDB archive acceptance scenarios", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-test-fixture-personel-archive-mysql: OK");
    expect(result.stdout).toContain("[PASS] 1 valid persisted DEMO_TEST evidence → classify PASS");
    expect(result.stdout).toContain("[PASS] 2 real employee → DENY");
    expect(result.stdout).toContain("[PASS] 3 client-only assertion → DENY");
    expect(result.stdout).toContain("[PASS] 4 name/sicil pattern alone → DENY");
    expect(result.stdout).toContain("[PASS] 5 insufficient MANUAL_OPS evidence → DENY");
    expect(result.stdout).toContain("[PASS] 6 unauthorized role → DENY");
    expect(result.stdout).toContain("[PASS] 7 PERSONEL → DENY");
    expect(result.stdout).toContain("[PASS] 8 second classification → ALREADY_CORRECT");
    expect(result.stdout).toContain("[PASS] 9 classification evidence preserved");
    expect(result.stdout).toContain("[PASS] 10 HAFTALIK fixture safely cancelled");
    expect(result.stdout).toContain(
      "[PASS] 11 ordinary real personnel HAFTALIK cancel remains blocked",
    );
    expect(result.stdout).toContain("[PASS] 12 cancellation audit reason = TEST_FIXTURE_ARCHIVE");
    expect(result.stdout).toContain("[PASS] 13 P1-style dependency set archives atomically");
    expect(result.stdout).toContain("[PASS] 14 dependency failure transaction rollback");
    expect(result.stdout).toContain("[PASS] 15 P2-style RAPOR passes");
    expect(result.stdout).toContain("[PASS] 16 P3-style IZIN/POZISYON/BELGE passes");
    expect(result.stdout).toContain("[PASS] 17 P4 no-dependency → PASS");
    expect(result.stdout).toContain("[PASS] 18 fake termination date absent");
    expect(result.stdout).toContain("[PASS] 19 historical puantaj preserved");
    expect(result.stdout).toContain("[PASS] 20 active-set exclusion passes");
  });
});
