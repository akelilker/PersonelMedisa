import { spawnSync } from "node:child_process";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

describe("Bugun personel durumu PHP runner", () => {
  it("validates late minutes and 09:30 completion boundary", () => {
    const runner = resolve(process.cwd(), "tests/php/BugunPersonelDurumuPhpTestRunner.php");
    const result = spawnSync("php", [runner], { encoding: "utf8", cwd: process.cwd() });
    const combined = `${result.stdout ?? ""}\n${result.stderr ?? ""}`;
    expect(result.status, combined).toBe(0);
    expect(combined).toContain("ALL_PASS bugun-personel-durumu");
  });
});
