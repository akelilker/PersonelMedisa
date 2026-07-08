import { describe, expect, it } from "vitest";
import { INITIAL_SUREC_FORM } from "../../src/hooks/useSurecler";
import { buildCreateSurecPayload, buildUpdateSurecPayload } from "../../src/features/surecler/surec-form-utils";

const BASE_FORM = {
  ...INITIAL_SUREC_FORM,
  personelId: "1",
  surecTuru: "RAPOR",
  altTur: "Raporlu_Hastalik",
  baslangicTarihi: "2026-04-12",
  bitisTarihi: "2026-04-14"
};

describe("buildCreateSurecPayload ilk_iki_gun_firma_oder_mi", () => {
  it("hastalik raporu + checkbox false => payload false", () => {
    const payload = buildCreateSurecPayload({
      ...BASE_FORM,
      ilkIkiGunFirmaOderMi: false
    });

    expect(payload.ilk_iki_gun_firma_oder_mi).toBe(false);
  });

  it("hastalik raporu + checkbox true => payload true", () => {
    const payload = buildCreateSurecPayload({
      ...BASE_FORM,
      ilkIkiGunFirmaOderMi: true
    });

    expect(payload.ilk_iki_gun_firma_oder_mi).toBe(true);
  });

  it("is kazasi surecinde alan payloadda yok", () => {
    const payload = buildCreateSurecPayload({
      ...BASE_FORM,
      surecTuru: "IS_KAZASI",
      altTur: "IS_KAZASI_BILDIRIMI",
      ilkIkiGunFirmaOderMi: true
    });

    expect(payload).not.toHaveProperty("ilk_iki_gun_firma_oder_mi");
  });

  it("izin surecinde alan payloadda yok", () => {
    const payload = buildCreateSurecPayload({
      ...BASE_FORM,
      surecTuru: "IZIN",
      altTur: "YILLIK_IZIN",
      ilkIkiGunFirmaOderMi: true
    });

    expect(payload).not.toHaveProperty("ilk_iki_gun_firma_oder_mi");
  });
});

describe("buildUpdateSurecPayload ilk_iki_gun_firma_oder_mi", () => {
  it("hastalik raporu guncellemede checkbox degerini tasir", () => {
    const payload = buildUpdateSurecPayload({
      ...BASE_FORM,
      ilkIkiGunFirmaOderMi: true
    });

    expect(payload.ilk_iki_gun_firma_oder_mi).toBe(true);
  });
});

describe("buildCreateSurecPayload", () => {
  it("rejects empty surec turu after trim", () => {
    expect(() =>
      buildCreateSurecPayload({
        ...BASE_FORM,
        surecTuru: "   "
      })
    ).toThrow("Surec turu zorunludur.");
  });

  it("accepts trimmed surec turu", () => {
    expect(
      buildCreateSurecPayload({
        ...BASE_FORM,
        surecTuru: "  RAPOR  "
      }).surec_turu
    ).toBe("RAPOR");
  });
});

describe("buildUpdateSurecPayload", () => {
  it("rejects empty surec turu after trim", () => {
    expect(() =>
      buildUpdateSurecPayload({
        ...BASE_FORM,
        surecTuru: ""
      })
    ).toThrow("Surec turu zorunludur.");
  });
});
