import { describe, expect, it } from "vitest";
import type { AttendanceTodayResponse } from "../../src/api/attendance-mobile.api";
import { buildPersonelSelfServiceHomeInfoView } from "../../src/features/self-service/personel-self-service-home-view";
import { buildSelfServiceYillikIzinView } from "../../src/features/self-service/personel-self-service-yillik-izin-view";
import type { YillikIzinBakiye } from "../../src/types/yillik-izin-hak-duzeltme";

function baseToday(overrides: Partial<AttendanceTodayResponse> = {}): AttendanceTodayResponse {
  return {
    business_date: "2026-09-25",
    capabilities: {
      calisan_kapsami: "IC_PERSONEL",
      shell: true,
      qr_scan: true,
      attendance_correct: true,
      puantaj_write: false,
      izin_write: false,
      coming_soon_message: null
    },
    personel: {
      id: 1,
      ad_soyad: "Test",
      sube_ad: "Merkez",
      bolum_ad: null,
      birim_ad: null,
      gorev_ad: null
    },
    giris: {
      id: 1,
      event_type: "GIRIS",
      occurred_at: "2026-09-25T09:00:00+03:00",
      local_time: "09:00",
      display_local_time: "09:00"
    },
    cikis: null,
    can_scan_giris: false,
    can_scan_cikis: true,
    pending_giris_correction: null,
    pending_cikis_correction: null,
    ...overrides
  };
}

function baseBakiye(overrides: Partial<YillikIzinBakiye> = {}): YillikIzinBakiye {
  return {
    personel_id: 1,
    contract_version: "s2c-v1",
    ise_giris_tarihi: "2020-01-01",
    referans_tarih: "2026-09-25",
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

describe("buildPersonelSelfServiceHomeInfoView", () => {
  it("birleşik izin satırı ve bugün saatlerini üretir", () => {
    const izinView = buildSelfServiceYillikIzinView(baseBakiye());
    const view = buildPersonelSelfServiceHomeInfoView(baseToday(), izinView);
    expect(view.izinModalRow?.text).toBe("Kalan izin: 84 gün");
    expect(view.rows.find((r) => r.label === "Kıdem")?.value).toContain("aydır çalışıyor");
    expect(view.rows.find((r) => r.label === "Bugün giriş")?.value).toBe("09:00");
    expect(view.rows.some((r) => r.label === "Mesai bitimine kalan")).toBe(false);
  });

  it("hak başlamadan önce bekleme metnini modal satırına taşır", () => {
    const izinView = buildSelfServiceYillikIzinView(
      baseBakiye({
        ise_giris_tarihi: "2025-08-11",
        referans_tarih: "2026-08-10",
        efektif_hak_gun: 0,
        kalan_gun: 0,
        kullanilan_gun: 0
      })
    );
    const view = buildPersonelSelfServiceHomeInfoView(baseToday(), izinView);
    expect(view.izinModalRow?.text).toContain("tarihinde başlar");
    expect(view.rows.some((r) => r.label === "Toplam izin")).toBe(false);
  });
});
