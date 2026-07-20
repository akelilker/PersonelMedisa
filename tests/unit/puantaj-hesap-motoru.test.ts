import { describe, expect, it } from "vitest";
import {
  cozumleHastalikRaporGunu,
  type HastalikRaporSureci
} from "../../src/services/hastalik-rapor-politikasi";
import {
  deriveGunTipi,
  isHaftaTatiliGunu,
  VARSAYILAN_HAFTA_TATILI_GUN_KODU,
  deriveHareketDurumu,
  deriveDayanak,
  deriveHesapEtkisi,
  geceBandinaGiriyor,
  hesaplaGeceCalismaDakika,
  GECE_CALISMASI_7_5_SAAT_ASIMI_CODE,
  GECE_CALISMASI_7_5_SAAT_ASIMI_MESSAGE,
  hesaplaBrutSure,
  hesaplaYasalMolaDakika,
  hesaplaNetSure,
  hesaplaHaftaTatiliHakki,
  hesaplaYasKuraliBlokMesaji,
  uretComplianceUyarilari,
  hesapla,
  hesaplaHaftalikCalismaOzeti,
  hesaplaHaftaAraligi,
  filtreleHaftalikPuantajSatirlari,
  hesaplaTarihtenHaftalikCalismaOzeti,
  hesaplaMevzuatFazlaCalismaOdemeDakika,
  hesaplaSaatlikUcret,
  hesaplaFazlaCalismaTutari,
  hesaplaHaftalikFazlaCalismaUcreti,
  hesaplaHaftalikPuantajUcretOzeti,
  HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA,
  hesaplaGunlukUcret,
  hesaplaSaatlikKesintiTutari,
  GEC_ERKEN_TOLERANS_DAKIKA,
  hesaplaGecErkenEksikSure,
  hesaplaGecKalmaErkenCikmaKesintiOzeti,
  hesaplaKesintiyeEsasDakika,
  hesaplaDevamsizlikKesintiOzeti,
  siniflandirPuantajEksikGunEtkisi,
  siniflandirHastalikRaporGunUcretEtkisi,
  hesaplaAylikPuantajEksikGunOzeti,
  hesaplaSgkPrimGunu,
  hesaplaTatilEkOdemeOzeti,
  hesaplaHaftaTatiliPazarEtkisi,
  gunlukPuantajToGirdi,
  hesapSonucuToGunlukPuantaj,
  birlestirUbgtFazlaMesaiCakismaUyari,
  birlestirOnsekizYasAltiFazlaCalismaUyari,
  isFazlaCalismaGeceYasagiKapsaminda,
  birlestirMazeretsizDevamsizlikAdayUyariari,
  UBGT_FAZLA_MESAI_CAKISMASI_CODE,
  UBGT_FAZLA_MESAI_CAKISMASI_MESSAGE,
  ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_CODE,
  ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_MESSAGE,
  GECE_CALISMASI_7_5_SAAT_ASIMI_CODE,
  DEVAMSIZLIK_UCRET_ETKISI_ADAYI_CODE,
  DEVAMSIZLIK_UCRET_ETKISI_ADAYI_MESSAGE,
  HAFTA_TATILI_HAK_KAYBI_ADAYI_CODE,
  HAFTA_TATILI_HAK_KAYBI_ADAYI_MESSAGE,
  mazeretsizDevamsizlikParasalNetKilitliMi,
  parasalNetEtkidenDusulecekKesintiTutari,
  type HesapGirdisi
} from "../../src/services/puantaj-hesap-motoru";
import { deriveGecErkenKesintiPreview } from "../../src/hooks/usePuantaj";
import type { GunlukPuantaj } from "../../src/types/puantaj";

// =========================================================================
// 1. Brüt süre hesaplama
// =========================================================================

describe("hesaplaBrutSure", () => {
  it("standart mesai 08:00-17:00 → 540 dk", () => {
    expect(hesaplaBrutSure("08:00", "17:00")).toBe(540);
  });

  it("kısa mesai 09:00-13:00 → 240 dk", () => {
    expect(hesaplaBrutSure("09:00", "13:00")).toBe(240);
  });

  it("giriş/çıkış yoksa 0 döner", () => {
    expect(hesaplaBrutSure(undefined, "17:00")).toBe(0);
    expect(hesaplaBrutSure("08:00", undefined)).toBe(0);
    expect(hesaplaBrutSure(undefined, undefined)).toBe(0);
  });

  it("çıkış girişten küçükse 0 döner", () => {
    expect(hesaplaBrutSure("18:00", "08:00")).toBe(0);
  });

  it("geçersiz saat formatı 0 döner", () => {
    expect(hesaplaBrutSure("abc", "17:00")).toBe(0);
  });
});

// =========================================================================
// 2. İş Kanunu md.68 – Yasal mola düşümü
// =========================================================================

describe("hesaplaYasalMolaDakika", () => {
  it("0 dakika çalışma → mola yok", () => {
    expect(hesaplaYasalMolaDakika(0)).toBe(0);
  });

  it("4 saat ve altı çalışma → 15 dk mola", () => {
    expect(hesaplaYasalMolaDakika(240)).toBe(15);
    expect(hesaplaYasalMolaDakika(120)).toBe(15);
  });

  it("4-7.5 saat arası → 30 dk mola", () => {
    expect(hesaplaYasalMolaDakika(241)).toBe(30);
    expect(hesaplaYasalMolaDakika(360)).toBe(30);
    expect(hesaplaYasalMolaDakika(450)).toBe(30);
  });

  it("7.5 saat üstü → 60 dk mola", () => {
    expect(hesaplaYasalMolaDakika(451)).toBe(60);
    expect(hesaplaYasalMolaDakika(540)).toBe(60);
    expect(hesaplaYasalMolaDakika(660)).toBe(60);
  });
});

// =========================================================================
// 3. Net süre hesaplama
// =========================================================================

describe("hesaplaNetSure", () => {
  it("brüt 540, gerçek mola yok, yasal 60 → net 480", () => {
    expect(hesaplaNetSure(540, undefined, 60)).toBe(480);
  });

  it("brüt 540, gerçek mola 90, yasal 60 → gerçek mola uygulanır → net 450", () => {
    expect(hesaplaNetSure(540, 90, 60)).toBe(450);
  });

  it("brüt 540, gerçek mola 30, yasal 60 → yasal minimum uygulanır → net 480", () => {
    expect(hesaplaNetSure(540, 30, 60)).toBe(480);
  });

  it("brüt 0 → net 0", () => {
    expect(hesaplaNetSure(0, undefined, 0)).toBe(0);
  });
});

// =========================================================================
// 4. Gün tipi türetme
// =========================================================================

describe("isHaftaTatiliGunu", () => {
  it("varsayılan Pazar kodu 0'dır", () => {
    expect(VARSAYILAN_HAFTA_TATILI_GUN_KODU).toBe(0);
  });

  it("default Pazar için Pazar tarihi true döner", () => {
    expect(isHaftaTatiliGunu("2026-04-12")).toBe(true);
  });

  it("default Pazar için hafta içi false döner", () => {
    expect(isHaftaTatiliGunu("2026-04-13")).toBe(false);
  });

  it("Cumartesi parametresiyle Cumartesi için true döner", () => {
    expect(isHaftaTatiliGunu("2026-04-11", 6)).toBe(true);
  });

  it("Cumartesi parametresiyle Pazar için false döner", () => {
    expect(isHaftaTatiliGunu("2026-04-12", 6)).toBe(false);
  });
});

describe("deriveGunTipi", () => {
  it("pazar günü → Hafta_Tatili_Pazar", () => {
    expect(deriveGunTipi("2026-04-12")).toBe("Hafta_Tatili_Pazar");
  });

  it("hafta içi → Normal_Is_Gunu", () => {
    expect(deriveGunTipi("2026-04-13")).toBe("Normal_Is_Gunu");
  });

  it("explicit verilmişse tarih görmezden gelinir", () => {
    expect(deriveGunTipi("2026-04-12", "Normal_Is_Gunu")).toBe("Normal_Is_Gunu");
    expect(deriveGunTipi("2026-04-13", "UBGT_Resmi_Tatil")).toBe("UBGT_Resmi_Tatil");
  });

  it("parametre verilmeden Pazar tarihi Hafta_Tatili_Pazar kalır", () => {
    expect(deriveGunTipi("2026-04-12")).toBe("Hafta_Tatili_Pazar");
  });

  it("Cumartesi parametresiyle Cumartesi Hafta_Tatili_Pazar döner", () => {
    expect(deriveGunTipi("2026-04-11", undefined, 6)).toBe("Hafta_Tatili_Pazar");
  });

  it("explicit gun_tipi override hafta tatili günü parametresinden önce gelir", () => {
    expect(deriveGunTipi("2026-04-11", "Normal_Is_Gunu", 6)).toBe("Normal_Is_Gunu");
  });
});

// =========================================================================
// 5. Hareket durumu türetme
// =========================================================================

describe("deriveHareketDurumu", () => {
  it("giriş saati varsa → Geldi", () => {
    expect(deriveHareketDurumu(undefined, "08:00", undefined)).toBe("Geldi");
  });

  it("hiçbir saat yoksa → Gelmedi", () => {
    expect(deriveHareketDurumu(undefined, undefined, undefined)).toBe("Gelmedi");
  });

  it("explicit verilmişse → o döner", () => {
    expect(deriveHareketDurumu("Gec_Geldi")).toBe("Gec_Geldi");
  });
});

// =========================================================================
// 6. Dayanak türetme
// =========================================================================

describe("deriveDayanak", () => {
  it("Gelmedi + saat yok → Yok_Izinsiz", () => {
    expect(deriveDayanak(undefined, "Gelmedi")).toBe("Yok_Izinsiz");
  });

  it("Geldi → undefined (dayanak gerekmez)", () => {
    expect(deriveDayanak(undefined, "Geldi", "08:00", "17:00")).toBeUndefined();
  });

  it("explicit verilmişse → o döner", () => {
    expect(deriveDayanak("Raporlu_Hastalik", "Gelmedi")).toBe("Raporlu_Hastalik");
  });
});

// =========================================================================
// 7. Hesap etkisi türetme
// =========================================================================

describe("deriveHesapEtkisi", () => {
  it("Gelmedi + Yok_Izinsiz → Yevmiye_Kes", () => {
    expect(deriveHesapEtkisi("Normal_Is_Gunu", "Gelmedi", "Yok_Izinsiz")).toBe("Yevmiye_Kes");
  });

  it("Pazar + giriş var → Mesai_Yaz", () => {
    expect(deriveHesapEtkisi("Hafta_Tatili_Pazar", "Geldi", undefined, "08:00", "17:00")).toBe("Mesai_Yaz");
  });

  it("UBGT + giriş var → Mesai_Yaz", () => {
    expect(deriveHesapEtkisi("UBGT_Resmi_Tatil", "Geldi", undefined, "08:00")).toBe("Mesai_Yaz");
  });

  it("Raporlu hastalık → otomatik hesap etkisi üretmez", () => {
    expect(deriveHesapEtkisi("Normal_Is_Gunu", "Gelmedi", "Raporlu_Hastalik")).toBeUndefined();
  });

  it("İş kazası → otomatik hesap etkisi üretmez", () => {
    expect(deriveHesapEtkisi("Normal_Is_Gunu", "Gelmedi", "Raporlu_Is_Kazasi")).toBeUndefined();
  });

  it("Pazar + rapor + giriş var → Mesai_Yaz üretmez", () => {
    expect(
      deriveHesapEtkisi("Hafta_Tatili_Pazar", "Geldi", "Raporlu_Hastalik", "08:00", "17:00")
    ).toBeUndefined();
  });

  it("UBGT + rapor + giriş var → Mesai_Yaz üretmez", () => {
    expect(
      deriveHesapEtkisi("UBGT_Resmi_Tatil", "Geldi", "Raporlu_Hastalik", "08:00", "17:00")
    ).toBeUndefined();
  });

  it("Ücretli izin → Tam_Yevmiye_Ver", () => {
    expect(deriveHesapEtkisi("Normal_Is_Gunu", "Gelmedi", "Ucretli_Izinli")).toBe("Tam_Yevmiye_Ver");
  });

  it("Yıllık izin → Tam_Yevmiye_Ver", () => {
    expect(deriveHesapEtkisi("Normal_Is_Gunu", "Gelmedi", "Yillik_Izin")).toBe("Tam_Yevmiye_Ver");
  });

  it("Geldi → Tam_Yevmiye_Ver", () => {
    expect(deriveHesapEtkisi("Normal_Is_Gunu", "Geldi")).toBe("Tam_Yevmiye_Ver");
  });

  it("Gorevde_Calisma + Geldi → Tam_Yevmiye_Ver", () => {
    expect(deriveHesapEtkisi("Normal_Is_Gunu", "Geldi", "Gorevde_Calisma")).toBe("Tam_Yevmiye_Ver");
  });
});

// =========================================================================
// 8. Hafta tatili — Pazar hak → etki kararı + hakkı (İş Kanunu md.46)
// =========================================================================

