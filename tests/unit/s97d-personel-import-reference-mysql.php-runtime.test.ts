import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runner = resolve(process.cwd(), "tests/php/S97DPersonelImportReferenceMysqlTestRunner.php");
const catalog = readFileSync(
  resolve(process.cwd(), "api/src/Services/Personel/PersonelImportReferenceCatalogService.php"),
  "utf8"
);
const router = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");

describe("S97-D personel import reference MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("wires read-only shared reference owner", () => {
    expect(catalog).toContain("PersonelImportReferenceCatalogService");
    expect(catalog).toContain("SCHEMA_NOT_READY");
    expect(catalog).not.toMatch(/\bINSERT\b|\bUPDATE\b|\bDELETE\b/);
    expect(router).toContain("/personeller/import/references.csv");
  });

  it("runs personel import reference acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-s97d-personel-import-reference-mysql: OK");
    expect(result.stdout).toContain("[PASS] MANIFEST_PARITY_WITH_PARENT = EXACT");
    expect(result.stdout).toContain("[PASS] EXPORT_USABILITY = DRY_RUN_RESOLUTION_RESULT");
    expect(result.stdout).toContain("[PASS] MAPPING_EMPTY_ERROR_DISTINCTION = VERIFIED");
    expect(result.stdout).toContain("[PASS] PERSONELLER_TABLE_READ = NO");
    expect(result.stdout).toContain("[PASS] UTF-8 BOM present");
    expect(result.stdout).toContain("[PASS] deterministic same bytes");
    expect(result.stdout).toContain("[PASS] scoped user only Merkez usable sube");
    expect(result.stdout).toContain("[PASS] formula injection guarded");
    expect(result.stdout).toContain("[PASS] dry-run ambiguous gorev preserved");
    expect(result.stdout).toContain("[PASS] personeller delta 0");
    expect(result.stdout).toContain("[PASS] SCHEMA_NOT_READY");
  });
});
