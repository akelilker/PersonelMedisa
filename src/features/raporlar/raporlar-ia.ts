/**
 * Raporlar information-architecture helpers (I11).
 * Panel query IDs are stable contracts; labels/grouping are presentation-only.
 */

export const RAPORLAR_PANEL_IDS = [
  "standart",
  "donem-kapanis",
  "etki-adayi",
  "maas-hesaplama",
  "bordro-hazirlik",
  "serbest-zaman-takip",
  "qr-giris-cikis"
] as const;

export type RaporlarPanel = (typeof RAPORLAR_PANEL_IDS)[number];

export type RaporlarStandartView = "liste" | "aylik-kapanis";

export type RaporlarNavGroupId = "raporlar" | "kapanis" | "bordro";

export type RaporlarSurfaceId =
  | "liste"
  | "aylik-kapanis"
  | "donem-kapanis"
  | "etki-adayi"
  | "maas-hesaplama"
  | "bordro-hazirlik"
  | "serbest-zaman-takip"
  | "qr-giris-cikis";

export type RaporlarNavItemId = RaporlarSurfaceId;

export type RaporlarNavItemDef = {
  id: RaporlarNavItemId;
  group: RaporlarNavGroupId;
  label: string;
  testId: string;
  panel: RaporlarPanel;
  view?: RaporlarStandartView;
};

export const RAPORLAR_GROUP_LABELS: Record<RaporlarNavGroupId, string> = {
  raporlar: "Raporlar",
  kapanis: "Kapanış",
  bordro: "Bordro"
};

export const RAPORLAR_NAV_ITEMS: RaporlarNavItemDef[] = [
  {
    id: "liste",
    group: "raporlar",
    label: "Liste Raporları",
    testId: "raporlar-panel-liste",
    panel: "standart",
    view: "liste"
  },
  {
    id: "etki-adayi",
    group: "raporlar",
    label: "Etki Adayı Raporu",
    testId: "raporlar-panel-etki-adayi",
    panel: "etki-adayi"
  },
  {
    id: "qr-giris-cikis",
    group: "raporlar",
    label: "Giriş / Çıkış Raporu",
    testId: "raporlar-panel-qr-giris-cikis",
    panel: "qr-giris-cikis"
  },
  {
    id: "serbest-zaman-takip",
    group: "raporlar",
    label: "Serbest Zaman Takibi",
    testId: "raporlar-panel-serbest-zaman-takip",
    panel: "serbest-zaman-takip"
  },
  {
    id: "aylik-kapanis",
    group: "kapanis",
    label: "Aylık Kapanış Özeti",
    testId: "raporlar-panel-aylik-kapanis",
    panel: "standart",
    view: "aylik-kapanis"
  },
  {
    id: "donem-kapanis",
    group: "kapanis",
    label: "Dönem Kapanış Merkezi",
    testId: "raporlar-panel-donem-kapanis",
    panel: "donem-kapanis"
  },
  {
    id: "maas-hesaplama",
    group: "bordro",
    label: "Maaş Hesaplama Merkezi",
    testId: "raporlar-panel-maas-hesaplama",
    panel: "maas-hesaplama"
  },
  {
    id: "bordro-hazirlik",
    group: "bordro",
    label: "Bordro Hazırlık Merkezi",
    testId: "raporlar-panel-bordro-hazirlik",
    panel: "bordro-hazirlik"
  }
];

export const RAPORLAR_SURFACE_LEADS: Record<RaporlarSurfaceId, string> = {
  liste: "Filtreleri kullanarak liste raporlarını görüntüleyin.",
  "aylik-kapanis": "Ay sonu puantaj ve onay durumunu inceleyin.",
  "donem-kapanis": "Dönem kapanış ön kontrollerini ve mühür durumunu yönetin.",
  "etki-adayi": "Bildirim etki adayı kayıtlarını inceleyin.",
  "serbest-zaman-takip":
    "Serbest zaman 6 aylık kullanım deadline takibi (operasyonel uyarı; otomatik bordro blokajı yoktur).",
  "maas-hesaplama": "Deterministik maaş hesaplama çalıştırmalarını yönetin.",
  "bordro-hazirlik": "Bordro hazırlık, ön kontrol ve personel kapsamını yönetin.",
  "qr-giris-cikis": "QR giriş/çıkış geçmişini filtreleyin ve inceleyin."
};

/** Legacy flat-nav label that must not appear after I11. */
export const RAPORLAR_LEGACY_FLAT_LABEL = "Liste ve aylık özet";

