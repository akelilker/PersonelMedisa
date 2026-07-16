import { spawnSync } from "node:child_process";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/DonemKapanisMysqlConcurrencyTestRunner.php");

describe("DonemKapanis MariaDB concurrency", () => {
  it("serializes close races when MySQL test credentials exist", () => {
    if (!process.env.MEDISA_TEST_MYSQL_DSN || !process.env.MEDISA_TEST_MYSQL_USER) {
      expect(true).toBe(true);
      return;
    }

    const result = spawnSync("php", [runnerPath], { encoding: "utf8", cwd: process.cwd() });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-donem-kapanis-mysql-concurrency: OK");
    expect(result.stdout).toContain("[PASS] parallel close leaves one seal and one idempotent result");
    expect(result.stdout).toContain("[PASS] blocked audit idempotency keeps one row");
  });
});
