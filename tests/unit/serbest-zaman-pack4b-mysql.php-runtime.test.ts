import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(
  process.cwd(),
  "tests/php/SerbestZamanPack4bMysqlTestRunner.php"
);

describe("SerbestZaman Pack4B MariaDB runtime", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("runs Pack4B retention destroy + deadline acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }
    expect(result.stdout).toContain("verify-serbest-zaman-pack4b-mysql: OK");
  });
});
