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
          personelId: parsedPersonelId
        }
      }
    });
  }, [navigate, parsedPersonelId]);

  return {
    handleOpenSurecModal
  };
}
