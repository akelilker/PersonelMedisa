import { execFileSync, spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();

describe("personel yazma kapısında kişisel DENY", () => {
  it("oluşturma, güncelleme ve organizasyon aynı kapıyı kullanır; ücret ayrı izinde kalır", () => {
    const src = readFileSync(resolve(root, "api/src/Controllers/PersonellerController.php"), "utf8");
    expect(src).toContain("self::assertWriteRole($user, 'personeller.create');");
    expect(src.split("self::assertWriteRole($user, 'personeller.update');").length - 1).toBe(2);
    const updateBody = src.slice(
      src.indexOf("public static function update("),
      src.indexOf("public static function organizasyonDegisikligi(")
    );
    expect(updateBody.indexOf("assertWriteRole")).toBeLessThan(updateBody.indexOf("personeller.ucret.manage"));
    expect(updateBody).toContain("!RolePermissions::has($user, 'personeller.ucret.manage')");
    const org = src.slice(src.indexOf("public static function organizasyonDegisikligi("));
    expect(org).toContain("self::assertWriteRole($user, 'personeller.update');");
    expect(src).toContain("EffectivePermissionResolver::hasActiveDeny");
  });

  it("legacy erişim, geçerli DENY, süre ve hassas kilit ayrımı", () => {
    const php =
      process.platform === "win32"
        ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
        : "php";
    const result = spawnSync(php, [resolve(root, "tests/php/DinamikYetkiTamamlamaTestRunner.php")], {
      encoding: "utf8",
      cwd: root
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-dinamik-yetki-tamamlama: OK");
    expect(result.stdout).toContain("[PASS] Muhasebe kişisel DENY yazmayı kapatır: personeller.update");
    expect(result.stdout).toContain("[PASS] Bölüm Yöneticisi geçerli DENY yazmayı kapatır: personeller.create");
    expect(result.stdout).toContain("[PASS] Sonradan konan hassas DENY kilitli değildir");
    expect(result.stdout).toContain("[PASS] Muhasebe rol varsayılanıyla yazar (t900 kararı): personeller.create");
    expect(result.stdout).toContain("[PASS] assertWriteRole içinde rol adı açılımı yok");
    expect(result.stdout).not.toContain("[FAIL]");
  });
});
