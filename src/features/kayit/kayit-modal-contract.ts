import type { KayitTab } from "../../components/main-menu/MainMenu";

export type KayitModalIntent = "personel-edit-gateway" | "personel-zimmet-gateway";

export type KayitModalRouteConfig = {
  tab: KayitTab;
  personelId: string | null;
  intent: KayitModalIntent | null;
  returnTo: string | null;
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
  const rawIntent = (kayitModal as { intent?: unknown }).intent;
  const rawReturnTo = (kayitModal as { returnTo?: unknown }).returnTo;

  return {
    tab: rawTab === "surec" ? "surec" : "yeni-kayit",
    personelId: rawPersonelId === undefined || rawPersonelId === null ? null : String(rawPersonelId),
    intent:
      rawIntent === "personel-edit-gateway" || rawIntent === "personel-zimmet-gateway" ? rawIntent : null,
    returnTo: typeof rawReturnTo === "string" && rawReturnTo.trim() ? rawReturnTo.trim() : null
  };
}
