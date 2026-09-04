import { describe, expect, it } from "vitest";
import {
  formatHeaderBildirimCopy,
  formatHeaderReminderCopy,
  summarizeDigerAciklama
} from "../../src/lib/bildirim/header-notification-copy";
import { formatBildirimTuruLabel } from "../../src/lib/display/enum-display";
import type { Bildirim } from "../../src/types/bildirim";

function baseItem(overrides: Partial<Bildirim> = {}): Pick<
  Bildirim,
  | "bildirim_turu"
  | "personel_ad_soyad"
  | "tarih"
  | "aciklama"
  | "dakika"
  | "baslangic_saati"
  | "bitis_saati"
  | "sube_adi"
  | "personel_id"
> {
  return {
    bildirim_turu: "GELMEDI",
    personel_ad_soyad: "Ahmet Yılmaz",
    tarih: "2026-07-15",
    aciklama: null,
    dakika: null,
    baslangic_saati: null,
    bitis_saati: null,
    sube_adi: null,
    personel_id: 1,
    ...overrides
  };
}

function assertNoTechnicalLeak(copy: { title: string; subtitle: string }) {
  const haystack = `${copy.title}\n${copy.subtitle}`;
  expect(haystack).not.toMatch(/Personel:\s*\d/);
  expect(haystack).not.toMatch(/Personel\s*#\d/);
  expect(haystack).not.toMatch(/Tarih:\s*\d{4}-/);
  expect(haystack).not.toContain("personel_id");
}

describe("formatHeaderBildirimCopy", () => {
  it("formats GELMEDI with TR date subtitle", () => {
    const copy = formatHeaderBildirimCopy(baseItem({ bildirim_turu: "GELMEDI" }));
    expect(copy.title).toBe("Ahmet Yılmaz gelmedi.");
    expect(copy.subtitle).toBe("15.07.2026");
    assertNoTechnicalLeak(copy);
  });

  it("formats GEC_GELDI with dakika when present", () => {
    const copy = formatHeaderBildirimCopy(
      baseItem({
        bildirim_turu: "GEC_GELDI",
        dakika: 25,
        baslangic_saati: "08:00",
        bitis_saati: "08:25"
      })
    );
    expect(copy.title).toBe("Ahmet Yılmaz 25 dakika geç geldi.");
    expect(copy.subtitle).toBe("15.07.2026");
    assertNoTechnicalLeak(copy);
  });

  it("formats GEC_GELDI without dakika", () => {
    const copy = formatHeaderBildirimCopy(baseItem({ bildirim_turu: "GEC_GELDI", dakika: null }));
    expect(copy.title).toBe("Ahmet Yılmaz geç geldi.");
    expect(copy.subtitle).toBe("15.07.2026");
  });

  it("formats ERKEN_CIKTI with dakika", () => {
    const copy = formatHeaderBildirimCopy(
      baseItem({
        bildirim_turu: "ERKEN_CIKTI",
        dakika: 15,
        baslangic_saati: "17:00",
        bitis_saati: "16:45"
      })
    );
    expect(copy.title).toBe("Ahmet Yılmaz 15 dakika erken çıktı.");
    expect(copy.subtitle).toBe("15.07.2026");
  });

  it("formats IZINLI / RAPORLU / GOREVDE", () => {
    expect(formatHeaderBildirimCopy(baseItem({ bildirim_turu: "IZINLI" })).title).toBe(
      "Ahmet Yılmaz izinli."
    );
    expect(formatHeaderBildirimCopy(baseItem({ bildirim_turu: "RAPORLU" })).title).toBe(
      "Ahmet Yılmaz raporlu."
    );
    expect(formatHeaderBildirimCopy(baseItem({ bildirim_turu: "GOREVDE" })).title).toBe(
      "Ahmet Yılmaz görevde çalıştı."
    );
  });

  it("formats DIGER with short aciklama summary in title", () => {
    const copy = formatHeaderBildirimCopy(
      baseItem({
        bildirim_turu: "DIGER",
        aciklama: "Servis nedeniyle geç giriş yaptı."
      })
    );
    expect(copy.title).toBe("Ahmet Yılmaz — Servis nedeniyle geç giriş yaptı.");
    expect(copy.subtitle).toBe("15.07.2026");
    assertNoTechnicalLeak(copy);
  });

  it("formats DIGER long paragraph via result sentence, not full dump", () => {
    const long =
      "Personel ile sabah görüşmesi yapılmış, eksik evraklar kontrol edilmiş ve ilgili birim bilgilendirilmiştir. " +
      "Ayrıca vardiya notları gözden geçirilmiştir. Testi Yapılmıştır.";
    const copy = formatHeaderBildirimCopy(
      baseItem({
        bildirim_turu: "DIGER",
        aciklama: long
      })
    );
    expect(copy.title).toBe("Ahmet Yılmaz — Testi Yapılmıştır.");
    expect(copy.title).not.toContain("Personel ile sabah");
    expect(copy.title.length).toBeLessThan(long.length);
    expect(copy.subtitle).toBe("15.07.2026");
    assertNoTechnicalLeak(copy);
  });

  it("formats DIGER legacy without aciklama", () => {
    const copy = formatHeaderBildirimCopy(baseItem({ bildirim_turu: "DIGER", aciklama: "  " }));
    expect(copy.title).toBe("Ahmet Yılmaz için özel bildirim kaydı.");
  });

  it("never leaks personel id when name is missing", () => {
    const copy = formatHeaderBildirimCopy(
      baseItem({
        personel_ad_soyad: null,
        personel_id: 1,
        bildirim_turu: "GOREVDE"
      })
    );
    expect(copy.title).toBe("Personel bildirimi görevde çalıştı.");
    expect(copy.subtitle).toBe("15.07.2026");
    expect(copy.title).not.toContain("1");
    expect(copy.subtitle).not.toContain("Personel:");
    assertNoTechnicalLeak(copy);
  });

  it("uses lookup name only as secondary fallback", () => {
    const copy = formatHeaderBildirimCopy(
      baseItem({ personel_ad_soyad: null, bildirim_turu: "GELMEDI" }),
      { lookupPersonelName: "Ayşe Demir" }
    );
    expect(copy.title).toBe("Ayşe Demir gelmedi.");
  });

  it("formats unknown historical without collapsing to Diğer", () => {
    const copy = formatHeaderBildirimCopy(
      baseItem({
        bildirim_turu: "IZINSIZ_GELMEDI",
        personel_ad_soyad: "Ahmet Yılmaz"
      })
    );
    expect(copy.title).toBe("Ahmet Yılmaz · İzinsiz Gelmedi");
    expect(copy.title).not.toContain("Diğer");
    assertNoTechnicalLeak(copy);
  });

  it("formats unknown without personel name", () => {
    const copy = formatHeaderBildirimCopy(
      baseItem({
        bildirim_turu: "UYARI",
        personel_ad_soyad: null
      })
    );
    expect(copy.title).toBe("Günlük bildirim · Uyarı");
    assertNoTechnicalLeak(copy);
  });
});

describe("summarizeDigerAciklama", () => {
  it("prefers conclusive result sentence", () => {
    expect(
      summarizeDigerAciklama(
        "Uzun giriş notu burada yer alır. İkinci cümle de uzundur. Testi Yapılmıştır."
      )
    ).toBe("Testi Yapılmıştır.");
  });

  it("picks shortest meaningful sentence among several", () => {
    expect(
      summarizeDigerAciklama(
        "Bu sabah şube içinde uzun bir süreç işletildi ve birden fazla kontrol yapıldı. Raporlu."
      )
    ).toBe("Raporlu.");
  });

  it("reduces single long sentence without mid-word chop when possible", () => {
    const long =
      "Personelin gün içindeki tüm görev dağılımı yeniden planlanarak sabah brifinginde aktarıldı ve ilgili amir bilgilendirildi";
    const summary = summarizeDigerAciklama(long);
    expect(summary.length).toBeLessThanOrEqual(100);
    expect(summary.endsWith(".") || summary.endsWith("…")).toBe(true);
    if (summary.endsWith("…")) {
      const before = summary.slice(0, -1);
      expect(before).toMatch(/\S$/);
      expect(long.startsWith(before) || long.includes(before.replace(/\.$/, ""))).toBe(true);
    }
  });
});

describe("formatHeaderReminderCopy", () => {
  it("formats salary and sgk deadline copy", () => {
    expect(
      formatHeaderReminderCopy({ key: "salary", daysLeft: 1, dueDateLabel: "05.09.2026" })
    ).toEqual({
      title: "Maaş ödeme zamanı yaklaşıyor.",
      subtitle: "1 gün kaldı · 05.09.2026"
    });

    expect(
      formatHeaderReminderCopy({ key: "sgk", daysLeft: 0, dueDateLabel: "26.09.2026" })
    ).toEqual({
      title: "SGK prim ödeme takibini kontrol et.",
      subtitle: "Bugün son gün · 26.09.2026"
    });
  });
});

describe("ERKEN_CIKTI display label", () => {
  it("maps ERKEN_CIKTI to Erken Çıktı without changing persistence keys", () => {
    expect(formatBildirimTuruLabel("ERKEN_CIKTI")).toBe("Erken Çıktı");
  });
});
