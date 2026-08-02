import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/SirketPolitikasiKanitOwner045MysqlTestRunner.php");

describe("S87 policy evidence 045 MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("applies migration twice and enforces evidence gates", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("SirketPolitikasiKanitOwner045MysqlTestRunner: ALL PASS");
    expect(result.stdout).toContain("[PASS] legacy policy_version_hash unchanged");
    expect(result.stdout).toContain("[PASS] submit without evidence => POLICY_EVIDENCE_REQUIRED");
    expect(result.stdout).toContain("[PASS] self approval forbidden");
    expect(result.stdout).toContain("[PASS] same values + different evidence => same policy_version_hash");
  });
});