describe("hesaplaHaftaTatiliPazarEtkisi", () => {
  it("hak kazandı + Pazar çalışmadı → kayıp yok, ek ödeme yok", () => {
    const o = hesaplaHaftaTatiliPazarEtkisi(true, false, 1000);
    expect(o).toEqual(
      expect.objectContaining({
        hafta_tatili_hak_kazandi_mi: true,
        pazar_calisildi_mi: false,
        hafta_tatili_kaybi_var_mi: false,
        ek_odeme_gun_carpani: 0,
        ek_odeme_tutari: 0,
        manuel_inceleme_gerekli_mi: false,
        aciklama: "normal ücretli hafta tatili, ek ödeme yok"
      })
    );
  });

  it("hak kazandı + Pazar çalıştı → çarpan 1,5 ve tutar günlük × 1,5", () => {
    const o = hesaplaHaftaTatiliPazarEtkisi(true, true, 1000);
    expect(o.hafta_tatili_hak_kazandi_mi).toBe(true);
    expect(o.pazar_calisildi_mi).toBe(true);
    expect(o.hafta_tatili_kaybi_var_mi).toBe(false);
    expect(o.ek_odeme_gun_carpani).toBe(1.5);
    expect(o.ek_odeme_tutari).toBe(1500);
    expect(o.manuel_inceleme_gerekli_mi).toBe(false);
    expect(o.aciklama).toBe(
      "hafta tatiline hak kazanmış personelin Pazar çalışması için +1.5 günlük ek ödeme"
    );
  });

  it("hak kazanmadı + Pazar çalışmadı → tatil kaybı, ek ödeme yok", () => {
    const o = hesaplaHaftaTatiliPazarEtkisi(false, false, 1000);
    expect(o).toEqual(
      expect.objectContaining({
        hafta_tatili_hak_kazandi_mi: false,
        pazar_calisildi_mi: false,
        hafta_tatili_kaybi_var_mi: true,
        ek_odeme_gun_carpani: 0,
        ek_odeme_tutari: 0,
        manuel_inceleme_gerekli_mi: false,
        aciklama: "hafta tatili hakkı kaybedilmiş, ek ödeme yok"
      })
    );
  });

  it("hak kazanmadı + Pazar çalıştı → manuel inceleme, otomatik tutar yok", () => {
    const o = hesaplaHaftaTatiliPazarEtkisi(false, true, 1000);
    expect(o.hafta_tatili_hak_kazandi_mi).toBe(false);
    expect(o.pazar_calisildi_mi).toBe(true);
    expect(o.hafta_tatili_kaybi_var_mi).toBe(true);
    expect(o.ek_odeme_gun_carpani).toBe(0);
    expect(o.ek_odeme_tutari).toBe(0);
    expect(o.manuel_inceleme_gerekli_mi).toBe(true);
    expect(o.aciklama).toBe(
      "hafta tatili hakkı kaybedilmişken Pazar çalışması var; otomatik ödeme üretilmez, manuel inceleme gerekir"
    );
  });

  it("günlük ücret yok veya geçersizse tutar 0; hak + Pazar’da çarpan 1,5 kalır", () => {
    expect(hesaplaHaftaTatiliPazarEtkisi(true, true)).toMatchObject({
      ek_odeme_gun_carpani: 1.5,
      ek_odeme_tutari: 0
    });
    expect(hesaplaHaftaTatiliPazarEtkisi(true, true, undefined)).toMatchObject({
      ek_odeme_gun_carpani: 1.5,
      ek_odeme_tutari: 0
    });
    expect(hesaplaHaftaTatiliPazarEtkisi(true, true, 0)).toMatchObject({
      ek_odeme_gun_carpani: 1.5,
      ek_odeme_tutari: 0
    });
    expect(hesaplaHaftaTatiliPazarEtkisi(true, true, -100)).toMatchObject({
      ek_odeme_gun_carpani: 1.5,
      ek_odeme_tutari: 0
    });
    expect(hesaplaHaftaTatiliPazarEtkisi(true, true, Number.NaN)).toMatchObject({
      ek_odeme_gun_carpani: 1.5,
      ek_odeme_tutari: 0
    });
  });
});

describe("hesaplaHaftaTatiliHakki", () => {
  it("Gelmedi + Yok_Izinsiz → hak KAYBI (false)", () => {
    expect(hesaplaHaftaTatiliHakki("Gelmedi", "Yok_Izinsiz")).toBe(false);
  });

  it("Gelmedi + dayanak undefined → hak KAYBI (false)", () => {
    expect(hesaplaHaftaTatiliHakki("Gelmedi", undefined)).toBe(false);
  });

  it("Gelmedi + Ucretli_Izinli → hak KORUNUR (true)", () => {
    expect(hesaplaHaftaTatiliHakki("Gelmedi", "Ucretli_Izinli")).toBe(true);
  });

  it("Gelmedi + Raporlu_Hastalik → hak KORUNUR (true)", () => {
    expect(hesaplaHaftaTatiliHakki("Gelmedi", "Raporlu_Hastalik")).toBe(true);
  });

  it("Gelmedi + Raporlu_Is_Kazasi → hak KORUNUR (true)", () => {
    expect(hesaplaHaftaTatiliHakki("Gelmedi", "Raporlu_Is_Kazasi")).toBe(true);
  });

  it("Gelmedi + Yillik_Izin → hak KORUNUR (true)", () => {
    expect(hesaplaHaftaTatiliHakki("Gelmedi", "Yillik_Izin")).toBe(true);
  });

  it("Gelmedi + Telafi_Calismasi → hak KORUNUR (true)", () => {
    expect(hesaplaHaftaTatiliHakki("Gelmedi", "Telafi_Calismasi")).toBe(true);
  });

  it("Geldi → hak KORUNUR (true)", () => {
    expect(hesaplaHaftaTatiliHakki("Geldi")).toBe(true);
  });

  it("Gec_Geldi → hak KORUNUR (true)", () => {
    expect(hesaplaHaftaTatiliHakki("Gec_Geldi")).toBe(true);
  });

  it("Erken_Cikti → hak KORUNUR (true)", () => {
    expect(hesaplaHaftaTatiliHakki("Erken_Cikti")).toBe(true);
  });
});

// =========================================================================
// 9. Compliance uyarıları
// =========================================================================

describe("uretComplianceUyarilari", () => {
  it("normal süre → boş dizi", () => {
    expect(uretComplianceUyarilari(480, 420, "08:00", "16:00")).toEqual([]);
  });

  it("net > 450 → UYARI seviyesinde MAX_DAILY_LIMIT", () => {
    const uyarilar = uretComplianceUyarilari(540, 480, "08:00", "17:00");
    expect(uyarilar).toContainEqual(
      expect.objectContaining({ code: "MAX_DAILY_LIMIT", level: "UYARI" })
    );
  });

  it("net > 660 (11 saat) → KRİTİK seviyesinde MAX_DAILY_LIMIT", () => {
    const uyarilar = uretComplianceUyarilari(780, 720, "06:00", "19:00");
    expect(uyarilar).toContainEqual(
      expect.objectContaining({ code: "MAX_DAILY_LIMIT", level: "KRITIK" })
    );
  });

  it("çıkış 20:00+ → GECE_MESAI uyarısı", () => {
    const uyarilar = uretComplianceUyarilari(720, 660, "08:00", "20:00");
    expect(uyarilar).toContainEqual(
      expect.objectContaining({ code: "GECE_MESAI", level: "BILGI" })
    );
  });
});

describe("hesaplaGeceCalismaDakika", () => {
  it("gece bandı dışı kayıt → 0", () => {
    expect(hesaplaGeceCalismaDakika("08:00", "17:00")).toBe(0);
  });

  it("20:00 sonrası kısım gece bandı sayılır", () => {
    expect(hesaplaGeceCalismaDakika("08:00", "22:00")).toBe(120);
  });

  it("06:00 öncesi kısım gece bandı sayılır", () => {
    expect(hesaplaGeceCalismaDakika("04:00", "10:00")).toBe(120);
  });

  it("sabah + akşam gece bandı parçaları toplanır", () => {
    // 00:00–06:00 = 360 dk + 20:00–21:31 = 91 dk → 451 dk gece bandı
    expect(hesaplaGeceCalismaDakika("00:00", "21:31")).toBe(451);
  });

  it("450 dk tam sınır", () => {
    expect(hesaplaGeceCalismaDakika("00:00", "21:30")).toBe(450);
  });

  it("saat eksik → null", () => {
    expect(hesaplaGeceCalismaDakika("08:00", undefined)).toBeNull();
    expect(hesaplaGeceCalismaDakika(undefined, "17:00")).toBeNull();
  });

  it("gece yarısı geçişli kayıt → null", () => {
    expect(hesaplaGeceCalismaDakika("22:00", "02:00")).toBeNull();
  });
});

describe("GECE_CALISMASI_7_5_SAAT_ASIMI compliance", () => {
  it("451 dk gece bandı brüt süre → GECE_CALISMASI_7_5_SAAT_ASIMI üretir", () => {
    const uyarilar = uretComplianceUyarilari(1291, 1200, "00:00", "21:31");
    expect(uyarilar).toContainEqual({
      code: GECE_CALISMASI_7_5_SAAT_ASIMI_CODE,
      message: GECE_CALISMASI_7_5_SAAT_ASIMI_MESSAGE,
      level: "UYARI"
    });
  });

  it("450 dk tam sınır → GECE_CALISMASI_7_5_SAAT_ASIMI üretmez", () => {
    const uyarilar = uretComplianceUyarilari(1290, 1200, "00:00", "21:30");
    expect(
      uyarilar.some((u) => u.code === GECE_CALISMASI_7_5_SAAT_ASIMI_CODE)
    ).toBe(false);
  });

  it("gece bandı dışı kayıt → GECE_CALISMASI_7_5_SAAT_ASIMI üretmez", () => {
    const uyarilar = uretComplianceUyarilari(540, 480, "08:00", "17:00");
    expect(
      uyarilar.some((u) => u.code === GECE_CALISMASI_7_5_SAAT_ASIMI_CODE)
    ).toBe(false);
  });

  it("saat eksik → GECE_CALISMASI_7_5_SAAT_ASIMI üretmez", () => {
    const uyarilar = uretComplianceUyarilari(480, 420, "08:00", undefined);
    expect(
      uyarilar.some((u) => u.code === GECE_CALISMASI_7_5_SAAT_ASIMI_CODE)
    ).toBe(false);
  });

  it("gece yarısı geçişli kayıt → GECE_CALISMASI_7_5_SAAT_ASIMI üretmez", () => {
    const uyarilar = uretComplianceUyarilari(240, 180, "22:00", "02:00");
    expect(
      uyarilar.some((u) => u.code === GECE_CALISMASI_7_5_SAAT_ASIMI_CODE)
    ).toBe(false);
  });

  it("GECE_MESAI bilgisi bozulmaz", () => {
    const uyarilar = uretComplianceUyarilari(1291, 1200, "00:00", "21:31");
    expect(uyarilar).toContainEqual(
      expect.objectContaining({ code: "GECE_MESAI", level: "BILGI" })
    );
  });

  it("mevcut MAX_DAILY_LIMIT uyarıları bozulmaz", () => {
    const uyarilar = uretComplianceUyarilari(780, 720, "00:00", "21:31");
    expect(uyarilar).toContainEqual(
      expect.objectContaining({ code: "MAX_DAILY_LIMIT", level: "KRITIK" })
    );
    expect(uyarilar).toContainEqual(
      expect.objectContaining({ code: GECE_CALISMASI_7_5_SAAT_ASIMI_CODE, level: "UYARI" })
    );
  });

  it("hesapla() entegrasyon: gece bandı aşımı compliance'a yansır", () => {
    const sonuc = hesapla({
      personel_id: 1,
      tarih: "2026-04-13",
      giris_saati: "00:00",
      cikis_saati: "21:31"
    });
    expect(sonuc.compliance_uyarilari).toContainEqual(
      expect.objectContaining({ code: GECE_CALISMASI_7_5_SAAT_ASIMI_CODE, level: "UYARI" })
    );
  });
});

describe("18 yas alti blok kurallari", () => {
  it("20:00 sonrasi cikis gece bandina girer", () => {
    expect(geceBandinaGiriyor("08:00", "20:00")).toBe(true);
  });

  it("06:00 oncesi giris gece bandina girer", () => {
    expect(geceBandinaGiriyor("05:30", "14:00")).toBe(true);
  });

  it("yetiskin personelde blok mesaji uretmez", () => {
    expect(
      hesaplaYasKuraliBlokMesaji({
        tarih: "2026-04-13",
        dogum_tarihi: "1990-01-01",
        giris_saati: "08:00",
        cikis_saati: "17:00"
      })
    ).toBeNull();
  });

  it("tam 18 yaş personelde gece ve fazla çalışma yasağı uygulanmaz", () => {
    expect(
      hesaplaYasKuraliBlokMesaji({
        tarih: "2026-04-13",
        dogum_tarihi: "2008-04-13",
        giris_saati: "12:00",
        cikis_saati: "20:30"
      })
    ).toBeNull();
  });

  it("18 yas alti gece calismasini bloklar", () => {
    expect(
      hesaplaYasKuraliBlokMesaji({
        tarih: "2026-04-13",
        dogum_tarihi: "2009-01-01",
        giris_saati: "12:00",
        cikis_saati: "20:30"
      })
    ).toBe("Yasal Uyari: 18 yas alti personele gece calismasi girilemez.");
  });

  it("18 yas alti pazar mesaisini bloklar", () => {
    expect(
      hesaplaYasKuraliBlokMesaji({
        tarih: "2026-04-12",
        dogum_tarihi: "2009-01-01",
        giris_saati: "09:00",
        cikis_saati: "17:00"
      })
    ).toBe("Yasal Uyari: 18 yas alti personele fazla mesai girilemez.");
  });
});

// =========================================================================
// 10. Ana hesapla() fonksiyonu – Entegre senaryolar
// =========================================================================

