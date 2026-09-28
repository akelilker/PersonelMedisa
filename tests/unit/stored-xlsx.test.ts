import { describe, expect, it } from "vitest";
import { buildStoredXlsx } from "../../src/reports/stored-xlsx";

describe("stored xlsx", () => {
  it("writes a zip workbook with the puantaj header row", () => {
    const bytes = buildStoredXlsx("Puantaj Raporu", ["Tarih", "Ad Soyad"], [["2026-04-06", "Ayse Yilmaz"]]);
    expect(Buffer.from(bytes.subarray(0, 4)).toString("latin1")).toBe("PK\u0003\u0004");
    const xml = Buffer.from(bytes).toString("utf8");
    expect(xml).toContain("Tarih");
    expect(xml).toContain("Ayse Yilmaz");
    expect(xml).toContain("xl/worksheets/sheet1.xml");
  });
});
