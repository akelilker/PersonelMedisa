import { describe, expect, it } from "vitest";
import { buildPersonelTimeline } from "../../src/features/personeller/components/personel-dosya/personel-timeline-utils";
import type { Personel } from "../../src/types/personel";
import type { Surec } from "../../src/types/surec";

function makePersonel(overrides: Partial<Personel> = {}): Personel {
  return {
    id: 1,
    tc_kimlik_no: "11111111111",
    ad: "Ayşe",
    soyad: "Yılmaz",
    aktif_durum: "AKTIF",
    sube_id: 1,
    departman_id: 1,
    ...overrides
  } as Personel;
}

function makeSurec(overrides: Partial<Surec> & Pick<Surec, "id" | "surec_turu">): Surec {
  return {
    personel_id: 1,
    baslangic_tarihi: "2026-06-30",
    state: "AKTIF",
    ...overrides
  };
}

function renderTimelineItem(event: ReturnType<typeof buildPersonelTimeline>[number]) {
  return [event.baslik, event.tarih ?? "", event.ozet, event.aciklama ?? ""].join(" | ");
}

describe("personel-timeline-utils", () => {
  it("belge surec basligini tekrarsiz uretir", () => {
    const timeline = buildPersonelTimeline(makePersonel(), [
      makeSurec({ id: 10, surec_turu: "BELGE", alt_tur: "SERTIFIKA", baslangic_tarihi: "2026-06-30" })
    ], []);

    expect(timeline).toHaveLength(1);
    expect(timeline[0]?.baslik).toBe("Belge / Sertifika");
    expect(renderTimelineItem(timeline[0]!)).not.toMatch(/Belge \/ Sertifika \/ Sertifika/);
    expect(renderTimelineItem(timeline[0]!)).not.toMatch(/Sertf/i);
  });

  it("yazim hatasi SERTFIKA alt turunu Sertifika olarak gosterir", () => {
    const timeline = buildPersonelTimeline(makePersonel(), [
      makeSurec({ id: 11, surec_turu: "BELGE", alt_tur: "SERTFIKA", baslangic_tarihi: "2026-06-30" })
    ], []);

    expect(timeline[0]?.baslik).toBe("Belge / Sertifika");
    expect(renderTimelineItem(timeline[0]!)).not.toMatch(/Sertfika/i);
  });

  it("tek baslangic tarihinde Baslangic tekrar etmez", () => {
    const timeline = buildPersonelTimeline(makePersonel(), [
      makeSurec({ id: 12, surec_turu: "BELGE", alt_tur: "SERTIFIKA", baslangic_tarihi: "2026-06-30" })
    ], []);

    const text = renderTimelineItem(timeline[0]!);
    expect(text).toContain("Tarih: 2026-06-30");
    expect(text.match(/Başlangıç:/g) ?? []).toHaveLength(0);
  });

  it("farkli baslangic ve bitis tarihlerini tek meta satirinda gosterir", () => {
    const timeline = buildPersonelTimeline(makePersonel(), [
      makeSurec({
        id: 13,
        surec_turu: "IZIN",
        alt_tur: "YILLIK_IZIN",
        baslangic_tarihi: "2026-06-10",
        bitis_tarihi: "2026-06-15"
      })
    ], []);

    expect(timeline[0]?.baslik).toBe("İzin / Yıllık İzin");
    expect(timeline[0]?.tarih).toBe("Başlangıç: 2026-06-10 · Bitiş: 2026-06-15");
    expect(timeline[0]?.ozet).not.toContain("Başlangıç:");
  });

  it("ayni baslangic ve bitis tarihinde tek Tarih satiri kullanir", () => {
    const timeline = buildPersonelTimeline(makePersonel(), [
      makeSurec({
        id: 14,
        surec_turu: "DEVAMSIZLIK",
        alt_tur: "IZINSIZ_GELMEDI",
        baslangic_tarihi: "2026-06-30",
        bitis_tarihi: "2026-06-30"
      })
    ], []);

    expect(timeline[0]?.baslik).toBe("Devamsızlık / İzinsiz Gelmedi");
    expect(timeline[0]?.tarih).toBe("Tarih: 2026-06-30");
  });

  it("raw json aciklama sizintisini gostermez", () => {
    const timeline = buildPersonelTimeline(makePersonel(), [
      makeSurec({
        id: 15,
        surec_turu: "BELGE",
        alt_tur: "SERTIFIKA",
        aciklama: '{"tip":"SERTIFIKA","ad":"ISO 9001"}'
      })
    ], []);

    const text = renderTimelineItem(timeline[0]!);
    expect(timeline[0]?.aciklama).toBeUndefined();
    expect(text).not.toContain('"tip"');
    expect(text).not.toContain("{");
  });

  it("ornek surec turu basliklarini uretir", () => {
    const timeline = buildPersonelTimeline(
      makePersonel(),
      [
        makeSurec({ id: 20, surec_turu: "IZIN", alt_tur: "YILLIK_IZIN" }),
        makeSurec({ id: 21, surec_turu: "POZISYON_DEGISTI" }),
        makeSurec({ id: 22, surec_turu: "DEVAMSIZLIK" }),
        makeSurec({ id: 23, surec_turu: "TESVIK" }),
        makeSurec({ id: 24, surec_turu: "IS_KAZASI" })
      ],
      []
    );

    const titles = timeline.map((item) => item.baslik);
    expect(titles).toContain("İzin / Yıllık İzin");
    expect(titles).toContain("Pozisyon Değişti");
    expect(titles).toContain("Devamsızlık");
    expect(titles).toContain("Teşvik");
    expect(titles).toContain("İş Kazası");
  });

  it("en yeni kaydi once siralar", () => {
    const timeline = buildPersonelTimeline(
      makePersonel(),
      [
        makeSurec({ id: 30, surec_turu: "TESVIK", baslangic_tarihi: "2026-01-01" }),
        makeSurec({ id: 31, surec_turu: "BELGE", alt_tur: "SERTIFIKA", baslangic_tarihi: "2026-06-30" })
      ],
      []
    );

    expect(timeline.map((item) => item.id)).toEqual(["surec-31", "surec-30"]);
  });
});
