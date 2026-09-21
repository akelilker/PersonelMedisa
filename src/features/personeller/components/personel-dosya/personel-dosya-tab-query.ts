import { PERSONEL_DOSYA_TABS, type PersonelDosyaTabId } from "./PersonelDosyaTabs";

export function resolvePersonelTab(raw: string | null): PersonelDosyaTabId | null {
  if (!raw) return null;
  if (raw === "genel" || raw === "ucret") return "genel-bilgiler";
  const match = PERSONEL_DOSYA_TABS.find((tab) => tab.id === raw);
  return match ? match.id : null;
}

export function personelTabQueryValue(tabId: PersonelDosyaTabId): string {
  return tabId;
}
