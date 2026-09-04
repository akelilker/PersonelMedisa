import { describe, expect, it } from "vitest";
import {
  buildBirimAmiriOzetCounts,
  deriveBirimAmiriPersonelDurum,
  formatBirimAmiriPersonelStatusLine,
  isAktifBirimPersonelForDate,
  mapBirimAmiriPersonelRow
} from "../../src/features/self-service/birim-amiri-operational";

describe("BIRIM_AMIRI operational status derivation", () => {
  it("treats missing notification as Geldi (exception-only)", () => {
    expect(deriveBirimAmiriPersonelDurum(null)).toBe("GELDI");
    expect(deriveBirimAmiriPersonelDurum("")).toBe("GELDI");
    expect(deriveBirimAmiriPersonelDurum("GELMEDI")).toBe("GELMEDI");
    expect(deriveBirimAmiriPersonelDurum("gec_geldi")).toBe("GEC_GELDI");
  });

  it("counts unit summary buckets including late minutes separately from Geldi", () => {
    const ozet = buildBirimAmiriOzetCounts([
      { durum: "GELDI" },
      { durum: "GELDI" },
      { durum: "GEC_GELDI" },
      { durum: "GELMEDI" },
      { durum: "IZINLI" },
      { durum: "RAPORLU" },
      { durum: "ERKEN_CIKTI" },
      { durum: "GOREVDE" }
    ]);
    expect(ozet).toEqual({
      toplam_personel: 8,
      geldi: 2,
      gelmedi: 1,
      gec_geldi: 1,
      izinli_raporlu: 2,
      erken_cikti: 1,
      gorevde: 1
    });
  });

  it("formats Geç Geldi minutes like the operational list copy", () => {
    expect(
      formatBirimAmiriPersonelStatusLine({
        durum: "GEC_GELDI",
        gec_kalma_dakika: 18
      })
    ).toBe("Geç Geldi · 18 Dakika");
    expect(formatBirimAmiriPersonelStatusLine({ durum: "GELMEDI" })).toBe("Gelmedi");
    expect(formatBirimAmiriPersonelStatusLine({ durum: "RAPORLU" })).toBe("Raporlu");
  });

  it("maps late dakika from notification onto gec_kalma_dakika", () => {
    const row = mapBirimAmiriPersonelRow({
      personel_id: 1,
      ad_soyad: "Ahmet Yılmaz",
      bildirim_turu: "GEC_GELDI",
      dakika: 18
    });
    expect(row.durum).toBe("GEC_GELDI");
    expect(row.gec_kalma_dakika).toBe(18);
    expect(row.erken_cikis_dakika).toBeNull();
  });

  it("fail-closes personnel outside assigned birim or inactive", () => {
    expect(
      isAktifBirimPersonelForDate({ aktif_durum: "AKTIF", birim_id: 10, ise_giris_tarihi: "2020-01-01" }, [10], "2026-09-04")
    ).toBe(true);
    expect(
      isAktifBirimPersonelForDate({ aktif_durum: "AKTIF", birim_id: 20, ise_giris_tarihi: "2020-01-01" }, [10], "2026-09-04")
    ).toBe(false);
    expect(
      isAktifBirimPersonelForDate({ aktif_durum: "PASIF", birim_id: 10, ise_giris_tarihi: "2020-01-01" }, [10], "2026-09-04")
    ).toBe(false);
    expect(
      isAktifBirimPersonelForDate({ aktif_durum: "AKTIF", birim_id: null, ise_giris_tarihi: "2020-01-01" }, [10], "2026-09-04")
    ).toBe(false);
  });
});
