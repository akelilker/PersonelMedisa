import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/RetentionPhysicalPack3bMysqlTestRunner.php");

describe("RetentionPhysicalPack3B MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("applies migrations and enforces Pack 3B PUANTAJ/BORDRO/SGK matrix", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }
    expect(result.stdout).toContain("verify-retention-physical-pack3b-mysql: OK");
  });
});
