import { useCallback, useEffect, useMemo, useState } from "react";
import { getApiErrorMessage } from "../api/api-client";
import {
  approveHaftalikBildirimMutabakat,
  fetchHaftalikBildirimMutabakatOzet
} from "../api/haftalik-bildirim-mutabakatlari.api";
import {
  computeHaftaBitisFromMonday,
  getCurrentMondayIsoDate,
  isMondayIsoDate
} from "../lib/bildirim/haftalik-mutabakat";
import type { HaftalikBildirimMutabakatOzet } from "../types/haftalik-bildirim-mutabakat";

type UseHaftalikBildirimMutabakatOptions = {
  enabled?: boolean;
  onApproved?: () => void | Promise<void>;
};

export function useHaftalikBildirimMutabakat(options: UseHaftalikBildirimMutabakatOptions = {}) {
  const { enabled = true, onApproved } = options;
  const [haftaBaslangic, setHaftaBaslangicState] = useState(getCurrentMondayIsoDate);
  const [ozet, setOzet] = useState<HaftalikBildirimMutabakatOzet | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [weekWarning, setWeekWarning] = useState<string | null>(null);
  const [isApproving, setIsApproving] = useState(false);

  const haftaBitis = useMemo(() => {
    if (ozet?.hafta_bitis) {
      return ozet.hafta_bitis;
    }
    return computeHaftaBitisFromMonday(haftaBaslangic);
  }, [haftaBaslangic, ozet?.hafta_bitis]);

  const refreshOzet = useCallback(async () => {
    if (!enabled || !isMondayIsoDate(haftaBaslangic)) {
      return;
    }

    setIsLoading(true);
    setError(null);

    try {
      const data = await fetchHaftalikBildirimMutabakatOzet(haftaBaslangic);
      setOzet(data);
    } catch (caught) {
      setOzet(null);
      setError(getApiErrorMessage(caught, "Haftalik mutabakat ozeti yuklenemedi."));
    } finally {
      setIsLoading(false);
    }
  }, [enabled, haftaBaslangic]);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    if (!isMondayIsoDate(haftaBaslangic)) {
      setWeekWarning("Hafta baslangici Pazartesi olmalidir.");
      setOzet(null);
      setError(null);
      return;
    }

    setWeekWarning(null);
    void refreshOzet();
  }, [enabled, haftaBaslangic, refreshOzet]);

  const setHaftaBaslangic = useCallback((value: string) => {
    setHaftaBaslangicState(value);
    if (value && !isMondayIsoDate(value)) {
      setWeekWarning("Hafta baslangici Pazartesi olmalidir.");
    } else {
      setWeekWarning(null);
    }
  }, []);

  const approveWeek = useCallback(async () => {
    if (!enabled || isApproving || !isMondayIsoDate(haftaBaslangic)) {
      return;
    }

    setIsApproving(true);
    setError(null);

    try {
      await approveHaftalikBildirimMutabakat(haftaBaslangic);
      await refreshOzet();
      await onApproved?.();
    } catch (caught) {
      setError(getApiErrorMessage(caught, "Hafta onaylanamadi."));
    } finally {
      setIsApproving(false);
    }
  }, [enabled, haftaBaslangic, isApproving, onApproved, refreshOzet]);

  return {
    haftaBaslangic,
    setHaftaBaslangic,
    haftaBitis,
    ozet,
    isLoading,
    error,
    weekWarning,
    refreshOzet,
    approveWeek,
    isApproving
  };
}
