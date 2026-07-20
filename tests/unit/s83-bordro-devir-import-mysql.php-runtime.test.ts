import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/S83BordroDevirImportMysqlTestRunner.php");
const serviceSource = readFileSync(
  resolve(process.cwd(), "api/src/Services/PersonelBordroDevirService.php"),
  "utf8"
);

describe("S83 bordro devir import MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("service exposes processImport classification + transactional commit", () => {
    expect(serviceSource).toContain("processImport");
    expect(serviceSource).toContain("eklenecek");
    expect(serviceSource).toContain("eslesmeyen");
    expect(serviceSource).toContain("scope_disi");
    expect(serviceSource).toContain("duplicate");
    expect(serviceSource).toContain("DEVIR_IMPORT_VALIDATION_FAILED");
    expect(serviceSource).toContain("upsertInTransaction");
  });

  it("runs carryover import dry-run/commit/rollback acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-s83-devir-import-mysql: OK");
    expect(result.stdout).toContain("[PASS] dry_run: eklenecek for valid sicil");
    expect(result.stdout).toContain("[PASS] dry_run: eslesmeyen for unknown");
    expect(result.stdout).toContain("[PASS] dry_run: scope_disi for other-sube sicil");
    expect(result.stdout).toContain("[PASS] dry_run: duplicate for same sicil twice");
    expect(result.stdout).toContain("[PASS] dry_run: hatali for negative money");
    expect(result.stdout).toContain("[PASS] commit with any invalid row throws DEVIR_IMPORT_VALIDATION_FAILED");
    expect(result.stdout).toContain("[PASS] commit with invalid leaves zero AKTIF rows (no partial)");
    expect(result.stdout).toContain("[PASS] commit dry_run=false with all-valid rows inserts AKTIF row");
    expect(result.stdout).toContain("[PASS] second commit same values → degismeyecek");
    expect(result.stdout).toContain("[PASS] second commit same values → no duplicate AKTIF");
  });
});
