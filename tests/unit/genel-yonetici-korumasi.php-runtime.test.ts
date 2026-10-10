import { execFileSync, spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * Yönetici hesap korumaları (Fast CI, DB'siz): kural matrisi + controller kaynak kilidi.
 * MariaDB + eşzamanlılık senaryoları: genel-yonetici-korumasi-mysql.php-runtime.test.ts (CI Full).
 */
const root = process.cwd();
const runnerPath = resolve(root, "tests/php/GenelYoneticiKorumasiRulesTestRunner.php");
const controllerSrc = readFileSync(resolve(root, "api/src/Controllers/YonetimController.php"), "utf8");

function methodBody(name: string) {
  const start = controllerSrc.indexOf(`function ${name}(`);
  expect(start, name).toBeGreaterThan(-1);
  const next = controllerSrc.indexOf("\n    public static function ", start + 1);
  const nextPrivate = controllerSrc.indexOf("\n    private static function ", start + 1);
  const ends = [next, nextPrivate].filter((value) => value > -1);
  return controllerSrc.slice(start, ends.length ? Math.min(...ends) : undefined);
}

describe("Genel Yönetici korumaları", () => {
  it("kural matrisi (yetki yükseltme, kendi rol/durum, transaction şartı)", () => {
    const php = process.platform === "win32"
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const result = spawnSync(php, [runnerPath], { encoding: "utf8", cwd: root });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-genel-yonetici-korumasi-rules: OK");
    expect(result.stdout).toContain("[PASS] 1 SY yeni GY olusturamaz");
    expect(result.stdout).toContain("[PASS] 9 kendi rolunu degistiremez (SY → GY)");
    expect(result.stdout).toContain("[PASS] 16 son yonetici kontrolu transaction disinda calismaz");
  });

  it("controller: kurallar her yazma yolunda, son-yönetici kontrolü UPDATE'ten önce aynı transaction içinde", () => {
    const create = methodBody("kullaniciOlustur");
    expect(create).toContain("GenelYoneticiKorumasi::assertActorMayAssign($user, null, null, null, $rol, $durum)");

    const update = methodBody("kullaniciGuncelle");
    expect(update).toContain("GenelYoneticiKorumasi::assertActorMayAssign(");
    const begin = update.indexOf("$pdo->beginTransaction();");
    const guard = update.indexOf("GenelYoneticiKorumasi::assertNotLastActiveAdminLocked($pdo, $kullaniciId, $rol, $durum)");
    const write = update.indexOf("'UPDATE users SET username = :username");
    expect(begin).toBeGreaterThan(-1);
    expect(guard).toBeGreaterThan(begin);
    expect(write).toBeGreaterThan(guard);

    const revoke = methodBody("kullaniciErisimKaldir");
    expect(revoke).toContain("SELF_ACCESS_REMOVAL_FORBIDDEN");
    expect(revoke).toContain("GenelYoneticiKorumasi::assertActorMayRevoke($user, (string) $existing['rol'])");
    const rBegin = revoke.indexOf("$pdo->beginTransaction();");
    const rGuard = revoke.indexOf("GenelYoneticiKorumasi::assertNotLastActiveAdminLocked(");
    const rWrite = revoke.indexOf("'UPDATE users SET durum = :durum");
    expect(rGuard).toBeGreaterThan(rBegin);
    expect(rWrite).toBeGreaterThan(rGuard);

    const owner = readFileSync(resolve(root, "api/src/Services/Auth/GenelYoneticiKorumasi.php"), "utf8");
    expect(owner).toContain("rol = 'GENEL_YONETICI' AND durum = 'AKTIF' ORDER BY id FOR UPDATE");
    // Kişi/kullanıcı adı istisnası yok.
    expect(owner).not.toMatch(/ilker|serhan|sinem|sedanur/i);
  });
});
