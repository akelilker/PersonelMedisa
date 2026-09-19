import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/SgkIsverenBildirimDonemiOwnerMysqlTestRunner.php");
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/090_sgk_isveren_bildirim_donemi_owner.sql"),
  "utf8"
);

describe("SGK employer reporting-period owner (acceptance A-J)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks additive 090 schema invariants in source", () => {
    expect(migrationSource).toContain("sgk_isveren_bildirim_donemi_surumleri");
    expect(migrationSource).toContain("REFERENCES sgk_isverenler (id)");
    expect(migrationSource).not.toMatch(/\bDROP\s+TABLE\b/i);
    expect(migrationSource).not.toMatch(/maas_hesaplama_sgk_snapshotlari/i);
  });

  it("runs the focused employer-period acceptance runner", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-sgk-isveren-bildirim-donemi-owner-mysql: OK");
    for (const label of ["A ", "B ", "C ", "D ", "E ", "F ", "G ", "H ", "I ", "J "]) {
      expect(result.stdout).toContain(`[PASS] ${label}`);
    }
  });
});
