import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  buildCreatePersonelPayload,
  normalizePersonelAd,
  normalizePersonelSoyad
} from "../../src/features/personeller/personel-create-utils";
import { buildPersonelUpdatePayload } from "../../src/features/personeller/personel-edit-utils";
import { INITIAL_CREATE_PERSONEL_FORM } from "../../src/hooks/usePersoneller";
import { normalizeKullaniciAdSoyadForWrite } from "../../src/lib/yonetim/kullanici-ad-soyad";

const canonicalAd = "Saıf Tareq Jasım";
const canonicalSoyad = "Al-Gburı";

const validCreateForm = {
  ...INITIAL_CREATE_PERSONEL_FORM,
  tcKimlikNo: "12345678901",
  ad: canonicalAd,
  soyad: canonicalSoyad,
  dogumTarihi: "1990-05-15",
  telefon: "05321234567",
  acilDurumKisi: "Yakın Kişi",
  acilDurumTelefon: "05329876543",
  iseGirisTarihi: "2026-06-01",
  subeId: "1",
  sgkIsverenId: "1",
  departmanId: "3",
  gorevId: "1",
  personelTipiId: "1"
};

function readSource(relativePath: string) {
  return readFileSync(resolve(process.cwd(), relativePath), "utf8");
}

describe("personel soyad WRITE canonical case", () => {
  it("normalizePersonelSoyad canonical case'i korur, yalnız boşluk temizler", () => {
    expect(normalizePersonelSoyad(canonicalSoyad)).toBe(canonicalSoyad);
    expect(normalizePersonelSoyad("  Al-Gburı  ")).toBe("Al-Gburı");
    expect(normalizePersonelSoyad("Al  Gburı")).toBe("Al Gburı");
  });

  it("buildCreatePersonelPayload soyadı uppercase'e çevirmez", () => {
    const payload = buildCreatePersonelPayload(validCreateForm);
    expect(payload.soyad).toBe(canonicalSoyad);
  });

  it("buildPersonelUpdatePayload soyadı uppercase'e çevirmez", () => {
    const payload = buildPersonelUpdatePayload(
      {
        calisanKapsami: "IC_PERSONEL",
        tcKimlikNo: "12345678901",
        ad: canonicalAd,
        soyad: canonicalSoyad,
        dogumTarihi: "1990-05-15",
        telefon: "05321234567",
        sicilNo: "197",
        iseGirisTarihi: "2026-06-01",
        departmanId: "",
        bolumId: "",
        birimId: "",
        gorevId: "",
        pozisyonId: "",
        bagliAmirId: "",
        ucretTipiId: "",
        maasTutari: "",
        primKuraliId: "",
        effectiveDate: ""
      },
      false,
      { includeWageFields: false }
    );

    expect(payload.soyad).toBe(canonicalSoyad);
  });

  it("normalizePersonelAd canonical case'i korur, yalnız boşluk temizler", () => {
    expect(normalizePersonelAd(canonicalAd)).toBe(canonicalAd);
    expect(normalizePersonelAd("  Saıf   Tareq  Jasım  ")).toBe(canonicalAd);
    expect(normalizePersonelAd("SAIF TAREQ JASIM")).toBe("SAIF TAREQ JASIM");
  });

  it("buildCreatePersonelPayload adı uppercase/title case'e çevirmez", () => {
    const payload = buildCreatePersonelPayload(validCreateForm);
    expect(payload.ad).toBe(canonicalAd);
  });
});

describe("Yönetim kullanıcı ad_soyad WRITE canonical case", () => {
  it("canonical ad_soyad değerini birebir korur", () => {
    expect(normalizeKullaniciAdSoyadForWrite(`${canonicalAd} ${canonicalSoyad}`)).toBe(
      `${canonicalAd} ${canonicalSoyad}`
    );
  });

  it("yalnız boşluk temizler, case'e dokunmaz", () => {
    expect(normalizeKullaniciAdSoyadForWrite(`  ${canonicalAd}   ${canonicalSoyad}  `)).toBe(
      `${canonicalAd} ${canonicalSoyad}`
    );
  });
});

describe("görünüm katmanı (SOYAD BÜYÜK) korunur", () => {
  it("YonetimPaneliPage display formatı soyadı hâlâ uppercase gösterir", () => {
    const source = readSource("src/features/yonetim/pages/YonetimPaneliPage.tsx");

    // Display formatter soyadı uppercase yapar.
    expect(source).toContain('soyad.toLocaleUpperCase("tr-TR")');
    // Display formatter list/card görünümünde kullanılmaya devam eder.
    expect(source).toContain("formatAdSoyad(item.ad_soyad)");
    // WRITE payload'ı artık display formatını değil write normalizasyonunu kullanır.
    expect(source).toContain("const adSoyad = normalizeKullaniciAdSoyadForWrite(form.adSoyad);");
  });
});
