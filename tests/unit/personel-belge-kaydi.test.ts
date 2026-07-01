import { describe, expect, it } from "vitest";
import { formatSurecTuruLabel } from "../../src/lib/display/enum-display";
import { normalizePersonelBelgeKaydi } from "../../src/api/personel-belge-kayitlari.api";
import {
  computeGecerlilikDurumu,
  formatPersonelBelgeDisplayText,
  formatPersonelBelgeKayitDurumLabel,
  formatPersonelBelgeKayitTipiLabel,
  normalizePersonelBelgeKayitTipi,
  PERSONEL_BELGE_GECERLILIK_LABELS,
  PERSONEL_BELGE_KAYIT_EMPTY_MESSAGE,
  PERSONEL_BELGE_KAYIT_TIPI_LABELS
} from "../../src/types/personel-belge-kaydi";

describe("personel-belge-kaydi helpers", () => {
  it("computeGecerlilikDurumu bitis yoksa GECERLI yapar", () => {
    expect(computeGecerlilikDurumu(null)).toBe("GECERLI");
    expect(computeGecerlilikDurumu(undefined)).toBe("GECERLI");
  });

  it("computeGecerlilikDurumu gecmis tarihi SURESI_DOLMUS yapar", () => {
    expect(computeGecerlilikDurumu("2020-01-01", new Date("2026-06-27T12:00:00.000Z"))).toBe(
      "SURESI_DOLMUS"
    );
  });

  it("computeGecerlilikDurumu 30 gun ici bitisi YAKINDA_DOLUYOR yapar", () => {
    expect(computeGecerlilikDurumu("2026-07-15", new Date("2026-06-27T12:00:00.000Z"))).toBe(
      "YAKINDA_DOLUYOR"
    );
  });

  it("computeGecerlilikDurumu uzak gelecek bitisi GECERLI yapar", () => {
    expect(computeGecerlilikDurumu("2028-01-01", new Date("2026-06-27T12:00:00.000Z"))).toBe(
      "GECERLI"
    );
  });

  it("tip label map dogru calisir", () => {
    expect(PERSONEL_BELGE_KAYIT_TIPI_LABELS.SERTIFIKA).toBe("Sertifika");
    expect(PERSONEL_BELGE_KAYIT_TIPI_LABELS.EGITIM).toBe("Eğitim");
    expect(PERSONEL_BELGE_GECERLILIK_LABELS.YAKINDA_DOLUYOR).toBe("Yakında doluyor");
    expect(formatPersonelBelgeKayitTipiLabel("SERTIFIKA")).toBe("Sertifika");
    expect(formatPersonelBelgeKayitTipiLabel("SERTFIKA")).toBe("Sertifika");
    expect(formatPersonelBelgeKayitTipiLabel("SERTFIKA")).not.toMatch(/Sertf/i);
  });

  it("belge kayit tipi alias ve durum label'larini normalize eder", () => {
    expect(normalizePersonelBelgeKayitTipi("SERTFIKA")).toBe("SERTIFIKA");
    expect(formatPersonelBelgeKayitDurumLabel("IPTAL")).toBe("İptal");
    expect(formatPersonelBelgeKayitDurumLabel("AKTIF")).toBe("Aktif");
    expect(PERSONEL_BELGE_KAYIT_EMPTY_MESSAGE).toBe("Belge kaydı bulunmuyor.");
  });

  it("belge display metinlerinde raw json ve object sizintisini filtreler", () => {
    expect(formatPersonelBelgeDisplayText('{"tip":"SERTIFIKA"}')).toBe("-");
    expect(formatPersonelBelgeDisplayText("[object Object]")).toBe("-");
    expect(formatPersonelBelgeDisplayText("  ISO 9001 sertifikası  ")).toBe("ISO 9001 sertifikası");
    expect(formatPersonelBelgeDisplayText(null)).toBe("-");
  });

  it("timeline ve belge sekmesi Sertifika label mantigi celismez", () => {
    const panelLabel = formatPersonelBelgeKayitTipiLabel("SERTIFIKA");
    const timelineAlt = formatSurecTuruLabel("SERTIFIKA");
    const timelineBaslik = `${formatSurecTuruLabel("BELGE")} / ${timelineAlt}`;

    expect(panelLabel).toBe("Sertifika");
    expect(timelineBaslik).toBe("Belge / Sertifika");
    expect(timelineBaslik).toContain(panelLabel);
  });

  it("normalizePersonelBelgeKaydi SERTFIKA alias tipini normalize eder", () => {
    const normalized = normalizePersonelBelgeKaydi({
      id: 5,
      personel_id: 1,
      kayit_tipi: "SERTFIKA",
      ad: "Forklift Sertifikasi",
      durum: "AKTIF"
    });

    expect(normalized.kayit_tipi).toBe("SERTIFIKA");
    expect(formatPersonelBelgeKayitTipiLabel(normalized.kayit_tipi)).toBe("Sertifika");
  });

  it("normalizePersonelBelgeKaydi eksik opsiyonel alanlari null yapar", () => {
    const normalized = normalizePersonelBelgeKaydi({
      id: 1,
      personel_id: 2,
      kayit_tipi: "YETKINLIK",
      ad: "Kaynakçı Yetkinliği",
      durum: "AKTIF",
      bitis_tarihi: "2027-01-01"
    });

    expect(normalized.veren_kurum).toBeNull();
    expect(normalized.belge_no).toBeNull();
    expect(normalized.ek_ref).toBeNull();
    expect(normalized.aciklama).toBeNull();
    expect(normalized.gecerlilik_durumu).toBe("GECERLI");
  });

  it("normalizePersonelBelgeKaydi hatali gecerlilik alanini yeniden hesaplar", () => {
    const normalized = normalizePersonelBelgeKaydi({
      id: 3,
      personel_id: 1,
      kayit_tipi: "EHLIYET",
      ad: "B Sınıfı Ehliyet",
      durum: "AKTIF",
      bitis_tarihi: "2020-05-01",
      gecerlilik_durumu: "GECERLI"
    });

    expect(normalized.gecerlilik_durumu).toBe("SURESI_DOLMUS");
  });

  it("normalizePersonelBelgeKaydi zorunlu alan eksikse hata firlatir", () => {
    expect(() => normalizePersonelBelgeKaydi({ id: 1 })).toThrow(/eksik alan/i);
  });
});
