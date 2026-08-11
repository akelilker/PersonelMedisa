import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/RetentionPolicy053MysqlTestRunner.php");
const purePath = resolve(process.cwd(), "tests/php/RetentionPolicyPureTestRunner.php");

describe("RetentionPolicy053 MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("runs pure calendar/category PHP tests", () => {
    const result = runPhpMysqlRunner(purePath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-retention-policy-pure: OK");
  });

  it("applies 053 idempotently and enforces retention matrix", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    // SKIP (no MariaDB) exits 0 with SKIP message — vitest still passes; real env asserts OK.
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }
    expect(result.stdout).toContain("verify-retention-policy-053-mysql: OK");
  });
});
