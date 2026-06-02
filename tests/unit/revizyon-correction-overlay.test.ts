import { describe, expect, it } from "vitest";
import type { RevizyonCorrectionEvent } from "../../src/types/revizyon-correction";
import {
  applyCorrectionOverlay,
  filterActiveCorrections,
  resolveEffectiveCorrections
} from "../../src/lib/revizyon-talebi/revizyon-correction-overlay";

function buildCorrection(
  overrides: Partial<RevizyonCorrectionEvent>
): RevizyonCorrectionEvent {
  return {
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
    snapshot_ref: null,
    ...overrides
  };
}

describe("revizyon-correction-overlay", () => {
  it("filterActiveCorrections iptal edilmisi dusurur", () => {
    const active = buildCorrection({ id: 1 });
    const cancelled = buildCorrection({
      id: 2,
      iptal_edildi_mi: true,
      iptal_zamani: "2026-06-02T10:00:00.000Z"
    });

    const result = filterActiveCorrections([active, cancelled]);

    expect(result).toHaveLength(1);
    expect(result[0]?.id).toBe(1);
  });

  it("resolveEffectiveCorrections ayni key icin son olusturma_zamani kazanir", () => {
    const older = buildCorrection({
      id: 1,
      delta_dakika: 10,
      olusturma_zamani: "2026-06-01T10:00:00.000Z"
    });
    const newer = buildCorrection({
      id: 2,
      delta_dakika: 25,
      olusturma_zamani: "2026-06-01T12:00:00.000Z"
    });

    const result = resolveEffectiveCorrections([older, newer]);

    expect(result).toHaveLength(1);
    expect(result[0]?.id).toBe(2);
    expect(result[0]?.delta_dakika).toBe(25);
  });

  it("applyCorrectionOverlay input snapshot'i mutate etmez", () => {
    const snapshot = { toplam_net_dakika: 480, personel_id: 1 };
    const snapshotCopy = { ...snapshot };
    const correction = buildCorrection({ delta_dakika: 15 });

    applyCorrectionOverlay(snapshot, [correction]);

    expect(snapshot).toEqual(snapshotCopy);
  });

  it("toplam_net_dakika number ise delta_dakika eklenir", () => {
    const snapshot = { toplam_net_dakika: 480, personel_id: 1 };
    const correction = buildCorrection({ delta_dakika: 15 });

    const result = applyCorrectionOverlay(snapshot, [correction]);

    expect(result.toplam_net_dakika).toBe(495);
    expect(result.correction_events).toHaveLength(1);
  });

  it("BORDRO_ETKI_NOTU delta uygulamaz ama correction_events icinde kalir", () => {
    const snapshot = { toplam_net_dakika: 480, personel_id: 1 };
    const bordroNote = buildCorrection({
      id: 3,
      correction_tipi: "BORDRO_ETKI_NOTU",
      delta_dakika: 99,
      bordro_etki_var_mi: true,
      bordro_etki_tipi: "BORDRO_ETKI_NOTU"
    });

    const result = applyCorrectionOverlay(snapshot, [bordroNote]);

    expect(result.toplam_net_dakika).toBe(480);
    expect(result.correction_events).toHaveLength(1);
    expect(result.correction_events[0]?.correction_tipi).toBe("BORDRO_ETKI_NOTU");
  });
});
