import { describe, expect, it } from "vitest";
import { REVIZYON_CORRECTION_TIPLERI } from "../../src/types/revizyon-correction";
import {
  formatRevizyonCorrectionTipiLabel,
  revizyonUserMessage
} from "../../src/features/revizyon/revizyon-display";

describe("revizyon-display terminology contract", () => {
  it("maps every correction_tipi enum to a Turkish label (no raw enum leak)", () => {
    const expected: Record<(typeof REVIZYON_CORRECTION_TIPLERI)[number], string> = {
      GIRIS_CIKIS_DUZELTME: "Giriş / çıkış düzeltme",
      MOLA_DUZELTME: "Mola düzeltme",
      DEVAMSIZLIK_DUZELTME: "Devamsızlık düzeltme",
      SERBEST_ZAMAN_ETKI_DUZELTME: "Serbest zaman etki düzeltme",
      KAPANIS_HESAP_REVIZYONU: "Kapanış hesap revizyonu",
      BORDRO_ETKI_NOTU: "Bordro etki notu"
    };

    for (const tipi of REVIZYON_CORRECTION_TIPLERI) {
      expect(formatRevizyonCorrectionTipiLabel(tipi)).toBe(expected[tipi]);
      expect(formatRevizyonCorrectionTipiLabel(tipi)).not.toBe(tipi);
    }
  });

  it("falls back to the raw value for unknown correction tip keys", () => {
    expect(formatRevizyonCorrectionTipiLabel("BILINMEYEN_TIP")).toBe("BILINMEYEN_TIP");
  });

  it("uses Oluştur wording for CORRECTION_ALREADY_EXISTS", () => {
    expect(revizyonUserMessage("CORRECTION_ALREADY_EXISTS", "fallback")).toBe(
      "Bu talep için düzeltme kaydı zaten oluşturulmuş."
    );
  });
});
