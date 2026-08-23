import type { PersonelSurecTab } from "./kayit-surec-constants";
import { normalizePersonelSurecTab } from "./kayit-surec-constants";

export type KayitSurecReturnContext = {
  personelId: number;
  personelTab: PersonelSurecTab;
};

/** Location state for reopening Kayıt ve Süreç after a full-route detour. */
export function buildKayitSurecReturnState(context: KayitSurecReturnContext) {
  return {
    kayitModal: {
      tab: "surec" as const,
      personelId: context.personelId,
      targetTab: normalizePersonelSurecTab(context.personelTab)
    }
  };
}

export function buildKayitSurecRouteState(
  personelId: number,
  personelTab: PersonelSurecTab,
  extra?: Record<string, unknown>
) {
  return {
    ...extra,
    prefillPersonelId: personelId,
    kayitSurecReturn: {
      personelId,
      personelTab: normalizePersonelSurecTab(personelTab)
    } satisfies KayitSurecReturnContext
  };
}
