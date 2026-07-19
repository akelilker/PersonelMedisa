import { describe, expect, it } from "vitest";
import {
  computeHaftaBitisFromMonday,
  getCurrentMondayIsoDate,
  isHaftalikMutabakatApproveEnabled,
  isMondayIsoDate,
  resolveHaftalikMutabakatApproval,
  resolveHaftalikMutabakatStatusMessage
} from "../../src/lib/bildirim/haftalik-mutabakat";
import type {
  HaftalikBildirimMutabakatCounts,
  HaftalikBildirimMutabakatOzet
} from "../../src/types/haftalik-bildirim-mutabakat";

const counts = (extra: Partial<HaftalikBildirimMutabakatCounts> = {}): HaftalikBildirimMutabakatCounts => ({
  toplam: 1, taslak: 0, gonderildi: 1, duzeltme_istendi: 0,
  haftalik_mutabakata_alindi: 0, iptal: 0, ...extra
});

describe("haftalik bildirim mutabakati", () => {
  it("yalnizca gecerli Pazartesi ISO tarihini kabul eder", () => {
    expect(isMondayIsoDate("2026-04-13")).toBe(true);
    expect(isMondayIsoDate("2026-04-14")).toBe(false);
    expect(isMondayIsoDate("2026-02-30")).toBe(false);
  });

  it("Pazartesi haftasi icin bitis tarihini hesaplar", () => {
    expect(computeHaftaBitisFromMonday("2026-04-06")).toBe("2026-04-12");
    expect(computeHaftaBitisFromMonday("2026-04-07")).toBeNull();
  });

  it("varsayilan hafta baslangicini icinde bulunulan haftanin Pazartesi gunu olarak verir", () => {
    expect(getCurrentMondayIsoDate(new Date("2026-04-09T12:00:00"))).toBe("2026-04-06");
    expect(getCurrentMondayIsoDate(new Date("2026-04-12T12:00:00"))).toBe("2026-04-06");
  });

  it("onaylanabilir ozette approve butonunu acar", () => {
    const ozet = {
      onaylanabilir_mi: true,
      blok_nedeni: null,
      mevcut_mutabakat_id: null
    } as HaftalikBildirimMutabakatOzet;

    expect(isHaftalikMutabakatApproveEnabled(true, ozet)).toBe(true);
    expect(isHaftalikMutabakatApproveEnabled(false, ozet)).toBe(false);
  });

  it("blok nedeni veya mevcut mutabakatta approve butonunu kapatir", () => {
    const blocked = {
      onaylanabilir_mi: false,
      blok_nedeni: "Haftada taslak bildirim bulunuyor.",
      mevcut_mutabakat_id: null
    } as HaftalikBildirimMutabakatOzet;

    expect(isHaftalikMutabakatApproveEnabled(true, blocked)).toBe(false);
    expect(resolveHaftalikMutabakatStatusMessage(blocked)).toBe(
      "Haftada taslak bildirim bulunuyor."
    );
    expect(
      resolveHaftalikMutabakatStatusMessage({
        ...blocked,
        onaylanabilir_mi: false,
        blok_nedeni: null,
        mevcut_mutabakat_id: 12
      } as HaftalikBildirimMutabakatOzet)
    ).toBe("Bu hafta mutabakata alinmis.");
  });

  it("gonderilmis kayit varsa onaya izin verir", () => {
    expect(resolveHaftalikMutabakatApproval(counts(), null).onaylanabilir_mi).toBe(true);
  });

  it("eksik_gun varsayilan 0 iken onaylanabilirligi bozmaz", () => {
    expect(resolveHaftalikMutabakatApproval(counts({ eksik_gun: 0 }), null).onaylanabilir_mi).toBe(true);
  });

  it("eksik_gun > 0 ise onayi bloklar", () => {
    const result = resolveHaftalikMutabakatApproval(counts({ eksik_gun: 2 }), null);
    expect(result.onaylanabilir_mi).toBe(false);
    expect(result.blok_nedeni).toBe("Bu hafta için tamamlanmamış bildirimler var.");
  });

  it.each([
    [counts({ taslak: 1 }), null],
    [counts({ duzeltme_istendi: 1 }), null],
    [counts(), 42],
    [counts({ toplam: 1, gonderildi: 0, iptal: 1 }), null]
  ])("bloklayici durumda onaya izin vermez", (input, existingId) => {
    expect(resolveHaftalikMutabakatApproval(input, existingId).onaylanabilir_mi).toBe(false);
  });
});
