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
    expect(purgeSrc).toContain("PURGE_TEST_FIXTURE");
    expect(purgeSrc).not.toMatch(/personel_id === 1/);
    expect(validatorSrc).toContain("CREATE_PASIF_FORBIDDEN");
    expect(validatorSrc).toContain("requireCreateAktifDurum");
    expect(incompleteSrc).toContain("requireCreateAktifDurum");
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
  });
});
