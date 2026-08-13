import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { isSgkKodSecilebilir, SGK_AKTIFLIK_DURUMU_LABEL } from "../../src/api/sgk-katalog-hazirlik.api";

const migration040 = readFileSync("api/migrations/040_sgk_mevzuat_canonical_schema.sql", "utf8");
const contracts = readFileSync("api/src/Services/Payroll/SgkKatalogContracts.php", "utf8");
const importValidator = readFileSync("api/src/Services/Payroll/SgkKatalogImportValidator.php", "utf8");
const tamlik = readFileSync("api/src/Services/Payroll/SgkKatalogTamlikService.php", "utf8");
const coklu = readFileSync("api/src/Services/Payroll/SgkCokluNedenValidator.php", "utf8");
const panel = readFileSync("src/features/raporlar/components/SgkKatalogHazirlikPanel.tsx", "utf8");
const mock = readFileSync("src/api/sgk-katalog-hazirlik.mock.ts", "utf8");

describe("S98 SGK mevzuat schema hardening", () => {
  it("040 additive canonical columns and no catalog seed", () => {
    expect(migration040).toContain("aktiflik_durumu");
    expect(migration040).toContain("sifir_gun_sifir_kazanc_durumu");
    expect(migration040).toContain("belge_saklama_ibraz_durumu");
    expect(migration040).toContain("yabanci_kullanim_durumu");
    expect(migration040).toContain("portal_teyit_durumu");
    expect(migration040).toContain("mevzuat_kurallari_json");
    expect(migration040).toContain("ADD COLUMN IF NOT EXISTS");
    expect(migration040).toContain("SGK_EKSIK_GUN_BELGELERI_20180417");
    expect(migration040).toContain("2018-04-17");
    expect(migration040).toContain("PASIF");
    expect(migration040).toContain("yerine_gecen_kaynak_id");
    expect(migration040).not.toMatch(/INSERT\s+INTO\s+sgk_eksik_gun_kodlari/i);
    expect(migration040).not.toMatch(/\b(?:DROP\s+COLUMN|DROP\s+TABLE|TRUNCATE|DELETE\s+FROM)\b/i);
    expect(migration040).not.toMatch(/UPDATE\s+sgk_eksik_gun_kodlari/i);
  });

  it("locks official 07/20/18-27/foreign/historical rules in contracts", () => {
    expect(contracts).toContain("assert07PuantajRules");
    expect(contracts).toContain("assert20UcretsizYolIzniRules");
    expect(contracts).toContain("assert1827Combination");
    expect(contracts).toContain("assertYabanciKodIzni");
    expect(contracts).toContain("assertKod22_29EvidenceGate");
    expect(contracts).toContain("projectCanonicalToLegacy");
    expect(contracts).toContain("ISVERENCE_SAKLA_TALEPTE_IBRAZ");
    expect(contracts).toContain("YABANCI_TEMEL_KODLAR");
    expect(contracts).toContain("'26'");
    expect(contracts).toContain("TARIHSEL_KOD_AKTIF_RED");
    expect(contracts).toContain("EXPERT_DRAFT");
  });

  it("replaces blanket 22-29 reject with evidence gate while keeping 22-25 fail-closed", () => {
    expect(importValidator).toContain("assertKod22_29EvidenceGate");
    expect(importValidator).toContain("import_yapilabilir_mi");
    expect(importValidator).toContain("gecerlilik_tarih_durumu");
    expect(importValidator).toContain("CELISKI_TARIH_DURUMU");
    expect(importValidator).toContain("yazma_endpoint_aktif_mi");
    expect(importValidator).toContain("aktiflik_durumu");
    expect(importValidator).toContain("legacy_projection");
    expect(contracts).toContain("KAYNAKSIZ_KOD_ARALIGI_22_29");
    expect(contracts).toContain("resmi_primary_kod_kaniti_var_mi");
  });

  it("S106 three-level tamlik: kisitli path open, DOGRULANMIS_TAM gated; blocks TEYITSIZ as full blocker", () => {
    expect(tamlik).toContain("TEYITSIZ_SIFIR_GUN");
    expect(tamlik).toContain("PORTAL_TEYIT_BEKLIYOR");
    expect(tamlik).toContain("EXPERT_DRAFT_TEK_BASINA_YETERSIZ");
    expect(tamlik).toContain("TARIHSEL_KOD_GUNCEL_AKTIF");
    expect(tamlik).toContain("RESMI_KAYNAKLI_KISITLI");
    expect(tamlik).toContain("limitedBlockers");
    expect(tamlik).toContain("limitedWarnings");
    expect(tamlik).toContain("'dogrulanmis_tam_secilebilir_mi' => \$dogrulanmisTamEligible");
    expect(tamlik).toContain("'import_yazma_aktif_mi' => \$importYazmaAktif");
    expect(coklu).toContain("assert1827Combination");
    expect(coklu).toContain("ozel_18_27_kurali_uygulandi_mi");
  });

  it("frontend/mock expose canonical aktiflik labels and never select TEYITSIZ", () => {
    const api = readFileSync("src/api/sgk-katalog-hazirlik.api.ts", "utf8");
    expect(panel).toContain("SGK_AKTIFLIK_DURUMU_LABEL");
    expect(panel).toContain("TEYITSIZ");
    expect(api).toContain("PORTAL TEYİDİ BEKLİYOR");
    expect(api).toContain("BAĞLAMA ÖZGÜ");
    expect(mock).toContain("teyitsiz_secilebilir_mi: false");
    expect(mock).toContain("BAĞLAMA ÖZGÜ");
    expect(isSgkKodSecilebilir({
      aktiflik_durumu: "AKTIF",
      portal_teyit_durumu: "TEYIT_EDILDI",
      sifir_gun_sifir_kazanc_durumu: "IZINLI"
    })).toBe(true);
    expect(isSgkKodSecilebilir({
      aktiflik_durumu: "PORTAL_TEYIT_BEKLIYOR",
      portal_teyit_durumu: "TEYIT_BEKLIYOR",
      sifir_gun_sifir_kazanc_durumu: "TEYITSIZ"
    })).toBe(false);
    expect(isSgkKodSecilebilir({
      aktiflik_durumu: "TARIHSEL",
      portal_teyit_durumu: "TARIHSEL",
      sifir_gun_sifir_kazanc_durumu: "YASAK"
    })).toBe(false);
    expect(SGK_AKTIFLIK_DURUMU_LABEL.BAGLAMA_OZGUN).toBe("BAĞLAMA ÖZGÜ");
  });

  it("migration sequence ends with 040 and parity last file updated", () => {
    const names = readdirSync(resolve("api/migrations")).filter((n) => n.endsWith(".sql")).sort();
    expect(names.some((n) => n.startsWith("040_"))).toBe(true);
    expect(names.at(-1)).toBe("065_personel_org_structure.sql");
  });
});
