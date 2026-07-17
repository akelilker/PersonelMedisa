import { describe, expect, it } from "vitest";
import {
  formatUcretGecerlilikAraligi,
  formatUcretOzeti,
  formatUcretTutar,
  isUcretKaydiIptalEdilebilir,
  sortUcretKayitlari
} from "../../src/features/personeller/components/personel-dosya/personel-ucret-utils";
import type { PersonelUcretKaydi } from "../../src/types/ucret";

function buildKayit(overrides: Partial<PersonelUcretKaydi> = {}): PersonelUcretKaydi {
  return {
    id: 1,
    personel_id: 1,
    ucret_tutari: 35000,
    ucret_turu: "NET",
    para_birimi: "TRY",
    gecerlilik_baslangic: "2026-01-01",
    gecerlilik_bitis: null,
    durum: "AKTIF",
    guncel_mi: true,
    kaynak: "MANUEL",
    ...overrides
  };
}

describe("personel-ucret-utils", () => {
  it("tutar formatlamada TRY icin TL, diger para birimlerinde kodu gosterir", () => {
    expect(formatUcretTutar(35000)).toBe("35.000 TL");
    expect(formatUcretTutar(1250.5, "EUR")).toBe("1.250,5 EUR");
  });

  it("ucret ozeti tutar ve turu birlikte verir", () => {
    expect(formatUcretOzeti(buildKayit())).toBe("35.000 TL (Net)");
    expect(formatUcretOzeti(buildKayit({ ucret_turu: "BRUT", ucret_tutari: 42000 }))).toBe(
      "42.000 TL (Brüt)"
    );
  });

  it("acik uclu kayitlarda devam ediyor etiketi gosterir", () => {
    expect(formatUcretGecerlilikAraligi(buildKayit())).toContain("devam ediyor");
    expect(
      formatUcretGecerlilikAraligi(buildKayit({ gecerlilik_bitis: "2026-06-30" }))
    ).not.toContain("devam ediyor");
  });

  it("kayitlari baslangic tarihine gore azalan siralar", () => {
    const sorted = sortUcretKayitlari([
      buildKayit({ id: 1, gecerlilik_baslangic: "2025-01-01" }),
      buildKayit({ id: 3, gecerlilik_baslangic: "2026-05-01" }),
      buildKayit({ id: 2, gecerlilik_baslangic: "2026-05-01" })
    ]);
    expect(sorted.map((item) => item.id)).toEqual([3, 2, 1]);
  });

  it("iptal yalnizca id'li aktif kayitlar icin sunulur", () => {
    expect(isUcretKaydiIptalEdilebilir(buildKayit())).toBe(true);
    expect(isUcretKaydiIptalEdilebilir(buildKayit({ durum: "IPTAL" }))).toBe(false);
    expect(isUcretKaydiIptalEdilebilir(buildKayit({ id: null }))).toBe(false);
  });
});
