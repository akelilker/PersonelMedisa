import { describe, expect, it } from "vitest";
import { resolveDemoApiResponse } from "../../src/api/mock-demo";

type DemoRefRow = { id: number; ad: string; departman_id?: number; bolum_id?: number };

function readRows(path: string): DemoRefRow[] {
  const response = resolveDemoApiResponse(path, { method: "GET" }) as { data?: unknown } | null;
  expect(response).not.toBeNull();
  const data = response?.data;
  if (Array.isArray(data)) {
    return data as DemoRefRow[];
  }
  const items = (data as { items?: unknown } | null)?.items;
  return Array.isArray(items) ? (items as DemoRefRow[]) : [];
}

/**
 * Manuel local geliştirme (5173) demo runtime kontratı:
 * Kayıt ve Süreç bootstrap'ı Bölüm / Birim / Pozisyon referanslarını da ister.
 * Boş dizi dönerse create formunda ilgili select hiç render edilmez.
 */
describe("mock-demo referans bootstrap coverage", () => {
  it("bolumler demo katalogu dolu ve departman_id ile bagli", () => {
    const departmanIds = new Set(readRows("/referans/departmanlar").map((row) => row.id));
    const bolumler = readRows("/referans/bolumler");

    expect(bolumler.length).toBeGreaterThan(0);
    for (const row of bolumler) {
      expect(row.id).toBeGreaterThan(0);
      expect(row.ad.trim().length).toBeGreaterThan(0);
      expect(departmanIds.has(Number(row.departman_id))).toBe(true);
    }

    const scoped = readRows("/referans/bolumler?departman_id=3");
    expect(scoped.length).toBeGreaterThan(0);
    expect(scoped.every((row) => row.departman_id === 3)).toBe(true);
    expect(scoped.length).toBeLessThan(bolumler.length);
  });

  it("birimler demo katalogu dolu ve bolum_id ile bagli", () => {
    const bolumIds = new Set(readRows("/referans/bolumler").map((row) => row.id));
    const birimler = readRows("/referans/birimler");

    expect(birimler.length).toBeGreaterThan(0);
    for (const row of birimler) {
      expect(row.id).toBeGreaterThan(0);
      expect(row.ad.trim().length).toBeGreaterThan(0);
      expect(bolumIds.has(Number(row.bolum_id))).toBe(true);
    }

    const scoped = readRows("/referans/birimler?bolum_id=3");
    expect(scoped.length).toBeGreaterThan(0);
    expect(scoped.every((row) => row.bolum_id === 3)).toBe(true);
  });

  it("pozisyonlar demo katalogu dolu", () => {
    const pozisyonlar = readRows("/referans/pozisyonlar");

    expect(pozisyonlar.length).toBeGreaterThan(0);
    for (const row of pozisyonlar) {
      expect(row.id).toBeGreaterThan(0);
      expect(row.ad.trim().length).toBeGreaterThan(0);
    }
  });

  it("kayit bootstrap referanslarinin tamami demo'da karsilanir", () => {
    for (const path of [
      "/referans/departmanlar",
      "/referans/bolumler",
      "/referans/birimler",
      "/referans/gorevler",
      "/referans/pozisyonlar",
      "/referans/personel-tipleri",
      "/referans/sgk-isverenler",
      "/referans/calisma-lokasyonlari",
      "/referans/bagli-amirler",
      "/referans/ucret-tipleri",
      "/referans/prim-kurallari",
      "/referans/surec-turleri"
    ]) {
      expect(readRows(path), path).not.toHaveLength(0);
    }
  });
});
