import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/S97PersonelImportDryRunMysqlTestRunner.php");
const serviceSource = readFileSync(
  resolve(process.cwd(), "api/src/Services/Personel/PersonelImportDryRunService.php"),
  "utf8"
);
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/PersonellerController.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");

describe("S97 personel import dry-run MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("wires dry-run-only endpoints and shared validator owner", () => {
    expect(serviceSource).toContain("PERSONEL_IMPORT_UCRET_KARARI_BEKLENIYOR");
    expect(serviceSource).toContain("MAX_ROWS = 500");
    expect(serviceSource).toContain("MAX_BYTES = 2097152");
    expect(serviceSource).not.toContain("INSERT INTO personeller");
    expect(serviceSource).not.toContain("UPDATE personeller");
    expect(controllerSource).toContain("importDryRun");
    expect(controllerSource).toContain("importTemplate");
    expect(controllerSource).not.toContain("/personeller/import/commit");
    expect(controllerSource).toContain("PersonelCanonicalValidator");
    expect(routerSource).toContain("/personeller/import/template.csv");
    expect(routerSource).toContain("/personeller/import/dry-run");
    expect(routerSource).not.toContain("/personeller/import/commit");
    expect(serviceSource).toContain("manifest_hash");
    expect(serviceSource).toContain("can_apply");
  });

  it("runs personel import dry-run acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-s97-personel-import-dry-run-mysql: OK");
    expect(result.stdout).toContain("[PASS] valid csv dry-run PASS");
    expect(result.stdout).toContain("[PASS] PERSONEL_ROW_DELTA = 0");
    expect(result.stdout).toContain("[PASS] SALARY_ROW_DELTA = 0");
    expect(result.stdout).toContain("[PASS] AUDIT_ROW_DELTA = 0");
    expect(result.stdout).toContain("[PASS] missing required column");
    expect(result.stdout).toContain("[PASS] invalid TC");
    expect(result.stdout).toContain("[PASS] invalid date");
    expect(result.stdout).toContain("[PASS] local dotted date rejected");
    expect(result.stdout).toContain("[PASS] infile duplicate TC");
    expect(result.stdout).toContain("[PASS] existing TC in DB");
    expect(result.stdout).toContain("[PASS] infile duplicate sicil");
    expect(result.stdout).toContain("[PASS] unknown reference");
    expect(result.stdout).toContain("[PASS] ambiguous reference");
    expect(result.stdout).toContain("[PASS] sube scope ihlali");
    expect(result.stdout).toContain("[PASS] wage field reject");
    expect(result.stdout).toContain("[PASS] mixed-case headers accepted");
    expect(result.stdout).toContain("[PASS] jagged row fail-closed");
    expect(result.stdout).toContain("[PASS] turkish reference exact match");
    expect(result.stdout).toContain("[PASS] 500 row limit");
    expect(result.stdout).toContain("[PASS] file size limit");
    expect(result.stdout).toContain("[PASS] mask helper first3+last2");
    expect(result.stdout).toContain("[PASS] create validator regression");
    expect(result.stdout).toContain("[PASS] update validator regression");
  });
});