describe("hesapla – entegre senaryolar", () => {
  it("standart iş günü: 08:00-17:00, mola yok girişi", () => {
    const sonuc = hesapla({
      personel_id: 1,
      tarih: "2026-04-13",
      giris_saati: "08:00",
      cikis_saati: "17:00"
    });

    expect(sonuc.gun_tipi).toBe("Normal_Is_Gunu");
    expect(sonuc.hareket_durumu).toBe("Geldi");
    expect(sonuc.dayanak).toBeUndefined();
    expect(sonuc.hesap_etkisi).toBe("Tam_Yevmiye_Ver");
    expect(sonuc.gunluk_brut_sure_dakika).toBe(540);
    expect(sonuc.hesaplanan_mola_dakika).toBe(60);
    expect(sonuc.net_calisma_suresi_dakika).toBe(480);
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(true);
  });

  it("kısa mesai: 09:00-13:00 → 15 dk mola", () => {
    const sonuc = hesapla({
      personel_id: 2,
      tarih: "2026-04-14",
      giris_saati: "09:00",
      cikis_saati: "13:00"
    });

    expect(sonuc.gunluk_brut_sure_dakika).toBe(240);
    expect(sonuc.hesaplanan_mola_dakika).toBe(15);
    expect(sonuc.net_calisma_suresi_dakika).toBe(225);
  });

  it("yarım gün: 08:00-12:30 → 270 dk brüt, 30 dk mola, 240 dk net", () => {
    const sonuc = hesapla({
      personel_id: 3,
      tarih: "2026-04-15",
      giris_saati: "08:00",
      cikis_saati: "12:30"
    });

    expect(sonuc.gunluk_brut_sure_dakika).toBe(270);
    expect(sonuc.hesaplanan_mola_dakika).toBe(30);
    expect(sonuc.net_calisma_suresi_dakika).toBe(240);
  });

  it("devamsızlık: gelmedi, izinsiz → kesinti + hafta tatili hak kaybı", () => {
    const sonuc = hesapla({
      personel_id: 4,
      tarih: "2026-04-16",
      hareket_durumu: "Gelmedi",
      dayanak: "Yok_Izinsiz"
    });

    expect(sonuc.hesap_etkisi).toBe("Yevmiye_Kes");
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(false);
    expect(sonuc.gunluk_brut_sure_dakika).toBe(0);
    expect(sonuc.net_calisma_suresi_dakika).toBe(0);
  });

  it("hastalık raporu: gelmedi ama raporlu → otomatik hesap etkisi yok + hafta tatili korunur", () => {
    const sonuc = hesapla({
      personel_id: 5,
      tarih: "2026-04-16",
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Hastalik"
    });

    expect(sonuc.hesap_etkisi).toBeUndefined();
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(true);
  });

  it("iş kazası: gelmedi ama raporlu → otomatik hesap etkisi yok + hafta tatili korunur", () => {
    const sonuc = hesapla({
      personel_id: 6,
      tarih: "2026-04-16",
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Is_Kazasi"
    });

    expect(sonuc.hesap_etkisi).toBeUndefined();
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(true);
  });

  it("hastalık raporu + çalışma saati → normal çalışma veya mesai sayılmaz, kritik uyarı üretir", () => {
    const sonuc = hesapla({
      personel_id: 14,
      tarih: "2026-04-16",
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Hastalik",
      giris_saati: "08:00",
      cikis_saati: "17:00"
    });

    expect(sonuc.hesap_etkisi).toBeUndefined();
    expect(sonuc.hesap_etkisi).not.toBe("Tam_Yevmiye_Ver");
    expect(sonuc.hesap_etkisi).not.toBe("Mesai_Yaz");
    expect(sonuc.compliance_uyarilari).toContainEqual(
      expect.objectContaining({
        code: "RAPOR_CALISMA_CAKISMASI",
        level: "KRITIK"
      })
    );
  });

  it("iş kazası raporu + çalışma saati → normal çalışma veya mesai sayılmaz, kritik uyarı üretir", () => {
    const sonuc = hesapla({
      personel_id: 15,
      tarih: "2026-04-16",
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Is_Kazasi",
      giris_saati: "08:00",
      cikis_saati: "17:00"
    });

    expect(sonuc.hesap_etkisi).toBeUndefined();
    expect(sonuc.hesap_etkisi).not.toBe("Tam_Yevmiye_Ver");
    expect(sonuc.hesap_etkisi).not.toBe("Mesai_Yaz");
    expect(sonuc.compliance_uyarilari).toContainEqual(
      expect.objectContaining({
        code: "RAPOR_CALISMA_CAKISMASI",
        level: "KRITIK"
      })
    );
  });

  it("Pazar + hastalık raporu + çalışma saati → Mesai_Yaz üretmez, kritik uyarı üretir", () => {
    const sonuc = hesapla({
      personel_id: 16,
      tarih: "2026-04-19",
      gun_tipi: "Hafta_Tatili_Pazar",
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Hastalik",
      giris_saati: "09:00",
      cikis_saati: "12:00"
    });

    expect(sonuc.hesap_etkisi).toBeUndefined();
    expect(sonuc.hesap_etkisi).not.toBe("Mesai_Yaz");
    expect(sonuc.compliance_uyarilari).toContainEqual(
      expect.objectContaining({
        code: "RAPOR_CALISMA_CAKISMASI",
        level: "KRITIK"
      })
    );
  });

  it("UBGT + hastalık raporu + çalışma saati → Mesai_Yaz üretmez, kritik uyarı üretir", () => {
    const sonuc = hesapla({
      personel_id: 17,
      tarih: "2026-04-23",
      gun_tipi: "UBGT_Resmi_Tatil",
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Hastalik",
      giris_saati: "09:00",
      cikis_saati: "12:00"
    });

    expect(sonuc.hesap_etkisi).toBeUndefined();
    expect(sonuc.hesap_etkisi).not.toBe("Mesai_Yaz");
    expect(sonuc.compliance_uyarilari).toContainEqual(
      expect.objectContaining({
        code: "RAPOR_CALISMA_CAKISMASI",
        level: "KRITIK"
      })
    );
  });

  it("ücretli izin: gelmedi ama izinli → tam yevmiye + hafta tatili korunur", () => {
    const sonuc = hesapla({
      personel_id: 7,
      tarih: "2026-04-16",
      hareket_durumu: "Gelmedi",
      dayanak: "Ucretli_Izinli"
    });

    expect(sonuc.hesap_etkisi).toBe("Tam_Yevmiye_Ver");
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(true);
  });

  it("yıllık izin: gelmedi ama yıllık → tam yevmiye + hafta tatili korunur", () => {
    const sonuc = hesapla({
      personel_id: 8,
      tarih: "2026-04-16",
      hareket_durumu: "Gelmedi",
      dayanak: "Yillik_Izin"
    });

    expect(sonuc.hesap_etkisi).toBe("Tam_Yevmiye_Ver");
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(true);
  });

  it("pazar mesaisi: tatil günü geldi → mesai yaz", () => {
    const sonuc = hesapla({
      personel_id: 9,
      tarih: "2026-04-12",
      giris_saati: "09:00",
      cikis_saati: "17:00"
    });

    expect(sonuc.gun_tipi).toBe("Hafta_Tatili_Pazar");
    expect(sonuc.hesap_etkisi).toBe("Mesai_Yaz");
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(true);
  });

  it("UBGT resmi tatil mesaisi → mesai yaz", () => {
    const sonuc = hesapla({
      personel_id: 10,
      tarih: "2026-04-23",
      gun_tipi: "UBGT_Resmi_Tatil",
      giris_saati: "08:00",
      cikis_saati: "17:00"
    });

    expect(sonuc.gun_tipi).toBe("UBGT_Resmi_Tatil");
    expect(sonuc.hesap_etkisi).toBe("Mesai_Yaz");
  });

  it("gerçek mola yasal minimumdan büyükse gerçek uygulanır", () => {
    const sonuc = hesapla({
      personel_id: 11,
      tarih: "2026-04-13",
      giris_saati: "08:00",
      cikis_saati: "17:00",
      gercek_mola_dakika: 90
    });

    expect(sonuc.gunluk_brut_sure_dakika).toBe(540);
    expect(sonuc.hesaplanan_mola_dakika).toBe(60);
    expect(sonuc.net_calisma_suresi_dakika).toBe(450);
  });

  it("uzun mesai uyarı üretir", () => {
    const sonuc = hesapla({
      personel_id: 12,
      tarih: "2026-04-13",
      giris_saati: "06:00",
      cikis_saati: "20:00"
    });

    expect(sonuc.gunluk_brut_sure_dakika).toBe(840);
    expect(sonuc.compliance_uyarilari.length).toBeGreaterThan(0);
    expect(sonuc.compliance_uyarilari).toContainEqual(
      expect.objectContaining({ code: "MAX_DAILY_LIMIT" })
    );
    expect(sonuc.compliance_uyarilari).toContainEqual(
      expect.objectContaining({ code: "GECE_MESAI" })
    );
  });

  it("saat bilgisi olmadan otomatik türetme: Gelmedi + Yok_Izinsiz", () => {
    const sonuc = hesapla({
      personel_id: 13,
      tarih: "2026-04-13"
    });

    expect(sonuc.hareket_durumu).toBe("Gelmedi");
    expect(sonuc.dayanak).toBe("Yok_Izinsiz");
    expect(sonuc.hesap_etkisi).toBe("Yevmiye_Kes");
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(false);
  });
});

// =========================================================================
// 11. Haftalık net çalışma özeti (45 saat eşiği)
// =========================================================================

describe("hesaplaHaftalikCalismaOzeti", () => {
  it("boş hafta → toplam ve fazla 0, eşik sabit", () => {
    const o = hesaplaHaftalikCalismaOzeti([]);
    expect(o.toplam_net_dakika).toBe(0);
    expect(o.normal_calisma_dakika).toBe(0);
    expect(o.fazla_calisma_dakika).toBe(0);
    expect(o.haftalik_esik_dakika).toBe(HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA);
    expect(o.haftalik_esik_dakika).toBe(2700);
  });

  it("5 gün x 8 saat net (480 dk) = 2400 dk → fazla yok", () => {
    const gunler = Array.from({ length: 5 }, () => ({ net_calisma_suresi_dakika: 480 }));
    const o = hesaplaHaftalikCalismaOzeti(gunler);
    expect(o.toplam_net_dakika).toBe(2400);
    expect(o.normal_calisma_dakika).toBe(2400);
    expect(o.fazla_calisma_dakika).toBe(0);
  });

  it("tam 45 saat (2700 dk) → fazla yok, normal = toplam", () => {
    const o = hesaplaHaftalikCalismaOzeti([{ net_calisma_suresi_dakika: 2700 }]);
    expect(o.toplam_net_dakika).toBe(2700);
    expect(o.normal_calisma_dakika).toBe(2700);
    expect(o.fazla_calisma_dakika).toBe(0);
  });

  it("46 saat (2760 dk) → 60 dk fazla", () => {
    const o = hesaplaHaftalikCalismaOzeti([{ net_calisma_suresi_dakika: 2760 }]);
    expect(o.toplam_net_dakika).toBe(2760);
    expect(o.normal_calisma_dakika).toBe(2700);
    expect(o.fazla_calisma_dakika).toBe(60);
  });

  it("karışık günler → toplam ve normal/fazla doğru", () => {
    const o = hesaplaHaftalikCalismaOzeti([
      { net_calisma_suresi_dakika: 400 },
      { net_calisma_suresi_dakika: 500 },
      { net_calisma_suresi_dakika: 600 },
      { net_calisma_suresi_dakika: 200 },
      { net_calisma_suresi_dakika: 300 }
    ]);
    expect(o.toplam_net_dakika).toBe(2000);
    expect(o.normal_calisma_dakika).toBe(2000);
    expect(o.fazla_calisma_dakika).toBe(0);
  });

  it("Pazar günü net dakika varsa haftalık toplama dahil (sadece süre toplamı)", () => {
    const haftaIci = Array.from({ length: 5 }, () => ({ net_calisma_suresi_dakika: 480 }));
    const pazar = { net_calisma_suresi_dakika: 360 };
    const o = hesaplaHaftalikCalismaOzeti([...haftaIci, pazar]);
    expect(o.toplam_net_dakika).toBe(5 * 480 + 360);
    expect(o.fazla_calisma_dakika).toBe(5 * 480 + 360 - 2700);
  });

  it("bazı satırlarda net süre undefined → 0 sayılır", () => {
    const o = hesaplaHaftalikCalismaOzeti([
      { net_calisma_suresi_dakika: 1000 },
      {},
      { net_calisma_suresi_dakika: 500 }
    ]);
    expect(o.toplam_net_dakika).toBe(1500);
    expect(o.fazla_calisma_dakika).toBe(0);
  });
});

// =========================================================================
// 11a. Haftalık fazla çalışma ücreti (maaş / 225, FM × 1.5)
// =========================================================================

describe("hesaplaSaatlikUcret", () => {
  it("45000 / 225 → 200", () => {
    expect(hesaplaSaatlikUcret(45000)).toBe(200);
  });

  it("maaş 0 → saatlik 0", () => {
    expect(hesaplaSaatlikUcret(0)).toBe(0);
  });

  it("negatif veya NaN maaş → 0", () => {
    expect(hesaplaSaatlikUcret(-100)).toBe(0);
    expect(hesaplaSaatlikUcret(Number.NaN)).toBe(0);
  });
});

describe("hesaplaFazlaCalismaTutari", () => {
  const saatlik = 200;

  it("60 dk fazla → 1 saat × 1.5 × 200 = 300", () => {
    expect(hesaplaFazlaCalismaTutari(60, saatlik)).toBe(300);
  });

  it("120 dk fazla → 2 saat × 1.5 × 200 = 600", () => {
    expect(hesaplaFazlaCalismaTutari(120, saatlik)).toBe(600);
  });

  it("fazla dakika 0 → tutar 0", () => {
    expect(hesaplaFazlaCalismaTutari(0, saatlik)).toBe(0);
  });

  it("90 dk → 1.5 saat × 1.5 × 200 = 450", () => {
    expect(hesaplaFazlaCalismaTutari(90, saatlik)).toBe(450);
  });

  it("31 dk ham → ödeme esas 60 dk → tutar 300", () => {
    expect(hesaplaFazlaCalismaTutari(31, saatlik)).toBe(300);
  });
});

describe("hesaplaMevzuatFazlaCalismaOdemeDakika", () => {
  it.each([
    [0, 0],
    [1, 30],
    [29, 30],
    [30, 30],
    [31, 60],
    [59, 60],
    [60, 60],
    [61, 90]
  ])("%i dk ham → %i dk ödeme esas", (ham, odemeEsas) => {
    expect(hesaplaMevzuatFazlaCalismaOdemeDakika(ham)).toBe(odemeEsas);
  });

  it("haftalık aggregate ham kalır; ödeme katmanı yuvarlar", () => {
    const ozet = hesaplaHaftalikCalismaOzeti([{ net_calisma_suresi_dakika: 2700 + 31 }]);
    expect(ozet.fazla_calisma_dakika).toBe(31);
    const ucret = hesaplaHaftalikFazlaCalismaUcreti(ozet, 45000);
    expect(ucret.odeme_esas_fazla_calisma_dakika).toBe(60);
    expect(ucret.fazla_calisma_tutari).toBe(300);
  });

  it("geç/erken ham dakikası FM mevzuat yuvarlamasından ayrıdır", () => {
    expect(hesaplaKesintiyeEsasDakika(31)).toBe(31);
    expect(hesaplaMevzuatFazlaCalismaOdemeDakika(31)).toBe(60);
    expect(hesaplaKesintiyeEsasDakika(45)).toBe(45);
    expect(hesaplaMevzuatFazlaCalismaOdemeDakika(45)).toBe(60);
    expect(hesaplaKesintiyeEsasDakika(61)).toBe(61);
    expect(hesaplaMevzuatFazlaCalismaOdemeDakika(61)).toBe(90);
  });
});

// =========================================================================
// 11a1. Günlük kesinti (geç kalma / erken çıkma / devamsızlık)
// =========================================================================

describe("hesaplaGunlukUcret", () => {
  it("30000 / 30 → 1000", () => {
    expect(hesaplaGunlukUcret(30000)).toBe(1000);
  });

  it("maaş 0 → günlük ücret 0", () => {
    expect(hesaplaGunlukUcret(0)).toBe(0);
  });
});

