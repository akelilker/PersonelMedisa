import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/MaasHesaplamaAdayMysqlConcurrencyTestRunner.php");

describe("MaasHesaplamaAday MariaDB concurrency", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 60_000);

  it("serializes calculation create races and preserves immutable aday rows", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-maas-hesaplama-aday-concurrency: OK");
  });
});
