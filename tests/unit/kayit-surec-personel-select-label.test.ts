import { describe, expect, it } from "vitest";
import { buildPersonelSelectLabels, formatPersonelLabel } from "../../src/features/kayit/kayit-surec-utils";
import type { Personel } from "../../src/types/personel";

function makePersonel(overrides: Partial<Personel> = {}): Personel {
  return {
    id: 1,
    tc_kimlik_no: "12345678901",
    ad: "Ayşe",
    soyad: "Yılmaz",
    sicil_no: "P-001",
    aktif_durum: "AKTIF",
    sube_id: 1,
    departman_id: 3,
    gorev_id: 1,
    departman_adi: "Finans ve Risk Yönetimi",
    gorev_adi: "Finans ve Risk Yönetimi Genel Müdür Yardımcısı",
    ...overrides
  };
}

describe("personel seçim listesi görünür label", () => {
  it("yalnız Ad Soyad gösterir; bölüm/birim/pozisyon göstermez", () => {
    const label = formatPersonelLabel(makePersonel());

    expect(label).toBe("Ayşe Yılmaz");
    expect(label).not.toContain("•");
    expect(label).not.toContain("Finans");
    expect(label).not.toContain("Müdür");
  });

  it("ad/soyad boşsa güvenli kısa metin döner", () => {
    expect(formatPersonelLabel(makePersonel({ ad: "  ", soyad: "Kaya" }))).toBe("Kaya");
    expect(formatPersonelLabel(makePersonel({ ad: null, soyad: null }))).toBe("");
  });

  it("mevcut veri setinde duplicate ad-soyad yoksa yalnız ad soyad kalır", () => {
    const labels = buildPersonelSelectLabels([
      makePersonel({ id: 1, ad: "Ayşe", soyad: "Yılmaz" }),
      makePersonel({ id: 2, ad: "Mehmet", soyad: "Kaya", sicil_no: "P-002" })
    ]);

    expect(labels.get(1)).toBe("Ayşe Yılmaz");
    expect(labels.get(2)).toBe("Mehmet Kaya");
  });

  it("aynı ad-soyad gerçekten çakışırsa yalnız Sicil secondary bilgisi eklenir", () => {
    const labels = buildPersonelSelectLabels([
      makePersonel({ id: 1, sicil_no: "P-001" }),
      makePersonel({ id: 2, sicil_no: "P-002" }),
      makePersonel({ id: 3, ad: "Mehmet", soyad: "Kaya", sicil_no: "P-003" })
    ]);

    expect(labels.get(1)).toBe("Ayşe Yılmaz • Sicil P-001");
    expect(labels.get(2)).toBe("Ayşe Yılmaz • Sicil P-002");
    expect(labels.get(3)).toBe("Mehmet Kaya");
    expect(labels.get(1)).not.toContain("Finans");
  });

  it("çakışan ad-soyadda sicil yoksa ek bilgi uydurmaz", () => {
    const labels = buildPersonelSelectLabels([
      makePersonel({ id: 1, sicil_no: null }),
      makePersonel({ id: 2, sicil_no: null })
    ]);

    expect(labels.get(1)).toBe("Ayşe Yılmaz");
    expect(labels.get(2)).toBe("Ayşe Yılmaz");
  });
});
