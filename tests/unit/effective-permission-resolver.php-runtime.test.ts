import { execFileSync, spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

/** PR1: tek etkin izin çözücüsü, sıfır davranış değişikliği (eski has == yeni çözücü). */
const root = process.cwd();

describe("EffectivePermissionResolver eşdeğerlik", () => {
  it("her rol × izin × bağlam için eski karar ile aynı; rol izin kümeleri anlık görüntüyle aynı", () => {
    const php = process.platform === "win32"
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const result = spawnSync(php, [resolve(root, "tests/php/EffectivePermissionResolverEquivalenceTestRunner.php")], {
      encoding: "utf8",
      cwd: root
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-effective-permission-resolver-equivalence: OK");
    expect(result.stdout).toContain("[PASS] snapshot GENEL_YONETICI = 108");
    expect(result.stdout).toContain("[PASS] snapshot SISTEM_YONETICISI = 47");
    expect(result.stdout).toContain("[PASS] snapshot BOLUM_YONETICISI = 53");
    expect(result.stdout).toContain("[PASS] snapshot SUBE_YONETICISI = 43");
    expect(result.stdout).not.toContain("[FAIL]");
  });

  it("RolePermissions::has tek çözücüye devreder", () => {
    const src = readFileSync(resolve(root, "api/src/Auth/RolePermissions.php"), "utf8");
    const body = src.slice(src.indexOf("public static function has(array $user, $permission)"));
    expect(body.slice(0, body.indexOf("\n    }"))).toContain(
      "return EffectivePermissionResolver::resolve($user, $permission);"
    );
  });
});
