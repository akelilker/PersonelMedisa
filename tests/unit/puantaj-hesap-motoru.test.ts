import { describe, expect, it } from "vitest";
import {
  deriveGunTipi,
  deriveHareketDurumu,
  deriveDayanak,
  deriveHesapEtkisi,
  hesaplaBrutSure,
  hesaplaYasalMolaDakika,
  hesaplaNetSure,
  hesaplaHaftaTatiliHakki,
  uretComplianceUyarilari,
  hesapla,
  gunlukPuantajToGirdi,
  hesapSonucuToGunlukPuantaj,
  type HesapGirdisi
} from "../../src/services/puantaj-hesap-motoru";
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
  it("4 saat ve altı → mola yok", () => {
    expect(hesaplaYasalMolaDakika(240)).toBe(0);
    expect(hesaplaYasalMolaDakika(120)).toBe(0);
    expect(hesaplaYasalMolaDakika(0)).toBe(0);
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
  it("Gelmedi + Yok_Izinsiz → Kesinti_Yap", () => {
    expect(deriveHesapEtkisi("Normal_Is_Gunu", "Gelmedi", "Yok_Izinsiz")).toBe("Kesinti_Yap");
  });

  it("Pazar + giriş var → Mesai_Yaz", () => {
    expect(deriveHesapEtkisi("Hafta_Tatili_Pazar", "Geldi", undefined, "08:00", "17:00")).toBe("Mesai_Yaz");
  });

  it("UBGT + giriş var → Mesai_Yaz", () => {
    expect(deriveHesapEtkisi("UBGT_Resmi_Tatil", "Geldi", undefined, "08:00")).toBe("Mesai_Yaz");
  });

  it("Raporlu hastalık → Tam_Yevmiye_Ver", () => {
    expect(deriveHesapEtkisi("Normal_Is_Gunu", "Gelmedi", "Raporlu_Hastalik")).toBe("Tam_Yevmiye_Ver");
  });

  it("İş kazası → Tam_Yevmiye_Ver", () => {
    expect(deriveHesapEtkisi("Normal_Is_Gunu", "Gelmedi", "Raporlu_Is_Kazasi")).toBe("Tam_Yevmiye_Ver");
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
});

// =========================================================================
// 8. Hafta tatili hakkı (İş Kanunu md.46) – KRİTİK
// =========================================================================

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

  it("kısa mesai: 09:00-13:00 → mola 0", () => {
    const sonuc = hesapla({
      personel_id: 2,
      tarih: "2026-04-14",
      giris_saati: "09:00",
      cikis_saati: "13:00"
    });

    expect(sonuc.gunluk_brut_sure_dakika).toBe(240);
    expect(sonuc.hesaplanan_mola_dakika).toBe(0);
    expect(sonuc.net_calisma_suresi_dakika).toBe(240);
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

    expect(sonuc.hesap_etkisi).toBe("Kesinti_Yap");
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(false);
    expect(sonuc.gunluk_brut_sure_dakika).toBe(0);
    expect(sonuc.net_calisma_suresi_dakika).toBe(0);
  });

  it("hastalık raporu: gelmedi ama raporlu → tam yevmiye + hafta tatili korunur", () => {
    const sonuc = hesapla({
      personel_id: 5,
      tarih: "2026-04-16",
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Hastalik"
    });

    expect(sonuc.hesap_etkisi).toBe("Tam_Yevmiye_Ver");
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(true);
  });

  it("iş kazası: gelmedi ama raporlu → tam yevmiye + hafta tatili korunur", () => {
    const sonuc = hesapla({
      personel_id: 6,
      tarih: "2026-04-16",
      hareket_durumu: "Gelmedi",
      dayanak: "Raporlu_Is_Kazasi"
    });

    expect(sonuc.hesap_etkisi).toBe("Tam_Yevmiye_Ver");
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(true);
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
    expect(sonuc.hesap_etkisi).toBe("Kesinti_Yap");
    expect(sonuc.hafta_tatili_hak_kazandi_mi).toBe(false);
  });
});

// =========================================================================
// 11. Adaptör fonksiyonları
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
    expect(puantaj.compliance_uyarilari).toBeDefined();
  });

  it("hesapSonucuToGunlukPuantaj: özel state parametresi", () => {
    const sonuc = hesapla({ personel_id: 1, tarih: "2026-04-13" });
    const puantaj = hesapSonucuToGunlukPuantaj(sonuc, "ACIK");
    expect(puantaj.state).toBe("ACIK");
  });
});
