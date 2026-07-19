import { describe, expect, it } from "vitest";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";

function hasPhpCli(): boolean {
  try {
    execFileSync("php", ["-v"], { encoding: "utf8" });
    return true;
  } catch {
    return false;
  }
}

describe("S82 bordro hazirlik PHP runner", () => {
  it.skipIf(!hasPhpCli())("runs policy catalog and correction projection checks", () => {
    const runner = path.resolve("tests/php/S82BordroHazirlikTestRunner.php");
    const output = execFileSync("php", [runner], { encoding: "utf8" });
    expect(output).toContain("S82 PHP runner OK");
  });
});

describe("S82 migration sources", () => {
  it("includes company policy and bordro approval migrations", () => {
    const policy = readFileSync("api/migrations/033_sirket_calisma_politikalari.sql", "utf8");
    const onay = readFileSync("api/migrations/034_bordro_onay_ve_projection.sql", "utf8");
    expect(policy).toContain("sirket_calisma_politikalari");
    expect(onay).toContain("bordro_onay_durumu");
    expect(onay).toContain("correction_projection_json");
  });
});
