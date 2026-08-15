import { describe, expect, it } from "vitest";
import {
  RAPORLAR_GROUP_LABELS,
  RAPORLAR_LEGACY_FLAT_LABEL,
  RAPORLAR_NAV_ITEMS,
  RAPORLAR_PANEL_IDS,
  buildRaporlarNavHref,
  buildVisibleRaporlarNavGroups,
  parseRaporlarPanel,
  parseRaporlarStandartView,
  resolveRaporlarSurface,
  resolveRaporlarSurfaceFromSearch,
  type RaporlarNavVisibility
} from "../../src/features/raporlar/raporlar-ia";

const FULL_VISIBILITY: RaporlarNavVisibility = {
  canViewListe: true,
  canViewAylikOzet: true,
  canViewDonemKapanis: true,
  canViewEtkiAdayiRapor: true,
  canViewSerbestZamanTakip: true,
  canViewMaasHesaplama: true,
  canViewBordroHazirlik: true
};

describe("raporlar-ia", () => {
  it("keeps stable panel query ids", () => {
    expect([...RAPORLAR_PANEL_IDS]).toEqual([
      "standart",
      "donem-kapanis",
      "etki-adayi",
      "maas-hesaplama",
      "bordro-hazirlik",
      "serbest-zaman-takip",
      "qr-giris-cikis"
    ]);
  });

  it("groups navigation into Raporlar / Kapanış / Bordro with preferred labels", () => {
    const groups = buildVisibleRaporlarNavGroups(FULL_VISIBILITY);
    expect(groups.map((group) => group.id)).toEqual(["raporlar", "kapanis", "bordro"]);
    expect(groups.map((group) => group.label)).toEqual([
      RAPORLAR_GROUP_LABELS.raporlar,
      RAPORLAR_GROUP_LABELS.kapanis,
      RAPORLAR_GROUP_LABELS.bordro
    ]);
    expect(groups[0].items.map((item) => item.label)).toEqual([
      "Liste Raporları",
      "Etki Adayı Raporu",
      "Giriş / Çıkış Raporu",
      "Serbest Zaman Takibi"
    ]);
    expect(groups[1].items.map((item) => item.label)).toEqual([
      "Aylık Kapanış Özeti",
      "Dönem Kapanış Merkezi"
    ]);
    expect(groups[2].items.map((item) => item.label)).toEqual([
      "Maaş Hesaplama Merkezi",
      "Bordro Hazırlık Merkezi"
    ]);
    expect(RAPORLAR_NAV_ITEMS.some((item) => item.label === RAPORLAR_LEGACY_FLAT_LABEL)).toBe(false);
  });

  it("defaults /raporlar to Liste Raporları and falls back unknown panel", () => {
    expect(parseRaporlarPanel(null)).toBe("standart");
    expect(parseRaporlarPanel("garbage")).toBe("standart");
    expect(parseRaporlarStandartView(null)).toBe("liste");
    expect(parseRaporlarStandartView("aylik-kapanis")).toBe("aylik-kapanis");
    expect(resolveRaporlarSurface("standart", "liste")).toBe("liste");
    expect(resolveRaporlarSurfaceFromSearch(new URLSearchParams(""))).toBe("liste");
    expect(resolveRaporlarSurfaceFromSearch(new URLSearchParams("panel=garbage"))).toBe("liste");
    expect(
      resolveRaporlarSurfaceFromSearch(new URLSearchParams("view=aylik-kapanis"))
    ).toBe("aylik-kapanis");
    expect(
      resolveRaporlarSurfaceFromSearch(new URLSearchParams("panel=maas-hesaplama"))
    ).toBe("maas-hesaplama");
    expect(
      resolveRaporlarSurfaceFromSearch(new URLSearchParams("panel=serbest-zaman-takip"))
    ).toBe("serbest-zaman-takip");
  });

  it("builds nav hrefs without leaking child params and preserves panel ids", () => {
    expect(buildRaporlarNavHref({ panel: "standart" })).toBe("/raporlar");
    expect(buildRaporlarNavHref({ panel: "standart", view: "liste" })).toBe("/raporlar");
    expect(buildRaporlarNavHref({ panel: "standart", view: "aylik-kapanis" })).toBe(
      "/raporlar?view=aylik-kapanis"
    );
    expect(buildRaporlarNavHref({ panel: "donem-kapanis" })).toBe("/raporlar?panel=donem-kapanis");
    expect(buildRaporlarNavHref({ panel: "etki-adayi" })).toBe("/raporlar?panel=etki-adayi");
    expect(buildRaporlarNavHref({ panel: "serbest-zaman-takip" })).toBe(
      "/raporlar?panel=serbest-zaman-takip"
    );
    expect(buildRaporlarNavHref({ panel: "maas-hesaplama" })).toBe("/raporlar?panel=maas-hesaplama");
    expect(buildRaporlarNavHref({ panel: "bordro-hazirlik" })).toBe(
      "/raporlar?panel=bordro-hazirlik"
    );
  });

  it("filters nav by permissions and omits empty groups", () => {
    const groups = buildVisibleRaporlarNavGroups({
      canViewListe: true,
      canViewAylikOzet: false,
      canViewDonemKapanis: false,
      canViewEtkiAdayiRapor: true,
      canViewSerbestZamanTakip: true,
      canViewMaasHesaplama: false,
      canViewBordroHazirlik: false
    });
    expect(groups.map((group) => group.id)).toEqual(["raporlar"]);
    expect(groups[0].items.map((item) => item.id)).toEqual([
      "liste",
      "etki-adayi",
      "qr-giris-cikis",
      "serbest-zaman-takip"
    ]);
  });

  it("preserves bordro kapsam deep-link panel contract", () => {
    const params = new URLSearchParams(
      "panel=bordro-hazirlik&tab=personel-kapsam&personelId=1&subeId=1"
    );
    expect(parseRaporlarPanel(params.get("panel"))).toBe("bordro-hazirlik");
    expect(resolveRaporlarSurfaceFromSearch(params)).toBe("bordro-hazirlik");
    expect(params.get("tab")).toBe("personel-kapsam");
    expect(params.get("personelId")).toBe("1");
  });
});
