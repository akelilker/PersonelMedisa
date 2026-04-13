import { describe, expect, it } from "vitest";
import {
  hesaplaPersonelIstatistikleri,
  hesaplaPuantajIstatistikleri,
  hesaplaOrtalamaKalanIzin,
  hesaplaDashboardKpi,
  hesaplaAylikSgkPuantajOzeti
} from "../../src/services/dashboard-rapor-servisi";
import type { GunlukPuantaj } from "../../src/types/puantaj";
import type { Personel } from "../../src/types/personel";
import type { Surec } from "../../src/types/surec";

function makePersonel(overrides: Partial<Personel> & { id: number }): Personel {
  return {
    tc_kimlik_no: "00000000000",
    ad: "Test",
    soyad: "Personel",
    aktif_durum: "AKTIF",
    ...overrides
  };
}

function makePuantaj(overrides: Partial<GunlukPuantaj> & { personel_id: number; tarih: string }): GunlukPuantaj {
  return {
    compliance_uyarilari: [],
    ...overrides
  };
}

// =========================================================================
// 1. Personel istatistikleri
// =========================================================================

describe("hesaplaPersonelIstatistikleri", () => {
  it("aktif ve pasif personeli dogru sayar", () => {
    const personeller = [
      makePersonel({ id: 1, aktif_durum: "AKTIF" }),
      makePersonel({ id: 2, aktif_durum: "AKTIF" }),
      makePersonel({ id: 3, aktif_durum: "PASIF" })
    ];

    const sonuc = hesaplaPersonelIstatistikleri(personeller);
    expect(sonuc.toplam).toBe(3);
    expect(sonuc.aktif).toBe(2);
    expect(sonuc.pasif).toBe(1);
  });

  it("bos liste icin 0 doner", () => {
    const sonuc = hesaplaPersonelIstatistikleri([]);
    expect(sonuc.toplam).toBe(0);
    expect(sonuc.aktif).toBe(0);
    expect(sonuc.pasif).toBe(0);
  });
});

// =========================================================================
// 2. Puantaj istatistikleri
// =========================================================================

describe("hesaplaPuantajIstatistikleri", () => {
  it("MUHURLENDI ve acik kayitlari dogru sayar", () => {
    const kayitlar = [
      makePuantaj({ personel_id: 1, tarih: "2026-04-01", state: "MUHURLENDI", net_calisma_suresi_dakika: 480 }),
      makePuantaj({ personel_id: 1, tarih: "2026-04-02", state: "MUHURLENDI", net_calisma_suresi_dakika: 450 }),
      makePuantaj({ personel_id: 1, tarih: "2026-04-03", state: "HESAPLANDI", net_calisma_suresi_dakika: 480 })
    ];

    const sonuc = hesaplaPuantajIstatistikleri(kayitlar);
    expect(sonuc.muhurlenen).toBe(2);
    expect(sonuc.acik).toBe(1);
  });

  it("izinsiz devamsizligi dogru sayar", () => {
    const kayitlar = [
      makePuantaj({ personel_id: 1, tarih: "2026-04-01", hareket_durumu: "Gelmedi", dayanak: "Yok_Izinsiz", state: "MUHURLENDI" }),
      makePuantaj({ personel_id: 2, tarih: "2026-04-01", hareket_durumu: "Gelmedi", dayanak: "Raporlu_Hastalik", state: "MUHURLENDI" }),
      makePuantaj({ personel_id: 3, tarih: "2026-04-01", hareket_durumu: "Geldi", state: "MUHURLENDI", net_calisma_suresi_dakika: 480 })
    ];

    const sonuc = hesaplaPuantajIstatistikleri(kayitlar);
    expect(sonuc.izinsizDevamsizlik).toBe(1);
  });

  it("toplam ve ortalama net calisma suresini hesaplar", () => {
    const kayitlar = [
      makePuantaj({ personel_id: 1, tarih: "2026-04-01", state: "MUHURLENDI", net_calisma_suresi_dakika: 480 }),
      makePuantaj({ personel_id: 1, tarih: "2026-04-02", state: "MUHURLENDI", net_calisma_suresi_dakika: 420 })
    ];

    const sonuc = hesaplaPuantajIstatistikleri(kayitlar);
    expect(sonuc.toplamNetDakika).toBe(900);
    expect(sonuc.ortalamaNetDakika).toBe(450);
  });

  it("hafta tatili hak kaybini sayar", () => {
    const kayitlar = [
      makePuantaj({ personel_id: 1, tarih: "2026-04-01", hafta_tatili_hak_kazandi_mi: false, state: "MUHURLENDI" }),
      makePuantaj({ personel_id: 2, tarih: "2026-04-01", hafta_tatili_hak_kazandi_mi: true, state: "MUHURLENDI" })
    ];

    const sonuc = hesaplaPuantajIstatistikleri(kayitlar);
    expect(sonuc.haftaTatiliHakKaybı).toBe(1);
  });

  it("bos liste icin 0 doner", () => {
    const sonuc = hesaplaPuantajIstatistikleri([]);
    expect(sonuc.muhurlenen).toBe(0);
    expect(sonuc.acik).toBe(0);
    expect(sonuc.izinsizDevamsizlik).toBe(0);
    expect(sonuc.toplamNetDakika).toBe(0);
    expect(sonuc.ortalamaNetDakika).toBe(0);
  });
});

// =========================================================================
// 3. Ortalama kalan izin
// =========================================================================

