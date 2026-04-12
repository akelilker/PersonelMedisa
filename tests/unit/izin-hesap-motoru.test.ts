import { describe, expect, it } from "vitest";
import {
  hesaplaKidemYil,
  hesaplaYas,
  hesaplaYillikIzinGun,
  hesaplaIzinHakEdis,
  hesaplaKullanilanIzinGun,
  hesaplaIzinBakiye
} from "../../src/services/izin-hesap-motoru";
import type { Surec } from "../../src/types/surec";

// =========================================================================
// 1. Kıdem yılı hesaplama
// =========================================================================

describe("hesaplaKidemYil", () => {
  it("tam 3 yıl → 3", () => {
    expect(hesaplaKidemYil("2023-01-01", "2026-01-01")).toBe(3);
  });

  it("3 yıl dolmamış (1 gün eksik) → 2", () => {
    expect(hesaplaKidemYil("2023-01-02", "2026-01-01")).toBe(2);
  });

  it("1 yılını doldurmamış → 0", () => {
    expect(hesaplaKidemYil("2026-01-01", "2026-06-15")).toBe(0);
  });

  it("aynı gün → 0", () => {
    expect(hesaplaKidemYil("2026-04-13", "2026-04-13")).toBe(0);
  });

  it("referans tarih girişten önce → 0", () => {
    expect(hesaplaKidemYil("2026-04-13", "2020-01-01")).toBe(0);
  });

  it("geçersiz tarih → 0", () => {
    expect(hesaplaKidemYil("invalid", "2026-04-13")).toBe(0);
  });

  it("15 yıl kıdem", () => {
    expect(hesaplaKidemYil("2011-04-13", "2026-04-13")).toBe(15);
  });

  it("5 yıl tam", () => {
    expect(hesaplaKidemYil("2021-04-13", "2026-04-13")).toBe(5);
  });
});

// =========================================================================
// 2. Yaş hesaplama
// =========================================================================

describe("hesaplaYas", () => {
  it("tam 30 yaşında", () => {
    expect(hesaplaYas("1996-04-13", "2026-04-13")).toBe(30);
  });

  it("doğum günü henüz gelmemiş → 29", () => {
    expect(hesaplaYas("1996-06-15", "2026-04-13")).toBe(29);
  });

  it("50 yaşında", () => {
    expect(hesaplaYas("1976-01-01", "2026-04-13")).toBe(50);
  });

  it("51 yaşında", () => {
    expect(hesaplaYas("1975-01-01", "2026-04-13")).toBe(51);
  });

  it("geçersiz tarih → null", () => {
    expect(hesaplaYas("invalid", "2026-04-13")).toBeNull();
  });
});

// =========================================================================
// 3. İş Kanunu md.53 – Yıllık izin gün hesaplama
// =========================================================================

describe("hesaplaYillikIzinGun", () => {
  it("1 yılını doldurmamış → 0 gün", () => {
    expect(hesaplaYillikIzinGun(0, 25)).toEqual({ gun: 0, yas_istisna_uygulandi: false });
  });

  it("1-5 yıl arası → 14 gün", () => {
    expect(hesaplaYillikIzinGun(1, 25)).toEqual({ gun: 14, yas_istisna_uygulandi: false });
    expect(hesaplaYillikIzinGun(4, 30)).toEqual({ gun: 14, yas_istisna_uygulandi: false });
  });

  it("5-15 yıl arası → 20 gün", () => {
    expect(hesaplaYillikIzinGun(5, 35)).toEqual({ gun: 20, yas_istisna_uygulandi: false });
    expect(hesaplaYillikIzinGun(10, 40)).toEqual({ gun: 20, yas_istisna_uygulandi: false });
    expect(hesaplaYillikIzinGun(14, 45)).toEqual({ gun: 20, yas_istisna_uygulandi: false });
  });

  it("15 yıl ve üstü → 26 gün", () => {
    expect(hesaplaYillikIzinGun(15, 45)).toEqual({ gun: 26, yas_istisna_uygulandi: false });
    expect(hesaplaYillikIzinGun(20, 55)).toEqual({ gun: 26, yas_istisna_uygulandi: false });
  });

  it("50 yaş istisnası: 2 yıl kıdem + 50 yaş → 14 yerine 20 gün", () => {
    expect(hesaplaYillikIzinGun(2, 50)).toEqual({ gun: 20, yas_istisna_uygulandi: true });
  });

  it("50 yaş istisnası: 3 yıl kıdem + 52 yaş → 14 yerine 20 gün", () => {
    expect(hesaplaYillikIzinGun(3, 52)).toEqual({ gun: 20, yas_istisna_uygulandi: true });
  });

  it("50 yaş + 5 yıl kıdem → zaten 20, istisna uygulanmaz (gereksiz)", () => {
    expect(hesaplaYillikIzinGun(5, 50)).toEqual({ gun: 20, yas_istisna_uygulandi: false });
  });

  it("50 yaş + 15 yıl kıdem → 26 gün, istisna uygulanmaz", () => {
    expect(hesaplaYillikIzinGun(15, 55)).toEqual({ gun: 26, yas_istisna_uygulandi: false });
  });

  it("yaş null (bilinmiyor) → standart hesaplama, istisna yok", () => {
    expect(hesaplaYillikIzinGun(2, null)).toEqual({ gun: 14, yas_istisna_uygulandi: false });
  });
});

