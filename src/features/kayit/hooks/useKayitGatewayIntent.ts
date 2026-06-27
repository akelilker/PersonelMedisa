import { useMemo } from "react";
import { useNavigate } from "react-router-dom";
import type { KayitModalIntent } from "../kayit-modal-contract";

type UseKayitGatewayIntentArgs = {
  activeTab: "yeni-kayit" | "surec";
  initialIntent?: KayitModalIntent | null;
  initialReturnTo?: string | null;
};

export function useKayitGatewayIntent({ activeTab, initialIntent, initialReturnTo }: UseKayitGatewayIntentArgs) {
  const navigate = useNavigate();

  const showGatewayMessage = useMemo(
    () =>
      activeTab === "yeni-kayit" &&
      (initialIntent === "personel-edit-gateway" || initialIntent === "personel-zimmet-gateway") &&
      typeof initialReturnTo === "string" &&
      initialReturnTo.length > 0,
    [activeTab, initialIntent, initialReturnTo]
  );

  const gatewayActionLabel =
    initialIntent === "personel-zimmet-gateway"
      ? "Personel Kartına dön ve zimmet ekle"
      : "Personel Kartına dön ve düzenle";

  const gatewayInfoMessage =
    initialIntent === "personel-zimmet-gateway"
      ? "Zimmet işlemi merkez ekrana taşınıyor. Bu geçişte zimmet formu personel kartında çalışmaya devam eder."
      : "Kart düzenleme işlemi merkez ekrana taşınıyor. Bu geçişte düzenleme formu personel kartında çalışmaya devam eder.";

  const handleGatewayReturn = () => {
    if (!initialReturnTo) {
      return;
    }

    navigate(initialReturnTo, {
      state:
        initialIntent === "personel-zimmet-gateway"
          ? { openPersonelZimmet: true }
          : { openPersonelEdit: true }
    });
  };

  return {
    showGatewayMessage,
    gatewayActionLabel,
    gatewayInfoMessage,
    handleGatewayReturn
  };
}