describe("hesaplaSaatlikKesintiTutari ve gecKalmaErkenCikma özeti", () => {
  const maas = 30000;

  it("60 dk eksik → 1 saatlik kesinti doğru", () => {
    expect(hesaplaSaatlikKesintiTutari(60, maas)).toBeCloseTo(133.33, 2);
    const o = hesaplaGecKalmaErkenCikmaKesintiOzeti(60, maas);
    expect(o.gercek_eksik_dakika).toBe(60);
    expect(o.kesintiye_esas_dakika).toBe(60);
    expect(o.kesintiye_esas_saat).toBe(1);
    expect(o.kesinti_tutari).toBe(hesaplaSaatlikKesintiTutari(60, maas));
    expect(o.saatlik_ucret).toBeCloseTo(133.33, 2);
  });

  it("90 dk eksik → 1.5 saatlik kesinti doğru", () => {
    expect(hesaplaSaatlikKesintiTutari(90, maas)).toBeCloseTo(200, 2);
    const o = hesaplaGecKalmaErkenCikmaKesintiOzeti(90, maas);
    expect(o.kesinti_tutari).toBeCloseTo(200, 2);
  });

  it("negatif eksik dakika → 0 kesinti", () => {
    expect(hesaplaSaatlikKesintiTutari(-30, maas)).toBe(0);
    const o = hesaplaGecKalmaErkenCikmaKesintiOzeti(-5, maas);
    expect(o.gercek_eksik_dakika).toBe(0);
    expect(o.kesintiye_esas_dakika).toBe(0);
    expect(o.kesinti_tutari).toBe(0);
  });

  it("ondalıklı para: günlük ücret ve ham dakika kesintisi 2 hane", () => {
    expect(hesaplaGunlukUcret(10001)).toBe(333.37);
    const o = hesaplaGecKalmaErkenCikmaKesintiOzeti(45, 30000);
    expect(o.gercek_eksik_dakika).toBe(45);
    expect(o.kesintiye_esas_dakika).toBe(45);
    expect(o.kesinti_tutari).toBeCloseTo(100, 2);
  });

  it("0 dk eksik → kesintiye esas 0 ve kesinti 0", () => {
    const o = hesaplaGecKalmaErkenCikmaKesintiOzeti(0, maas);
    expect(o.gercek_eksik_dakika).toBe(0);
    expect(o.kesintiye_esas_dakika).toBe(0);
    expect(o.kesinti_tutari).toBe(0);
  });

  it("1 dk eksik → 1 dk kesintiye esas süre", () => {
    const o = hesaplaGecKalmaErkenCikmaKesintiOzeti(1, maas);
    expect(o.gercek_eksik_dakika).toBe(1);
    expect(o.kesintiye_esas_dakika).toBe(1);
    expect(o.kesinti_tutari).toBe(hesaplaSaatlikKesintiTutari(1, maas));
  });

  it("30 dk eksik → 30 dk kesintiye esas sure", () => {
    const o = hesaplaGecKalmaErkenCikmaKesintiOzeti(30, maas);
    expect(o.kesintiye_esas_dakika).toBe(30);
    expect(o.kesinti_tutari).toBeCloseTo(66.67, 2);
  });

  it("31 dk eksik → 31 dk kesintiye esas süre", () => {
    const o = hesaplaGecKalmaErkenCikmaKesintiOzeti(31, maas);
    expect(o.gercek_eksik_dakika).toBe(31);
    expect(o.kesintiye_esas_dakika).toBe(31);
    expect(o.kesinti_tutari).toBe(hesaplaSaatlikKesintiTutari(31, maas));
  });

  it("61 dk eksik → 61 dk kesintiye esas süre", () => {
    const o = hesaplaGecKalmaErkenCikmaKesintiOzeti(61, maas);
    expect(o.gercek_eksik_dakika).toBe(61);
    expect(o.kesintiye_esas_dakika).toBe(61);
    expect(o.kesinti_tutari).toBe(hesaplaSaatlikKesintiTutari(61, maas));
  });
});

describe("hesaplaGecErkenEksikSure", () => {
  it("Gec_Geldi + beklenen 08:00 + gercek 08:15 => 15 dakika", () => {
    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Gec_Geldi",
      beklenen_giris_saati: "08:00",
      giris_saati: "08:15"
    });

    expect(sonuc).toEqual({
      hesaplanabilir_mi: true,
      eksik_dakika: 15,
      tip: "GEC_KALMA"
    });
  });

  it("Erken_Cikti + beklenen 17:00 + gercek 16:40 => 20 dakika", () => {
    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Erken_Cikti",
      beklenen_cikis_saati: "17:00",
      cikis_saati: "16:40"
    });

    expect(sonuc).toEqual({
      hesaplanabilir_mi: true,
      eksik_dakika: 20,
      tip: "ERKEN_CIKMA"
    });
  });

  it("Gec_Geldi + gercek giris beklenenden once/esit => 0 dakika ve hesaplanabilir", () => {
    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Gec_Geldi",
        beklenen_giris_saati: "08:00",
        giris_saati: "08:00"
      })
    ).toEqual({
      hesaplanabilir_mi: true,
      eksik_dakika: 0,
      tip: "GEC_KALMA"
    });

    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Gec_Geldi",
        beklenen_giris_saati: "08:00",
        giris_saati: "07:55"
      })
    ).toEqual({
      hesaplanabilir_mi: true,
      eksik_dakika: 0,
      tip: "GEC_KALMA"
    });
  });

  it("Erken_Cikti + gercek cikis beklenenden sonra/esit => 0 dakika ve hesaplanabilir", () => {
    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Erken_Cikti",
        beklenen_cikis_saati: "17:00",
        cikis_saati: "17:00"
      })
    ).toEqual({
      hesaplanabilir_mi: true,
      eksik_dakika: 0,
      tip: "ERKEN_CIKMA"
    });

    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Erken_Cikti",
        beklenen_cikis_saati: "17:00",
        cikis_saati: "17:10"
      })
    ).toEqual({
      hesaplanabilir_mi: true,
      eksik_dakika: 0,
      tip: "ERKEN_CIKMA"
    });
  });

  it("beklenen giris yok => hesaplanamaz, BEKLENEN_SAAT_YOK", () => {
    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Gec_Geldi",
        giris_saati: "08:15"
      })
    ).toEqual({
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "BEKLENEN_SAAT_YOK"
    });
  });

  it("beklenen cikis yok => hesaplanamaz, BEKLENEN_SAAT_YOK", () => {
    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Erken_Cikti",
        cikis_saati: "16:40"
      })
    ).toEqual({
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "BEKLENEN_SAAT_YOK"
    });
  });

  it("gercek giris yok => hesaplanamaz, GERCEK_SAAT_YOK", () => {
    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Gec_Geldi",
        beklenen_giris_saati: "08:00"
      })
    ).toEqual({
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "GERCEK_SAAT_YOK"
    });
  });

  it("gercek cikis yok => hesaplanamaz, GERCEK_SAAT_YOK", () => {
    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Erken_Cikti",
        beklenen_cikis_saati: "17:00"
      })
    ).toEqual({
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "GERCEK_SAAT_YOK"
    });
  });

  it("gecersiz saat formati => hesaplanamaz, GECERSIZ_SAAT", () => {
    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Gec_Geldi",
        beklenen_giris_saati: "08:00",
        giris_saati: "08:6x"
      })
    ).toEqual({
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "GECERSIZ_SAAT"
    });

    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Erken_Cikti",
        beklenen_cikis_saati: "17:0x",
        cikis_saati: "16:40"
      })
    ).toEqual({
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "GECERSIZ_SAAT"
    });
  });

  it("hareket durumu Geldi => hesaplanamaz, HAREKET_DURUMU_UYGUN_DEGIL", () => {
    expect(
      hesaplaGecErkenEksikSure({
        hareket_durumu: "Geldi",
        beklenen_giris_saati: "08:00",
        giris_saati: "08:15"
      })
    ).toEqual({
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "HAREKET_DURUMU_UYGUN_DEGIL"
    });
  });

  it("hesaplanan eksik dakika mevcut kesinti ozetine beslenebilir", () => {
    const sure = hesaplaGecErkenEksikSure({
      hareket_durumu: "Gec_Geldi",
      beklenen_giris_saati: "08:00",
      giris_saati: "09:30"
    });

    expect(sure.hesaplanabilir_mi).toBe(true);
    expect(sure.eksik_dakika).toBe(90);

    const kesinti = hesaplaGecKalmaErkenCikmaKesintiOzeti(sure.eksik_dakika, 30000);
    expect(kesinti.gercek_eksik_dakika).toBe(90);
    expect(kesinti.kesintiye_esas_dakika).toBe(90);
    expect(kesinti.kesinti_tutari).toBeCloseTo(200, 2);
  });

  it("tolerans sabiti 0 iken 15 dk fark 15 dk doner", () => {
    expect(GEC_ERKEN_TOLERANS_DAKIKA).toBe(0);

    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Gec_Geldi",
      beklenen_giris_saati: "08:00",
      giris_saati: "08:15"
    });

    expect(sonuc.hesaplanabilir_mi).toBe(true);
    expect(sonuc.eksik_dakika).toBe(15);
  });

  it("gec_kalma_dakika alani saat hesabindan once authoritative kabul edilir", () => {
    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Gec_Geldi",
      gec_kalma_dakika: 22,
      beklenen_giris_saati: "08:00",
      giris_saati: "08:05"
    });

    expect(sonuc).toEqual({
      hesaplanabilir_mi: true,
      eksik_dakika: 22,
      tip: "GEC_KALMA"
    });
  });

  it("erken_cikis_dakika alani saat hesabindan once authoritative kabul edilir", () => {
    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Erken_Cikti",
      erken_cikis_dakika: 11,
      beklenen_cikis_saati: "17:00",
      cikis_saati: "16:50"
    });

    expect(sonuc).toEqual({
      hesaplanabilir_mi: true,
      eksik_dakika: 11,
      tip: "ERKEN_CIKMA"
    });
  });

  it("gec_kalma_dakika 0 ise beklenen saat olmadan hesaplanabilir", () => {
    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Gec_Geldi",
      gec_kalma_dakika: 0
    });

    expect(sonuc).toEqual({
      hesaplanabilir_mi: true,
      eksik_dakika: 0,
      tip: "GEC_KALMA"
    });
  });

  it("explicit gec_kalma_dakika 0 saat farki fallback tetiklemez", () => {
    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Gec_Geldi",
      gec_kalma_dakika: 0,
      beklenen_giris_saati: "08:00",
      giris_saati: "08:30"
    });

    expect(sonuc.eksik_dakika).toBe(0);
    expect(sonuc.tip).toBe("GEC_KALMA");
  });

  it("explicit dakika ile saatler cift hesaplanmaz", () => {
    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Gec_Geldi",
      gec_kalma_dakika: 9,
      beklenen_giris_saati: "08:00",
      giris_saati: "08:45"
    });

    expect(sonuc.eksik_dakika).toBe(9);
  });

  it("acik dakika alani yoksa saat farki fallback calisir", () => {
    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Gec_Geldi",
      beklenen_giris_saati: "08:00",
      giris_saati: "08:12"
    });

    expect(sonuc.eksik_dakika).toBe(12);
  });

  it.each([
    ["negatif", -4],
    ["NaN", Number.NaN],
    ["Infinity", Number.POSITIVE_INFINITY]
  ] as const)("gecersiz gec_kalma_dakika (%s) saat fallback ile normalize edilir", (_label, value) => {
    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Gec_Geldi",
      gec_kalma_dakika: value,
      beklenen_giris_saati: "08:00",
      giris_saati: "08:10"
    });

    expect(sonuc.eksik_dakika).toBe(10);
  });

  it.each([
    ["negatif", -3],
    ["NaN", Number.NaN],
    ["Infinity", Number.POSITIVE_INFINITY]
  ] as const)("gecersiz erken_cikis_dakika (%s) saat fallback ile normalize edilir", (_label, value) => {
    const sonuc = hesaplaGecErkenEksikSure({
      hareket_durumu: "Erken_Cikti",
      erken_cikis_dakika: value,
      beklenen_cikis_saati: "17:00",
      cikis_saati: "16:45"
    });

    expect(sonuc.eksik_dakika).toBe(15);
  });
});

describe("deriveGecErkenKesintiPreview (hook güvenli davranış)", () => {
  const maas = 30000;

  function basePuantaj(partial: Partial<GunlukPuantaj>): GunlukPuantaj {
    return {
      personel_id: 1,
      tarih: "2026-04-10",
      compliance_uyarilari: [],
      ...partial
    };
  }

  it("beklenen_giris_saati yoksa parasal kesinti özeti üretmez, not verir", () => {
    const p = basePuantaj({
      hareket_durumu: "Gec_Geldi",
      giris_saati: "08:15"
    });
    const out = deriveGecErkenKesintiPreview(p, maas);
    expect(out.gecErkenKesintiOzeti).toBeNull();
    expect(out.gecErkenKesintiTutari).toBe(0);
    expect(out.gecErkenKesintiHesaplanamadiMi).toBe(true);
    expect(out.gecErkenKesintiNotu).toBe(
      "Geç kalma veya erken çıkma kesintisi için beklenen mesai saati bulunmadığından saatlik kesinti ön izlemesi gösterilmiyor."
    );
  });

  it("gec_kalma_dakika doluysa beklenen saat olmadan kesinti özeti üretir", () => {
    const p = basePuantaj({
      hareket_durumu: "Gec_Geldi",
      gec_kalma_dakika: 30
    });
    const out = deriveGecErkenKesintiPreview(p, maas);
    expect(out.gecErkenKesintiHesaplanamadiMi).toBe(false);
    expect(out.gecErkenKesintiOzeti?.gercek_eksik_dakika).toBe(30);
    expect(out.gecErkenKesintiTutari).toBeGreaterThan(0);
  });

  it("beklenen_cikis_saati yoksa parasal kesinti özeti üretmez, not verir", () => {
    const p = basePuantaj({
      hareket_durumu: "Erken_Cikti",
      cikis_saati: "16:40"
    });
    const out = deriveGecErkenKesintiPreview(p, maas);
    expect(out.gecErkenKesintiOzeti).toBeNull();
    expect(out.gecErkenKesintiTutari).toBe(0);
    expect(out.gecErkenKesintiHesaplanamadiMi).toBe(true);
    expect(out.gecErkenKesintiNotu).toBe(
      "Geç kalma veya erken çıkma kesintisi için beklenen mesai saati bulunmadığından saatlik kesinti ön izlemesi gösterilmiyor."
    );
  });

  it("gerçek giriş saati yoksa parasal kesinti özeti üretmez, not verir", () => {
    const p = basePuantaj({
      hareket_durumu: "Gec_Geldi",
      beklenen_giris_saati: "08:00"
    });
    const out = deriveGecErkenKesintiPreview(p, maas);
    expect(out.gecErkenKesintiOzeti).toBeNull();
    expect(out.gecErkenKesintiTutari).toBe(0);
    expect(out.gecErkenKesintiHesaplanamadiMi).toBe(true);
    expect(out.gecErkenKesintiNotu).toBe(
      "Geç kalma veya erken çıkma kesintisi için gerçek saat bilgisi eksik olduğundan saatlik kesinti ön izlemesi gösterilmiyor."
    );
  });

  it("gerçek çıkış saati yoksa parasal kesinti özeti üretmez, not verir", () => {
    const p = basePuantaj({
      hareket_durumu: "Erken_Cikti",
      beklenen_cikis_saati: "17:00"
    });
    const out = deriveGecErkenKesintiPreview(p, maas);
    expect(out.gecErkenKesintiOzeti).toBeNull();
    expect(out.gecErkenKesintiTutari).toBe(0);
    expect(out.gecErkenKesintiHesaplanamadiMi).toBe(true);
    expect(out.gecErkenKesintiNotu).toBe(
      "Geç kalma veya erken çıkma kesintisi için gerçek saat bilgisi eksik olduğundan saatlik kesinti ön izlemesi gösterilmiyor."
    );
  });

  it("geçersiz saat formatında parasal kesinti özeti üretmez, not verir", () => {
    const p = basePuantaj({
      hareket_durumu: "Gec_Geldi",
      beklenen_giris_saati: "08:0x",
      giris_saati: "08:15"
    });
    const out = deriveGecErkenKesintiPreview(p, maas);
    expect(out.gecErkenKesintiOzeti).toBeNull();
    expect(out.gecErkenKesintiTutari).toBe(0);
    expect(out.gecErkenKesintiHesaplanamadiMi).toBe(true);
    expect(out.gecErkenKesintiNotu).toBe(
      "Geç kalma veya erken çıkma kesintisi için saat formatı geçersiz olduğundan saatlik kesinti ön izlemesi gösterilmiyor."
    );
  });

  it("eksik_dakika = 0 ise özet üretmez (kart şişmez), not üretmez", () => {
    const p = basePuantaj({
      hareket_durumu: "Gec_Geldi",
      beklenen_giris_saati: "08:00",
      giris_saati: "08:00"
    });
    const out = deriveGecErkenKesintiPreview(p, maas);
    expect(out.gecErkenKesintiOzeti).toBeNull();
    expect(out.gecErkenKesintiTutari).toBe(0);
    expect(out.gecErkenKesintiNotu).toBeNull();
    expect(out.gecErkenKesintiHesaplanamadiMi).toBe(false);
  });

  it("eksik_dakika = 1 ise Engine V2 gibi ham 1 dakika kesilir", () => {
    const p = basePuantaj({
      hareket_durumu: "Gec_Geldi",
      beklenen_giris_saati: "08:00",
      giris_saati: "08:01"
    });
    const out = deriveGecErkenKesintiPreview(p, maas);
    expect(out.gecErkenKesintiOzeti).not.toBeNull();
    expect(out.gecErkenKesintiOzeti!.gercek_eksik_dakika).toBe(1);
    expect(out.gecErkenKesintiOzeti!.kesintiye_esas_dakika).toBe(1);
  });
});

