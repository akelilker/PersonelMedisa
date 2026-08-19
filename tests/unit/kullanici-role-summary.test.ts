import { describe, expect, it } from "vitest";
import { buildKullaniciRoleSummary } from "../../src/lib/yonetim/kullanici-role-summary";

describe("kullanici role summary", () => {
  it("summarizes GENEL_YONETICI management and reporting access", () => {
    const groups = buildKullaniciRoleSummary("GENEL_YONETICI");
    const titles = groups.map((group) => group.title);
    expect(titles).toContain("Yönetim");
    expect(titles).toContain("Personel");
    expect(titles).toContain("Rapor / finans");
  });

  it("includes self-service QR for PERSONEL role", () => {
    const groups = buildKullaniciRoleSummary("PERSONEL");
    const qrGroup = groups.find((group) => group.title === "Self-service / QR");
    expect(qrGroup?.items.some((item) => item.includes("QR"))).toBe(true);
  });

  it("includes SGK dual-control permissions for approver roles", () => {
    const groups = buildKullaniciRoleSummary("GENEL_YONETICI");
    const sgkGroup = groups.find((group) => group.title === "SGK dual-control");
    expect(sgkGroup?.items.length).toBeGreaterThan(0);
  });
});
