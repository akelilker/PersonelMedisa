import { describe, expect, it } from "vitest";
import {
  canApplyBildirimPuantajEtkiAday,
  canDismissBildirimPuantajEtkiAday,
  canManualApplyBildirimPuantajEtkiAday,
  countUnicodeCharacters,
  formatBildirimPuantajEtkiAdayStateLabel,
  formatConflictDisplay,
  formatManualKararPreview,
  formatUygulamaModuLabel,
  isTerminalBildirimPuantajEtkiAdayState,
  MANUAL_KARAR_PRESET_OPTIONS,
  manualKararRequiresMiktar,
  trimDismissGerekce,
  validateDismissGerekce,
  validateManualGerekce,
  validateManualMiktar
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
    expect(canApplyBildirimPuantajEtkiAday("HAZIR")).toBe(true);
    expect(canApplyBildirimPuantajEtkiAday("INCELEME_GEREKLI")).toBe(false);
    expect(canManualApplyBildirimPuantajEtkiAday("INCELEME_GEREKLI")).toBe(true);
    expect(canManualApplyBildirimPuantajEtkiAday("HAZIR")).toBe(false);
    expect(isTerminalBildirimPuantajEtkiAdayState("YOK_SAYILDI")).toBe(true);
  });

  it("exposes four manual presets and preview mapping", () => {
    expect(MANUAL_KARAR_PRESET_OPTIONS).toHaveLength(4);
    expect(MANUAL_KARAR_PRESET_OPTIONS.map((item) => item.value)).toEqual([
      "DEVAMSIZLIK_GUN",
      "GEC_KALMA_DAKIKA",
      "ERKEN_CIKIS_DAKIKA",
      "GOREVDE_CALISILMIS_GUN"
    ]);
    expect(formatManualKararPreview("GOREVDE_CALISILMIS_GUN").dayanak).toContain("Görevde");
    expect(formatUygulamaModuLabel("MANUEL")).toBe("Manuel");
    expect(manualKararRequiresMiktar("GEC_KALMA_DAKIKA")).toBe(true);
    expect(validateManualMiktar("GOREVDE_CALISILMIS_GUN", "10")).toMatch(/girilmemelidir/);
    expect(validateManualMiktar("GEC_KALMA_DAKIKA", "")).toMatch(/zorunludur/);
    expect(validateManualGerekce("abcde")).toBeNull();
  });
});