describe("hesaplaDevamsizlikKesintiOzeti", () => {
  const maas = 30000;

  it("1 gün devamsızlık, hafta tatili kaybı yok → 1 günlük kesinti", () => {
    const o = hesaplaDevamsizlikKesintiOzeti(maas, { devamsizlik_gun_sayisi: 1 });
    expect(o.gunluk_ucret).toBe(1000);
    expect(o.hafta_tatili_kaybi_gun_sayisi).toBe(0);
    expect(o.toplam_kesinti_gun_esdegeri).toBe(1);
    expect(o.toplam_kesinti_tutari).toBe(1000);
  });

  it("1 gün devamsızlık + 1 gün tatil kaybı → 2 günlük eşdeğer kesinti", () => {
    const o = hesaplaDevamsizlikKesintiOzeti(maas, {
      devamsizlik_gun_sayisi: 1,
      hafta_tatili_kaybi_gun_sayisi: 1
    });
    expect(o.toplam_kesinti_gun_esdegeri).toBe(2);
    expect(o.toplam_kesinti_tutari).toBe(2000);
  });

  it("2 gün devamsızlık + 1 gün tatil kaybı → 3 günlük eşdeğer kesinti", () => {
    const o = hesaplaDevamsizlikKesintiOzeti(maas, {
      devamsizlik_gun_sayisi: 2,
      hafta_tatili_kaybi_gun_sayisi: 1
    });
    expect(o.toplam_kesinti_gun_esdegeri).toBe(3);
    expect(o.toplam_kesinti_tutari).toBe(3000);
  });

  it("geçersiz maaşta tutar 0, gün eşdeğeri korunur", () => {
    const o = hesaplaDevamsizlikKesintiOzeti(-50, {
      devamsizlik_gun_sayisi: 1,
      hafta_tatili_kaybi_gun_sayisi: 1
    });
    expect(o.gunluk_ucret).toBe(0);
    expect(o.toplam_kesinti_gun_esdegeri).toBe(2);
    expect(o.toplam_kesinti_tutari).toBe(0);
  });
});

describe("siniflandirPuantajEksikGunEtkisi", () => {
  it("Geldi eksik gun, SGK dusumu veya ucret kesintisi uretmez", () => {
    const o = siniflandirPuantajEksikGunEtkisi({
      hareket_durumu: "Geldi"
    });

    expect(o).toMatchObject({
      eksik_gun_adayi_mi: false,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "YOK",
      manuel_inceleme_gerekli_mi: false
    });
  });

  it.each([
    ["Gec_Geldi", "DAKIKA_BAZLI_KESINTI_ADAYI"],
    ["Erken_Cikti", "DAKIKA_BAZLI_KESINTI_ADAYI"]
  ] as const)("%s SGK eksik gün sayılmaz, yalnız dakika bazlı ücret etkisi adayıdır", (hareketDurumu, ucretEtkisiTuru) => {
    const o = siniflandirPuantajEksikGunEtkisi({
      hareket_durumu: hareketDurumu
    });

    expect(o).toMatchObject({
      eksik_gun_adayi_mi: false,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: ucretEtkisiTuru,
      manuel_inceleme_gerekli_mi: false
    });
  });

  it.each([
    ["Ucretli_Izinli"],
    ["Yillik_Izin"],
    ["Telafi_Calismasi"],
    ["Gorevde_Calisma"]
  ] as const)("Gelmedi + %s SGK prim gününü düşürmez ve ücreti korur", (dayanak) => {
    const o = siniflandirPuantajEksikGunEtkisi({
      hareket_durumu: "Gelmedi",
      dayanak
    });

    expect(o).toMatchObject({
      eksik_gun_adayi_mi: false,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "UCRET_KORUNUR",
      manuel_inceleme_gerekli_mi: false
    });
  });

  it("Gelmedi + Yok_Izinsiz tam gün eksik gün ve SGK düşümü adayıdır", () => {
    const o = siniflandirPuantajEksikGunEtkisi({
      hareket_durumu: "Gelmedi",
      dayanak: "Yok_Izinsiz"
    });

    expect(o).toMatchObject({
      eksik_gun_adayi_mi: true,
      eksik_gun_sayisi: 1,
      sgk_prim_gununu_dusurur_mu: true,
      ucret_etkisi_turu: "GUNLUK_KESINTI_ADAYI",
      manuel_inceleme_gerekli_mi: false
    });
  });

  it.each([
    ["Raporlu_Hastalik"],
    ["Raporlu_Is_Kazasi"]
  ] as const)("Gelmedi + %s otomatik SGK düşümü üretmez, manuel inceleme sinyali verir", (dayanak) => {
    const o = siniflandirPuantajEksikGunEtkisi({
      hareket_durumu: "Gelmedi",
      dayanak
    });

    expect(o).toMatchObject({
      eksik_gun_adayi_mi: true,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true
    });
  });

  it.each([
    ["Raporlu_Hastalik", true],
    ["Raporlu_Hastalik", false],
    ["Raporlu_Is_Kazasi", true],
    ["Raporlu_Is_Kazasi", false]
  ] as const)("%s + durumu_bildirdi_mi=%s ile kesin SGK düşümü vermez", (dayanak, durumuBildirdiMi) => {
    const o = siniflandirPuantajEksikGunEtkisi({
      hareket_durumu: "Gelmedi",
      dayanak,
      durumu_bildirdi_mi: durumuBildirdiMi
    });

    expect(o.sgk_prim_gununu_dusurur_mu).toBe(false);
    expect(o.eksik_gun_sayisi).toBe(0);
    expect(o.manuel_inceleme_gerekli_mi).toBe(true);
  });

  it.each([
    [true, true, false],
    [false, false, true]
  ] as const)(
    "durumu_bildirdi_mi=%s tek başına ücretli/ücretsiz veya SGK kararı üretmez",
    (durumuBildirdiMi, haberliSinyal, habersizSinyal) => {
      const o = siniflandirPuantajEksikGunEtkisi({
        hareket_durumu: "Gelmedi",
        durumu_bildirdi_mi: durumuBildirdiMi
      });

      expect(o).toMatchObject({
        eksik_gun_adayi_mi: true,
        eksik_gun_sayisi: 0,
        sgk_prim_gununu_dusurur_mu: false,
        ucret_etkisi_turu: "POLITIKA_INCELEMESI",
        manuel_inceleme_gerekli_mi: true,
        haberli_yokluk_sinyali_mi: haberliSinyal,
        habersiz_yokluk_sinyali_mi: habersizSinyal
      });
    }
  );
});

function makeHastalikSurec(
  overrides: Partial<HastalikRaporSureci> & { id?: number | string }
): HastalikRaporSureci {
  return {
    id: 1,
    personel_id: 1,
    surec_turu: "RAPOR",
    alt_tur: "Raporlu_Hastalik",
    baslangic_tarihi: "2026-04-10",
    bitis_tarihi: "2026-04-14",
    ilk_iki_gun_firma_oder_mi: false,
    state: "AKTIF",
    ...overrides
  };
}

describe("siniflandirHastalikRaporGunUcretEtkisi (S63-2)", () => {
  it("ilk 2 gun + firma odemez → GUNLUK_KESINTI_ADAYI", () => {
    const cozum = cozumleHastalikRaporGunu([makeHastalikSurec({ id: 10, ilk_iki_gun_firma_oder_mi: false })], {
      personelId: 1,
      tarih: "2026-04-10"
    });

    expect(siniflandirHastalikRaporGunUcretEtkisi(cozum)).toMatchObject({
      eksik_gun_adayi_mi: true,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "GUNLUK_KESINTI_ADAYI",
      manuel_inceleme_gerekli_mi: false
    });
  });

  it("ilk 2 gun + firma oder → UCRET_KORUNUR", () => {
    const cozum = cozumleHastalikRaporGunu([makeHastalikSurec({ id: 11, ilk_iki_gun_firma_oder_mi: true })], {
      personelId: 1,
      tarih: "2026-04-11"
    });

    expect(siniflandirHastalikRaporGunUcretEtkisi(cozum)).toMatchObject({
      eksik_gun_adayi_mi: false,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "UCRET_KORUNUR",
      manuel_inceleme_gerekli_mi: false
    });
  });

  it("3. gun+ → POLITIKA_INCELEMESI ve manuel inceleme", () => {
    const cozum = cozumleHastalikRaporGunu([makeHastalikSurec({ id: 12, ilk_iki_gun_firma_oder_mi: false })], {
      personelId: 1,
      tarih: "2026-04-12"
    });

    expect(siniflandirHastalikRaporGunUcretEtkisi(cozum)).toMatchObject({
      eksik_gun_adayi_mi: true,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true
    });
  });
});

describe("siniflandirPuantajEksikGunEtkisi + hastalik_rapor_cozumu (S63-2)", () => {
  it("Raporlu_Hastalik + policy cozumu ile gunluk kesinti adayi uretir", () => {
    const cozum = cozumleHastalikRaporGunu([makeHastalikSurec({ id: 20, ilk_iki_gun_firma_oder_mi: false })], {
      personelId: 1,
      tarih: "2026-04-10"
    });

    const o = siniflandirPuantajEksikGunEtkisi({
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Hastalik",
      hastalik_rapor_cozumu: cozum
    });

    expect(o).toMatchObject({
      eksik_gun_adayi_mi: true,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "GUNLUK_KESINTI_ADAYI",
      manuel_inceleme_gerekli_mi: false
    });
  });

  it("Raporlu_Hastalik + firma oder policy ile ucret korunur", () => {
    const cozum = cozumleHastalikRaporGunu([makeHastalikSurec({ id: 21, ilk_iki_gun_firma_oder_mi: true })], {
      personelId: 1,
      tarih: "2026-04-10"
    });

    const o = siniflandirPuantajEksikGunEtkisi({
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Hastalik",
      hastalik_rapor_cozumu: cozum
    });

    expect(o.ucret_etkisi_turu).toBe("UCRET_KORUNUR");
    expect(o.manuel_inceleme_gerekli_mi).toBe(false);
  });

  it("Raporlu_Is_Kazasi policy cozumunu yok sayar", () => {
    const cozum = cozumleHastalikRaporGunu([makeHastalikSurec({ id: 22, ilk_iki_gun_firma_oder_mi: false })], {
      personelId: 1,
      tarih: "2026-04-10"
    });

    const o = siniflandirPuantajEksikGunEtkisi({
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Is_Kazasi",
      hastalik_rapor_cozumu: cozum
    });

    expect(o).toMatchObject({
      ucret_etkisi_turu: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true
    });
  });

  it("Raporlu_Hastalik policy cozumu olmadan geriye uyumlu POLITIKA_INCELEMESI dondurur", () => {
    const o = siniflandirPuantajEksikGunEtkisi({
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Hastalik"
    });

    expect(o).toMatchObject({
      ucret_etkisi_turu: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true
    });
  });
});

