import { resolve } from "node:path";
import { beforeAll, describe, expect, it } from "vitest";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/FullDatabaseBackupMysqlTestRunner.php");

beforeAll(async () => {
  await ensureDisposableMariaDbEnv();
});

describe("Tam veritabanı yedeği (MariaDB)", () => {
  it("dökümü izole veritabanına geri yükler ve yetki şemasını sınıflar", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-full-database-backup-mysql: OK");
    for (const line of [
      "[PASS] tam yedek ve izole geri yükleme PASS",
      "[PASS] üç yetki tablosu yokken sınıflama ABSENT",
      "[PASS] döküm aktarılmadı",
      "[PASS] noktalı virgül ve Türkçe karakter geri geldi",
      "[PASS] tetikleyici geri yüklenen veritabanında silmeyi engelledi",
      "[PASS] canlı veritabanının üzerine geri yükleme reddedildi",
      "[PASS] tek yetki tablosu PARTIAL sayılır",
      "[PASS] web köküne yazma reddedilir",
      "[PASS] bozuk yedek geri yüklenmez",
      "[PASS] hata kodu korunur RESTORE_PRIVILEGE_ABSENT",
      "[PASS] genel CREATE ve DROP yetkisi olmayan hesap ayrılır",
      "[PASS] olmayan hazır geri yükleme veritabanı reddedilir",
      "[PASS] hedef canlı veritabanıyla aynıysa reddedilir",
      "[PASS] hazır veritabanında yetersiz yetki reddedilir",
      "[PASS] başka uygulama verisi silinmeden işlem durur",
      "[PASS] genel yetki olmadan hazır veritabanına tam yedek PASS",
      "[PASS] hazır veritabanı kalır ve test nesneleri temizlenir",
      "[PASS] migration 100 kapısı hazır yedekte de yetki tablosunu durdurur",
      "[PASS] temizlik başarısızsa test verisi kalır ve başarı sayılmaz"
    ]) {
      expect(result.stdout).toContain(line);
    }
  });
});
