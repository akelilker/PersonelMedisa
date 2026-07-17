import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelUcretMigrationTestRunner.php");

describe("S77-B salary and legal parameter migrations", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 60_000);

  it("applies additive schema with decimal, index and foreign-key contracts", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-ucret-migrations: OK");
  });
});
