import { execFileSync, spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/ReferansGorevCreateTestRunner.php");
const controllerPath = resolve(process.cwd(), "api/src/Controllers/ReferansController.php");
const routerPath = resolve(process.cwd(), "api/src/Router.php");
const migrationPath = resolve(process.cwd(), "api/migrations/050_gorevler_ad_unique.sql");
const controllerSource = readFileSync(controllerPath, "utf8");
const routerSource = readFileSync(routerPath, "utf8");
const migrationSource = readFileSync(migrationPath, "utf8");

describe("ReferansController createGorev validation (SQLite helper)", () => {
  it("locks route, unique migration and auth in source", () => {
    expect(routerSource).toContain("ReferansController::createGorev");
    expect(controllerSource).toContain("createGorevRecord");
    expect(controllerSource).toContain("GOREV_ZATEN_VAR");
    expect(controllerSource).toContain("RolePermissions::assert($user, 'yonetim-paneli.manage')");
    expect(migrationSource).toContain("uq_gorevler_ad");
  });

  it("runs string/type/trim validation scenarios via PHP CLI", () => {
    const isWindows = process.platform === "win32";
    let phpPath = "php";
    try {
      phpPath = isWindows
        ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
        : "php";
    } catch {
      throw new Error("PHP CLI not found on PATH.");
    }

    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, "-d", "extension=php_pdo_sqlite.dll", runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-referans-gorev-create: OK");
  });
});
