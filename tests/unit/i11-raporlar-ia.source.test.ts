import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function readOwner(relativePath: string) {
  return readFileSync(resolve(process.cwd(), relativePath), "utf8");
}

describe("i11 raporlar IA source contracts", () => {
  it("keeps panel ids and owners; removes flat mixed label", () => {
    const page = readOwner("src/features/raporlar/pages/RaporlarPage.tsx");
    const ia = readOwner("src/features/raporlar/raporlar-ia.ts");
    const nav = readOwner("src/features/raporlar/components/RaporlarGroupedNav.tsx");

    expect(page).toContain('from "../components/RaporlarGroupedNav"');
    expect(page).toContain("<RaporlarGroupedNav");
    expect(page).toContain('activeSurface === "liste"');
    expect(page).toContain('activeSurface === "aylik-kapanis" && canViewAylikOzet');
    expect(page).toContain("<DonemKapanisMerkeziPage");
    expect(page).toContain("<EtkiAdayiRaporuPage");
    expect(page).toContain("<MaasHesaplamaMerkeziPage");
    expect(page).toContain("<BordroHazirlikMerkeziPage");
    expect(page).toContain("<SerbestZamanTakipPage");
    expect(page).not.toContain("Liste ve aylık özet");
    expect(page).not.toContain("Detaylı Liste");

    expect(ia).toContain('"donem-kapanis"');
    expect(ia).toContain('"etki-adayi"');
    expect(ia).toContain('"maas-hesaplama"');
    expect(ia).toContain('"bordro-hazirlik"');
    expect(ia).toContain('"serbest-zaman-takip"');
    expect(ia).toContain('view=aylik-kapanis');
    expect(ia).toContain("Liste Raporları");
    expect(ia).toContain("Serbest Zaman Takibi");
    expect(ia).toContain("Aylık Kapanış Özeti");
    expect(ia).toContain('group: "raporlar"');
    expect(ia).toContain('group: "kapanis"');
    expect(ia).toContain('group: "bordro"');
    expect(ia).toContain('export const RAPORLAR_LEGACY_FLAT_LABEL = "Liste ve aylık özet"');
    expect(ia.includes("label: \"Liste ve aylık özet\"")).toBe(false);

    expect(nav).toContain("buildVisibleRaporlarNavGroups");
    expect(nav).toContain('data-testid="raporlar-panel-nav"');
    expect(nav).not.toContain("Liste ve aylık özet");
    expect(nav).toContain("<ul");
    expect(nav).toContain("<li key={item.id}>");
    expect(nav).not.toContain('role="listitem"');
    expect(nav).not.toContain('role="list"');
  });

  it("does not mount inactive panel owners together", () => {
    const page = readOwner("src/features/raporlar/pages/RaporlarPage.tsx");
    expect(page).toContain('activePanel === "donem-kapanis" && canViewDonemKapanis');
    expect(page).toContain('activePanel === "etki-adayi" && canViewEtkiAdayiRapor');
    expect(page).toContain('activePanel === "serbest-zaman-takip" && canViewSerbestZamanTakip');
    expect(page).toContain('activePanel === "maas-hesaplama" && canViewMaasHesaplama');
    expect(page).toContain('activePanel === "bordro-hazirlik" && canViewBordroHazirlik');
    expect(page).not.toContain("<DonemKapanisMerkeziPage /><EtkiAdayiRaporuPage");
  });
});
