import type { KayitTab } from "../../components/main-menu/MainMenu";
import { PERSONEL_SUREC_TABS, type PersonelSurecTab } from "./kayit-surec-constants";

export type KayitModalRouteConfig = {
  tab: KayitTab;
  personelId: string | null;
  targetTab: PersonelSurecTab | null;
  personelTab: PersonelSurecTab | null;
  intent: string | null;
  recordId: string | null;
  returnTo: string | null;
  operation: "yillik-izin-hak-duzeltme" | null;
};

export function resolveKayitModalRouteConfig(state: unknown): KayitModalRouteConfig | null {
  if (state === null || typeof state !== "object") {
    return null;
  }

  const kayitModal = (state as { kayitModal?: unknown }).kayitModal;
  if (kayitModal === null || typeof kayitModal !== "object") {
    return null;
  }

  const rawTab = (kayitModal as { tab?: unknown }).tab;
  const rawPersonelId = (kayitModal as { personelId?: unknown }).personelId;
  const rawTargetTab = (kayitModal as { targetTab?: unknown }).targetTab;
  const rawPersonelTab = (kayitModal as { personelTab?: unknown }).personelTab;
  const rawIntent = (kayitModal as { intent?: unknown }).intent;
  const rawRecordId = (kayitModal as { recordId?: unknown }).recordId;
  const rawReturnTo = (kayitModal as { returnTo?: unknown }).returnTo;
  const rawOperation = (kayitModal as { operation?: unknown }).operation;
  const targetTab = PERSONEL_SUREC_TABS.some((tab) => tab.id === rawTargetTab)
    ? (rawTargetTab as PersonelSurecTab)
    : PERSONEL_SUREC_TABS.some((tab) => tab.id === rawPersonelTab)
      ? (rawPersonelTab as PersonelSurecTab)
      : null;

  return {
    tab: rawTab === "surec" ? "surec" : "yeni-kayit",
    personelId: rawPersonelId === undefined || rawPersonelId === null ? null : String(rawPersonelId),
    targetTab,
    personelTab: targetTab,
    intent: typeof rawIntent === "string" ? rawIntent : null,
    recordId: rawRecordId === undefined || rawRecordId === null ? null : String(rawRecordId),
    returnTo: typeof rawReturnTo === "string" ? rawReturnTo : null,
    operation: rawOperation === "yillik-izin-hak-duzeltme" ? "yillik-izin-hak-duzeltme" : null
  };
}
