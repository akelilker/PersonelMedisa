import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { evaluatePersonelCompleteness } from "../../src/features/personeller/personel-missing-info";
import type { Personel } from "../../src/types/personel";

function read(rel: string): string {
  return readFileSync(resolve(process.cwd(), rel), "utf8");
}

describe("canonical payroll SGK integrity", () => {
  it("owns same-company SGK check in a single service", () => {
    const consistency = read("api/src/Services/Personel/PersonelSgkCompanyConsistency.php");
    expect(consistency).toContain("class PersonelSgkCompanyConsistency");
    expect(consistency).toContain("ERROR_MISMATCH");
    expect(consistency).toContain("ERROR_REQUIRED");
    expect(consistency).toContain("assertRequiredForActiveIc");
    expect(consistency).toContain("evaluateAgainstSirket");

    const kalici = read("api/src/Services/Personel/PersonelKaliciSubeDegisikligiService.php");
    expect(kalici).toContain("PersonelSgkCompanyConsistency::evaluateAgainstSirket");
    expect(kalici).not.toMatch(/SELECT sirket_id FROM sgk_isverenler/);

    const org = read("api/src/Services/Personel/PersonelOrganizasyonDegisikligiService.php");
    expect(org).toContain("PersonelSgkCompanyConsistency::evaluate");
    expect(org).toContain("assertSgkCompanyCompatible");
  });

  it("fail-closes active IC create without SGK and wires create/import owners", () => {
    const canonical = read("api/src/Services/Personel/PersonelCanonicalValidator.php");
    expect(canonical).toContain("assertRequiredForActiveIc");
    expect(canonical).toContain("Aktif IC personel icin SGK isvereni zorunludur");

    const create = read("api/src/Services/Personel/PersonelCreateService.php");
    expect(create).toContain("PersonelSgkCompanyConsistency::assertRequiredForActiveIc");
    expect(create).toContain("PersonelSgkCompanyConsistency::assertCompatible");

    const importDry = read("api/src/Services/Personel/PersonelImportDryRunService.php");
    expect(importDry).toContain("PersonelSgkCompanyConsistency::assertCompatible");

    const incomplete = read("api/src/Services/Personel/PersonelIncompleteCreateService.php");
    expect(incomplete).not.toContain("assertRequiredForActiveIc");
  });

  it("marks IC SGK missing in completeness and ignores DIS", () => {
    const service = read("api/src/Services/Personel/PersonelCompletenessService.php");
    expect(service).toContain("'key' => 'sgk_isveren_id'");
    expect(service).toContain("sgk_isveren_id");
    expect(service).toMatch(/calisanKapsami\} <> 'DIS_KAYNAK'[\s\S]*sgk_isveren_id/);

    const icMissing: Personel = {
      id: 1,
      tc_kimlik_no: "12345678901",
      ad: "Ada",
      soyad: "Yılmaz",
      aktif_durum: "AKTIF",
      calisan_kapsami: "IC_PERSONEL",
      sicil_no: "I-1",
      ise_giris_tarihi: "2026-01-01",
      telefon: "555",
      dogum_tarihi: "1990-01-01",
      sube_id: 1,
      sgk_isveren_id: null,
      calisma_lokasyonu_id: 1,
      bagli_amir_id: 1,
      departman_id: 1,
      bolum_id: 1,
      birim_id: 1,
      gorev_id: 1,
      pozisyon_id: 1,
      personel_tipi_id: 1
    };
    const missing = evaluatePersonelCompleteness(icMissing);
    expect(missing.is_complete).toBe(false);
    expect(missing.critical_missing_labels).toContain("SGK İşveren");

    const icOk = evaluatePersonelCompleteness({ ...icMissing, sgk_isveren_id: 1 });
    expect(icOk.critical_missing_labels).not.toContain("SGK İşveren");

    const dis: Personel = {
      id: 2,
      tc_kimlik_no: null,
      ad: "Ali",
      soyad: null,
      aktif_durum: "AKTIF",
      calisan_kapsami: "DIS_KAYNAK",
      sicil_no: "D-1",
      ise_giris_tarihi: "2026-01-01",
      sube_id: 1,
      sgk_isveren_id: null,
      calisma_lokasyonu_id: 1,
      bagli_amir_id: 1,
      departman_id: 1,
      bolum_id: 1,
      birim_id: 1,
      gorev_id: 1,
      pozisyon_id: 1
    };
    expect(evaluatePersonelCompleteness(dis).critical_missing_labels).not.toContain("SGK İşveren");
  });

  it("keeps payroll candidate resolver employment-based (no PASIF shortcut)", () => {
    const snap = read("api/src/Services/MaasHesaplamaSnapshotService.php");
    expect(snap).toContain("resolvePersonnelSet");
    expect(snap).toContain("cikis_tarihi");
    expect(snap).toContain("BLOCKER_SGK_ISVEREN_MISSING");
    // Must not exclude PASIF solely by aktif_durum — archive residual is data remediation.
    expect(snap).not.toMatch(/aktif_durum\s*=\s*'AKTIF'/);
  });

  it("exposes SGK referans list for create contract", () => {
    expect(read("api/src/Router.php")).toContain("/referans/sgk-isverenler");
    expect(read("api/src/Controllers/ReferansController.php")).toContain("function sgkIsverenler");
    expect(read("src/api/endpoints.ts")).toContain("sgkIsverenler");
    expect(read("src/features/personeller/personel-create-utils.ts")).toContain("sgk_isveren_id");
    expect(read("src/features/personeller/components/PersonelCreateFields.tsx")).toContain("SGK İşveren");
  });
});