describe("hesaplaOrtalamaKalanIzin", () => {
  it("aktif personellerin ortalama kalan iznini hesaplar", () => {
    const personeller = [
      makePersonel({ id: 1, ise_giris_tarihi: "2023-01-01", dogum_tarihi: "1990-01-01" }),
      makePersonel({ id: 2, ise_giris_tarihi: "2023-01-01", dogum_tarihi: "1990-01-01" })
    ];

    const surecler: Surec[] = [
      { id: 1, personel_id: 1, surec_turu: "IZIN", alt_tur: "YILLIK_IZIN", baslangic_tarihi: "2026-03-01", bitis_tarihi: "2026-03-05", state: "AKTIF" }
    ];

    const sonuc = hesaplaOrtalamaKalanIzin(personeller, surecler);
    expect(sonuc).toBe(12);
  });

  it("pasif personelleri dahil etmez", () => {
    const personeller = [
      makePersonel({ id: 1, ise_giris_tarihi: "2023-01-01", aktif_durum: "PASIF" }),
      makePersonel({ id: 2, ise_giris_tarihi: "2023-01-01" })
    ];

    const sonuc = hesaplaOrtalamaKalanIzin(personeller, []);
    expect(sonuc).toBe(14);
  });

  it("bos personel listesi icin 0 doner", () => {
    expect(hesaplaOrtalamaKalanIzin([], [])).toBe(0);
  });
});

// =========================================================================
// 4. Ana dashboard KPI (entegre)
// =========================================================================

describe("hesaplaDashboardKpi", () => {
  it("tum KPI metriklerini tek cagrida hesaplar", () => {
    const personeller = [
      makePersonel({ id: 1, ise_giris_tarihi: "2020-01-01" }),
      makePersonel({ id: 2, ise_giris_tarihi: "2024-01-01", aktif_durum: "PASIF" })
    ];

    const puantajKayitlari = [
      makePuantaj({ personel_id: 1, tarih: "2026-04-01", state: "MUHURLENDI", net_calisma_suresi_dakika: 480, hareket_durumu: "Geldi", hafta_tatili_hak_kazandi_mi: true }),
      makePuantaj({ personel_id: 1, tarih: "2026-04-02", state: "MUHURLENDI", net_calisma_suresi_dakika: 480, hareket_durumu: "Gelmedi", dayanak: "Yok_Izinsiz", hafta_tatili_hak_kazandi_mi: false }),
      makePuantaj({ personel_id: 1, tarih: "2026-04-03", state: "HESAPLANDI", net_calisma_suresi_dakika: 450, hareket_durumu: "Geldi", hafta_tatili_hak_kazandi_mi: true })
    ];

    const surecler: Surec[] = [];

    const kpi = hesaplaDashboardKpi(personeller, puantajKayitlari, surecler);

    expect(kpi.toplam_personel).toBe(2);
    expect(kpi.aktif_personel).toBe(1);
    expect(kpi.pasif_personel).toBe(1);
    expect(kpi.toplam_muhurlenen_puantaj).toBe(2);
    expect(kpi.toplam_acik_puantaj).toBe(1);
    expect(kpi.toplam_izinsiz_devamsizlik).toBe(1);
    expect(kpi.toplam_net_calisma_dakika).toBe(1410);
    expect(kpi.ortalama_gunluk_net_calisma_dakika).toBe(470);
    expect(kpi.hafta_tatili_hak_kaybi_sayisi).toBe(1);
    expect(kpi.ortalama_kalan_izin).toBe(20);
  });
});

// =========================================================================
// 5. Aylik SGK puantaj ozeti
// =========================================================================

describe("hesaplaAylikSgkPuantajOzeti", () => {
  it("aylik kayitlardan eksik gun ve SGK prim gununu hesaplar", () => {
    const kayitlar = [
      makePuantaj({ personel_id: 1, tarih: "2026-04-09", hareket_durumu: "Geldi" }),
      makePuantaj({
        personel_id: 1,
        tarih: "2026-04-10",
        hareket_durumu: "Gelmedi",
        dayanak: "Yok_Izinsiz"
      }),
      makePuantaj({
        personel_id: 1,
        tarih: "2026-04-11",
        hareket_durumu: "Gelmedi",
        dayanak: "Ucretli_Izinli"
      })
    ];

    const sonuc = hesaplaAylikSgkPuantajOzeti(kayitlar, 2026, 4);
    expect(sonuc.kayit_gun_sayisi).toBe(3);
    expect(sonuc.eksik_gun_sayisi).toBe(1);
    expect(sonuc.sgk_prim_gun).toBe(29);
    expect(sonuc.hesaplama_modu).toBe("TAKVIM_GUNU");
  });

  it("ucretli izin ve yillik izin gunlerini eksik gune saymaz", () => {
    const kayitlar = [
      makePuantaj({
        personel_id: 1,
        tarih: "2026-04-10",
        hareket_durumu: "Gelmedi",
        dayanak: "Ucretli_Izinli"
      }),
      makePuantaj({
        personel_id: 1,
        tarih: "2026-04-11",
        hareket_durumu: "Gelmedi",
        dayanak: "Yillik_Izin"
      })
    ];

    const sonuc = hesaplaAylikSgkPuantajOzeti(kayitlar, 2026, 4);
    expect(sonuc.eksik_gun_sayisi).toBe(0);
    expect(sonuc.sgk_prim_gun).toBe(30);
    expect(sonuc.hesaplama_modu).toBe("OTUZ_GUN_STANDART");
  });
});
