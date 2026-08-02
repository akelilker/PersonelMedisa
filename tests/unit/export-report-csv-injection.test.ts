import { describe, expect, it } from "vitest";
import { toCsvValue, buildCsv } from "../../src/reports/export-report";

describe("export-report CSV injection guard", () => {
  it("neutralizes formula-leading cells used by personel import error CSV", () => {
    expect(toCsvValue("=CMD|'/C calc'!A0")).toBe("'=CMD|'/C calc'!A0");
    expect(toCsvValue("+1234")).toBe("'+1234");
    expect(toCsvValue("-1234")).toBe("'-1234");
    expect(toCsvValue("@SUM(A1)")).toBe("'@SUM(A1)");
    expect(toCsvValue("100******46")).toBe("100******46");

    const csv = buildCsv(["hata_kodlari", "tc_kimlik_no_masked"], [
      {
        hata_kodlari: "=HYPERLINK(\"http://evil\")",
        tc_kimlik_no_masked: "123******01"
      }
    ]);
    expect(csv).toContain("'=HYPERLINK");
    expect(csv).toContain("123******01");
    expect(csv).not.toContain("12345678901");
  });
});
