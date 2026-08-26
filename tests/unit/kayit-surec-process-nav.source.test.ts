import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  normalizePersonelSurecTab,
  PERSONEL_SUREC_TABS,
  resolvePersonelSurecTabForSurecTuru,
  resolveVisiblePersonelSurecTabs
} from "../../src/features/kayit/kayit-surec-constants";

const root = resolve(import.meta.dirname, "../..");

function read(path: string) {
  return readFileSync(resolve(root, path), "utf8");
}

describe("kayit-surec selected-person process navigation", () => {
  it("exposes canonical IC_PERSONEL process order without peer izin/gunluk/revizyon tabs", () => {
    expect(PERSONEL_SUREC_TABS.map((tab) => tab.id)).toEqual([
      "genel",
      "puantaj",
      "haftalik-kapanis",
      "belge-takip",
      "mali",
      "pozisyon",
      "belgeler",
      "zimmet",
      "ceza",
      "ayrilma"
    ]);
    expect(PERSONEL_SUREC_TABS.map((tab) => tab.label)).not.toContain("İzin / Devamsızlık");
    expect(PERSONEL_SUREC_TABS.map((tab) => tab.label)).not.toContain("Günlük Kayıt");
    expect(PERSONEL_SUREC_TABS.map((tab) => tab.label)).not.toContain("Revizyon Merkezi");
    expect(PERSONEL_SUREC_TABS.find((tab) => tab.id === "mali")?.label).toBe("Finans");
  });

  it("normalizes legacy izin-devamsizlik deep links to puantaj", () => {
    expect(normalizePersonelSurecTab("izin-devamsizlik")).toBe("puantaj");
    expect(resolvePersonelSurecTabForSurecTuru("IZIN")).toBe("puantaj");
  });

  it("keeps DIS_KAYNAK restriction to genel, pozisyon, belgeler", () => {
    expect(resolveVisiblePersonelSurecTabs(true).map((tab) => tab.id)).toEqual([
      "genel",
      "pozisyon",
      "belgeler"
    ]);
  });

  it("renders process nav only when a person is selected", () => {
    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    const processNav = read("src/features/kayit/components/KayitSurecPersonelProcessNav.tsx");
    expect(processNav).toContain('data-testid="kayit-surec-person-process-nav"');
    expect(workspace).toMatch(/selectedSurecPersonel \?\s*\(\s*<KayitSurecPersonelProcessNav/);
  });

  it("resets puantaj subdomain state on person switch", () => {
    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    expect(workspace).toContain("setPuantajSubdomain(null)");
    expect(workspace).toContain("setHakDuzeltmeOpen(false)");
  });

  it("does not restore global modules home card", () => {
    const mainMenu = read("src/components/main-menu/MainMenu.tsx");
    expect(mainMenu).not.toContain("menu-moduller");
    expect(mainMenu).not.toMatch(/Modüller/i);
  });
});
