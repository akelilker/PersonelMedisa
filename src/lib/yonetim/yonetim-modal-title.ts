export type YonetimPanelTab = "kullanicilar" | "subeler" | "mevzuat" | "saklama" | "ucret-tipi-envanteri";

const YONETIM_MODAL_TITLES: Record<YonetimPanelTab, string> = {
  kullanicilar: "KULLANICI YÖNETİMİ",
  subeler: "ŞİRKET VE ŞUBE YÖNETİMİ",
  mevzuat: "MEVZUAT PARAMETRELERİ",
  saklama: "SAKLAMA VE İMHA YÖNETİMİ",
  "ucret-tipi-envanteri": "ÜCRET TİPİ ENVANTERİ"
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
  if (normalized === "ucret-tipi-envanteri" || normalized === "ucret-tipi" || normalized === "ucret-envanteri") {
    return "ucret-tipi-envanteri";
  }
  return "kullanicilar";
}

export function resolveYonetimModalTitle(tabParam: string | null | undefined): string {
  return YONETIM_MODAL_TITLES[resolveYonetimPanelTab(tabParam)];
}
