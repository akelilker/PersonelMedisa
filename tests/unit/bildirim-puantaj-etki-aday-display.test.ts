import { describe, expect, it } from "vitest";
import {
  canDismissBildirimPuantajEtkiAday,
  countUnicodeCharacters,
  formatBildirimPuantajEtkiAdayStateLabel,
  formatConflictDisplay,
  isTerminalBildirimPuantajEtkiAdayState,
  trimDismissGerekce,
  validateDismissGerekce
} from "../../src/lib/bildirim-puantaj-etki-aday/display";

describe("bildirim-puantaj-etki-aday display", () => {
  it("maps state labels", () => {
    expect(formatBildirimPuantajEtkiAdayStateLabel("HAZIR")).toBe("Hazır");
    expect(formatBildirimPuantajEtkiAdayStateLabel("YOK_SAYILDI")).toBe("Yok Sayıldı");
  });

  it("validates gerekce length and trim", () => {
    expect(validateDismissGerekce("abcd")).toMatch(/5 karakter/);
    expect(validateDismissGerekce("abcde")).toBeNull();
    expect(validateDismissGerekce("çalış")).toBeNull();
    expect(validateDismissGerekce("ş".repeat(500))).toBeNull();
    expect(validateDismissGerekce("ş".repeat(501))).toMatch(/500 karakter/);
    expect(validateDismissGerekce("a".repeat(500))).toBeNull();
    expect(validateDismissGerekce("a".repeat(501))).toMatch(/500 karakter/);
    expect(trimDismissGerekce("  abcde  ")).toBe("abcde");
    expect(countUnicodeCharacters("üç")).toBe(2);
  });

  it("describes conflict and action matrix", () => {
    expect(formatConflictDisplay(null)).toBe("Çakışma yok");
    expect(formatConflictDisplay("MEVCUT_PUANTAJ_VAR")).toContain("mevcut puantaj");
    expect(canDismissBildirimPuantajEtkiAday("HAZIR")).toBe(true);
    expect(canDismissBildirimPuantajEtkiAday("UYGULANDI")).toBe(false);
    expect(isTerminalBildirimPuantajEtkiAdayState("YOK_SAYILDI")).toBe(true);
  });
});
