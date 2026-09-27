import { describe, expect, it } from "vitest";
import { buildSelfServiceYillikIzinView } from "../../src/features/self-service/personel-self-service-yillik-izin-view";
import type { YillikIzinBakiye } from "../../src/types/yillik-izin-hak-duzeltme";

function baseBakiye(overrides: Partial<YillikIzinBakiye> = {}): YillikIzinBakiye {
  return {
    personel_id: 1,
    contract_version: "s2c-v1",
    ise_giris_tarihi: "2020-01-01",
    referans_tarih: "2026-01-01",
    kidem_yil: 6,
    yas: null,
    yas_istisna_uygulandi: false,
    mevcut_yillik_hak_gun: 20,
    birikmis_yasal_hak_gun: 94,
    yasal_hak_gun: 94,
    manuel_duzeltme_gun: 0,
    efektif_hak_gun: 94,
    kullanilan_gun: 10,
    ham_kalan_gun: 84,
    kalan_gun: 84,
    takvim_dogrulandi_mi: true,
    eksik_takvim_tarihleri: [],
    sayilan_normal_gun: 10,
    haric_tutulan_hafta_tatili_gun: 0,
    haric_tutulan_ubgt_gun: 0,
    duzeltme_adet: 0,
    ...overrides
  };
}

describe("buildSelfServiceYillikIzinView", () => {
  it("hak başlamış personelde kalan satırı üretir", () => {
    const view = buildSelfServiceYillikIzinView(baseBakiye());
    expect(view?.rowText).toBe("Kalan izin: 84 gün");
    expect(view?.toplamLabel).toBe("94 gün");
    expect(view?.kullanilanLabel).toBe("10 gün");
  });

  it("ilk yıl öncesi bekleme metnini gösterir", () => {
    const view = buildSelfServiceYillikIzinView(
      baseBakiye({
        ise_giris_tarihi: "2025-08-11",
        referans_tarih: "2026-08-10",
        kidem_yil: 0,
        efektif_hak_gun: 0,
        kalan_gun: 0,
        kullanilan_gun: 0
      })
    );
    expect(view?.hakBasladi).toBe(false);
    expect(view?.rowText).toContain("11.08.2026");
    expect(view?.rowText).toContain("tarihinde başlar");
  });
});
