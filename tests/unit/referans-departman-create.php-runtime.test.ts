import { execFileSync, spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/ReferansDepartmanCreateTestRunner.php");
const controllerPath = resolve(process.cwd(), "api/src/Controllers/ReferansController.php");
const routerPath = resolve(process.cwd(), "api/src/Router.php");
const controllerSource = readFileSync(controllerPath, "utf8");
const routerSource = readFileSync(routerPath, "utf8");

describe("ReferansController createDepartman PHP owner", () => {
  it("locks route, auth, validation and response contract in source", () => {
    expect(routerSource).toContain("/referans/departmanlar");
    expect(routerSource).toContain("ReferansController::createDepartman");
    expect(controllerSource).toContain("function createDepartman");
    expect(controllerSource).toContain("RolePermissions::assert($user, 'yonetim-paneli.manage')");
    expect(controllerSource).toContain("JsonResponse::success($created, [], 201)");
    expect(controllerSource).toContain("DEPARTMAN_ZATEN_VAR");
    expect(controllerSource).toContain("DEPARTMAN_NAME_REQUIRED");
    expect(controllerSource).toContain("INSERT INTO departmanlar (ad, durum)");
    expect(controllerSource).not.toMatch(/INSERT\s+INTO\s+departmanlar\s*\([^)]*sube_id/i);
    expect(controllerSource).not.toContain("MAX(id)");
  });

  it("runs create/validation/duplicate/global-model scenarios via PHP CLI", () => {
    const isWindows = process.platform === "win32";
    let phpPath = "php";
    try {
      phpPath = isWindows
        ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
        : "php";
    } catch {
      throw new Error(
        "PHP CLI not found on PATH. Install PHP with pdo_sqlite to run ReferansDepartmanCreateTestRunner locally; CI provides PHP."
      );
    }

    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, "-d", "extension=php_pdo_sqlite.dll", runnerPath]
      : ["-d", "extension=php_pdo_sqlite", runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-referans-departman-create: OK");
    expect(result.stdout).toContain("[PASS] authorized create returns positive id");
    expect(result.stdout).toContain("[PASS] create trims department name");
    expect(result.stdout).toContain("[PASS] empty ad is rejected");
    expect(result.stdout).toContain("[PASS] whitespace-only ad is rejected");
    expect(result.stdout).toContain("[PASS] ad longer than 120 is rejected");
    expect(result.stdout).toContain("[PASS] duplicate name is rejected (case-insensitive)");
    expect(result.stdout).toContain("[PASS] BOLUM_YONETICISI lacks departman manage permission (403 path)");
    expect(result.stdout).toContain("[PASS] unexpected durum payload is not written");
  });

  it("test runner lives outside production api tree", () => {
    expect(runnerPath.replace(/\\/g, "/")).toContain("tests/php/");
    expect(() =>
      readFileSync(resolve(process.cwd(), "api/tests/ReferansDepartmanCreateTestRunner.php"))
    ).toThrow();
  });
});
