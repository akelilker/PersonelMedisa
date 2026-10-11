import { resolve } from "node:path";
import { beforeAll, describe, expect, it } from "vitest";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/YetkiIstisnaMysqlTestRunner.php");

beforeAll(async () => {
  await ensureDisposableMariaDbEnv();
});

describe("Dinamik yetki P2 — migration 100 + okuma uçları (MariaDB)", () => {
  it("boş tabloda davranış aynı; DENY/ALLOW gerçek istekte; tetikleyiciler; GY-only okuma", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-yetki-istisna-mysql: OK");
    for (const line of [
      "[PASS] kanonik zincirin ucu migration 100",
      "[PASS] migration 100 veri yazmaz (iki tablo boş)",
      "[PASS] boş tablo: login etkin izinleri rol matrisiyle aynı (GENEL_YONETICI, 111)",
      "[PASS] boş tablo: /auth/yetkiler aynı ve istisna yok (MUHASEBE)",
      "[PASS] tek sorgu yüklemesi: iptal edilen ve süresi dolan satır gelmez",
      "[PASS] gerçek istek: DENY rol varsayılanını kapatır",
      "[PASS] gerçek uç: DENY edilen yonetim-paneli.manage 403",
      "[PASS] gerçek uç: GY sistem hakkı DENY ile kapanmaz",
      "[PASS] istisna DELETE engellenir",
      "[PASS] audit UPDATE engellenir",
      "[PASS] audit DELETE engellenir",
      "[PASS] 100 öncesi şema: loadActive boş döner",
      "[PASS] Kayseri ALLOW (düzenleme) Kayseri'de geçerli",
      "[PASS] Kayseri ALLOW Ankara'da geçersiz",
      "[PASS] Kayseri DENY diğer şubeleri engellemez",
      "[PASS] Kalıcı Sil: yalnız geçmiş (iptal/süresi dolmuş, veren, audit) olan hesap SİLİNEBİLİR",
      "[PASS] Kalıcı Sil: AKTİF (ileri tarihli dahil) istisnası olan hesap ENGELLENDİ",
      "[PASS] Kalıcı Sil: yetki geçmişi silinmedi/yeniden atanmadı (5 satır)",
      "[PASS] K1: politika satırı yok → UYARI (Medisa canlı)",
      "[PASS] yeni kurulum: ilk yönetici gerçek-kişi kimliği VERIFIED ve bağlı",
      "[PASS] yeni kurulum: K1 = ZORUNLU",
      "[PASS] yeni kurulum: ilk yönetici kilitlenmeden yetki verebilir",
      "[PASS] yeni kurulum ZORUNLU: kimliği doğrulanmamış GY kişiye özel yetki veremez"
    ]) {
      expect(result.stdout).toContain(line);
    }
  });
});
