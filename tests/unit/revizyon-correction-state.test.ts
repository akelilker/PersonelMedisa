import { describe, expect, it } from "vitest";
import type { RevizyonCorrectionEvent } from "../../src/types/revizyon-correction";
import type { RevizyonTalebi } from "../../src/types/revizyon-talebi";
import {
  canCancelCorrection,
  canProduceCorrection,
  getCancelCorrectionError,
  getProduceCorrectionError
} from "../../src/lib/revizyon-talebi/revizyon-correction-state";

const baseTalep: RevizyonTalebi = {
  id: 1,
  personel_id: 1,
  hafta_baslangic: "2026-04-06",
  hafta_bitis: "2026-04-12",
  etkilenen_tarih: "2026-04-08",
  kaynak_tipi: "PUANTAJ",
  kaynak_id: 501,
  revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
  onceki_deger: 100,
  talep_edilen_deger: 115,
  gerekce: "Test",
  talep_eden_kullanici_id: 1,
  talep_zamani: "2026-06-01T10:00:00.000Z",
  durum: "ONAY_BEKLIYOR",
  bordro_etki_var_mi: false,
  correction_event_id: null
};

const baseCorrection: RevizyonCorrectionEvent = {
  id: 1,
  revizyon_talebi_id: 1,
  personel_id: 1,
  hafta_baslangic: "2026-04-06",
  hafta_bitis: "2026-04-12",
  etkilenen_tarih: "2026-04-08",
  kaynak_tipi: "PUANTAJ",
  kaynak_id: 501,
  correction_tipi: "GIRIS_CIKIS_DUZELTME",
  onceki_deger: 100,
  yeni_deger: 115,
  delta_dakika: 15,
  delta_gun: 0,
  bordro_etki_var_mi: false,
  bordro_etki_tipi: null,
  aciklama: "Test",
  olusturan_kullanici_id: 1,
  olusturma_zamani: "2026-06-01T12:00:00.000Z",
  iptal_edildi_mi: false,
  iptal_zamani: null,
  iptal_eden_kullanici_id: null,
  audit_ref: "REV-CORR-1-1",
  snapshot_ref: null
};

describe("revizyon-correction-state", () => {
  it("ONAYLANDI olmayan talep correction uretemez", () => {
    expect(canProduceCorrection(baseTalep)).toBe(false);
    expect(getProduceCorrectionError(baseTalep)).toBe("CORRECTION_NOT_ALLOWED_FOR_STATE");
  });

  it("correction_event_id olan talep CORRECTION_ALREADY_EXISTS doner", () => {
    const talep: RevizyonTalebi = {
      ...baseTalep,
      durum: "ONAYLANDI",
      correction_event_id: 99
    };

    expect(canProduceCorrection(talep)).toBe(false);
    expect(getProduceCorrectionError(talep)).toBe("CORRECTION_ALREADY_EXISTS");
  });

  it("ONAYLANDI ve correction_event_id null ise uretilebilir", () => {
    const talep: RevizyonTalebi = {
      ...baseTalep,
      durum: "ONAYLANDI",
      correction_event_id: null
    };

    expect(canProduceCorrection(talep)).toBe(true);
    expect(getProduceCorrectionError(talep)).toBeNull();
  });

  it("aktif correction iptal edilebilir", () => {
    expect(canCancelCorrection(baseCorrection)).toBe(true);
    expect(getCancelCorrectionError(baseCorrection)).toBeNull();
  });

  it("iptal edilmis correction tekrar iptal edilemez", () => {
    const correction: RevizyonCorrectionEvent = {
      ...baseCorrection,
      iptal_edildi_mi: true,
      iptal_zamani: "2026-06-02T10:00:00.000Z",
      iptal_eden_kullanici_id: 1
    };

    expect(canCancelCorrection(correction)).toBe(false);
    expect(getCancelCorrectionError(correction)).toBe("CORRECTION_NOT_FOUND");
  });
});