describe("hesaplaAylikPuantajEksikGunOzeti", () => {
  it("gunluk siniflandirmalardan aylik eksik gun ve ucret etkisi toplamlarini uretir", () => {
    const o = hesaplaAylikPuantajEksikGunOzeti({
      kayitlar: [
        { hareket_durumu: "Geldi" },
        { hareket_durumu: "Gec_Geldi" },
        { hareket_durumu: "Erken_Cikti" },
        { hareket_durumu: "Gelmedi", dayanak: "Yok_Izinsiz", durumu_bildirdi_mi: false },
        { hareket_durumu: "Gelmedi", dayanak: "Ucretli_Izinli", durumu_bildirdi_mi: true },
        { hareket_durumu: "Gelmedi", dayanak: "Yillik_Izin" }
      ]
    });

    expect(o).toMatchObject({
      toplam_kayit_sayisi: 6,
      eksik_gun_adayi_kayit_sayisi: 1,
      sgk_prim_gununu_dusuren_eksik_gun_sayisi: 1,
      manuel_inceleme_kayit_sayisi: 0,
      dakika_bazli_ucret_etkisi_adayi_sayisi: 2,
      gunluk_kesinti_adayi_sayisi: 1,
      ucret_korunan_kayit_sayisi: 2,
      haberli_yokluk_sinyali_sayisi: 1,
      habersiz_yokluk_sinyali_sayisi: 1,
      kesin_sgk_prim_gunu_hesaplanabilir_mi: true
    });
    expect(o.siniflandirmalar).toHaveLength(6);
  });

  it("raporlu gunleri otomatik SGK dusumune eklemez ve aylik ozeti manuel incelemeye alir", () => {
    const o = hesaplaAylikPuantajEksikGunOzeti({
      kayitlar: [
        { hareket_durumu: "Gelmedi", dayanak: "Yok_Izinsiz" },
        { hareket_durumu: "Gelmedi", dayanak: "Raporlu_Hastalik" },
        { hareket_durumu: "Gelmedi", dayanak: "Raporlu_Is_Kazasi" }
      ]
    });

    expect(o.sgk_prim_gununu_dusuren_eksik_gun_sayisi).toBe(1);
    expect(o.manuel_inceleme_kayit_sayisi).toBe(2);
    expect(o.kesin_sgk_prim_gunu_hesaplanabilir_mi).toBe(false);
  });

  it("bos kayit listesi icin sifirli ve hesaplanabilir ozet dondurur", () => {
    const o = hesaplaAylikPuantajEksikGunOzeti({ kayitlar: [] });

    expect(o).toMatchObject({
      toplam_kayit_sayisi: 0,
      eksik_gun_adayi_kayit_sayisi: 0,
      sgk_prim_gununu_dusuren_eksik_gun_sayisi: 0,
      manuel_inceleme_kayit_sayisi: 0,
      dakika_bazli_ucret_etkisi_adayi_sayisi: 0,
      gunluk_kesinti_adayi_sayisi: 0,
      ucret_korunan_kayit_sayisi: 0,
      haberli_yokluk_sinyali_sayisi: 0,
      habersiz_yokluk_sinyali_sayisi: 0,
      kesin_sgk_prim_gunu_hesaplanabilir_mi: true
    });
    expect(o.siniflandirmalar).toEqual([]);
  });
});

describe("hesaplaSgkPrimGunu", () => {
  it.each([
    [28, 0],
    [29, 0],
    [30, 0],
    [31, 0]
  ])("%i gün, 0 eksik ve prim günü düşüren eksik yoksa 30 döner", (takvimGunu, eksikGun) => {
    const o = hesaplaSgkPrimGunu({
      takvim_gunu: takvimGunu,
      eksik_gun: eksikGun,
      sgk_prim_gununu_dusurur_mu: false
    });

    expect(o.hesaplanabilir_mi).toBe(true);
    expect(o.sgk_gunu).toBe(30);
  });

  it.each([
    [28, 1, 27],
    [28, 2, 26],
    [29, 1, 28],
    [30, 1, 29],
    [31, 1, 30],
    [31, 2, 29]
  ])(
    "%i gün, %i ücret hak edilmeyen eksik gün için SGK prim günü %i olur",
    (takvimGunu, eksikGun, beklenenSgkGunu) => {
      const o = hesaplaSgkPrimGunu({
        takvim_gunu: takvimGunu,
        eksik_gun: eksikGun,
        sgk_prim_gununu_dusurur_mu: true
      });

      expect(o.hesaplanabilir_mi).toBe(true);
      expect(o.sgk_gunu).toBe(beklenenSgkGunu);
    }
  );

  it("eksik_gun takvim_gunu degerinden buyukse sonucu 0 altina dusurmez", () => {
    const o = hesaplaSgkPrimGunu({
      takvim_gunu: 28,
      eksik_gun: 40,
      sgk_prim_gununu_dusurur_mu: true
    });

    expect(o.hesaplanabilir_mi).toBe(true);
    expect(o.sgk_gunu).toBe(0);
  });

  it("sgk_prim_gununu_dusurur_mu false ise eksik_gun verilse bile 30 kalir", () => {
    const o = hesaplaSgkPrimGunu({
      takvim_gunu: 28,
      eksik_gun: 5,
      sgk_prim_gununu_dusurur_mu: false
    });

    expect(o.hesaplanabilir_mi).toBe(true);
    expect(o.sgk_gunu).toBe(30);
  });

  it("negatif eksik_gun guvenli sekilde reddedilir", () => {
    const o = hesaplaSgkPrimGunu({
      takvim_gunu: 30,
      eksik_gun: -1,
      sgk_prim_gununu_dusurur_mu: true
    });

    expect(o.hesaplanabilir_mi).toBe(false);
    expect(o.sgk_gunu).toBe(0);
    expect(o.neden).toBe("GECERSIZ_EKSIK_GUN");
  });

  it("gecersiz takvim_gunu guvenli sekilde reddedilir", () => {
    const o = hesaplaSgkPrimGunu({
      takvim_gunu: 0,
      eksik_gun: 1,
      sgk_prim_gununu_dusurur_mu: true
    });

    expect(o.hesaplanabilir_mi).toBe(false);
    expect(o.sgk_gunu).toBe(0);
    expect(o.neden).toBe("GECERSIZ_TAKVIM_GUNU");
  });
});

describe("hesaplaTatilEkOdemeOzeti", () => {
  const maas = 30000;

  it("normal iş günü veya Mesai_Yaz değilse null", () => {
    expect(
      hesaplaTatilEkOdemeOzeti(maas, {
        gun_tipi: "Normal_Is_Gunu",
        hesap_etkisi: "Mesai_Yaz",
        giris_saati: "09:00",
        cikis_saati: "18:00"
      })
    ).toBeNull();
    expect(
      hesaplaTatilEkOdemeOzeti(maas, {
        gun_tipi: "UBGT_Resmi_Tatil",
        hesap_etkisi: "Tam_Yevmiye_Ver",
        giris_saati: "09:00",
        cikis_saati: "18:00"
      })
    ).toBeNull();
  });

  it("UBGT + Mesai_Yaz + saat → çarpan 1 ve günlük ücret kadar ek ödeme", () => {
    const o = hesaplaTatilEkOdemeOzeti(maas, {
      gun_tipi: "UBGT_Resmi_Tatil",
      hesap_etkisi: "Mesai_Yaz",
      giris_saati: "08:00",
      cikis_saati: "12:00"
    });
    expect(o).not.toBeNull();
    expect(o!.tur).toBe("UBGT");
    expect(o!.carpani).toBe(1);
    expect(o!.gunluk_ucret).toBe(1000);
    expect(o!.ek_odeme_tutari).toBe(1000);
  });

  it("Hafta tatili + Mesai_Yaz + saat + hak var → çarpan 1,5 ve pazar kararı", () => {
    const o = hesaplaTatilEkOdemeOzeti(maas, {
      gun_tipi: "Hafta_Tatili_Pazar",
      hesap_etkisi: "Mesai_Yaz",
      giris_saati: "10:00",
      cikis_saati: "14:00",
      hafta_tatili_hak_kazandi_mi: true
    });
    expect(o).not.toBeNull();
    expect(o!.tur).toBe("HAFTA_TATILI");
    expect(o!.carpani).toBe(1.5);
    expect(o!.gunluk_ucret).toBe(1000);
    expect(o!.ek_odeme_tutari).toBe(1500);
    expect(o!.hafta_tatili_pazar_karar).toMatchObject({
      hafta_tatili_hak_kazandi_mi: true,
      pazar_calisildi_mi: true,
      ek_odeme_gun_carpani: 1.5,
      ek_odeme_tutari: 1500,
      manuel_inceleme_gerekli_mi: false
    });
  });

  it("Pazar + Mesai_Yaz + saat ama hak bilgisi yoksa otomatik 1,5 üretilmez", () => {
    const o = hesaplaTatilEkOdemeOzeti(maas, {
      gun_tipi: "Hafta_Tatili_Pazar",
      hesap_etkisi: "Mesai_Yaz",
      giris_saati: "10:00",
      cikis_saati: "14:00"
    });
    expect(o).not.toBeNull();
    expect(o!.tur).toBe("HAFTA_TATILI");
    expect(o!.carpani).toBe(0);
    expect(o!.ek_odeme_tutari).toBe(0);
    expect(o!.hafta_tatili_pazar_karar).toBeUndefined();
  });

  it("giriş ve çıkış boşsa null (tatil mesaisi doğrulanamaz)", () => {
    expect(
      hesaplaTatilEkOdemeOzeti(maas, {
        gun_tipi: "UBGT_Resmi_Tatil",
        hesap_etkisi: "Mesai_Yaz",
        giris_saati: "",
        cikis_saati: "   "
      })
    ).toBeNull();
  });

  it("maaş geçersizse tutar 0, özet yine üretilir", () => {
    const o = hesaplaTatilEkOdemeOzeti(-1, {
      gun_tipi: "UBGT_Resmi_Tatil",
      hesap_etkisi: "Mesai_Yaz",
      giris_saati: "08:00",
      cikis_saati: "12:00"
    });
    expect(o!.gunluk_ucret).toBe(0);
    expect(o!.ek_odeme_tutari).toBe(0);
  });
});

describe("hesaplaTatilEkOdemeOzeti — Pazar / hafta tatili hak entegrasyonu", () => {
  const maas = 30000;

  it("Pazar + hak var + çalıştı → 1,5 çarpan ve tutar üretir", () => {
    const o = hesaplaTatilEkOdemeOzeti(maas, {
      gun_tipi: "Hafta_Tatili_Pazar",
      hesap_etkisi: "Mesai_Yaz",
      giris_saati: "09:00",
      cikis_saati: "17:00",
      hafta_tatili_hak_kazandi_mi: true
    });
    expect(o!.carpani).toBe(1.5);
    expect(o!.ek_odeme_tutari).toBe(1500);
    expect(o!.hafta_tatili_pazar_karar?.manuel_inceleme_gerekli_mi).toBe(false);
  });

  it("Pazar + hak yok + çalıştı → otomatik tutar üretmez, manuel inceleme", () => {
    const o = hesaplaTatilEkOdemeOzeti(maas, {
      gun_tipi: "Hafta_Tatili_Pazar",
      hesap_etkisi: "Mesai_Yaz",
      giris_saati: "09:00",
      cikis_saati: "17:00",
      hafta_tatili_hak_kazandi_mi: false
    });
    expect(o!.carpani).toBe(0);
    expect(o!.ek_odeme_tutari).toBe(0);
    expect(o!.hafta_tatili_pazar_karar?.manuel_inceleme_gerekli_mi).toBe(true);
    expect(o!.hafta_tatili_pazar_karar?.hafta_tatili_kaybi_var_mi).toBe(true);
  });

  it("Pazar + hak var + çalışmadı → ek ödeme üretmez", () => {
    const o = hesaplaTatilEkOdemeOzeti(maas, {
      gun_tipi: "Hafta_Tatili_Pazar",
      hesap_etkisi: "Mesai_Yaz",
      giris_saati: "",
      cikis_saati: "",
      hafta_tatili_hak_kazandi_mi: true
    });
    expect(o!.carpani).toBe(0);
    expect(o!.ek_odeme_tutari).toBe(0);
    expect(o!.hafta_tatili_pazar_karar?.pazar_calisildi_mi).toBe(false);
    expect(o!.hafta_tatili_pazar_karar?.hafta_tatili_kaybi_var_mi).toBe(false);
  });

  it("Pazar + hak yok + çalışmadı → hafta tatili kaybı, ek ödeme yok", () => {
    const o = hesaplaTatilEkOdemeOzeti(maas, {
      gun_tipi: "Hafta_Tatili_Pazar",
      hesap_etkisi: "Mesai_Yaz",
      giris_saati: "",
      cikis_saati: "",
      hafta_tatili_hak_kazandi_mi: false
    });
    expect(o!.carpani).toBe(0);
    expect(o!.ek_odeme_tutari).toBe(0);
    expect(o!.hafta_tatili_pazar_karar?.hafta_tatili_kaybi_var_mi).toBe(true);
    expect(o!.hafta_tatili_pazar_karar?.pazar_calisildi_mi).toBe(false);
  });

  it("hak bilgisi güvensiz (yok) iken Pazar + çalıştı → otomatik +1,5 yok", () => {
    const o = hesaplaTatilEkOdemeOzeti(maas, {
      gun_tipi: "Hafta_Tatili_Pazar",
      hesap_etkisi: "Mesai_Yaz",
      giris_saati: "08:00",
      cikis_saati: "12:00"
    });
    expect(o!.carpani).toBe(0);
    expect(o!.ek_odeme_tutari).toBe(0);
    expect(o!.hafta_tatili_pazar_karar).toBeUndefined();
  });

  it("UBGT branch eski davranış: çarpan 1 ve tutar, pazar kararı yok", () => {
    const o = hesaplaTatilEkOdemeOzeti(maas, {
      gun_tipi: "UBGT_Resmi_Tatil",
      hesap_etkisi: "Mesai_Yaz",
      giris_saati: "08:00",
      cikis_saati: "12:00"
    });
    expect(o!.tur).toBe("UBGT");
    expect(o!.carpani).toBe(1);
    expect(o!.ek_odeme_tutari).toBe(1000);
    expect(o!.hafta_tatili_pazar_karar).toBeUndefined();
  });
});

describe("hesaplaHaftalikFazlaCalismaUcreti", () => {
  it("özet 2760 dk (60 dk fazla) + 45000 maaş → tutar 300", () => {
    const ozet = hesaplaHaftalikCalismaOzeti([{ net_calisma_suresi_dakika: 2760 }]);
    const u = hesaplaHaftalikFazlaCalismaUcreti(ozet, 45000);
    expect(u.fazla_calisma_dakika).toBe(60);
    expect(u.saatlik_ucret).toBe(200);
    expect(u.fazla_calisma_saat).toBe(1);
    expect(u.fazla_calisma_tutari).toBe(300);
  });

  it("fazla çalışma 0 → tutar 0", () => {
    const ozet = hesaplaHaftalikCalismaOzeti([{ net_calisma_suresi_dakika: 1000 }]);
    const u = hesaplaHaftalikFazlaCalismaUcreti(ozet, 45000);
    expect(u.fazla_calisma_tutari).toBe(0);
    expect(u.fazla_calisma_saat).toBe(0);
  });

  it("geçersiz maaş ile güvenli sıfır saatlik ve tutar", () => {
    const ozet = hesaplaHaftalikCalismaOzeti([{ net_calisma_suresi_dakika: 2760 }]);
    const u = hesaplaHaftalikFazlaCalismaUcreti(ozet, -1);
    expect(u.saatlik_ucret).toBe(0);
    expect(u.fazla_calisma_tutari).toBe(0);
  });
});

// =========================================================================
// 11a2. Günlük puantaj → haftalık FM ücret adapter
// =========================================================================

function gunlukSatir(
  personel_id: number,
  tarih: string,
  net?: number,
  gun_tipi: GunlukPuantaj["gun_tipi"] = "Normal_Is_Gunu"
): GunlukPuantaj {
  return {
    personel_id,
    tarih,
    gun_tipi,
    compliance_uyarilari: [],
    ...(net !== undefined ? { net_calisma_suresi_dakika: net } : {})
  };
}

