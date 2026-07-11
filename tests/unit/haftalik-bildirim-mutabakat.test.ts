import { describe, expect, it } from "vitest";
import { isMondayIsoDate, resolveHaftalikMutabakatApproval } from "../../src/lib/bildirim/haftalik-mutabakat";
import type { HaftalikBildirimMutabakatCounts } from "../../src/types/haftalik-bildirim-mutabakat";

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

  it("gonderilmis kayit varsa onaya izin verir", () => {
    expect(resolveHaftalikMutabakatApproval(counts(), null).onaylanabilir_mi).toBe(true);
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
