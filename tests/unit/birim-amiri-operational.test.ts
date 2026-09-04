import { describe, expect, it } from "vitest";
import {
  birimAmiriCountsSatisfyInvariant,
  buildBirimAmiriOzetCounts,
  deriveBirimAmiriPersonelDurum,
  formatBirimAmiriPersonelStatusLine,
  isAktifBirimPersonelForDate,
  mapBirimAmiriPersonelRow,
  resolveBirimAmiriPersonelDurum
} from "../../src/features/self-service/birim-amiri-operational";

describe("BIRIM_AMIRI operational status derivation", () => {
  it("treats missing evidence as HENUZ_DEGERLENDIRILMEDI (PR255 parity)", () => {
    expect(deriveBirimAmiriPersonelDurum(null)).toBe("HENUZ_DEGERLENDIRILMEDI");
    expect(deriveBirimAmiriPersonelDurum("")).toBe("HENUZ_DEGERLENDIRILMEDI");
    expect(deriveBirimAmiriPersonelDurum("GELMEDI")).toBe("GELMEDI");
    expect(deriveBirimAmiriPersonelDurum("gec_geldi")).toBe("GEC_GELDI");
    expect(resolveBirimAmiriPersonelDurum(null, null, true)).toBe("GELDI");
    expect(resolveBirimAmiriPersonelDurum(null, "08:47", false)).toBe("GEC_GELDI");
  });

  it("counts unit summary buckets including henuz_degerlendirilmedi", () => {
    const ozet = buildBirimAmiriOzetCounts([
      { durum: "GELDI" },
      { durum: "GELDI" },
      { durum: "GEC_GELDI" },
      { durum: "GELMEDI" },
      { durum: "IZINLI" },
      { durum: "RAPORLU" },
      { durum: "ERKEN_CIKTI" },
      { durum: "GOREVDE" },
      { durum: "HENUZ_DEGERLENDIRILMEDI" }
    ]);
    expect(ozet).toEqual({
      toplam_personel: 9,
      geldi: 2,
      gelmedi: 1,
      gec_geldi: 1,
      izinli: 1,
      raporlu: 1,
      izinli_raporlu: 2,
      erken_cikti: 1,
      gorevde: 1,
      henuz_degerlendirilmedi: 1
    });
    expect(birimAmiriCountsSatisfyInvariant(ozet)).toBe(true);
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
    expect(formatBirimAmiriPersonelStatusLine({ durum: "HENUZ_DEGERLENDIRILMEDI" })).toBe(
      "Henüz Değerlendirilmedi"
    );
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

  it("maps attendance proof without exception to late/present", () => {
    const late = mapBirimAmiriPersonelRow({
      personel_id: 2,
      ad_soyad: "B",
      giris_saati: "08:47",
      unit_completed: false
    });
    expect(late.durum).toBe("GEC_GELDI");
    expect(late.gec_kalma_dakika).toBe(17);

    const unassessed = mapBirimAmiriPersonelRow({
      personel_id: 3,
      ad_soyad: "C",
      unit_completed: false
    });
    expect(unassessed.durum).toBe("HENUZ_DEGERLENDIRILMEDI");

    const completed = mapBirimAmiriPersonelRow({
      personel_id: 4,
      ad_soyad: "D",
      unit_completed: true
    });
    expect(completed.durum).toBe("GELDI");
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
