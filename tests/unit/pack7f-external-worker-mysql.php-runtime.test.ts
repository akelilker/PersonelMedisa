import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("Pack7F external worker MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("verifies schema 065 compatibility, migration 066 and directory-only guards", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/Pack7FExternalWorkerMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }
    expect(result.stdout).toContain("verify-pack7f-external-worker-mysql: OK");
  });
});
