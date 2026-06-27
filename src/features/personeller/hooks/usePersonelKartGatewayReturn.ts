import { useCallback, useEffect } from "react";
import type { Location, NavigateFunction } from "react-router-dom";
import type { PersonelDosyaTabId } from "../components/personel-dosya";

type PersonelKartRouteState = {
  openPersonelEdit?: boolean;
  openPersonelZimmet?: boolean;
} | null;

export function usePersonelKartGatewayReturn({
  location,
  navigate,
  parsedPersonelId,
  canEditPersonel,
  canCreateZimmet,
  setActiveTab,
  setIsEditing,
  openZimmetModal
}: {
  location: Location;
  navigate: NavigateFunction;
  parsedPersonelId: number;
  canEditPersonel: boolean;
  canCreateZimmet: boolean;
  setActiveTab: (tab: PersonelDosyaTabId) => void;
  setIsEditing: (value: boolean) => void;
  openZimmetModal: () => void;
}) {
  useEffect(() => {
    const routeState = location.state as PersonelKartRouteState;
    if (!routeState?.openPersonelEdit || !canEditPersonel) {
      return;
    }

    setActiveTab("genel-bilgiler");
    setIsEditing(true);
    navigate(location.pathname, { replace: true, state: null });
  }, [canEditPersonel, location.pathname, location.state, navigate, setActiveTab, setIsEditing]);

  useEffect(() => {
    const routeState = location.state as PersonelKartRouteState;
    if (!routeState?.openPersonelZimmet || !canCreateZimmet) {
      return;
    }

    setActiveTab("zimmet-envanter");
    openZimmetModal();
    navigate(location.pathname, { replace: true, state: null });
  }, [
    canCreateZimmet,
    location.pathname,
    location.state,
    navigate,
    openZimmetModal,
    setActiveTab
  ]);

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

  const handleOpenPersonelEditGateway = useCallback(() => {
    navigate("/", {
      state: {
        kayitModal: {
          tab: "yeni-kayit",
          personelId: parsedPersonelId,
          intent: "personel-edit-gateway",
          returnTo: `/personeller/${parsedPersonelId}`
        }
      }
    });
  }, [navigate, parsedPersonelId]);

  const handleOpenPersonelZimmetGateway = useCallback(() => {
    navigate("/", {
      state: {
        kayitModal: {
          tab: "yeni-kayit",
          personelId: parsedPersonelId,
          intent: "personel-zimmet-gateway",
          returnTo: `/personeller/${parsedPersonelId}`
        }
      }
    });
  }, [navigate, parsedPersonelId]);

  return {
    handleOpenSurecModal,
    handleOpenPersonelEditGateway,
    handleOpenPersonelZimmetGateway
  };
}
