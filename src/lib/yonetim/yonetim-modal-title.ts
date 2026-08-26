export type YonetimPanelTab = "kullanicilar" | "subeler" | "mevzuat" | "saklama";

const YONETIM_MODAL_TITLES: Record<YonetimPanelTab, string> = {
  kullanicilar: "KULLANICI YÖNETİMİ",
  subeler: "ŞUBE YÖNETİMİ",
  mevzuat: "MEVZUAT PARAMETRELERİ",
  saklama: "SAKLAMA VE İMHA YÖNETİMİ"
};

export function resolveYonetimPanelTab(tabParam: string | null | undefined): YonetimPanelTab {
  const normalized = tabParam?.trim().toLowerCase() ?? "";
  if (normalized === "subeler" || normalized === "sube") {
    return "subeler";
  }
  if (normalized === "mevzuat") {
    return "mevzuat";
  }
  if (normalized === "saklama" || normalized === "legal-hold" || normalized === "retention") {
    return "saklama";
  }
  return "kullanicilar";
}

export function resolveYonetimModalTitle(tabParam: string | null | undefined): string {
  return YONETIM_MODAL_TITLES[resolveYonetimPanelTab(tabParam)];
}
