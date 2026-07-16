import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PuantajDonemKilidiMysqlConcurrencyTestRunner.php");

describe("PuantajDonemKilidi MariaDB concurrency", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 60_000);

  afterAll(() => undefined);

  it("serializes period lock races on disposable MariaDB/InnoDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-puantaj-donem-kilidi-mysql-concurrency: OK");
  });
});
