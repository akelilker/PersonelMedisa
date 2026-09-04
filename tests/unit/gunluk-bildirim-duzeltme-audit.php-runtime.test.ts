import { spawnSync } from "node:child_process";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

describe("Gunluk bildirim duzeltme audit PHP runner", () => {
  it("validates append-only correction history and BIRIM_AMIRI parity", () => {
    const runner = resolve(process.cwd(), "tests/php/GunlukBildirimDuzeltmeAuditPhpTestRunner.php");
    const result = spawnSync("php", [runner], { encoding: "utf8", cwd: process.cwd() });
    const combined = `${result.stdout ?? ""}\n${result.stderr ?? ""}`;
    expect(result.status, combined).toBe(0);
    expect(combined).toContain("ALL_PASS gunluk-bildirim-duzeltme-audit");
  });
});
