import { resolve } from "node:path";
import { beforeAll, describe, expect, it } from "vitest";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/YetkiYazmaMysqlTestRunner.php");

beforeAll(async () => {
  await ensureDisposableMariaDbEnv();
});

describe("Dinamik yetki P3 — yazma kuralları (MariaDB)", () => {
  it("kendine yasak, global-only, kırmızı liste, süre, K1, K3, K5, rol değişikliği, Kalıcı Sil kilidi", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-yetki-yazma-mysql: OK");
    for (const line of [
      "[PASS] kendine yetki/kısıt değiştirilemez",
      "[PASS] şube kapsamlı istisna reddedilir (yalnız global)",
      "[PASS] GY sistem hakkı DENY reddi (yetenek tabanlı son yönetici korunur)",
      "[PASS] süreli ALLOW > 365 gün reddedilir",
      "[PASS] kalıcı ALLOW (bitiş boş) verilir",
      "[PASS] K1 UYARI (Medisa): kimliksiz GY kilitlenmez, uyarı audit'te",
      "[PASS] K5 üç GY: hedef GY ve diğer aktif GY bildirim alır, aktör almaz",
      "[PASS] K5 iki GY: aktör + hedef → yalnız hedef GY bildirim alır, aktör almaz",
      "[PASS] K5: personel kaydı olmayan GY'ye kullanıcı bazlı bildirim (personel_id NULL)",
      "[PASS] K1 UYARI: aynı kişinin hesabına yetki verme her durumda reddedilir",
      "[PASS] K1 UYARI: aynı kişinin kısıtını kaldırma her durumda reddedilir",
      "[PASS] K1 ZORUNLU: aynı kişinin ikinci hesabına verme ENGELLENDİ",
      "[PASS] personel kaydı olmayan ve kimliği bağlanmayan yönetici aynı kişi sayılmaz",
      "[PASS] pasif hesap varken yeni hesap açılmaz; mevcut hesap incelenir; T.C. yanıtta yok",
      "[PASS] aynı isimli farklı personel karıştırılmaz; ikisine de hesap açılır",
      "[PASS] aynı T.C. ile ikinci hesap açılmaz; T.C. yanıtta yok; eski hesap birleştirilmez",
      "[PASS] users.actor_identity_id 1:1 dokunulmadı (SGK/dört göz değişmez)",
      "[PASS] koyan pasif olsa da başka GY kısıtı kaldırabilir",
      "[PASS] K3: uygun başka GY varken atayan ALLOW veremez",
      "[PASS] K3: uygun başka GY yokken atayan verir + K3_ISTISNA_TEK_YONETICI (iki yöneticili kurulum kilitlenmez)",
      "[PASS] rol değişince istisnalar ROL_DEGISTI ile iptal + her biri audit",
      "[PASS] Kalıcı Sil hedefi kilitliyken yetki yazımı bekler ve yarım kayıt bırakmaz",
      "[PASS] K1 ZORUNLU: kimliksiz GY yetki veremez",
      "[PASS] 100 uygulanmamış: yazma 503 YETKI_SEMASI_HAZIR_DEGIL",
      "[PASS] P4: mevcut GY tam kalır; yeni GY operasyonel yazamaz, okur ve yetki yönetir. Sapma: ",
      "[PASS] P4: yeni GY kendi operasyonel yetkisini genişletemez",
      "[PASS] P4: başka GY ek operasyonel izin açar; açılmayan yazma kapalı kalır",
      "[PASS] P4: rol değişince eski istisnalar kapanır; yeniden GY ataması yine kısıtlı başlar",
      "[PASS] P4: başka aktif GY yokken atama tam yetkili",
      "[PASS] P4: yalnız pasif GY varken ilk aktif yönetici tam yetkili",
      "[PASS] P4: başka şirkete bağlanan yeni GY, sistemde aktif GY varken kısıtlı başlar",
      "[PASS] P4: genel yönetici bildirim onayı ALLOW ile verilmez ve başlangıç kısıtı kalkmaz",
      "[PASS] P4: hassas başlangıç kısıtı kalkmaz; operasyonel yazma açılır; tam yetkili GY korunur. Sapma: ",
      "[PASS] 100 uygulanmamış: mevcut GY işlemleri rol varsayılanıyla sürer, kısıt uygulanmaz"
    ]) {
      expect(result.stdout).toContain(line);
    }
  });
});
