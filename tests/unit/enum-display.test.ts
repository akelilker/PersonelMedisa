import { describe, expect, it } from "vitest";
import {
  CALISAN_KAPSAMI_SELECT_OPTIONS,
  formatBildirimTuruLabel,
  formatCalisanKapsamiLabel,
  formatRetentionCategoryLabel,
  formatRetentionEligibilitySummary,
  formatRetentionImhaStatusLabel,
  formatUserRoleLabel,
  RETENTION_CATEGORY_SELECT_OPTIONS
} from "../../src/lib/display/enum-display";

describe("enum display labels", () => {
  it("renders exact Turkish daily notification labels", () => {
    expect(formatBildirimTuruLabel("DIGER")).toBe("Diğer");
    expect(formatBildirimTuruLabel("IZINLI")).toBe("İzinli");
    expect(formatBildirimTuruLabel("GOREVDE")).toBe("Görevde");
  });

  it("renders the exact Birim Yöneticisi role label while keeping internal role id", () => {
    expect(formatUserRoleLabel("BIRIM_AMIRI")).toBe("Birim Yöneticisi");
  });

  it("renders canonical Çalışan Kapsamı labels without changing enum values", () => {
    expect(formatCalisanKapsamiLabel("IC_PERSONEL")).toBe("Dahili Personel");
    expect(formatCalisanKapsamiLabel("DIS_KAYNAK")).toBe("Harici Personel");

    expect(CALISAN_KAPSAMI_SELECT_OPTIONS).toEqual([
      { value: "IC_PERSONEL", label: "Dahili Personel" },
      { value: "DIS_KAYNAK", label: "Harici Personel" }
    ]);

    const labels = CALISAN_KAPSAMI_SELECT_OPTIONS.map((option) => option.label);
    expect(labels).not.toContain("İç Personel");
    expect(labels).not.toContain("İç Kaynak");
    expect(labels).not.toContain("Dış Kaynak");
    expect(labels).not.toContain("Dış Kaynak / SGK Başka İşverende");
    expect(labels).not.toContain("SGK Başka İşverende");
  });

  it("renders retention category and imha status in plain Turkish", () => {
    expect(formatRetentionCategoryLabel("PERSONEL_OZLUK")).toBe("Personel Özlük Dosyası");
    expect(formatRetentionImhaStatusLabel("REQUESTED")).toBe("Onay Bekliyor");
    expect(formatRetentionImhaStatusLabel("APPROVED")).toBe("Onaylandı");
    expect(formatRetentionImhaStatusLabel("REJECTED")).toBe("Reddedildi");
    expect(RETENTION_CATEGORY_SELECT_OPTIONS[0]).toEqual({
      value: "PERSONEL_OZLUK",
      label: "Personel Özlük Dosyası"
    });
  });

  it("summarizes eligibility without exposing technical codes", () => {
    expect(
      formatRetentionEligibilitySummary({
        eligible: true,
        code: "ELIGIBLE_FOR_DESTRUCTION_REQUEST"
      })
    ).toBe("Saklama süresi tamamlanmış. İmha talebi oluşturulabilir.");

    const summary = formatRetentionEligibilitySummary({
      eligible: false,
      code: "RETENTION_NOT_MATURE",
      retention_until: "2036-08-15"
    });
    expect(summary).toContain("Bu kayıt henüz imha edilemez");
    expect(summary).toMatch(/15[./]08[./]2036/);
    expect(summary).not.toContain("RETENTION_NOT_MATURE");
    expect(summary).not.toMatch(/Kod:/i);
  });
});
