import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import type { KayitTab } from "../../../components/main-menu/MainMenu";
import {
  KAYIT_SUREC_PERSONEL_FORM_ID,
  KAYIT_SUREC_SUREC_FORM_ID
} from "../kayit-surec-constants";
import { resolveKayitModalRouteConfig } from "../kayit-modal-contract";

export function useKayitModalController(pathname: string, locationState: unknown) {
  const navigate = useNavigate();
  const [isKayitModalOpen, setIsKayitModalOpen] = useState(false);
  const [kayitTab, setKayitTab] = useState<KayitTab>("yeni-kayit");
  const [kayitInitialSurecPersonelId, setKayitInitialSurecPersonelId] = useState<string | null>(null);

  const kayitRouteConfig = useMemo(() => resolveKayitModalRouteConfig(locationState), [locationState]);

  useEffect(() => {
    if (!kayitRouteConfig) {
      return;
    }

    setKayitTab(kayitRouteConfig.tab);
    setKayitInitialSurecPersonelId(kayitRouteConfig.personelId);
    setIsKayitModalOpen(true);
    navigate(pathname, { replace: true, state: null });
  }, [kayitRouteConfig, navigate, pathname]);

  const resetKayitEntryContext = useCallback(() => {
    setKayitInitialSurecPersonelId(null);
  }, []);

  const closeKayitModal = useCallback(() => {
    setIsKayitModalOpen(false);
    resetKayitEntryContext();
  }, [resetKayitEntryContext]);

  const openKayitModal = useCallback(
    (tab: KayitTab) => {
      setKayitTab(tab);
      resetKayitEntryContext();
      setIsKayitModalOpen(true);
    },
    [resetKayitEntryContext]
  );

  const kayitPrimaryLabel = kayitTab === "yeni-kayit" ? "Kaydet" : "Süreci Kaydet";
  const kayitPrimaryFormId =
    kayitTab === "yeni-kayit" ? KAYIT_SUREC_PERSONEL_FORM_ID : KAYIT_SUREC_SUREC_FORM_ID;

  return {
    isKayitModalOpen,
    kayitTab,
    setKayitTab,
    kayitInitialSurecPersonelId,
    kayitPrimaryLabel,
    kayitPrimaryFormId,
    openKayitModal,
    closeKayitModal
  };
}
