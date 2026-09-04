import { spawnSync } from "node:child_process";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

describe("BIRIM_AMIRI gunluk durum PHP runner", () => {
  it("scopes roster to user_birimler and fail-closes empty assignment", () => {
    const runner = resolve(process.cwd(), "tests/php/BirimAmiriGunlukDurumPhpTestRunner.php");
    const result = spawnSync("php", [runner], { encoding: "utf8", cwd: process.cwd() });
    const combined = `${result.stdout ?? ""}\n${result.stderr ?? ""}`;
    expect(result.status, combined).toBe(0);
    expect(combined).toContain("ALL_PASS birim-amiri-gunluk-durum");
  });
});
