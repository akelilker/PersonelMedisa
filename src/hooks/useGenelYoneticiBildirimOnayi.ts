import { useCallback, useEffect, useRef, useState } from "react";
import {
  approveGenelYoneticiBildirimOnayi,
  fetchGenelYoneticiBildirimOnayiOzet
} from "../api/genel-yonetici-bildirim-onaylari.api";
import { getApiErrorDetail } from "../api/api-client";
import { isValidAyValue } from "../lib/bildirim/aylik-bildirim-onay";
import type { GenelYoneticiBildirimOnayiOzet } from "../types/genel-yonetici-bildirim-onayi";

type UseGenelYoneticiBildirimOnayiOptions = {
  canView: boolean;
  canApprove: boolean;
  ay: string;
  subeId: number | null;
  birimAmiriUserId: number | null;
};

export function useGenelYoneticiBildirimOnayi({
  canView,
  canApprove,
  ay,
  subeId,
  birimAmiriUserId
}: UseGenelYoneticiBildirimOnayiOptions) {
  const [ozet, setOzet] = useState<GenelYoneticiBildirimOnayiOzet | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isApproving, setIsApproving] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const requestIdRef = useRef(0);
  const approvingRef = useRef(false);

  const contextReady = Boolean(
    canView &&
      isValidAyValue(ay) &&
      typeof subeId === "number" &&
      subeId > 0 &&
      typeof birimAmiriUserId === "number" &&
      birimAmiriUserId > 0
  );

  const refreshOzet = useCallback(async () => {
    if (!contextReady || subeId === null || birimAmiriUserId === null) return;

    const requestId = ++requestIdRef.current;
    setIsLoading(true);
    setError(null);

    try {
      const data = await fetchGenelYoneticiBildirimOnayiOzet(
        ay,
        subeId,
        birimAmiriUserId
      );
      if (requestId === requestIdRef.current) setOzet(data);
    } catch {
      if (requestId === requestIdRef.current) {
        setOzet(null);
        setError("Genel Yönetici bildirim onayı özeti yüklenemedi.");
      }
    } finally {
      if (requestId === requestIdRef.current) setIsLoading(false);
    }
  }, [ay, birimAmiriUserId, contextReady, subeId]);

  useEffect(() => {
    requestIdRef.current += 1;
    setOzet(null);
    setError(null);
    setSuccessMessage(null);
    setIsLoading(false);
    if (contextReady) void refreshOzet();
  }, [contextReady, refreshOzet]);

  const approve = useCallback(async () => {
    if (
      approvingRef.current ||
      !canApprove ||
      !contextReady ||
      subeId === null ||
      birimAmiriUserId === null ||
      !ozet?.onay_verilebilir_mi ||
      ozet.genel_yonetici_bildirim_onayi
    ) {
      return;
    }

    approvingRef.current = true;
    setIsApproving(true);
    setError(null);
    setSuccessMessage(null);

    try {
      await approveGenelYoneticiBildirimOnayi({ ay, sube_id: subeId, birim_amiri_user_id: birimAmiriUserId });
      await refreshOzet();
      setSuccessMessage("Genel Yönetici bildirim onayı tamamlandı.");
    } catch (caught) {
      const detail = getApiErrorDetail(caught, "Genel Yönetici bildirim onayı tamamlanamadı.");
      if (detail.status === 409) {
        await refreshOzet();
      } else {
        setError("Genel Yönetici bildirim onayı tamamlanamadı.");
      }
    } finally {
      approvingRef.current = false;
      setIsApproving(false);
    }
  }, [ay, birimAmiriUserId, canApprove, contextReady, ozet, refreshOzet, subeId]);

  return {
    ozet,
    isLoading,
    error,
    successMessage,
    refreshOzet,
    approve,
    isApproving,
    contextReady
  };
}