describe("hesaplaHaftalikPuantajUcretOzeti", () => {
  const ref = "2026-04-15";
  const maas = 45000;

  it("aynı haftadaki 5 günlük kayıt + maaş → özet ve hafta aralığı doğru", () => {
    const gunler = [
      gunlukSatir(1, "2026-04-13", 480),
      gunlukSatir(1, "2026-04-14", 480),
      gunlukSatir(1, "2026-04-15", 480),
      gunlukSatir(1, "2026-04-16", 480),
      gunlukSatir(1, "2026-04-17", 480)
    ];
    const o = hesaplaHaftalikPuantajUcretOzeti(gunler, ref, maas);
    expect(o.hafta_baslangic).toBe("2026-04-13");
    expect(o.hafta_bitis).toBe("2026-04-19");
    expect(o.toplam_net_dakika).toBe(2400);
    expect(o.fazla_surelerle_calisma_dakika).toBe(0);
    expect(o.fazla_calisma_dakika).toBe(0);
    expect(o.toplam_fazla_calisma_tutari).toBe(0);
    expect(o.fazla_calisma_tutari).toBe(0);
    expect(o.saatlik_ucret).toBe(200);
  });

  it("45 saat altı → fazla çalışma tutarı 0", () => {
    const gunler = [gunlukSatir(1, "2026-04-14", 400), gunlukSatir(1, "2026-04-15", 400)];
    const o = hesaplaHaftalikPuantajUcretOzeti(gunler, ref, maas);
    expect(o.toplam_net_dakika).toBe(800);
    expect(o.fazla_calisma_tutari).toBe(0);
  });

  it("46 saat (2760 dk) → FSC 300 + FM 60 (Engine V2 bant) ve tutarlar", () => {
    const gunler = [gunlukSatir(1, "2026-04-14", 2760)];
    const o = hesaplaHaftalikPuantajUcretOzeti(gunler, ref, maas);
    expect(o.fazla_surelerle_calisma_dakika).toBe(300);
    expect(o.fazla_calisma_dakika).toBe(60);
    expect(o.fazla_surelerle_calisma_tutari).toBe(1250);
    expect(o.fazla_calisma_tutari).toBe(300);
    expect(o.toplam_fazla_calisma_tutari).toBe(1550);
  });

  it.each([
    [0, 0],
    [1, 30],
    [29, 30],
    [30, 30],
    [31, 60],
    [59, 60],
    [60, 60],
    [61, 90]
  ])("Engine V2 frontend FSC sınırı %i dk → %i dk", (hamDakika, odemeEsasDakika) => {
    const o = hesaplaHaftalikPuantajUcretOzeti(
      [gunlukSatir(1, "2026-04-14", 2400 + hamDakika)],
      ref,
      maas
    );
    expect(o.fazla_surelerle_calisma_dakika).toBe(hamDakika);
    expect(o.odeme_esas_fazla_surelerle_calisma_dakika).toBe(odemeEsasDakika);
  });

  it.each([
    [0, 0],
    [1, 30],
    [29, 30],
    [30, 30],
    [31, 60],
    [59, 60],
    [60, 60],
    [61, 90]
  ])("Engine V2 frontend FM sınırı %i dk → %i dk", (hamDakika, odemeEsasDakika) => {
    const o = hesaplaHaftalikPuantajUcretOzeti(
      [gunlukSatir(1, "2026-04-14", 2700 + hamDakika)],
      ref,
      maas
    );
    expect(o.fazla_calisma_dakika).toBe(hamDakika);
    expect(o.odeme_esas_fazla_calisma_dakika).toBe(odemeEsasDakika);
  });

  it("karışık haftalardaki kayıtlar → yalnız referans haftası", () => {
    const gunler = [
      gunlukSatir(1, "2026-04-13", 100),
      gunlukSatir(1, "2026-04-20", 9999),
      gunlukSatir(1, "2026-04-18", 200)
    ];
    const o = hesaplaHaftalikPuantajUcretOzeti(gunler, ref, maas);
    expect(o.toplam_net_dakika).toBe(300);
  });

  it("Pazar HT satırı haftalık OT havuzuna girmez (Engine V2)", () => {
    const gunler = [
      gunlukSatir(1, "2026-04-13", 480),
      gunlukSatir(1, "2026-04-14", 480),
      gunlukSatir(1, "2026-04-15", 480),
      gunlukSatir(1, "2026-04-16", 480),
      gunlukSatir(1, "2026-04-17", 480),
      gunlukSatir(1, "2026-04-19", 120, "Hafta_Tatili_Pazar")
    ];
    const o = hesaplaHaftalikPuantajUcretOzeti(gunler, ref, maas);
    expect(o.toplam_net_dakika).toBe(2400);
    expect(o.fazla_calisma_dakika).toBe(0);
    expect(o.fazla_surelerle_calisma_dakika).toBe(0);
  });

  it("geçersiz referans tarih → sıfır özet, hafta aralığı null", () => {
    const o = hesaplaHaftalikPuantajUcretOzeti(
      [gunlukSatir(1, "2026-04-14", 480)],
      "gecersiz-tarih",
      maas
    );
    expect(o.hafta_baslangic).toBeNull();
    expect(o.hafta_bitis).toBeNull();
    expect(o.toplam_net_dakika).toBe(0);
    expect(o.fazla_calisma_tutari).toBe(0);
    expect(o.haftalik_esik_dakika).toBe(HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA);
  });

  it("geçersiz maaş → süre özeti korunur, ücret alanları 0", () => {
    const gunler = [
      gunlukSatir(1, "2026-04-13", 480),
      gunlukSatir(1, "2026-04-14", 480),
      gunlukSatir(1, "2026-04-15", 480),
      gunlukSatir(1, "2026-04-16", 480),
      gunlukSatir(1, "2026-04-17", 480)
    ];
    const o = hesaplaHaftalikPuantajUcretOzeti(gunler, ref, -50);
    expect(o.toplam_net_dakika).toBe(2400);
    expect(o.saatlik_ucret).toBe(0);
    expect(o.fazla_calisma_tutari).toBe(0);
  });

  it("boş kayıt listesi → sıfır sonuç, geçerli referansta hafta aralığı dolu", () => {
    const o = hesaplaHaftalikPuantajUcretOzeti([], ref, maas);
    expect(o.toplam_net_dakika).toBe(0);
    expect(o.hafta_baslangic).toBe("2026-04-13");
    expect(o.hafta_bitis).toBe("2026-04-19");
  });
});

// =========================================================================
// 11b. Hafta aralığı ve tarihten haftalık özet (Pzt–Pz)
// =========================================================================

describe("hesaplaHaftaAraligi", () => {
  it("hafta içi tarih → Pazartesi başlangıç, Pazar bitiş", () => {
    const a = hesaplaHaftaAraligi("2026-04-15");
    expect(a).toEqual({
      hafta_baslangic: "2026-04-13",
      hafta_bitis: "2026-04-19"
    });
  });

  it("referans Pazar → aynı haftanın son günü bitişte, başlangıç önceki Pazartesi", () => {
    const a = hesaplaHaftaAraligi("2026-04-12");
    expect(a).toEqual({
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12"
    });
  });

  it("geçersiz tarih formatı → null (throw yok)", () => {
    expect(hesaplaHaftaAraligi("15-04-2026")).toBeNull();
    expect(hesaplaHaftaAraligi("")).toBeNull();
  });

  it("takvim taşması (var olmayan gün) → null", () => {
    expect(hesaplaHaftaAraligi("2026-02-31")).toBeNull();
  });
});

describe("filtreleHaftalikPuantajSatirlari", () => {
  it("karışık listede yalnızca referans haftasındaki satırlar kalır", () => {
    const tum = [
      { tarih: "2026-04-13", net_calisma_suresi_dakika: 100 },
      { tarih: "2026-04-20", net_calisma_suresi_dakika: 999 },
      { tarih: "2026-04-18", net_calisma_suresi_dakika: 200 }
    ];
    const f = filtreleHaftalikPuantajSatirlari(tum, "2026-04-15");
    expect(f.map((x) => x.tarih).sort()).toEqual(["2026-04-13", "2026-04-18"]);
  });

  it("geçersiz referans tarih → boş dizi", () => {
    expect(filtreleHaftalikPuantajSatirlari([{ tarih: "2026-04-13" }], "gecersiz")).toEqual([]);
  });

  it("geçersiz satır tarihi haftaya dahil edilmez", () => {
    const f = filtreleHaftalikPuantajSatirlari(
      [
        { tarih: "2026-04-14", net_calisma_suresi_dakika: 50 },
        { tarih: "2026-13-40", net_calisma_suresi_dakika: 999 }
      ],
      "2026-04-15"
    );
    expect(f).toHaveLength(1);
    expect(f[0].tarih).toBe("2026-04-14");
  });
});

describe("hesaplaTarihtenHaftalikCalismaOzeti", () => {
  it("aynı haftadaki kayıtların toplamı doğru", () => {
    const gunler = [
      { tarih: "2026-04-13", net_calisma_suresi_dakika: 480 },
      { tarih: "2026-04-14", net_calisma_suresi_dakika: 480 },
      { tarih: "2026-04-15", net_calisma_suresi_dakika: 480 },
      { tarih: "2026-04-16", net_calisma_suresi_dakika: 480 },
      { tarih: "2026-04-17", net_calisma_suresi_dakika: 480 }
    ];
    const o = hesaplaTarihtenHaftalikCalismaOzeti(gunler, "2026-04-15");
    expect(o.toplam_net_dakika).toBe(2400);
    expect(o.fazla_calisma_dakika).toBe(0);
  });

  it("Pazar günü kaydı haftaya dahil ve toplama girer", () => {
    const gunler = [
      { tarih: "2026-04-13", net_calisma_suresi_dakika: 480 },
      { tarih: "2026-04-14", net_calisma_suresi_dakika: 480 },
      { tarih: "2026-04-15", net_calisma_suresi_dakika: 480 },
      { tarih: "2026-04-16", net_calisma_suresi_dakika: 480 },
      { tarih: "2026-04-17", net_calisma_suresi_dakika: 480 },
      { tarih: "2026-04-19", net_calisma_suresi_dakika: 120 }
    ];
    const o = hesaplaTarihtenHaftalikCalismaOzeti(gunler, "2026-04-15");
    expect(o.toplam_net_dakika).toBe(2520);
    expect(o.normal_calisma_dakika).toBe(2520);
  });

  it("geçersiz referans tarih → süzüm boş, özet sıfır toplamlı", () => {
    const o = hesaplaTarihtenHaftalikCalismaOzeti(
      [{ tarih: "2026-04-14", net_calisma_suresi_dakika: 480 }],
      "not-a-date"
    );
    expect(o.toplam_net_dakika).toBe(0);
    expect(o.normal_calisma_dakika).toBe(0);
    expect(o.fazla_calisma_dakika).toBe(0);
    expect(o.haftalik_esik_dakika).toBe(HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA);
  });

  it("boş dizi + geçerli referans → sıfır toplamlı özet", () => {
    const o = hesaplaTarihtenHaftalikCalismaOzeti([], "2026-04-15");
    expect(o.toplam_net_dakika).toBe(0);
    expect(o.fazla_calisma_dakika).toBe(0);
  });
});

// =========================================================================
// 12. Adaptör fonksiyonları
// =========================================================================

describe("adaptör fonksiyonları", () => {
  it("gunlukPuantajToGirdi: GunlukPuantaj → HesapGirdisi dönüştürür", () => {
    const puantaj: GunlukPuantaj = {
      personel_id: 1,
      tarih: "2026-04-13",
      gun_tipi: "Normal_Is_Gunu",
      hareket_durumu: "Geldi",
      giris_saati: "08:00",
      cikis_saati: "17:00",
      gercek_mola_dakika: 60,
      compliance_uyarilari: []
    };

    const girdi = gunlukPuantajToGirdi(puantaj);
    expect(girdi.personel_id).toBe(1);
    expect(girdi.giris_saati).toBe("08:00");
    expect(girdi.gercek_mola_dakika).toBe(60);
  });

  it("gunlukPuantajToGirdi: acik dakika alanlarini tasir", () => {
    const puantaj: GunlukPuantaj = {
      personel_id: 1,
      tarih: "2026-04-13",
      gec_kalma_dakika: 14,
      erken_cikis_dakika: 0,
      compliance_uyarilari: []
    };

    const girdi = gunlukPuantajToGirdi(puantaj);
    expect(girdi.gec_kalma_dakika).toBe(14);
    expect(girdi.erken_cikis_dakika).toBe(0);
  });

  it("hesapSonucuToGunlukPuantaj: acik dakika alanlarini koruyabilir", () => {
    const sonuc = hesapla({
      personel_id: 1,
      tarih: "2026-04-13",
      giris_saati: "08:00",
      cikis_saati: "17:00"
    });

    const puantaj = hesapSonucuToGunlukPuantaj(sonuc, "HESAPLANDI", {
      gec_kalma_dakika: 11,
      erken_cikis_dakika: null,
      beklenen_giris_saati: "08:00",
      beklenen_cikis_saati: "17:00"
    });
    expect(puantaj.gec_kalma_dakika).toBe(11);
    expect(puantaj.erken_cikis_dakika).toBeUndefined();
    expect(puantaj.beklenen_giris_saati).toBe("08:00");
  });

  it("hesapSonucuToGunlukPuantaj: sonucu GunlukPuantaj tipine çevirir", () => {
    const sonuc = hesapla({
      personel_id: 1,
      tarih: "2026-04-13",
      giris_saati: "08:00",
      cikis_saati: "17:00"
    });

    const puantaj = hesapSonucuToGunlukPuantaj(sonuc);
    expect(puantaj.state).toBe("HESAPLANDI");
    expect(puantaj.personel_id).toBe(1);
    expect(puantaj.net_calisma_suresi_dakika).toBe(480);
    expect(puantaj.kontrol_durumu).toBe("BEKLIYOR");
    expect(puantaj.compliance_uyarilari).toBeDefined();
  });

  it("hesapSonucuToGunlukPuantaj: özel state parametresi", () => {
    const sonuc = hesapla({ personel_id: 1, tarih: "2026-04-13" });
    const puantaj = hesapSonucuToGunlukPuantaj(sonuc, "ACIK");
    expect(puantaj.state).toBe("ACIK");
    expect(puantaj.kontrol_durumu).toBe("BEKLIYOR");
  });

  it("hesapSonucuToGunlukPuantaj: onceki kontrol durumunu koruyabilir", () => {
    const sonuc = hesapla({
      personel_id: 1,
      tarih: "2026-04-13",
      giris_saati: "08:00",
      cikis_saati: "17:00"
    });
    const puantaj = hesapSonucuToGunlukPuantaj(sonuc, "HESAPLANDI", {
      kontrol_durumu: "AMIR_KONTROL_ETTI"
    });
    expect(puantaj.kontrol_durumu).toBe("AMIR_KONTROL_ETTI");
  });
});

