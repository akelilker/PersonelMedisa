import { describe, expect, it } from "vitest";
import {
  formatHeaderGunlukTamamlamaCopy,
  formatHeaderReminderCopy
} from "../../src/lib/bildirim/header-notification-copy";
import { formatGunlukTamamlamaKayitLine } from "../../src/lib/bildirim/gunluk-tamamlama-detail-copy";
import type { GunlukTamamlamaKayit } from "../../src/types/bildirim";

describe("formatHeaderGunlukTamamlamaCopy", () => {
  it("builds actor completion title and date · CTA subtitle", () => {
    const copy = formatHeaderGunlukTamamlamaCopy({
      tamamlayan_ad_soyad: "İlker Akel",
      tarih: "2026-09-04"
    });
    expect(copy.title).toBe("İlker Akel Devamsızlık Bildirimini Tamamladı.");
    expect(copy.subtitle).toBe("04.09.2026 · Detayları Gör");
  });

  it("keeps Turkish characters and sentence case", () => {
    const copy = formatHeaderGunlukTamamlamaCopy({
      tamamlayan_ad_soyad: "Şükrü Öğüt",
      tarih: "2026-01-15"
    });
    expect(copy.title).toContain("Şükrü Öğüt");
    expect(copy.title).toContain("Devamsızlık Bildirimini Tamamladı.");
    expect(copy.title).not.toMatch(/GELMEDI|GEC_GELDI|TAMAMLANDI/);
  });
});

describe("formatHeaderReminderCopy unaffected", () => {
  it("keeps salary and SGK reminder copy owners", () => {
    expect(
      formatHeaderReminderCopy({ key: "salary", daysLeft: 3, dueDateLabel: "05.09.2026" }).title
    ).toBe("Maaş ödeme zamanı yaklaşıyor.");
    expect(
      formatHeaderReminderCopy({ key: "sgk", daysLeft: 1, dueDateLabel: "26.09.2026" }).title
    ).toBe("SGK prim ödeme takibini kontrol et.");
  });
});

function kayit(partial: Partial<GunlukTamamlamaKayit> & Pick<GunlukTamamlamaKayit, "bildirim_turu">): GunlukTamamlamaKayit {
  return {
    bildirim_id: 1,
    personel_id: 1,
    ad_soyad: "Ahmet Yılmaz",
    dakika: null,
    baslangic_saati: null,
    bitis_saati: null,
    aciklama: null,
    departman_adi: null,
    ...partial
  };
}

describe("formatGunlukTamamlamaKayitLine", () => {
  it("formats GEC_GELDI with minutes", () => {
    expect(formatGunlukTamamlamaKayitLine(kayit({ bildirim_turu: "GEC_GELDI", dakika: 25 }))).toBe(
      "Ahmet Yılmaz — 25 Dakika Geç Geldi"
    );
  });

  it("formats ERKEN_CIKTI with minutes", () => {
    expect(formatGunlukTamamlamaKayitLine(kayit({ bildirim_turu: "ERKEN_CIKTI", dakika: 15 }))).toBe(
      "Ahmet Yılmaz — 15 Dakika Erken Çıktı"
    );
  });

  it("formats GELMEDI as name only", () => {
    expect(formatGunlukTamamlamaKayitLine(kayit({ bildirim_turu: "GELMEDI" }))).toBe("Ahmet Yılmaz");
  });

  it("formats IZINLI and RAPORLU separately", () => {
    expect(formatGunlukTamamlamaKayitLine(kayit({ bildirim_turu: "IZINLI" }))).toBe(
      "Ahmet Yılmaz — İzinli"
    );
    expect(formatGunlukTamamlamaKayitLine(kayit({ bildirim_turu: "RAPORLU" }))).toBe(
      "Ahmet Yılmaz — Raporlu"
    );
  });

  it("formats GOREVDE", () => {
    expect(formatGunlukTamamlamaKayitLine(kayit({ bildirim_turu: "GOREVDE" }))).toBe(
      "Ahmet Yılmaz — Görevde"
    );
  });

  it("keeps full DIGER explanation on detail line", () => {
    const long =
      "Sabah şantiye ziyareti nedeniyle vardiya dışında kaldı ve amir onayı ile kayıt açıldı.";
    expect(
      formatGunlukTamamlamaKayitLine(kayit({ bildirim_turu: "DIGER", aciklama: long }))
    ).toBe(`Ahmet Yılmaz — ${long}`);
  });
});
