import { describe, expect, it } from "vitest";
import {
  getPersonelMissingFieldKeys,
  getPersonelMissingFields
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

  it("DIS_KAYNAK organizasyon ve çalışma çekirdeğindeki boşlukları görünür tutar", () => {
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

    expect([...keys]).toEqual([
      "sicil_no",
      "ise_giris_tarihi",
      "departman_id",
      "bolum_id",
      "birim_id",
      "gorev_id",
      "personel_tipi_id"
    ]);
  });

  it("Personel Tipi eksikliği mevcut Pozisyon owner'ına yönlenir", () => {
    const missing = getPersonelMissingFields({
      ...completePersonel,
      personel_tipi_id: undefined
    });

    expect(missing).toEqual([
      { key: "personel_tipi_id", label: "Personel Tipi", editTarget: "pozisyon" }
    ]);
  });

  it("calisan_kapsami eski kayıtta yoksa güvenli biçimde IC_PERSONEL kabul eder", () => {
    const missing = getPersonelMissingFieldKeys({
      ...completePersonel,
      calisan_kapsami: undefined,
      tc_kimlik_no: null
    });

    expect(missing.has("tc_kimlik_no")).toBe(true);
  });
});
