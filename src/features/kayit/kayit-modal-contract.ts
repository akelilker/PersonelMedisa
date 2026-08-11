import type { KayitTab } from "../../components/main-menu/MainMenu";

export type KayitModalRouteConfig = {
  tab: KayitTab;
  personelId: string | null;
  personelTab: "izin-devamsizlik" | null;
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
  const rawPersonelTab = (kayitModal as { personelTab?: unknown }).personelTab;
  const rawOperation = (kayitModal as { operation?: unknown }).operation;

  return {
    tab: rawTab === "surec" ? "surec" : "yeni-kayit",
    personelId: rawPersonelId === undefined || rawPersonelId === null ? null : String(rawPersonelId),
    personelTab: rawPersonelTab === "izin-devamsizlik" ? "izin-devamsizlik" : null,
    operation: rawOperation === "yillik-izin-hak-duzeltme" ? "yillik-izin-hak-duzeltme" : null
  };
}
