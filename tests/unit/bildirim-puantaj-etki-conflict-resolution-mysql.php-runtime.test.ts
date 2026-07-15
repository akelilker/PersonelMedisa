import { execFileSync, spawnSync } from "node:child_process";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/BildirimPuantajEtkiConflictResolutionMysqlConcurrencyTestRunner.php"
);

describe("BildirimPuantajEtkiConflictResolution MariaDB concurrency", () => {
  it("serializes revise races without deadlock when MySQL test credentials exist", () => {
    if (!process.env.MEDISA_TEST_MYSQL_DSN || !process.env.MEDISA_TEST_MYSQL_USER) {
      expect(true).toBe(true);
      return;
    }

    const result = spawnSync("php", [runnerPath], { encoding: "utf8", cwd: process.cwd() });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain(
      "verify-bildirim-puantaj-etki-conflict-resolution-mysql-concurrency: OK"
    );
  });
});