// =========================================================================
// 4. Hak ediş hesaplama (entegre)
// =========================================================================

describe("hesaplaIzinHakEdis", () => {
  it("3 yıl kıdem, 30 yaş → 14 gün", () => {
    const sonuc = hesaplaIzinHakEdis({
      ise_giris_tarihi: "2023-01-01",
      dogum_tarihi: "1996-04-13",
      referans_tarih: "2026-04-13"
    });

    expect(sonuc.kidem_yil).toBe(3);
    expect(sonuc.yas).toBe(30);
    expect(sonuc.yillik_izin_gun).toBe(14);
    expect(sonuc.yas_istisna_uygulandi).toBe(false);
  });

  it("7 yıl kıdem → 20 gün", () => {
    const sonuc = hesaplaIzinHakEdis({
      ise_giris_tarihi: "2019-01-01",
      referans_tarih: "2026-04-13"
    });

    expect(sonuc.kidem_yil).toBe(7);
    expect(sonuc.yillik_izin_gun).toBe(20);
  });

  it("20 yıl kıdem → 26 gün", () => {
    const sonuc = hesaplaIzinHakEdis({
      ise_giris_tarihi: "2006-01-01",
      referans_tarih: "2026-04-13"
    });

    expect(sonuc.kidem_yil).toBe(20);
    expect(sonuc.yillik_izin_gun).toBe(26);
  });

  it("KRİTİK: 2 yıl kıdem + 50 yaş → istisna ile 20 gün", () => {
    const sonuc = hesaplaIzinHakEdis({
      ise_giris_tarihi: "2024-01-01",
      dogum_tarihi: "1976-01-01",
      referans_tarih: "2026-04-13"
    });

    expect(sonuc.kidem_yil).toBe(2);
    expect(sonuc.yas).toBe(50);
    expect(sonuc.yillik_izin_gun).toBe(20);
    expect(sonuc.yas_istisna_uygulandi).toBe(true);
  });

  it("henüz 1 yılını doldurmamış → 0 gün", () => {
    const sonuc = hesaplaIzinHakEdis({
      ise_giris_tarihi: "2026-01-01",
      referans_tarih: "2026-04-13"
    });

    expect(sonuc.kidem_yil).toBe(0);
    expect(sonuc.yillik_izin_gun).toBe(0);
  });
});

// =========================================================================
// 5. Kullanılan izin günü hesaplama
// =========================================================================

