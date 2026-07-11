import { describe, expect, it } from "vitest";
import {
  isValidAyValue,
  listWeeksIntersectingMonth,
  resolveAylikBildirimOnayApproval,
  resolveAyBounds
} from "../../src/lib/bildirim/aylik-bildirim-onay";
import type { AylikBildirimOnayCounts } from "../../src/types/aylik-bildirim-onay";

const counts = (extra: Partial<AylikBildirimOnayCounts> = {}): AylikBildirimOnayCounts => ({
  toplam_bildirim: 1,
  mutabakata_alinan: 1,
  mutabakatli_hafta: 1,
  eksik_hafta: 0,
  taslak: 0,
  duzeltme_istendi: 0,
  gonderildi: 0,
  ...extra
});

describe("aylik bildirim onayi helper", () => {
  it("YYYY-MM formatini dogrular", () => {
    expect(isValidAyValue("2026-04")).toBe(true);
    expect(isValidAyValue("2026-13")).toBe(false);
    expect(isValidAyValue("2026-4")).toBe(false);
    expect(isValidAyValue("26-04")).toBe(false);
  });

  it("ay baslangic ve bitis tarihlerini hesaplar", () => {
    expect(resolveAyBounds("2026-04")).toEqual({
      ay_baslangic: "2026-04-01",
      ay_bitis: "2026-04-30"
    });
    expect(resolveAyBounds("2026-02")).toEqual({
      ay_baslangic: "2026-02-01",
      ay_bitis: "2026-02-28"
    });
    expect(resolveAyBounds("bad")).toBeNull();
  });

  it("ay ile kesisen haftalari listeler", () => {
    const weeks = listWeeksIntersectingMonth("2026-04-01", "2026-04-30");
    expect(weeks[0]).toEqual({ hafta_baslangic: "2026-03-30", hafta_bitis: "2026-04-05" });
    expect(weeks.at(-1)).toEqual({ hafta_baslangic: "2026-04-27", hafta_bitis: "2026-05-03" });
  });

  it("mutabakata alinmis kayit ve tam hafta ile onaya izin verir", () => {
    expect(
      resolveAylikBildirimOnayApproval({
        counts: counts(),
        mevcutOnayId: null,
        eksikHaftaSayisi: 0
      }).onaylanabilir_mi
    ).toBe(true);
  });

  it.each([
    [counts({ taslak: 1 }), null, 0, "Ayda taslak bildirim bulunuyor."],
    [counts({ duzeltme_istendi: 1 }), null, 0, "Ayda duzeltme bekleyen bildirim bulunuyor."],
    [counts({ gonderildi: 1, mutabakata_alinan: 0 }), null, 0, "Ayda haftalik mutabakata alinmamis gonderilmis bildirim bulunuyor."],
    [counts({ mutabakata_alinan: 0, toplam_bildirim: 0 }), null, 0, "Aylik onaya alinacak mutabakata alinmis bildirim bulunamadi."],
    [counts(), 9, 0, "Bu ay icin aylik bildirim onayi zaten mevcut."],
    [counts(), null, 1, "Ayda eksik haftalik mutabakat bulunuyor."]
  ])("bloklayici durumda onaya izin vermez", (inputCounts, existingId, eksikHafta, expected) => {
    const result = resolveAylikBildirimOnayApproval({
      counts: inputCounts,
      mevcutOnayId: existingId,
      eksikHaftaSayisi: eksikHafta
    });
    expect(result.onaylanabilir_mi).toBe(false);
    expect(result.blok_nedeni).toBe(expected);
  });

  it("yalnizca IPTAL kayitlari oldugunda onaya izin vermez", () => {
    expect(
      resolveAylikBildirimOnayApproval({
        counts: counts({ toplam_bildirim: 0, mutabakata_alinan: 0, mutabakatli_hafta: 0 }),
        mevcutOnayId: null,
        eksikHaftaSayisi: 0
      })
    ).toEqual({
      onaylanabilir_mi: false,
      blok_nedeni: "Aylik onaya alinacak mutabakata alinmis bildirim bulunamadi."
    });
  });
});