// =========================================================================
// UBGT + haftalık fazla mesai çakışması (Faz A)
// =========================================================================

describe("birlestirUbgtFazlaMesaiCakismaUyari", () => {
  const ref = "2026-04-15";
  const maas = 30000;

  const normalHaftaGunleri = (): GunlukPuantaj[] => [
    gunlukSatir(1, "2026-04-13", 480),
    gunlukSatir(1, "2026-04-14", 480),
    gunlukSatir(1, "2026-04-15", 480),
    gunlukSatir(1, "2026-04-16", 480),
    gunlukSatir(1, "2026-04-17", 480)
  ];

  const ubgtMesaiGunu = (net = 480): GunlukPuantaj => ({
    ...gunlukSatir(1, "2026-04-18", net),
    gun_tipi: "UBGT_Resmi_Tatil",
    hesap_etkisi: "Mesai_Yaz",
    giris_saati: "08:00",
    cikis_saati: "16:00"
  });

  it("pozitif: tam hafta, FM > 0 ve UBGT mesai günü → UBGT_FAZLA_MESAI_CAKISMASI", () => {
    const tamHafta = [...normalHaftaGunleri(), ubgtMesaiGunu(), gunlukSatir(1, "2026-04-19", 0)];
    const ubgt = ubgtMesaiGunu();
    const merged = birlestirUbgtFazlaMesaiCakismaUyari([], ubgt, tamHafta, true);

    expect(merged).toContainEqual({
      code: UBGT_FAZLA_MESAI_CAKISMASI_CODE,
      message: UBGT_FAZLA_MESAI_CAKISMASI_MESSAGE,
      level: "UYARI"
    });

    const haftalik = hesaplaHaftalikPuantajUcretOzeti(tamHafta, ref, 45000);
    // Engine V2: yalnız Normal_Is_Gunu; 5×480=2400 → FM yok (UBGT havuza girmez)
    expect(haftalik.fazla_calisma_dakika).toBe(0);
    expect(haftalik.fazla_surelerle_calisma_dakika).toBe(0);

    const tatil = hesaplaTatilEkOdemeOzeti(maas, ubgt);
    expect(tatil!.ek_odeme_tutari).toBe(1000);
  });

  it("negatif A: UBGT mesai var ama haftalık toplam <= 45 saat → uyarı yok", () => {
    const tamHafta = [
      gunlukSatir(1, "2026-04-13", 385),
      gunlukSatir(1, "2026-04-14", 385),
      gunlukSatir(1, "2026-04-15", 385),
      gunlukSatir(1, "2026-04-16", 385),
      gunlukSatir(1, "2026-04-17", 385),
      ubgtMesaiGunu(385),
      gunlukSatir(1, "2026-04-19", 385)
    ];
    const ubgt = ubgtMesaiGunu(385);
    expect(hesaplaHaftalikPuantajUcretOzeti(tamHafta, ref, 45000).fazla_calisma_dakika).toBe(0);

    const merged = birlestirUbgtFazlaMesaiCakismaUyari([], ubgt, tamHafta, true);
    expect(merged).toHaveLength(0);
  });

  it("negatif B: haftalık FM > 0 ama UBGT mesai yok → uyarı yok", () => {
    const tamHafta = [gunlukSatir(1, "2026-04-14", 2760), gunlukSatir(1, "2026-04-15", 0)];
    const normal = gunlukSatir(1, "2026-04-14", 2760);
    const merged = birlestirUbgtFazlaMesaiCakismaUyari([], normal, tamHafta, true);
    expect(merged).toHaveLength(0);
  });

  it("negatif C: UBGT günü Mesai_Yaz değil veya net 0 → uyarı yok", () => {
    const tamHafta = [...normalHaftaGunleri(), ubgtMesaiGunu(), gunlukSatir(1, "2026-04-19", 0)];
    const ubgtTamYevmiye: GunlukPuantaj = {
      ...gunlukSatir(1, "2026-04-18", 480),
      gun_tipi: "UBGT_Resmi_Tatil",
      hesap_etkisi: "Tam_Yevmiye_Ver"
    };
    const ubgtNetSifir: GunlukPuantaj = {
      ...gunlukSatir(1, "2026-04-18", 0),
      gun_tipi: "UBGT_Resmi_Tatil",
      hesap_etkisi: "Mesai_Yaz"
    };

    expect(birlestirUbgtFazlaMesaiCakismaUyari([], ubgtTamYevmiye, tamHafta, true)).toHaveLength(0);
    expect(birlestirUbgtFazlaMesaiCakismaUyari([], ubgtNetSifir, tamHafta, true)).toHaveLength(0);
  });

  it("eksik hafta verisi (tamHaftaVerisi false) → uyarı yok", () => {
    const tamHafta = [...normalHaftaGunleri(), ubgtMesaiGunu()];
    const ubgt = ubgtMesaiGunu();
    const merged = birlestirUbgtFazlaMesaiCakismaUyari([], ubgt, tamHafta, false);
    expect(merged).toHaveLength(0);
  });

  it("duplicate uyarı kodu eklenmez", () => {
    const tamHafta = [...normalHaftaGunleri(), ubgtMesaiGunu(), gunlukSatir(1, "2026-04-19", 0)];
    const ubgt = ubgtMesaiGunu();
    const mevcut = [
      {
        code: UBGT_FAZLA_MESAI_CAKISMASI_CODE,
        message: UBGT_FAZLA_MESAI_CAKISMASI_MESSAGE,
        level: "UYARI" as const
      }
    ];
    const merged = birlestirUbgtFazlaMesaiCakismaUyari(mevcut, ubgt, tamHafta, true);
    expect(merged).toHaveLength(1);
  });
});

// =========================================================================
// 18 yaş altı haftalık fazla çalışma (Faz D2)
// =========================================================================

describe("isFazlaCalismaGeceYasagiKapsaminda", () => {
  it("17 yaşta true, tam 18 yaş sınırında false", () => {
    expect(isFazlaCalismaGeceYasagiKapsaminda("2009-04-13", "2026-04-13")).toBe(true);
    expect(isFazlaCalismaGeceYasagiKapsaminda("2008-04-13", "2026-04-13")).toBe(false);
  });

  it("yetişkin false", () => {
    expect(isFazlaCalismaGeceYasagiKapsaminda("1990-01-01", "2026-04-13")).toBe(false);
  });

  it("geçersiz doğum tarihi false", () => {
    expect(isFazlaCalismaGeceYasagiKapsaminda("invalid", "2026-04-13")).toBe(false);
  });
});

describe("birlestirOnsekizYasAltiFazlaCalismaUyari", () => {
  const referans = "2026-04-15";
  const gencDogum = "2009-01-01";
  const tamOnsekizDogum = "2008-04-15";
  const yetiskinDogum = "1990-01-01";

  const girdi = (
    dogum_tarihi: string | undefined,
    fazla_calisma_dakika: number,
    tam_hafta_verisi = true
  ) => ({
    dogum_tarihi,
    referans_tarih: referans,
    fazla_calisma_dakika,
    tam_hafta_verisi
  });

  it("yetişkin + fazla_calisma_dakika > 0 → uyarı yok", () => {
    const merged = birlestirOnsekizYasAltiFazlaCalismaUyari([], girdi(yetiskinDogum, 60));
    expect(merged).toHaveLength(0);
  });

  it("tam 18 yaş + fazla_calisma_dakika > 0 → yaş yasağı uyarısı yok", () => {
    const merged = birlestirOnsekizYasAltiFazlaCalismaUyari([], girdi(tamOnsekizDogum, 60));
    expect(merged).toHaveLength(0);
  });

  it("18 yaş altı + fazla_calisma_dakika > 0 → ONSEKIZ_YAS_ALTI_FAZLA_CALISMA", () => {
    const merged = birlestirOnsekizYasAltiFazlaCalismaUyari([], girdi(gencDogum, 60));
    expect(merged).toContainEqual({
      code: ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_CODE,
      message: ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_MESSAGE,
      level: "UYARI"
    });
  });

  it("18 yaş altı + fazla_calisma_dakika = 0 → uyarı yok", () => {
    const merged = birlestirOnsekizYasAltiFazlaCalismaUyari([], girdi(gencDogum, 0));
    expect(merged).toHaveLength(0);
  });

  it("dogum_tarihi eksik → uyarı yok", () => {
    const merged = birlestirOnsekizYasAltiFazlaCalismaUyari([], girdi(undefined, 60));
    expect(merged).toHaveLength(0);
  });

  it("geçersiz dogum_tarihi → uyarı yok", () => {
    const merged = birlestirOnsekizYasAltiFazlaCalismaUyari([], girdi("invalid", 60));
    expect(merged).toHaveLength(0);
  });

  it("tam hafta verisi yok → uyarı yok", () => {
    const merged = birlestirOnsekizYasAltiFazlaCalismaUyari(
      [],
      girdi(gencDogum, 60, false)
    );
    expect(merged).toHaveLength(0);
  });

  it("mevcut listede aynı kod varsa duplicate yok", () => {
    const mevcut = [
      {
        code: ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_CODE,
        message: ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_MESSAGE,
        level: "UYARI" as const
      }
    ];
    const merged = birlestirOnsekizYasAltiFazlaCalismaUyari(mevcut, girdi(gencDogum, 60));
    expect(merged).toHaveLength(1);
  });

  it("mevcut başka compliance uyarıları korunur", () => {
    const mevcut = [
      {
        code: GECE_CALISMASI_7_5_SAAT_ASIMI_CODE,
        message: "Gece çalışma süresi günlük 7,5 saat sınırını aşıyor.",
        level: "UYARI" as const
      }
    ];
    const merged = birlestirOnsekizYasAltiFazlaCalismaUyari(mevcut, girdi(gencDogum, 60));
    expect(merged).toHaveLength(2);
    expect(merged[0].code).toBe(GECE_CALISMASI_7_5_SAAT_ASIMI_CODE);
    expect(merged[1].code).toBe(ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_CODE);
  });
});

// =========================================================================
// Mazeretsiz devamsızlık / hafta tatili kaybı aday (Faz B)
// =========================================================================

describe("birlestirMazeretsizDevamsizlikAdayUyariari", () => {
  const mazeretsizKayit = (): GunlukPuantaj => ({
    personel_id: 1,
    tarih: "2026-04-14",
    hareket_durumu: "Gelmedi",
    dayanak: "Yok_Izinsiz",
    hafta_tatili_hak_kazandi_mi: false,
    compliance_uyarilari: []
  });

  it("Gelmedi + Yok_Izinsiz → DEVAMSIZLIK_UCRET_ETKISI_ADAYI", () => {
    const merged = birlestirMazeretsizDevamsizlikAdayUyariari([], mazeretsizKayit());
    expect(merged).toContainEqual({
      code: DEVAMSIZLIK_UCRET_ETKISI_ADAYI_CODE,
      message: DEVAMSIZLIK_UCRET_ETKISI_ADAYI_MESSAGE,
      level: "UYARI"
    });
  });

  it("hafta_tatili_hak_kazandi_mi false → HAFTA_TATILI_HAK_KAYBI_ADAYI", () => {
    const merged = birlestirMazeretsizDevamsizlikAdayUyariari([], mazeretsizKayit());
    expect(merged).toContainEqual({
      code: HAFTA_TATILI_HAK_KAYBI_ADAYI_CODE,
      message: HAFTA_TATILI_HAK_KAYBI_ADAYI_MESSAGE,
      level: "UYARI"
    });
    expect(merged).toHaveLength(2);
  });

  it("hafta tatili hakkı korunmuşsa yalnız devamsızlık aday uyarısı", () => {
    const kayit: GunlukPuantaj = {
      ...mazeretsizKayit(),
      hafta_tatili_hak_kazandi_mi: true
    };
    const merged = birlestirMazeretsizDevamsizlikAdayUyariari([], kayit);
    expect(merged).toHaveLength(1);
    expect(merged[0].code).toBe(DEVAMSIZLIK_UCRET_ETKISI_ADAYI_CODE);
  });

  it("Gelmedi + Yok_Izinsiz değilse uyarı üretmez", () => {
    const kayit: GunlukPuantaj = {
      personel_id: 1,
      tarih: "2026-04-14",
      hareket_durumu: "Geldi",
      compliance_uyarilari: []
    };
    expect(birlestirMazeretsizDevamsizlikAdayUyariari([], kayit)).toHaveLength(0);
  });

  it("duplicate uyarı kodu eklenmez", () => {
    const mevcut = [
      {
        code: DEVAMSIZLIK_UCRET_ETKISI_ADAYI_CODE,
        message: DEVAMSIZLIK_UCRET_ETKISI_ADAYI_MESSAGE,
        level: "UYARI" as const
      },
      {
        code: HAFTA_TATILI_HAK_KAYBI_ADAYI_CODE,
        message: HAFTA_TATILI_HAK_KAYBI_ADAYI_MESSAGE,
        level: "UYARI" as const
      }
    ];
    const merged = birlestirMazeretsizDevamsizlikAdayUyariari(mevcut, mazeretsizKayit());
    expect(merged).toHaveLength(2);
  });
});

describe("Faz B — devamsızlık matematiği ve parasal net kilidi", () => {
  const maas = 30000;

  it("hesaplaDevamsizlikKesintiOzeti 1+1 gün eşdeğeri matematiği değişmez", () => {
    const o = hesaplaDevamsizlikKesintiOzeti(maas, {
      devamsizlik_gun_sayisi: 1,
      hafta_tatili_kaybi_gun_sayisi: 1
    });
    expect(o.toplam_kesinti_gun_esdegeri).toBe(2);
    expect(o.toplam_kesinti_tutari).toBe(2000);
  });

  it("mazeretsiz devamsızlık parasal net kilitli", () => {
    expect(
      mazeretsizDevamsizlikParasalNetKilitliMi({
        hareket_durumu: "Gelmedi",
        dayanak: "Yok_Izinsiz"
      })
    ).toBe(true);
    expect(
      mazeretsizDevamsizlikParasalNetKilitliMi({
        hareket_durumu: "Geldi",
        dayanak: "Yok_Izinsiz"
      })
    ).toBe(false);
  });

  it("parasal netHam mazeretsiz adayda devamsızlık kesintisini düşmez", () => {
    const kesintiToplam = 2000;
    const netHam =
      300 +
      1000 -
      parasalNetEtkidenDusulecekKesintiTutari(kesintiToplam, true);
    expect(netHam).toBe(1300);
    const netKesin =
      300 +
      1000 -
      parasalNetEtkidenDusulecekKesintiTutari(kesintiToplam, false);
    expect(netKesin).toBe(-700);
  });
});
