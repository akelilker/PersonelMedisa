import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/DonemKapanisMysqlConcurrencyTestRunner.php");

describe("DonemKapanis MariaDB concurrency", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 60_000);

  // Keep disposable MariaDB alive for sibling concurrency files in the same vitest run.
  afterAll(() => undefined);

  it("serializes close races on disposable MariaDB/InnoDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-donem-kapanis-mysql-concurrency: OK");
    expect(result.stdout).toContain("[PASS] parallel close leaves one seal and one idempotent result");
    expect(result.stdout).toContain("[PASS] blocked audit idempotency keeps one row");
  });
});
