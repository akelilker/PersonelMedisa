import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PuantajDonemReopenMysqlConcurrencyTestRunner.php");

describe("S87 seal reopen MariaDB concurrency", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("serializes concurrent reopen/approve/reseal to single open talep and single revision", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("PuantajDonemReopenMysqlConcurrencyTestRunner: ALL PASS");
    expect(result.stdout).toContain("[PASS] exactly one open talep");
    expect(result.stdout).toContain("[PASS] exactly one effective seal after reseal race");
  });
});
