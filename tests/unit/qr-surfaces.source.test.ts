import { describe, expect, it } from "vitest";
import { readFileSync, existsSync } from "node:fs";

function read(path: string): string {
  return readFileSync(path, "utf8");
}

describe("QR surface ownership", () => {
  it("keeps daily operation ownership in Puantaj", () => {
    const source = read("src/features/puantaj/components/QrGirisCikisOperationSection.tsx");
    expect(source).toContain("Günlük operasyon / kontrol");
    expect(source).toContain("QR Ekranını Aç");
    expect(source).toContain("Günlük Puantaja Git");
    expect(source).toContain('hasPermission("qr.kiosk.display")');
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

  it("Personel Kartı'nda QR hareketi gösterilmez (t873)", () => {
    expect(existsSync("src/features/personeller/components/personel-dosya/PersonelQrHistorySection.tsx")).toBe(false);
    expect(read("src/features/personeller/components/personel-dosya/PersonelKartPanelGenelBilgiler.tsx")).not.toMatch(/Qr/);
  });
});
