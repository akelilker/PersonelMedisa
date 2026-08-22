import { describe, expect, it } from "vitest";
import {
  CALISAN_KAPSAMI_SELECT_OPTIONS,
  formatBildirimTuruLabel,
  formatCalisanKapsamiLabel,
  formatUserRoleLabel
} from "../../src/lib/display/enum-display";

describe("enum display labels", () => {
  it("renders exact Turkish daily notification labels", () => {
    expect(formatBildirimTuruLabel("DIGER")).toBe("Diğer");
    expect(formatBildirimTuruLabel("IZINLI")).toBe("İzinli");
    expect(formatBildirimTuruLabel("GOREVDE")).toBe("Görevde");
  });

  it("renders the exact Birim Amiri role label", () => {
    expect(formatUserRoleLabel("BIRIM_AMIRI")).toBe("Birim Amiri Rolü");
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
});
