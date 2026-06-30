import { describe, expect, it } from "vitest";
import {
  looksLikeRawDisplayLeak,
  sanitizeDisplayText
} from "../../src/lib/display/sanitize-display-text";

describe("sanitize-display-text", () => {
  it("normal kullanici metnini korur", () => {
    expect(sanitizeDisplayText("Normal açıklama")).toBe("Normal açıklama");
    expect(sanitizeDisplayText(" Forklift Belgesi ")).toBe("Forklift Belgesi");
    expect(sanitizeDisplayText("ISO 9001 sertifikası")).toBe("ISO 9001 sertifikası");
    expect(sanitizeDisplayText("Türkçe karakter: şğüöç İ")).toBe("Türkçe karakter: şğüöç İ");
  });

  it("bos ve gecersiz degerleri tire yapar", () => {
    expect(sanitizeDisplayText("")).toBe("-");
    expect(sanitizeDisplayText("   ")).toBe("-");
    expect(sanitizeDisplayText(null)).toBe("-");
    expect(sanitizeDisplayText(undefined)).toBe("-");
    expect(sanitizeDisplayText({})).toBe("-");
    expect(sanitizeDisplayText(42)).toBe("-");
  });

  it("raw json ve object sizintisini filtreler", () => {
    expect(sanitizeDisplayText("[object Object]")).toBe("-");
    expect(sanitizeDisplayText('{"tip":"SERTIFIKA"}')).toBe("-");
    expect(sanitizeDisplayText('{"_personel_belge_kaydi":true,"ad":"X"}')).toBe("-");
    expect(sanitizeDisplayText("[1,2,3]")).toBe("-");
    expect(sanitizeDisplayText("{tip:broken}")).toBe("-");
  });

  it("looksLikeRawDisplayLeak normal metni false dondurur", () => {
    expect(looksLikeRawDisplayLeak("Normal açıklama")).toBe(false);
    expect(looksLikeRawDisplayLeak("Forklift Belgesi")).toBe(false);
    expect(looksLikeRawDisplayLeak('{"tip":"SERTIFIKA"}')).toBe(true);
    expect(looksLikeRawDisplayLeak("[object Object]")).toBe(true);
  });
});
