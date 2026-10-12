import { describe, expect, it } from "vitest";
import { listGorevDisplay } from "../../src/features/personeller/pages/PersonellerPage";

describe("liste Görev kısaltması (yalnız görünüm)", () => {
  it("sondaki Yardımcısı → Yrd.; diğerleri aynen", () => {
    expect(listGorevDisplay("Finans ve Risk Yönetimi Genel Müdür Yardımcısı")).toBe("Finans ve Risk Yönetimi Genel Müdür Yrd.");
    expect(listGorevDisplay("Üretim Elemanı")).toBe("Üretim Elemanı");
    expect(listGorevDisplay("Yardımcısı Değil Usta")).toBe("Yardımcısı Değil Usta");
  });
});
