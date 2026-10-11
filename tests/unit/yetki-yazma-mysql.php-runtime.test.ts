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
      "[PASS] K5: bildirim yalnız diğer GY'ye (aktöre ve hedefe değil)",
      "[PASS] koyan pasif olsa da başka GY kısıtı kaldırabilir",
      "[PASS] K3: uygun başka GY varken atayan ALLOW veremez",
      "[PASS] K3: uygun başka GY yokken atayan verir + K3_ISTISNA_TEK_YONETICI (iki yöneticili kurulum kilitlenmez)",
      "[PASS] rol değişince istisnalar ROL_DEGISTI ile iptal + her biri audit",
      "[PASS] Kalıcı Sil hedefi kilitliyken yetki yazımı bekler ve yarım kayıt bırakmaz",
      "[PASS] K1 ZORUNLU: kimliksiz GY yetki veremez",
      "[PASS] 100 uygulanmamış: yazma 503 YETKI_SEMASI_HAZIR_DEGIL"
    ]) {
      expect(result.stdout).toContain(line);
    }
  });
});
