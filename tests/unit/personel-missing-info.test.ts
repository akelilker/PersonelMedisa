import { describe, expect, it } from "vitest";
import {
  countMissingByEditTarget,
  evaluatePersonelCompleteness,
  getPersonelMissingFieldKeys,
  getPersonelMissingFields,
  resolvePersonelCompleteness
} from "../../src/features/personeller/personel-missing-info";
import type { Personel } from "../../src/types/personel";

const completePersonel: Personel = {
  id: 1,
  tc_kimlik_no: "12345678901",
  ad: "Ayşe",
  soyad: "Yılmaz",
  aktif_durum: "AKTIF",
  calisan_kapsami: "IC_PERSONEL",
  telefon: "05550000000",
  dogum_tarihi: "1992-03-14",
  sicil_no: "P-001",
  ise_giris_tarihi: "2023-02-01",
  departman_id: 3,
  bolum_id: 4,
  birim_id: 5,
  gorev_id: 6,
  personel_tipi_id: 1
};

describe("personel-missing-info", () => {
  it("tam IC_PERSONEL kaydında eksik alan üretmez", () => {
    const completeness = evaluatePersonelCompleteness(completePersonel);
    expect(completeness.is_complete).toBe(true);
    expect(completeness.missing_count).toBe(0);
    expect(getPersonelMissingFields(completePersonel)).toEqual([]);
  });

  it("IC_PERSONEL kritik boşluklarını tek canonical listede döndürür", () => {
    const missing = getPersonelMissingFields({
      ...completePersonel,
      tc_kimlik_no: " ",
      telefon: null,
      sicil_no: "",
      bolum_id: null,
      birim_id: null
    });

    expect(missing.map((field) => field.key)).toEqual([
      "tc_kimlik_no",
      "sicil_no",
      "telefon",
      "bolum_id",
      "birim_id"
    ]);
    expect(missing.every((field) => field.editTarget === "genel")).toBe(true);
    expect(missing.every((field) => field.severity === "CRITICAL")).toBe(true);
    expect(missing.find((field) => field.key === "telefon")?.category).toBe("ILETISIM");
  });

  it("empty string ve whitespace-only değerleri eksik sayar", () => {
    expect(
      getPersonelMissingFieldKeys({ ...completePersonel, sicil_no: "" }).has("sicil_no")
    ).toBe(true);
    expect(
      getPersonelMissingFieldKeys({ ...completePersonel, telefon: "   " }).has("telefon")
    ).toBe(true);
  });

  it("opsiyonel alan NULL iken eksik saymaz", () => {
    const completeness = evaluatePersonelCompleteness({
      ...completePersonel,
      dogum_yeri: undefined,
      acil_durum_kisi: undefined,
      kan_grubu: undefined
    });
    expect(completeness.is_complete).toBe(true);
  });

  it("DIS_KAYNAK nullable kimlik alanlarını eksik saymaz", () => {
    const keys = getPersonelMissingFieldKeys({
      ...completePersonel,
      calisan_kapsami: "DIS_KAYNAK",
      tc_kimlik_no: null,
      soyad: null,
      dogum_tarihi: null,
      telefon: null
    });

    expect(keys.has("tc_kimlik_no")).toBe(false);
    expect(keys.has("dogum_tarihi")).toBe(false);
    expect(keys.has("telefon")).toBe(false);
    expect(keys.size).toBe(0);
  });

  it("DIS_KAYNAK organizasyon boşluklarını CRITICAL saymaz; yalnız çekirdek istihdam zorunlu", () => {
    const keys = getPersonelMissingFieldKeys({
      ...completePersonel,
      calisan_kapsami: "DIS_KAYNAK",
      sicil_no: "",
      ise_giris_tarihi: "",
      departman_id: undefined,
      bolum_id: null,
      birim_id: null,
      gorev_id: undefined,
      personel_tipi_id: undefined
    });

    expect([...keys]).toEqual(["sicil_no", "ise_giris_tarihi"]);
  });

  it("Personel Tipi eksikliği mevcut Pozisyon owner'ına yönlenir", () => {
    const missing = getPersonelMissingFields({
      ...completePersonel,
      personel_tipi_id: undefined
    });

    expect(missing).toEqual([
      {
        key: "personel_tipi_id",
        label: "Personel Tipi",
        category: "ISTIHDAM",
        severity: "CRITICAL",
        editTarget: "pozisyon"
      }
    ]);
    expect(countMissingByEditTarget({ ...completePersonel, personel_tipi_id: undefined }, "pozisyon")).toBe(
      1
    );
  });

  it("calisan_kapsami eski kayıtta yoksa güvenli biçimde IC_PERSONEL kabul eder", () => {
    const missing = getPersonelMissingFieldKeys({
      ...completePersonel,
      calisan_kapsami: undefined,
      tc_kimlik_no: null
    });

    expect(missing.has("tc_kimlik_no")).toBe(true);
  });

  it("API completeness varsa yerel politikayı ezmez; API özetini kullanır", () => {
    const personel: Personel = {
      ...completePersonel,
      telefon: null,
      completeness: {
        is_complete: false,
        missing_count: 1,
        critical_missing_labels: ["Telefon"],
        missing_fields: [
          {
            key: "telefon",
            label: "Telefon",
            category: "ILETISIM",
            severity: "CRITICAL",
            edit_target: "genel"
          }
        ]
      }
    };

    const resolved = resolvePersonelCompleteness(personel);
    expect(resolved.missing_count).toBe(1);
    expect(getPersonelMissingFields(personel).map((field) => field.key)).toEqual(["telefon"]);
  });

  it("list-light API summary labels ile badge üretilebilir", () => {
    const personel: Personel = {
      ...completePersonel,
      completeness: {
        is_complete: false,
        missing_count: 2,
        critical_missing_labels: ["Bölüm", "Birim"]
      }
    };

    expect(resolvePersonelCompleteness(personel).missing_count).toBe(2);
    expect(getPersonelMissingFields(personel).map((field) => field.key)).toEqual([
      "bolum_id",
      "birim_id"
    ]);
  });
});
