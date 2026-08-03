import { describe, expect, it } from "vitest";
import { spawnSync } from "node:child_process";
import { resolve } from "node:path";

const runnerPath = resolve(process.cwd(), "tests/php/S98MappingBootstrapPhp74SafeTestRunner.php");

describe("S98 mapping bootstrap PHP 7.4-safe (no Composer)", () => {
  it("loads mapping require_once chain and builds template export", () => {
    const result = spawnSync("php", [runnerPath], {
      encoding: "utf8",
      cwd: process.cwd(),
      env: process.env,
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("S98_MAPPING_BOOTSTRAP_PHP74_SAFE=PASS");
    expect(result.stdout).toContain("requiredCatalogCodes(OLAY)=01,06,15,21");
    expect(result.stdout).toContain("UTF-8 BOM present");
    expect(result.stdout).toContain("raw inventory exact 14");
  });
});
