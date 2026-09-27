import { describe, expect, it } from "vitest";
import { puantajRaporuXlsxFilename } from "../../src/features/raporlar/puantaj-raporu-filename";

describe("puantaj raporu xlsx filename", () => {
  it("uses the month when the filter is a full calendar month", () => {
    expect(puantajRaporuXlsxFilename("2026-04-01", "2026-04-30")).toBe("puantaj-raporu-2026-04.xlsx");
    expect(puantajRaporuXlsxFilename("2024-02-01", "2024-02-29")).toBe("puantaj-raporu-2024-02.xlsx");
  });

  it("keeps a partial range in the file name", () => {
    expect(puantajRaporuXlsxFilename("2026-04-06", "2026-04-12")).toBe(
      "puantaj-raporu-2026-04-06_2026-04-12.xlsx"
    );
  });

  it("falls back when dates are absent", () => {
    expect(puantajRaporuXlsxFilename()).toBe("puantaj-raporu.xlsx");
  });
});
