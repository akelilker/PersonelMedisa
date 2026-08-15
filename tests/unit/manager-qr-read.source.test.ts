import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { ensureDisposableMariaDbEnv } from "../scripts/disposable-mariadb.mjs";

const runner = resolve(process.cwd(), "tests/php/ManagerQrAttendanceMysqlTestRunner.php");

describe("manager QR daily read model", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("passes daily grain, boundary, scope, and pagination acceptance", () => {
    const result = spawnSync("php", [runner], {
      cwd: process.cwd(),
      encoding: "utf8",
      env: process.env
    });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[OK] ManagerQrAttendanceMysqlTestRunner");
  }, 90_000);
});
