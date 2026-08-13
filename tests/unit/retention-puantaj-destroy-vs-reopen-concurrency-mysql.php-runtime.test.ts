import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/RetentionPuantajDestroyVsReopenConcurrencyMysqlTestRunner.php"
);

describe("PUANTAJ destroy vs reopen concurrency MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("serializes physical destroy and reopen create so both cannot succeed", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }
    expect(result.stdout).toContain(
      "verify-retention-puantaj-destroy-vs-reopen-concurrency-mysql: OK"
    );
  });
});
