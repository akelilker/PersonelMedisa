import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/SgkOwnerMigrationMysqlTestRunner.php");

describe("S85-B SGK owner migration MariaDB acceptance", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 60_000);

  it("applies twice, preserves data, enforces immutability and restores backup", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-sgk-owner-migration-mysql: OK");
  });
});
