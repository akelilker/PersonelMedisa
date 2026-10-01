import { useCallback } from "react";
import { useLocation, type NavigateFunction } from "react-router-dom";

export function usePersonelKartGatewayReturn({
  navigate,
  parsedPersonelId
}: {
  navigate: NavigateFunction;
  parsedPersonelId: number;
}) {
  const location = useLocation();
  const kartReturnPath = `/personeller/${parsedPersonelId}`;
  const overlayPath = `${location.pathname}${location.search}`;

  const handleOpenSurecModal = useCallback(() => {
    navigate(overlayPath, {
      state: {
        kayitModal: {
          tab: "surec",
          personelId: parsedPersonelId,
          targetTab: "puantaj",
          intent: "personel-surec-gateway",
          returnTo: kartReturnPath
        }
      }
    });
  }, [navigate, overlayPath, parsedPersonelId, kartReturnPath]);

  const handleOpenMissingInfo = useCallback((targetTab: "genel" | "pozisyon" = "genel") => {
    navigate(overlayPath, {
      state: {
        kayitModal: {
          tab: "surec",
          personelId: parsedPersonelId,
          targetTab,
          intent: "personel-missing-info-gateway",
          returnTo: kartReturnPath
        }
      }
    });
  }, [navigate, overlayPath, parsedPersonelId, kartReturnPath]);

  const handleOpenYillikIzinHakDuzeltme = useCallback(() => {
    navigate(overlayPath, {
      state: {
        kayitModal: {
          tab: "surec",
          personelId: parsedPersonelId,
          targetTab: "puantaj",
          intent: "yillik-izin-hak-duzeltme-gateway",
          operation: "yillik-izin-hak-duzeltme"
        }
      }
    });
  }, [navigate, overlayPath, parsedPersonelId]);

  return {
    handleOpenSurecModal,
    handleOpenMissingInfo,
    handleOpenYillikIzinHakDuzeltme
  };
}
