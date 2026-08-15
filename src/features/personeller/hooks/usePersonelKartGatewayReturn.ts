import { useCallback } from "react";
import type { NavigateFunction } from "react-router-dom";

export function usePersonelKartGatewayReturn({
  navigate,
  parsedPersonelId
}: {
  navigate: NavigateFunction;
  parsedPersonelId: number;
}) {
  const handleOpenSurecModal = useCallback(() => {
    navigate("/", {
      state: {
        kayitModal: {
          tab: "surec",
          personelId: parsedPersonelId,
          targetTab: "izin-devamsizlik",
          intent: "personel-surec-gateway",
          returnTo: `/personeller/${parsedPersonelId}`
        }
      }
    });
  }, [navigate, parsedPersonelId]);

  const handleOpenMissingInfo = useCallback((targetTab: "genel" | "pozisyon" = "genel") => {
    navigate("/", {
      state: {
        kayitModal: {
          tab: "surec",
          personelId: parsedPersonelId,
          targetTab,
          intent: "personel-missing-info-gateway",
          returnTo: `/personeller/${parsedPersonelId}`
        }
      }
    });
  }, [navigate, parsedPersonelId]);

  const handleOpenYillikIzinHakDuzeltme = useCallback(() => {
    navigate("/", {
      state: {
        kayitModal: {
          tab: "surec",
          personelId: parsedPersonelId,
          targetTab: "izin-devamsizlik",
          intent: "yillik-izin-hak-duzeltme-gateway",
          operation: "yillik-izin-hak-duzeltme"
        }
      }
    });
  }, [navigate, parsedPersonelId]);

  return {
    handleOpenSurecModal,
    handleOpenMissingInfo,
    handleOpenYillikIzinHakDuzeltme
  };
}
