import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelUcretMysqlConcurrencyTestRunner.php");

describe("PersonelUcretService MariaDB concurrency", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 60_000);

  it("serializes overlapping creates and cancel-create races", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-ucret-mysql-concurrency: OK");
  });
});
