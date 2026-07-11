import { useCallback, useEffect, useRef, useState } from "react";
import { getApiErrorMessage } from "../api/api-client";
import {
  approveAylikBildirimOnayi,
  fetchAylikBildirimOnayiOzet
} from "../api/aylik-bildirim-onaylari.api";
import {
  getCurrentMonthValue,
  isValidAyValue
} from "../lib/bildirim/aylik-bildirim-onay";
import type { AylikBildirimOnayOzet } from "../types/aylik-bildirim-onay";

type UseAylikBildirimOnayOptions = {
  enabled?: boolean;
  subeId?: number | null;
  birimAmiriUserId?: number | null;
  onApproved?: () => void | Promise<void>;
};

export function useAylikBildirimOnay(options: UseAylikBildirimOnayOptions = {}) {
  const { enabled = true, subeId = null, birimAmiriUserId = null, onApproved } = options;
  const [ay, setAyState] = useState(getCurrentMonthValue);
  const [ozet, setOzet] = useState<AylikBildirimOnayOzet | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ayWarning, setAyWarning] = useState<string | null>(null);
  const [isApproving, setIsApproving] = useState(false);
  const requestIdRef = useRef(0);

  const refreshOzet = useCallback(async () => {
    if (!enabled || !isValidAyValue(ay)) {
      return;
    }

    setIsLoading(true);
    setError(null);
    const requestId = ++requestIdRef.current;

    try {
      const data = await fetchAylikBildirimOnayiOzet(ay, { subeId, birimAmiriUserId });
      if (requestId === requestIdRef.current) {
        setOzet(data);
      }
    } catch (caught) {
      if (requestId === requestIdRef.current) {
        setOzet(null);
        setError(getApiErrorMessage(caught, "Aylik bildirim onayi ozeti yuklenemedi."));
      }
    } finally {
      if (requestId === requestIdRef.current) {
        setIsLoading(false);
      }
    }
  }, [ay, birimAmiriUserId, enabled, subeId]);

  useEffect(() => {
    if (!enabled) {
      requestIdRef.current += 1;
      setOzet(null);
      setError(null);
      setIsLoading(false);
      return;
    }

    if (!isValidAyValue(ay)) {
      setAyWarning("Ay YYYY-MM formatinda olmalidir.");
      setOzet(null);
      setError(null);
      return;
    }

    setAyWarning(null);
    void refreshOzet();
  }, [ay, enabled, refreshOzet]);

  const setAy = useCallback((value: string) => {
    setAyState(value);
    if (value && !isValidAyValue(value)) {
      setAyWarning("Ay YYYY-MM formatinda olmalidir.");
    } else {
      setAyWarning(null);
    }
  }, []);

  const approveMonth = useCallback(async () => {
    if (!enabled || isApproving || !isValidAyValue(ay)) {
      return;
    }

    if (ozet?.mevcut_onay_id || !ozet?.onaylanabilir_mi) {
      return;
    }

    setIsApproving(true);
    setError(null);

    try {
      await approveAylikBildirimOnayi({ ay });
      await refreshOzet();
      await onApproved?.();
    } catch (caught) {
      setError(getApiErrorMessage(caught, "Ay onaya gonderilemedi."));
    } finally {
      setIsApproving(false);
    }
  }, [ay, enabled, isApproving, onApproved, ozet, refreshOzet]);

  return {
    ay,
    setAy,
    ozet,
    isLoading,
    error,
    ayWarning,
    refreshOzet,
    approveMonth,
    isApproving
  };
}
