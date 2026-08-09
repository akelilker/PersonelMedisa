import { useEffect, useMemo } from "react";
import type { KayitModalIntent } from "../kayit-modal-contract";
import type { PersonelSurecTab } from "../kayit-surec-constants";

type UseKayitGatewayIntentArgs = {
  activeTab: "yeni-kayit" | "surec";
  initialIntent?: KayitModalIntent | null;
  onSelectPersonelTab?: (tab: PersonelSurecTab) => void;
};

/**
 * Stale legacy intents (personel-edit/zimmet-gateway) must land in Süreç with the
 * correct personel tab — no bounce-back-to-card write theater.
 */
export function useKayitGatewayIntent({
  activeTab,
  initialIntent,
  onSelectPersonelTab
}: UseKayitGatewayIntentArgs) {
  const legacyPersonelTab = useMemo((): PersonelSurecTab | null => {
    if (initialIntent === "personel-zimmet-gateway") {
      return "zimmet";
    }
    if (initialIntent === "personel-edit-gateway") {
      return "genel";
    }
    return null;
  }, [initialIntent]);

  useEffect(() => {
    if (activeTab !== "surec" || !legacyPersonelTab || !onSelectPersonelTab) {
      return;
    }
    onSelectPersonelTab(legacyPersonelTab);
  }, [activeTab, legacyPersonelTab, onSelectPersonelTab]);

  return {
    legacyPersonelTab,
    showGatewayMessage: false as const,
    gatewayActionLabel: "",
    gatewayInfoMessage: "",
    handleGatewayReturn: () => undefined
  };
}
