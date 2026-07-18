import { execFileSync, spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/ReferansDepartmanCreateTestRunner.php");
const controllerPath = resolve(process.cwd(), "api/src/Controllers/ReferansController.php");
const routerPath = resolve(process.cwd(), "api/src/Router.php");
const controllerSource = readFileSync(controllerPath, "utf8");
const routerSource = readFileSync(routerPath, "utf8");

describe("ReferansController createDepartman validation (SQLite helper)", () => {
  it("locks route, string contract, unique WHERE and auth in source", () => {
    expect(routerSource).toContain("ReferansController::createDepartman");
    expect(controllerSource).toContain("RolePermissions::assert($user, 'yonetim-paneli.manage')");
    expect(controllerSource).toContain("JsonResponse::success($created, [], 201)");
    expect(controllerSource).toContain("DEPARTMAN_ZATEN_VAR");
    expect(controllerSource).toMatch(/!is_string\(\$body\['ad'\]\)/);
    expect(controllerSource).not.toContain("is_numeric($body['ad'])");
    expect(controllerSource).toContain("WHERE ad = :ad");
    expect(controllerSource).not.toContain("SELECT id, ad FROM departmanlar");
    expect(controllerSource).not.toContain("normalizeDepartmanAdForCompare");
    expect(controllerSource).not.toContain("MAX(id)");
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
      : ["-d", "extension=php_pdo_sqlite", runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-referans-departman-create: OK");
    expect(result.stdout).toContain("[PASS] numeric int ad rejected");
    expect(result.stdout).toContain("[PASS] boolean ad rejected");
    expect(result.stdout).toContain("[PASS] null ad rejected");
    expect(result.stdout).toContain("[PASS] trim accepted on string ad");
  });

  it("test runner lives outside production api tree", () => {
    expect(runnerPath.replace(/\\/g, "/")).toContain("tests/php/");
    expect(() =>
      readFileSync(resolve(process.cwd(), "api/tests/ReferansDepartmanCreateTestRunner.php"))
    ).toThrow();
  });
});
