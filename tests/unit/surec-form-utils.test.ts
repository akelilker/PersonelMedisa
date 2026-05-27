import { describe, expect, it } from "vitest";
import { INITIAL_SUREC_FORM } from "../../src/hooks/useSurecler";
import { buildCreateSurecPayload, buildUpdateSurecPayload } from "../../src/features/surecler/surec-form-utils";

const VALID_FORM = {
  ...INITIAL_SUREC_FORM,
  personelId: "1",
  surecTuru: "RAPOR",
  baslangicTarihi: "2026-04-12",
  bitisTarihi: "2026-04-12"
};

describe("buildCreateSurecPayload", () => {
  it("rejects empty surec turu after trim", () => {
    expect(() =>
      buildCreateSurecPayload({
        ...VALID_FORM,
        surecTuru: "   "
      })
    ).toThrow("Surec turu zorunludur.");
  });

  it("accepts trimmed surec turu", () => {
    expect(
      buildCreateSurecPayload({
        ...VALID_FORM,
        surecTuru: "  RAPOR  "
      }).surec_turu
    ).toBe("RAPOR");
  });
});

describe("buildUpdateSurecPayload", () => {
  it("rejects empty surec turu after trim", () => {
    expect(() =>
      buildUpdateSurecPayload({
        ...VALID_FORM,
        surecTuru: ""
      })
    ).toThrow("Surec turu zorunludur.");
  });
});
