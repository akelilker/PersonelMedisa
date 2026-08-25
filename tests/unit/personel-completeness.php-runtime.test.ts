import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelCompletenessTestRunner.php");
const servicePath = resolve(process.cwd(), "api/src/Services/Personel/PersonelCompletenessService.php");
const controllerPath = resolve(process.cwd(), "api/src/Controllers/PersonellerController.php");

describe("PersonelCompletenessService (PHP CLI)", () => {
  it("locks canonical owner wiring in source", () => {
    const service = readFileSync(servicePath, "utf8");
    const controller = readFileSync(controllerPath, "utf8");
    expect(service).toContain("class PersonelCompletenessService");
    expect(service).toContain("sqlHasMissingPredicate");
    expect(controller).toContain("PersonelCompletenessService::evaluate");
    expect(controller).toContain("eksik_bilgi");
    expect(controller).toContain("missing_personel_total");
  });

  it("runs completeness policy scenarios via PHP CLI", () => {
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
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-completeness: OK");
  });
});
