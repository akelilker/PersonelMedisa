import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import {
  isTombstonedPersonelAdSoyad,
  normalizeKullaniciAdSoyadForWrite
} from "../../src/lib/yonetim/kullanici-ad-soyad";

const pageSrc = readFileSync(resolve(process.cwd(), "src/features/yonetim/pages/YonetimPaneliPage.tsx"), "utf8");

describe("Kullanıcı Yönetimi — tombstone placeholder görünen ad olmaz", () => {
  it("detects the backend de-identify placeholder in any case/spacing", () => {
    expect(isTombstonedPersonelAdSoyad("DESTROYED PERSONEL")).toBe(true);
    expect(isTombstonedPersonelAdSoyad("Destroyed PERSONEL")).toBe(true);
    expect(isTombstonedPersonelAdSoyad("  destroyed   personel ")).toBe(true);
  });

  it("never flags real names or empty values", () => {
    expect(isTombstonedPersonelAdSoyad("Ayşe YILMAZ")).toBe(false);
    expect(isTombstonedPersonelAdSoyad("Destroyed")).toBe(false);
    expect(isTombstonedPersonelAdSoyad("PERSONEL")).toBe(false);
    expect(isTombstonedPersonelAdSoyad(null)).toBe(false);
    expect(isTombstonedPersonelAdSoyad(undefined)).toBe(false);
    expect(isTombstonedPersonelAdSoyad("")).toBe(false);
  });

  it("leaves the write normalizer untouched", () => {
    expect(normalizeKullaniciAdSoyadForWrite("  Saıf   Tareq ")).toBe("Saıf Tareq");
  });

  it("card label and table name fall back to username for a tombstoned personel name", () => {
    const guardCount = pageSrc.split("isTombstonedPersonelAdSoyad(item.personel_ad_soyad)").length - 1;
    expect(guardCount).toBe(2);
    expect(pageSrc.split('return (item.username ?? "").trim() || formatUserRoleLabel(item.rol);').length - 1).toBe(2);
    expect(pageSrc).toContain("!isTombstonedPersonelAdSoyad(personelLabel)");
    expect(pageSrc).toContain("!isTombstonedPersonelAdSoyad(mappedName)");
  });
});
