import { useEffect, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { SUBE_DETAIL_REDIRECT_STATE_KEY } from "../lib/detail-sube-context";

/** Detay sayfasindan listeye yonlendirmede location.state mesajini okur ve URL state temizler. */
export function useSubeDetailListFlash(): { flash: string | null; dismiss: () => void } {
  const location = useLocation();
  const navigate = useNavigate();
  const [flash, setFlash] = useState<string | null>(null);

  useEffect(() => {
    const raw = location.state as Record<string, unknown> | null | undefined;
    const msg = raw?.[SUBE_DETAIL_REDIRECT_STATE_KEY];
    if (typeof msg === "string" && msg.trim()) {
      setFlash(msg);
      navigate(location.pathname, { replace: true, state: {} });
    }
  }, [location.state, location.pathname, navigate]);

  const dismiss = () => setFlash(null);

  return { flash, dismiss };
}