describe("hesaplaKullanilanIzinGun", () => {
  it("2 günlük yıllık izin kaydı", () => {
    const surecler: Surec[] = [
      {
        id: 1,
        personel_id: 1,
        surec_turu: "IZIN",
        alt_tur: "YILLIK_IZIN",
        baslangic_tarihi: "2026-03-10",
        bitis_tarihi: "2026-03-11",
        state: "AKTIF"
      }
    ];
    expect(hesaplaKullanilanIzinGun(surecler)).toBe(2);
  });

  it("iptal edilen izinler sayılmaz", () => {
    const surecler: Surec[] = [
      {
        id: 1,
        personel_id: 1,
        surec_turu: "IZIN",
        alt_tur: "YILLIK_IZIN",
        baslangic_tarihi: "2026-03-10",
        bitis_tarihi: "2026-03-14",
        state: "IPTAL"
      }
    ];
    expect(hesaplaKullanilanIzinGun(surecler)).toBe(0);
  });

  it("farklı süreç türleri (DEVAMSIZLIK, ISTEN_AYRILMA) sayılmaz", () => {
    const surecler: Surec[] = [
      {
        id: 1,
        personel_id: 1,
        surec_turu: "DEVAMSIZLIK",
        baslangic_tarihi: "2026-03-10",
        bitis_tarihi: "2026-03-11",
        state: "AKTIF"
      },
      {
        id: 2,
        personel_id: 1,
        surec_turu: "ISTEN_AYRILMA",
        baslangic_tarihi: "2026-03-15",
        state: "AKTIF"
      }
    ];
    expect(hesaplaKullanilanIzinGun(surecler)).toBe(0);
  });

  it("alt_tur farklı olan izinler (HASTALIK vb.) sayılmaz", () => {
    const surecler: Surec[] = [
      {
        id: 1,
        personel_id: 1,
        surec_turu: "IZIN",
        alt_tur: "HASTALIK",
        baslangic_tarihi: "2026-03-10",
        bitis_tarihi: "2026-03-14",
        state: "AKTIF"
      }
    ];
    expect(hesaplaKullanilanIzinGun(surecler)).toBe(0);
  });

  it("alt_tur yoksa (sadece IZIN) → sayılır", () => {
    const surecler: Surec[] = [
      {
        id: 1,
        personel_id: 1,
        surec_turu: "IZIN",
        baslangic_tarihi: "2026-03-10",
        bitis_tarihi: "2026-03-12",
        state: "AKTIF"
      }
    ];
    expect(hesaplaKullanilanIzinGun(surecler)).toBe(3);
  });

  it("bitiş tarihi olmayan tek günlük izin → 1 gün", () => {
    const surecler: Surec[] = [
      {
        id: 1,
        personel_id: 1,
        surec_turu: "IZIN",
        alt_tur: "YILLIK_IZIN",
        baslangic_tarihi: "2026-03-10",
        state: "AKTIF"
      }
    ];
    expect(hesaplaKullanilanIzinGun(surecler)).toBe(1);
  });

  it("birden fazla izin kaydı toplanır", () => {
    const surecler: Surec[] = [
      {
        id: 1,
        personel_id: 1,
        surec_turu: "IZIN",
        alt_tur: "YILLIK_IZIN",
        baslangic_tarihi: "2026-02-10",
        bitis_tarihi: "2026-02-14",
        state: "AKTIF"
      },
      {
        id: 2,
        personel_id: 1,
        surec_turu: "IZIN",
        alt_tur: "YILLIK_IZIN",
        baslangic_tarihi: "2026-03-20",
        bitis_tarihi: "2026-03-22",
        state: "AKTIF"
      }
    ];
    expect(hesaplaKullanilanIzinGun(surecler)).toBe(8);
  });

  it("boş liste → 0", () => {
    expect(hesaplaKullanilanIzinGun([])).toBe(0);
  });
});

// =========================================================================
// 6. Bakiye hesaplama (entegre)
// =========================================================================

describe("hesaplaIzinBakiye", () => {
  it("14 gün hak, 5 gün kullanılmış → 9 kalan", () => {
    const sonuc = hesaplaIzinBakiye(
      {
        ise_giris_tarihi: "2023-01-01",
        dogum_tarihi: "1996-01-01",
        referans_tarih: "2026-04-13"
      },
      [
        {
          id: 1,
          personel_id: 1,
          surec_turu: "IZIN",
          alt_tur: "YILLIK_IZIN",
          baslangic_tarihi: "2026-02-10",
          bitis_tarihi: "2026-02-14",
          state: "AKTIF"
        }
      ]
    );

    expect(sonuc.hak_edis.yillik_izin_gun).toBe(14);
    expect(sonuc.kullanilan_gun).toBe(5);
    expect(sonuc.kalan_gun).toBe(9);
  });

  it("tüm izin kullanılmış → 0 kalan (negatif olmaz)", () => {
    const sonuc = hesaplaIzinBakiye(
      {
        ise_giris_tarihi: "2024-01-01",
        referans_tarih: "2026-04-13"
      },
      [
        {
          id: 1,
          personel_id: 1,
          surec_turu: "IZIN",
          alt_tur: "YILLIK_IZIN",
          baslangic_tarihi: "2026-01-01",
          bitis_tarihi: "2026-01-20",
          state: "AKTIF"
        }
      ]
    );

    expect(sonuc.kalan_gun).toBe(0);
  });

  it("KRİTİK: 50 yaş istisnası bakiyeye yansır", () => {
    const sonuc = hesaplaIzinBakiye(
      {
        ise_giris_tarihi: "2024-01-01",
        dogum_tarihi: "1974-01-01",
        referans_tarih: "2026-04-13"
      },
      [
        {
          id: 1,
          personel_id: 1,
          surec_turu: "IZIN",
          alt_tur: "YILLIK_IZIN",
          baslangic_tarihi: "2026-02-01",
          bitis_tarihi: "2026-02-05",
          state: "AKTIF"
        }
      ]
    );

    expect(sonuc.hak_edis.yillik_izin_gun).toBe(20);
    expect(sonuc.hak_edis.yas_istisna_uygulandi).toBe(true);
    expect(sonuc.kullanilan_gun).toBe(5);
    expect(sonuc.kalan_gun).toBe(15);
  });

  it("süreç yok → kalan = hak ediş", () => {
    const sonuc = hesaplaIzinBakiye(
      {
        ise_giris_tarihi: "2019-01-01",
        referans_tarih: "2026-04-13"
      },
      []
    );

    expect(sonuc.hak_edis.yillik_izin_gun).toBe(20);
    expect(sonuc.kullanilan_gun).toBe(0);
    expect(sonuc.kalan_gun).toBe(20);
  });
});
