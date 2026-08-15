import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";

function read(path: string): string {
  return readFileSync(path, "utf8");
}

describe("QR surface ownership", () => {
  it("keeps daily operation ownership in Puantaj", () => {
    const source = read("src/features/puantaj/components/QrGirisCikisOperationSection.tsx");
    expect(source).toContain("Günlük operasyon / kontrol");
    expect(source).toContain("QR Ekranını Aç");
    expect(source).toContain("Günlük Puantaja Git");
  });

  it("keeps Raporlar as an independent historical/reporting surface", () => {
    const source = read("src/features/raporlar/components/QrGirisCikisReportSection.tsx");
    expect(source).not.toContain("QrGirisCikisOperationSection");
    expect(source).not.toContain("Günlük operasyon / kontrol");
    expect(source).not.toContain("QR Ekranını Aç");
    expect(source).toContain("raporlar-qr-table");
    expect(source).toContain("Geçmişi Getir");
    expect(source).toContain("CSV");
  });

  it("keeps Personel Kartı history at daily row grain", () => {
    const source = read("src/features/personeller/components/personel-dosya/PersonelQrHistorySection.tsx");
    expect(source).toContain("Giriş / Çıkış — Son 30 gün");
    expect(source).toContain("istanbulDateDaysAgo(30)");
    expect(source).toContain("row.date_from");
    expect(source).toContain("Günlük puantaj");
  });
});
