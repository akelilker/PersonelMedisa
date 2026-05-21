import { describe, expect, it } from "vitest";
import { hesaplaDevamPrimiEligibility } from "../../src/services/devam-primi-hesap-motoru";
import {
  DEVAM_PRIMI_VERI_KAPSAMI_EKSIK_ACIKLAMA,
  mapDevamPrimiEligibilityToView
} from "../../src/hooks/useDevamPrimiEligibilityOzeti";
import type { GunlukPuantaj } from "../../src/types/puantaj";

const NISAN_2026_GUN_SAYISI = 30;

describe("mapDevamPrimiEligibilityToView", () => {
  it("eksik kapsamda durumLabel Hak Kazandi olmaz", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      personel_id: 1,
      yil: 2026,
      ay: 4,
      prim_kurali_id: 7,
      gunluk_kayitlar: []
    });

    const view = mapDevamPrimiEligibilityToView(sonuc, 0, NISAN_2026_GUN_SAYISI);

    expect(view.durumLabel).not.toBe("Hak Kazandı");
    expect(view.durum).not.toBe("hak_kazandi");
  });

  it("eksik kapsam ve hastalik kaydi yoksa manuel inceleme gosterir", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      personel_id: 1,
      yil: 2026,
      ay: 4,
      prim_kurali_id: 7,
      gunluk_kayitlar: []
    });

    const view = mapDevamPrimiEligibilityToView(sonuc, 0, NISAN_2026_GUN_SAYISI);

    expect(view.durum).toBe("manuel_inceleme");
    expect(view.durumLabel).toBe("Manuel İnceleme Gerekli");
    expect(view.aciklama).toBe(DEVAM_PRIMI_VERI_KAPSAMI_EKSIK_ACIKLAMA);
    expect(view.kayitKapsamiNotu).toContain("0/30");
  });

  it("tam kapsam ve hastalik yoksa hak kazandi gosterir", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      personel_id: 1,
      yil: 2026,
      ay: 4,
      prim_kurali_id: 7,
      gunluk_kayitlar: []
    });

    const view = mapDevamPrimiEligibilityToView(sonuc, NISAN_2026_GUN_SAYISI, NISAN_2026_GUN_SAYISI);

    expect(view.durum).toBe("hak_kazandi");
    expect(view.durumLabel).toBe("Hak Kazandı");
    expect(view.aciklama).toContain("hak kazandi");
    expect(view.kayitKapsamiNotu).toBeNull();
  });

  it("eksik kapsam ve hastalik kaydi varsa kesildi korunur ve kapsam notu gosterilir", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      personel_id: 1,
      yil: 2026,
      ay: 4,
      prim_kurali_id: 7,
      gunluk_kayitlar: [
        {
          personel_id: 1,
          tarih: "2026-04-10",
          hareket_durumu: "Gelmedi",
          dayanak: "Raporlu_Hastalik"
        } satisfies GunlukPuantaj
      ]
    });

    const view = mapDevamPrimiEligibilityToView(sonuc, 1, NISAN_2026_GUN_SAYISI);

    expect(view.durum).toBe("kesildi");
    expect(view.durumLabel).toBe("Kesildi");
    expect(view.durumLabel).not.toBe("Hak Kazandı");
    expect(view.aciklama).toContain("kesildi");
    expect(view.kayitKapsamiNotu).toContain("1/30");
  });

  it("tam kapsamda motor manuel inceleme dondururse prim kurali aciklamasi korunur", () => {
    const sonuc = hesaplaDevamPrimiEligibility({
      personel_id: 1,
      yil: 2026,
      ay: 4,
      gunluk_kayitlar: []
    });

    const view = mapDevamPrimiEligibilityToView(sonuc, NISAN_2026_GUN_SAYISI, NISAN_2026_GUN_SAYISI);

    expect(view.durum).toBe("manuel_inceleme");
    expect(view.durumLabel).toBe("Manuel İnceleme Gerekli");
    expect(view.aciklama).toContain("prim_kurali_id");
    expect(view.kayitKapsamiNotu).toBeNull();
  });
});
