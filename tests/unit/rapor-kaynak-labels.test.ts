import { describe, expect, it } from "vitest";
import {
  RAPOR_LIVE_KAYNAK_UYARI,
  buildRaporKaynakMetaLine,
  getRaporKaynakLabel,
  isRaporLiveKaynak
} from "../../src/lib/display/rapor-kaynak-labels";

describe("rapor-kaynak-labels", () => {
  it("maps known kaynak codes to Turkish labels", () => {
    expect(getRaporKaynakLabel("SNAPSHOT")).toBe("Mühürlü snapshot");
    expect(getRaporKaynakLabel("LIVE")).toBe("Canlı veri");
    expect(getRaporKaynakLabel("FINANS")).toBe("Finans kaydı");
    expect(getRaporKaynakLabel("SUREC")).toBe("Süreç kaydı");
  });

  it("returns null for unknown or missing kaynak", () => {
    expect(getRaporKaynakLabel(undefined)).toBeNull();
    expect(getRaporKaynakLabel("UNKNOWN")).toBeNull();
  });

  it("builds meta line with optional donem and muhur_id", () => {
    expect(
      buildRaporKaynakMetaLine({
        kaynak: "SNAPSHOT",
        donem: "2026-04",
        muhur_id: 42,
        kayitSayisi: 24
      })
    ).toBe("Kaynak: Mühürlü snapshot · Dönem: 2026-04 · Mühür ID: 42 · Kayıt: 24");

    expect(
      buildRaporKaynakMetaLine({
        kaynak: "LIVE",
        donem: "2026-04",
        kayitSayisi: 4
      })
    ).toBe("Kaynak: Canlı veri · Dönem: 2026-04 · Kayıt: 4");
  });

  it("returns null meta line when kaynak is unknown", () => {
    expect(
      buildRaporKaynakMetaLine({
        kaynak: "CUSTOM",
        kayitSayisi: 1
      })
    ).toBeNull();
  });

  it("identifies LIVE kaynak and exposes warning copy", () => {
    expect(isRaporLiveKaynak("LIVE")).toBe(true);
    expect(isRaporLiveKaynak("SNAPSHOT")).toBe(false);
    expect(RAPOR_LIVE_KAYNAK_UYARI).toContain("canlı veriden");
  });
});
