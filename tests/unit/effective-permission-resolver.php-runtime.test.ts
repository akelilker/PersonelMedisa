import { execFileSync, spawnSync } from "node:child_process";
import { readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

/** PR1: tek etkin izin çözücüsü, sıfır davranış değişikliği (eski has == yeni çözücü). */
const root = process.cwd();

describe("EffectivePermissionResolver eşdeğerlik", () => {
  it("P2: istisna yazan yol yok ve şube kapsamlı istisnalar kapalı", () => {
    const read = (p: string) => readFileSync(resolve(root, p), "utf8");
    expect(read("api/src/Auth/EffectivePermissionResolver.php")).toContain("public const SUBE_ISTISNALARI_ETKIN = false;");
    const router = read("api/src/Router.php");
    for (const line of router.split(/\r?\n/).filter((l) => /yetkiler|yetki-auditleri|KullaniciYetkiController/.test(l) && /\$method/.test(l))) {
      expect(line).toContain("'GET'");
    }
    const writes = /(INSERT\s+(IGNORE\s+)?INTO|UPDATE|REPLACE\s+INTO|DELETE\s+FROM)\s+`?user_yetki_(istisnalari|auditleri)/i;
    const scan = (dir: string): string[] =>
      readdirSync(resolve(root, dir), { withFileTypes: true }).flatMap((e) =>
        e.isDirectory() ? scan(`${dir}/${e.name}`) : /\.(php|sql)$/.test(e.name) ? [`${dir}/${e.name}`] : []
      );
    const offenders = ["api/src", "api/bin", "api/migrations"].flatMap(scan).filter((f) => writes.test(read(f)));
    expect(offenders).toEqual([]);
  });
  it("kişiye özel istisna kuralları (DENY > ALLOW, global DENY, süre, kapsam, kırmızı liste)", () => {
    const php = process.platform === "win32"
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const result = spawnSync(php, [resolve(root, "tests/php/EffectivePermissionResolverIstisnaTestRunner.php")], {
      encoding: "utf8",
      cwd: root
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-effective-permission-resolver-istisna: OK");
    expect(result.stdout).not.toContain("[FAIL]");
  });

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
    expect(result.stdout).toContain("[PASS] snapshot GENEL_YONETICI = 111");
    expect(result.stdout).toContain("[PASS] snapshot SISTEM_YONETICISI = 47");
    expect(result.stdout).toContain("[PASS] snapshot BOLUM_YONETICISI = 53");
    expect(result.stdout).toContain("[PASS] snapshot SUBE_YONETICISI = 43");
    expect(result.stdout).toContain("[PASS] GENEL_YONETICI 1f0513f1 108 izni korunur + 3 yetki yönetimi izni (111)");
    expect(result.stdout).toContain("[PASS] boş/etkisiz istisna listesiyle karar değişmez");
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
