import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const historyRunner = resolve(
  process.cwd(),
  "tests/php/S97CPersonelImportHistoryMysqlTestRunner.php"
);
const historyService = readFileSync(
  resolve(process.cwd(), "api/src/Services/Personel/PersonelImportHistoryService.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");

describe("S97-C personel import history MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("wires read-only history owner without write routes", () => {
    expect(historyService).toContain("PersonelImportHistoryService");
    expect(historyService).toContain("SCHEMA_NOT_READY");
    expect(historyService).not.toContain("UPDATE personel_import_runs");
    expect(routerSource).toContain("/personeller/import/runs");
    expect(routerSource).toMatch(
      /personeller\/import\/runs[\s\S]*method === 'GET'/
    );
  });

  it("runs personel import history acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(historyRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-s97c-personel-import-history-mysql: OK");
    expect(result.stdout).toContain("[PASS] empty list items=[] next_cursor=null");
    expect(result.stdout).toContain("[PASS] invalid cursor fail-closed");
    expect(result.stdout).toContain("[PASS] cursor rejected when filters change");
    expect(result.stdout).toContain("[PASS] schema missing SCHEMA_NOT_READY no 500");
    expect(result.stdout).toContain("[PASS] evidence no raw idempotency key");
    expect(result.stdout).toContain("[PASS] formula injection ad guarded");
    expect(result.stdout).toContain("[PASS] 25 item list without N+1 requirement breach");
    expect(result.stdout).toContain("[PASS] query count does not grow with item count");
    expect(result.stdout).toContain("[PASS] detail accepts 500 rows");
    expect(result.stdout).toContain("[PASS] read-only runs delta 0");
    expect(result.stdout).toContain("[PASS] MIGRATION_REQUIRED=NO existing 046 indexes sufficient for current volume");
  });
});
