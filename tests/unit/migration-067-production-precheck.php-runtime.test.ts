import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/Migration067OpsBackupMysqlTestRunner.php"
);

describe("Migration 067 PHP fallback backup", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("restores tables, binary/text data, constraints, trigger and view", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-migration-067-php-backup-mysql: OK");
  });
});
