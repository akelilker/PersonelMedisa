import { describe, expect, it } from "vitest";
import { resolveGunlukKayitPreset } from "../../src/features/bildirimler/gunluk-kayit-presets";

describe("gunluk-kayit-presets S74-C3-B1 contract", () => {
  it("defines GOREVDE with canonical Geldi + Gorevde_Calisma + Tam_Yevmiye_Ver", () => {
    const preset = resolveGunlukKayitPreset("GOREVDE");
    expect(preset.gunTipi).toBe("Normal_Is_Gunu");
    expect(preset.hareketDurumu).toBe("Geldi");
    expect(preset.dayanak).toBe("Gorevde_Calisma");
    expect(preset.hesapEtkisi).toBe("Tam_Yevmiye_Ver");
  });
});
