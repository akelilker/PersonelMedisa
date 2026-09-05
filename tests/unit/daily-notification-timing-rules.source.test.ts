import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  formatEksikGirisAttention,
  formatEksikGirisCompleteConfirm,
  formatPazarMesaiMondayPrompt
} from "../../src/lib/bildirim/gunluk-bildirim-timing-copy";

const root = resolve(__dirname, "../..");

function read(pathFromRoot: string) {
  return readFileSync(resolve(root, pathFromRoot), "utf8");
}

describe("daily notification timing rules", () => {
  it("exposes exact missing-entry and Monday Sunday-mesai copy", () => {
    expect(formatEksikGirisCompleteConfirm(3)).toBe(
      "3 Personel Henüz Giriş Yapmadı. Önce Kontrol Etmeniz Önerilir. Yine de Devam Etmek İstiyor musunuz?"
    );
    expect(formatEksikGirisAttention(3)).toBe("3 Personel Henüz Giriş Yapmadı");
    expect(formatPazarMesaiMondayPrompt(3)).toBe(
      "Dün Mesaiye Gelen 3 Personel Var. Bildirimi Tamamlamak İster misiniz?"
    );
  });

  it("wires Sunday Monday deadline and live eksik_giris on PHP owners", () => {
    const service = read("api/src/Services/Bildirim/BugunPersonelDurumuService.php");
    expect(service).toContain("SUNDAY_REVIEW_DEADLINE = '12:00'");
    expect(service).toContain("isMissingEntryEvidence");
    expect(service).toContain("isSundayDate");
    expect(service).toContain("next monday");
    expect(service).toContain("eksik_giris");
    expect(service).toContain("Pazar Mesaisi Bildirimi Süresi Geçti");
    expect(service).not.toContain("qr_attendance");

    const birim = read("api/src/Services/Bildirim/BirimAmiriGunlukDurumService.php");
    expect(birim).toContain("pazar_mesai_prompt");
    expect(birim).toContain("attendance_proof_count");
    expect(birim).toContain("Dün Mesaiye Gelen");

    const controller = read("api/src/Controllers/BildirimlerController.php");
    expect(controller).toContain("countEksikGiris");
    expect(controller).toContain("'eksik_giris'");
  });

  it("keeps completion confirm Evet/Hayır and red eksik on BIRIM / IK surfaces", () => {
    const page = read("src/features/bildirimler/pages/BildirimlerPage.tsx");
    expect(page).toContain("formatEksikGirisCompleteConfirm");
    expect(page).toContain('confirmLabel="Evet"');
    expect(page).toContain('cancelLabel="Hayır"');
    expect(page).toContain("gunluk-tamamla-eksik-giris-dialog");
    expect(page).toContain("gunluk-eksik-giris-warning");

    const home = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(home).toContain("birim-amiri-pazar-mesai-prompt");
    expect(home).toContain("birim-amiri-eksik-giris-warning");
    expect(home).toContain("focusTarih");
    expect(home).not.toContain("/self/qr-okut");

    const modal = read("src/features/bildirimler/components/BugunPersonelDurumuModal.tsx");
    expect(modal).toContain("eksik_giris");
    expect(modal).toContain("Personel Henüz Giriş Yapmadı");

    const detail = read("src/features/bildirimler/pages/GunlukTamamlamaDetayPage.tsx");
    expect(detail).toContain("tamamlama-eksik-giris-warning");
  });

  it("keeps migration 085 correction audit owner unchanged", () => {
    const migration085 = read("api/migrations/085_gunluk_bildirim_duzeltme_auditleri.sql");
    const controller = read("api/src/Controllers/BildirimlerController.php");
    expect(migration085).toContain("eski_bildirim_turu");
    expect(controller).toContain("GunlukBildirimDuzeltmeAuditService");
    expect(controller).toContain("gunluk_bildirim.correct_scoped");
  });
});
