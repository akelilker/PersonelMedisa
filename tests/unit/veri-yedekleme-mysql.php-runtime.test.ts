import { resolve } from "node:path";
import { beforeAll, describe, expect, it } from "vitest";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/VeriYedeklemeMysqlTestRunner.php");

beforeAll(async () => {
  await ensureDisposableMariaDbEnv();
});

describe("Veri Yedekleme (MariaDB)", () => {
  it("Yedek Al dökümü izole veritabanına birebir geri yüklenir", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-veri-yedekleme-mysql: OK");
    expect(result.stdout).toContain("[PASS] geri yükleme: şema, satır sayısı ve içerik checksum farkı yok");
    expect(result.stdout).toContain("[PASS] değiştirilmiş yedek indirilmez");
    expect(result.stdout).toContain("[PASS] generated (VIRTUAL/STORED) kolonlar yazılmaz, geri yüklemede yeniden hesaplanır");
    expect(result.stdout).toContain("[PASS] yalnız son 7 yedek saklanır");
  }, 120_000);
});
