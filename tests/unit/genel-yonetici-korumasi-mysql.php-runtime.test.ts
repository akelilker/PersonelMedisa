import { resolve } from "node:path";
import { beforeAll, describe, expect, it } from "vitest";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/GenelYoneticiKorumasiMysqlTestRunner.php");

beforeAll(async () => {
  await ensureDisposableMariaDbEnv();
});

describe("Genel Yönetici korumaları — MariaDB + eşzamanlılık", () => {
  it("yetki yükseltme, kendi rol/durum, son aktif GY ve FOR UPDATE kilidi", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-genel-yonetici-korumasi-mysql: OK");
    for (const line of [
      "[PASS] 1 SISTEM_YONETICISI GENEL_YONETICI hesabi olusturamaz (403)",
      "[PASS] 3 SISTEM_YONETICISI kendini GENEL_YONETICI yapamaz",
      "[PASS] 6 SISTEM_YONETICISI GENEL_YONETICI erisimini kaldiramaz",
      "[PASS] 9 kullanici kendini pasife alamaz",
      "[PASS] 13 son aktif GY rolden dusurulemez",
      "[PASS] 15 son aktif GY erisimi kaldirilamaz",
      "[PASS] 17 ikinci dusurme istegi aktif GY kilidini bekliyor (FOR UPDATE)",
      "[PASS] 18 kilit birakilinca ikinci istek guncel sayimi gorur ve reddedilir",
      "[PASS] 20 eszamanli karsilikli dusurmede tam bir istek basarili, digeri LAST_ADMIN_PROTECTED"
    ]) {
      expect(result.stdout).toContain(line);
    }
  });
});
