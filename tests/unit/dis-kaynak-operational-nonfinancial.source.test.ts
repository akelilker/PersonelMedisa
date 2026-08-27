import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, resolve } from "node:path";
import { evaluatePersonelCompleteness } from "../../src/features/personeller/personel-missing-info";
import type { Personel } from "../../src/types/personel";

function read(path: string) {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("DIS_KAYNAK operasyonel/non-financial model", () => {
  it("ayırır: zaman operasyonu vs finansal fail-closed owner'lar", () => {
    const service = read("api/src/Services/Personel/PersonelCalisanKapsamService.php");
    expect(service).toContain("assertTimeOperationalEligible");
    expect(service).toContain("assertFinancialEligible");
    expect(service).toContain("PERSONEL_FINANSAL_KAPSAM_DISI");
    expect(service).toContain("PERSONEL_OPERASYON_ORG_SCOPE_YOK");
    expect(service).toContain("DIS_KAYNAK_SGK_ISVEREN_YASAK");
    expect(service).toContain("sqlFinancialEligiblePredicate");
    expect(service).toContain("sqlTimeOperationalEligiblePredicate");
  });

  it("finansal caller'lar assertFinancialEligible kullanır", () => {
    for (const path of [
      "api/src/Services/SgkPrimGunuService.php",
      "api/src/Controllers/MaasHesaplamaController.php",
      "api/src/Services/PersonelUcretService.php",
      "api/src/Controllers/FazlaCalismaOdemeTercihiController.php",
      "api/src/Controllers/SerbestZamanController.php"
    ]) {
      expect(read(path), path).toContain("assertFinancialEligible");
      expect(read(path), path).not.toContain("assertTimeOperationalEligible");
    }
  });

  it("zaman operasyon caller'lar assertTimeOperationalEligible kullanır", () => {
    for (const path of [
      "api/src/Services/Qr/QrAttendanceEventService.php",
      "api/src/Controllers/PuantajController.php",
      "api/src/Controllers/BildirimlerController.php",
      "api/src/Controllers/SureclerController.php"
    ]) {
      expect(read(path), path).toContain("assertTimeOperationalEligible");
    }
  });

  it("DIS mobil capability: QR ve attendance açık, izin fail-closed", () => {
    const mobile = read("api/src/Services/SelfService/PersonelMobileCapabilityService.php");
    expect(mobile).toMatch(/DIS_KAYNAK[\s\S]*qr_scan'\s*=>\s*true/);
    expect(mobile).toMatch(/DIS_KAYNAK[\s\S]*attendance_correct'\s*=>\s*true/);
    expect(mobile).toMatch(/DIS_KAYNAK[\s\S]*izin_write'\s*=>\s*false/);
    expect(mobile).toContain("ÜCRET VE SGK TAHAKKUKU OLUŞTURMAZ");
  });

  it("DIS completeness: bolum/birim CRITICAL değil", () => {
    const dis: Personel = {
      id: 1,
      tc_kimlik_no: null,
      ad: "Ali",
      soyad: null,
      aktif_durum: "AKTIF",
      calisan_kapsami: "DIS_KAYNAK",
      sicil_no: "D-1",
      ise_giris_tarihi: "2026-01-01",
      bolum_id: null,
      birim_id: null,
      departman_id: undefined,
      gorev_id: undefined,
      personel_tipi_id: undefined
    };
    const result = evaluatePersonelCompleteness(dis);
    expect(result.is_complete).toBe(true);
    expect(result.critical_missing_labels).not.toContain("Bölüm");
    expect(result.critical_missing_labels).not.toContain("Birim");
  });

  it("IC completeness: bolum/birim CRITICAL kalır", () => {
    const ic: Personel = {
      id: 2,
      tc_kimlik_no: "12345678901",
      ad: "Veli",
      soyad: "Yılmaz",
      aktif_durum: "AKTIF",
      calisan_kapsami: "IC_PERSONEL",
      sicil_no: "I-1",
      ise_giris_tarihi: "2026-01-01",
      telefon: "555",
      dogum_tarihi: "1990-01-01",
      bolum_id: null,
      birim_id: null,
      departman_id: 1,
      gorev_id: 1,
      personel_tipi_id: 1
    };
    const result = evaluatePersonelCompleteness(ic);
    expect(result.is_complete).toBe(false);
    expect(result.critical_missing_labels).toContain("Bölüm");
    expect(result.critical_missing_labels).toContain("Birim");
  });

  it("geçici görevlendirme: explicit hedef_sube + atomik overlap + silent fallback yok", () => {
    expect(read("api/migrations/076_dis_kaynak_gecici_gorevlendirme.sql")).toContain(
      "personel_gecici_gorevlendirmeler"
    );
    const svc = read("api/src/Services/Personel/PersonelGeciciGorevlendirmeService.php");
    expect(svc).toContain("GECICI_GOREVLENDIRME_CAKISMA");
    expect(svc).toContain("hedef_sube_id");
    expect(svc).toContain("FOR UPDATE");
    expect(svc).toContain("validateHedefChain");
    expect(svc).toContain("Europe/Istanbul");
    expect(svc).not.toContain("ORDER BY sube_id ASC LIMIT 1");
    const ctx = read("api/src/Services/Personel/PersonelOperationalContextService.php");
    expect(ctx).toContain("resolveNow");
    expect(ctx).toContain("resolveAt");
    const org = read("api/src/Scope/OrgScope.php");
    expect(org).toContain("PersonelGeciciGorevlendirmeSchema::isReady");
    expect(org).toContain("appendPermanentOrAssignmentInFilter");
  });

  it("ambiguous assertOperationalEligible consumer kalmamalı", () => {
    const service = read("api/src/Services/Personel/PersonelCalisanKapsamService.php");
    expect(service).not.toMatch(/function\s+assertOperationalEligible\b/);
    expect(service).not.toMatch(/function\s+assertOperationalEligibleOrThrow\b/);

    const callers: string[] = [];
    const walk = (dir: string) => {
      for (const name of readdirSync(dir)) {
        const full = join(dir, name);
        if (statSync(full).isDirectory()) {
          walk(full);
          continue;
        }
        if (!full.endsWith(".php")) continue;
        if (full.replace(/\\/g, "/").endsWith("PersonelCalisanKapsamService.php")) continue;
        const text = readFileSync(full, "utf8");
        if (/assertOperationalEligible(OrThrow)?\s*\(/.test(text)) {
          callers.push(full.replace(/\\/g, "/"));
        }
      }
    };
    walk(resolve(process.cwd(), "api/src"));
    expect(callers, callers.join("\n")).toEqual([]);
  });

  it("doc 127 superseded ve 130 canonical", () => {
    expect(read("docs/guncel/127-external-worker-directory-only.md")).toContain("SUPERSEDED");
    expect(read("docs/guncel/130-dis-kaynak-operasyonel-finansal-ayrim.md")).toContain(
      "assertTimeOperationalEligible"
    );
  });
});
