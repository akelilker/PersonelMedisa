import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/PersonelCanonicalReferenceMigration067MysqlTestRunner.php"
);

describe("067 canonical reference migration MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("runs fail-closed, idempotent canonical reference migration", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-canonical-reference-migration-067-mysql: OK");
    expect(result.stdout).toContain("[PASS] 067 first apply PASS");
    expect(result.stdout).toContain("[PASS] 067 reapply idempotent");
    expect(result.stdout).toContain("[PASS] 067 unsafe active child fails closed");
    expect(result.stdout).toContain("[PASS] 067 failed precondition leaves parent unchanged");
  });
});
