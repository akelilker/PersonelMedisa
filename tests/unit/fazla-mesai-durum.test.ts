import { describe, expect, it } from "vitest";
import {
  birimAmiriPersonelKapsamda,
  fazlaMesaiUyariSeviyesi,
  formatFazlaMesaiSure
} from "../../src/services/fazla-mesai-durum";

describe("fazla mesai durum gorunumu", () => {
  it("does not alarm when the canonical flags are clear", () => {
    expect(
      fazlaMesaiUyariSeviyesi({
        limit_asildi_mi: false,
        limit_yaklasiyor_mu: false
      })
    ).toBe("normal");
  });

  it("warns when the aggregate says the limit is approaching", () => {
    expect(
      fazlaMesaiUyariSeviyesi({
        limit_asildi_mi: false,
        limit_yaklasiyor_mu: true
      })
    ).toBe("yaklasiyor");
  });

  it("uses the stronger signal when the limit is exceeded", () => {
    expect(
      fazlaMesaiUyariSeviyesi({
        limit_asildi_mi: true,
        limit_yaklasiyor_mu: true
      })
    ).toBe("asildi");
  });

  it("formats minutes as readable hours", () => {
    expect(formatFazlaMesaiSure(16200)).toBe("270 sa");
    expect(formatFazlaMesaiSure(15630)).toBe("260 sa 30 dk");
    expect(formatFazlaMesaiSure(0)).toBe("0 sa");
  });

  it("keeps BIRIM_AMIRI inside assigned birim ids", () => {
    expect(birimAmiriPersonelKapsamda([10], 10)).toBe(true);
    expect(birimAmiriPersonelKapsamda([10], 20)).toBe(false);
    expect(birimAmiriPersonelKapsamda([], 10)).toBe(false);
    expect(birimAmiriPersonelKapsamda([10], null)).toBe(false);
  });
});
