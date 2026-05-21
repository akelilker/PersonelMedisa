import { describe, expect, it } from "vitest";
import { hesaplaDevamPrimiEligibility } from "../../src/services/devam-primi-hesap-motoru";
import type { GunlukPuantaj } from "../../src/types/puantaj";

function makeKayit(
  overrides: Partial<GunlukPuantaj> & Pick<GunlukPuantaj, "tarih">
): GunlukPuantaj {
  return {
    personel_id: 1,
    ...overrides
  };
}

const baseGirdi = {
  personel_id: 1,
  yil: 2026,
  ay: 4,
  prim_kurali_id: 7
};

describe("hesaplaDevamPrimiEligibility", () => {
  it("bos kayit → hak_kazandi_mi true", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      ...baseGirdi,
      gunluk_kayitlar: []
    });

    expect(sonuc.hak_kazandi_mi).toBe(true);
    expect(sonuc.kesildi_mi).toBe(false);
    expect(sonuc.kesinti_nedeni).toBeUndefined();
    expect(sonuc.manuel_inceleme_gerekli_mi).toBe(false);
    expect(sonuc.donem).toBe("2026-04");
  });

  it("tam gun hastalik raporu → kesildi_mi true", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      ...baseGirdi,
      gunluk_kayitlar: [
        makeKayit({
          tarih: "2026-04-10",
          hareket_durumu: "Gelmedi",
          dayanak: "Raporlu_Hastalik"
        })
      ]
    });

    expect(sonuc.hak_kazandi_mi).toBe(false);
    expect(sonuc.kesildi_mi).toBe(true);
    expect(sonuc.kesinti_nedeni).toBe("1_gun_hastalik_raporu");
    expect(sonuc.uygulanan_kural).toBe("aylik_tam_gun_raporlu_hastalik_kesinti");
  });

  it("birden fazla hastalik gunu → yine binary kesinti", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      ...baseGirdi,
      gunluk_kayitlar: [
        makeKayit({
          tarih: "2026-04-10",
          hareket_durumu: "Gelmedi",
          dayanak: "Raporlu_Hastalik"
        }),
        makeKayit({
          tarih: "2026-04-11",
          hareket_durumu: "Gelmedi",
          dayanak: "Raporlu_Hastalik"
        })
      ]
    });

    expect(sonuc.hak_kazandi_mi).toBe(false);
    expect(sonuc.kesildi_mi).toBe(true);
  });

  it("Raporlu_Is_Kazasi → manuel inceleme, otomatik kesme yok", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      ...baseGirdi,
      gunluk_kayitlar: [
        makeKayit({
          tarih: "2026-04-12",
          hareket_durumu: "Gelmedi",
          dayanak: "Raporlu_Is_Kazasi"
        })
      ]
    });

    expect(sonuc.manuel_inceleme_gerekli_mi).toBe(true);
    expect(sonuc.kesildi_mi).toBe(false);
    expect(sonuc.hak_kazandi_mi).toBe(true);
  });

  it("Yok_Izinsiz → otomatik kesme yok", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      ...baseGirdi,
      gunluk_kayitlar: [
        makeKayit({
          tarih: "2026-04-13",
          hareket_durumu: "Gelmedi",
          dayanak: "Yok_Izinsiz"
        })
      ]
    });

    expect(sonuc.kesildi_mi).toBe(false);
    expect(sonuc.hak_kazandi_mi).toBe(true);
    expect(sonuc.manuel_inceleme_gerekli_mi).toBe(false);
  });

  it("Gec_Geldi → otomatik kesme yok", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      ...baseGirdi,
      gunluk_kayitlar: [
        makeKayit({
          tarih: "2026-04-14",
          hareket_durumu: "Gec_Geldi",
          dayanak: undefined,
          giris_saati: "09:00",
          cikis_saati: "18:00"
        })
      ]
    });

    expect(sonuc.kesildi_mi).toBe(false);
    expect(sonuc.hak_kazandi_mi).toBe(true);
  });

  it("prim_kurali_id yok → manuel_inceleme_gerekli_mi true", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      personel_id: 1,
      yil: 2026,
      ay: 4,
      gunluk_kayitlar: []
    });

    expect(sonuc.prim_kurali_id).toBeUndefined();
    expect(sonuc.manuel_inceleme_gerekli_mi).toBe(true);
    expect(sonuc.kesildi_mi).toBe(false);
    expect(sonuc.hak_kazandi_mi).toBe(true);
  });

  it("farkli ay kayitlari → sadece hedef ay degerlendirilir", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      ...baseGirdi,
      gunluk_kayitlar: [
        makeKayit({
          tarih: "2026-03-30",
          hareket_durumu: "Gelmedi",
          dayanak: "Raporlu_Hastalik"
        }),
        makeKayit({
          tarih: "2026-05-01",
          hareket_durumu: "Gelmedi",
          dayanak: "Raporlu_Hastalik"
        })
      ]
    });

    expect(sonuc.kesildi_mi).toBe(false);
    expect(sonuc.hak_kazandi_mi).toBe(true);
  });
});