export function parseRaporlarPanel(value: string | null): RaporlarPanel {
  if (
    value === "donem-kapanis" ||
    value === "etki-adayi" ||
    value === "maas-hesaplama" ||
    value === "bordro-hazirlik" ||
    value === "serbest-zaman-takip"
    || value === "qr-giris-cikis"
  ) {
    return value;
  }
  return "standart";
}

export function parseRaporlarStandartView(value: string | null): RaporlarStandartView {
  if (value === "aylik-kapanis") {
    return "aylik-kapanis";
  }
  return "liste";
}

export function resolveRaporlarSurface(
  panel: RaporlarPanel,
  view: RaporlarStandartView
): RaporlarSurfaceId {
  if (panel === "standart") {
    return view === "aylik-kapanis" ? "aylik-kapanis" : "liste";
  }
  return panel;
}

export function resolveRaporlarSurfaceFromSearch(searchParams: URLSearchParams): RaporlarSurfaceId {
  const panel = parseRaporlarPanel(searchParams.get("panel"));
  const view = parseRaporlarStandartView(searchParams.get("view"));
  return resolveRaporlarSurface(panel, view);
}

export type BuildRaporlarNavHrefInput = {
  panel: RaporlarPanel;
  view?: RaporlarStandartView;
};

/**
 * Top-level panel/view navigation hrefs intentionally drop child-page params
 * (tab, personelId, ay, report prefill, …) so switching surfaces does not leak filters.
 */
export function buildRaporlarNavHref({ panel, view }: BuildRaporlarNavHrefInput): string {
  if (panel === "standart") {
    if (view === "aylik-kapanis") {
      return "/raporlar?view=aylik-kapanis";
    }
    return "/raporlar";
  }
  return `/raporlar?panel=${panel}`;
}

export function buildRaporlarNavHrefForItem(item: RaporlarNavItemDef): string {
  return buildRaporlarNavHref({ panel: item.panel, view: item.view });
}

export function isRaporlarNavItemActive(
  item: RaporlarNavItemDef,
  surface: RaporlarSurfaceId
): boolean {
  return item.id === surface;
}

export type RaporlarNavVisibility = {
  canViewListe: boolean;
  canViewAylikOzet: boolean;
  canViewDonemKapanis: boolean;
  canViewEtkiAdayiRapor: boolean;
  canViewSerbestZamanTakip: boolean;
  canViewMaasHesaplama: boolean;
  canViewBordroHazirlik: boolean;
};

export function isRaporlarNavItemVisible(
  item: RaporlarNavItemDef,
  visibility: RaporlarNavVisibility
): boolean {
  switch (item.id) {
    case "liste":
      return visibility.canViewListe;
    case "aylik-kapanis":
      return visibility.canViewAylikOzet;
    case "donem-kapanis":
      return visibility.canViewDonemKapanis;
    case "etki-adayi":
      return visibility.canViewEtkiAdayiRapor;
    case "serbest-zaman-takip":
      return visibility.canViewSerbestZamanTakip;
    case "maas-hesaplama":
      return visibility.canViewMaasHesaplama;
    case "bordro-hazirlik":
      return visibility.canViewBordroHazirlik;
    case "qr-giris-cikis":
      return visibility.canViewListe;
    default:
      return false;
  }
}

export type RaporlarNavGroup = {
  id: RaporlarNavGroupId;
  label: string;
  items: RaporlarNavItemDef[];
};

export function buildVisibleRaporlarNavGroups(
  visibility: RaporlarNavVisibility
): RaporlarNavGroup[] {
  const groupOrder: RaporlarNavGroupId[] = ["raporlar", "kapanis", "bordro"];
  return groupOrder
    .map((groupId) => {
      const items = RAPORLAR_NAV_ITEMS.filter(
        (item) => item.group === groupId && isRaporlarNavItemVisible(item, visibility)
      );
      return {
        id: groupId,
        label: RAPORLAR_GROUP_LABELS[groupId],
        items
      };
    })
    .filter((group) => group.items.length > 0);
}

export function serbestZamanDeadlineStateLabel(state: string): string {
  switch (state) {
    case "YAKLASIYOR":
      return "Yaklaşan";
    case "SURESI_DOLDU":
      return "Süresi dolmuş";
    case "ALLOCATION_UNRESOLVED":
      return "İnceleme gerekli";
    case "NORMAL":
      return "Normal";
    default:
      return state;
  }
}
