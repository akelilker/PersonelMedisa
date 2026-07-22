import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";

describe("S90 main bootstrap source", () => {
  it("main.tsx login oncesi loadDataFromServer cagirmaz", () => {
    const source = readFileSync(resolve(process.cwd(), "src/main.tsx"), "utf8");
    expect(source).toContain("initAppDataFromStorage()");
    expect(source).toContain("attachConnectivityListeners()");
    expect(source).not.toMatch(/loadDataFromServer\s*\(/);
  });

  it("Router BildirimlerController import eder", () => {
    const source = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");
    expect(source).toContain("use Medisa\\Api\\Controllers\\BildirimlerController;");
  });
});

describe("S90 Bildirimler auth PHP runner", () => {
  beforeEach(() => {
    vi.setConfig({ testTimeout: 60_000 });
  });

  afterEach(() => {
    vi.setConfig({ testTimeout: 5_000 });
  });

  it("auth'siz /bildirimler 401 JSON UNAUTHORIZED doner", () => {
    const runner = resolve(process.cwd(), "tests/php/BildirimlerAuthGateTestRunner.php");
    const phpArgs: string[] = [];
    if (process.platform === "win32") {
      const extDir = spawnSync("php", ["-r", "echo ini_get('extension_dir');"], {
        encoding: "utf8"
      }).stdout?.trim();
      if (extDir) {
        phpArgs.push("-d", `extension_dir=${extDir}`, "-d", "extension=pdo_mysql");
      }
    }
    const result = spawnSync("php", [...phpArgs, runner], {
      encoding: "utf8",
      cwd: process.cwd()
    });
    const combined = `${result.stdout ?? ""}\n${result.stderr ?? ""}`;
    expect(result.status, combined).toBe(0);
    expect(combined).toContain("S90_BILDIRIMLER_AUTH_GATE_OK");
    expect(combined).not.toMatch(/Fatal error/i);
  });
});
