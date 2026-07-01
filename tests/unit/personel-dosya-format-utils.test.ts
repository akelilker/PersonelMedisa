import { describe, expect, it } from "vitest";
import { formatIsoDateDetail } from "../../src/features/personeller/components/personel-dosya/personel-dosya-format-utils";

describe("formatIsoDateDetail", () => {
  it("gecerli ISO date string'i tr-TR kisa tarih olarak gosterir", () => {
    const formatted = formatIsoDateDetail("2024-03-01");
    expect(formatted).toMatch(/2024/);
    expect(formatted).toMatch(/03/);
    expect(formatted).toMatch(/1/);
    expect(formatted).not.toBe("2024-03-01");
  });

  it("bos, null ve undefined degerlerde - doner", () => {
    expect(formatIsoDateDetail("")).toBe("-");
    expect(formatIsoDateDetail("   ")).toBe("-");
    expect(formatIsoDateDetail(null)).toBe("-");
    expect(formatIsoDateDetail(undefined)).toBe("-");
  });

  it("gecersiz ve takvim disi tarihlerde - doner", () => {
    expect(formatIsoDateDetail("bozuk")).toBe("-");
    expect(formatIsoDateDetail("2024-99-99")).toBe("-");
    expect(formatIsoDateDetail("2024-02-30")).toBe("-");
  });

  it("datetime string kapsam disidir ve - doner", () => {
    expect(formatIsoDateDetail("2024-03-01T10:00:00.000Z")).toBe("-");
    expect(formatIsoDateDetail("2024-03-01 10:00:00")).toBe("-");
  });

  it("timezone kaymasi olusturmaz", () => {
    const formatted = formatIsoDateDetail("2024-03-01");
    const utcFormatted = new Intl.DateTimeFormat("tr-TR", {
      dateStyle: "short",
      timeZone: "UTC"
    }).format(new Date(Date.UTC(2024, 2, 1)));

    expect(formatted).toBe(utcFormatted);
    expect(formatted).not.toMatch(/29\.02\.2024|28\.02\.2024|02\.03\.2024/);
  });
});
