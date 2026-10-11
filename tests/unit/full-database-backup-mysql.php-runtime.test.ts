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
      "[PASS] web köküne yazma reddedilir"
    ]) {
      expect(result.stdout).toContain(line);
    }
  });
});
