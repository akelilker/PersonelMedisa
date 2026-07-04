import { describe, expect, it } from "vitest";
import {
  buildRaporlarPrefillUrl,
  donemToAyTarihAraligi,
  parseRaporlarQueryPrefill
} from "../../src/features/raporlar/rapor-query-prefill";

describe("rapor-query-prefill", () => {
  it("derives month date range from donem", () => {
    expect(donemToAyTarihAraligi("2026-04")).toEqual({
      baslangic: "2026-04-01",
      bitis: "2026-04-30"
    });
    expect(donemToAyTarihAraligi("2026-02")).toEqual({
      baslangic: "2026-02-01",
      bitis: "2026-02-28"
    });
  });

  it("returns undefined for invalid donem", () => {
    expect(donemToAyTarihAraligi("2026-13")).toBeUndefined();
    expect(donemToAyTarihAraligi("abc")).toBeUndefined();
  });

  it("builds raporlar prefill url with optional filters", () => {
    expect(
      buildRaporlarPrefillUrl({
        rapor: "personel-ozet",
        baslangic: "2026-04-01",
        bitis: "2026-04-30",
        donem: "2026-04",
        muhur_id: 123,
        personel_id: 42,
        sube_id: 1
      })
    ).toBe(
      "/raporlar?rapor=personel-ozet&baslangic=2026-04-01&bitis=2026-04-30&donem=2026-04&muhur_id=123&personel_id=42&sube_id=1"
    );
  });

  it("omits invalid donem and non-positive optional ids from url", () => {
    expect(
      buildRaporlarPrefillUrl({
        rapor: "personel-ozet",
        baslangic: "2026-04-01",
        bitis: "2026-04-30",
        donem: "2026-ab",
        muhur_id: 0,
        personel_id: 0,
        sube_id: 0
      })
    ).toBe("/raporlar?rapor=personel-ozet&baslangic=2026-04-01&bitis=2026-04-30");
  });

  it("parses personel_id from query and ignores invalid values", () => {
    expect(
      parseRaporlarQueryPrefill(
        new URLSearchParams(
          "rapor=personel-ozet&baslangic=2026-04-01&bitis=2026-04-30&personel_id=42"
        )
      ).personelId
    ).toBe(42);

    expect(
      parseRaporlarQueryPrefill(
        new URLSearchParams("rapor=personel-ozet&baslangic=2026-04-01&bitis=2026-04-30&personel_id=0")
      ).personelId
    ).toBeUndefined();

    expect(
      parseRaporlarQueryPrefill(
        new URLSearchParams("rapor=personel-ozet&baslangic=2026-04-01&bitis=2026-04-30&personel_id=abc")
      ).personelId
    ).toBeUndefined();
  });

  it("parses sube_id from query and ignores invalid values", () => {
    expect(
      parseRaporlarQueryPrefill(
        new URLSearchParams(
          "rapor=personel-ozet&baslangic=2026-04-01&bitis=2026-04-30&sube_id=1"
        )
      ).extraFilters.sube_id
    ).toBe(1);

    expect(
      parseRaporlarQueryPrefill(
        new URLSearchParams("rapor=personel-ozet&baslangic=2026-04-01&bitis=2026-04-30&sube_id=0")
      ).extraFilters.sube_id
    ).toBeUndefined();

    expect(
      parseRaporlarQueryPrefill(
        new URLSearchParams("rapor=personel-ozet&baslangic=2026-04-01&bitis=2026-04-30&sube_id=abc")
      ).extraFilters.sube_id
    ).toBeUndefined();
  });

  it("round-trips builder output through parser", () => {
    const url = buildRaporlarPrefillUrl({
      rapor: "personel-ozet",
      baslangic: "2026-04-01",
      bitis: "2026-04-30",
      donem: "2026-04",
      muhur_id: 123,
      personel_id: 42,
      sube_id: 1
    });
    const searchParams = new URLSearchParams(url.split("?")[1] ?? "");
    const parsed = parseRaporlarQueryPrefill(searchParams);

    expect(parsed.raporTipi).toBe("personel-ozet");
    expect(parsed.baslangicTarihi).toBe("2026-04-01");
    expect(parsed.bitisTarihi).toBe("2026-04-30");
    expect(parsed.personelId).toBe(42);
    expect(parsed.extraFilters).toEqual({ donem: "2026-04", muhur_id: 123, sube_id: 1 });
    expect(parsed.shouldAutoRun).toBe(true);
  });
});
