import { useCallback, useEffect, useRef, useState } from "react";
import { getApiErrorDetail } from "../api/api-client";
import {
  fetchMaasHesaplamaAudits,
  fetchMaasHesaplamaPreflight,
  fetchMaasHesaplamaSnapshots,
  type MaasHesaplamaAudit,
  type MaasHesaplamaParams,
  type MaasHesaplamaPreflight,
  type MaasHesaplamaSnapshot
} from "../api/maas-hesaplama.api";

export type MaasHesaplamaFilterState = {
  ay: string;
  subeId: string;
};

type UseMaasHesaplamaOptions = {
  enabled: boolean;
  filters: MaasHesaplamaFilterState;
  yil: number;
  ay: number;
  subeId: number | null;
};

export function useMaasHesaplama(options: UseMaasHesaplamaOptions) {
  const { enabled, filters, yil, ay, subeId } = options;
  const [preflight, setPreflight] = useState<MaasHesaplamaPreflight | null>(null);
  const [snapshots, setSnapshots] = useState<MaasHesaplamaSnapshot[]>([]);
  const [audits, setAudits] = useState<MaasHesaplamaAudit[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const requestIdRef = useRef(0);

  const buildParams = useCallback((): MaasHesaplamaParams | null => {
    if (!subeId) {
      return null;
    }
    return { sube_id: subeId, yil, ay };
  }, [subeId, yil, ay]);

  const refetch = useCallback(async () => {
    if (!enabled) {
      return;
    }
    const params = buildParams();
    if (!params) {
      setPreflight(null);
      setSnapshots([]);
      setAudits([]);
      setErrorMessage("Maaş hesaplama için şube seçilmelidir.");
      return;
    }

    const requestId = ++requestIdRef.current;
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const [nextPreflight, nextSnapshots, nextAudits] = await Promise.all([
        fetchMaasHesaplamaPreflight(params),
        fetchMaasHesaplamaSnapshots(params),
        fetchMaasHesaplamaAudits(params)
      ]);
      if (requestId !== requestIdRef.current) {
        return;
      }
      setPreflight(nextPreflight);
      setSnapshots(nextSnapshots);
      setAudits(nextAudits);
    } catch (caught) {
      if (requestId !== requestIdRef.current) {
        return;
      }
      setPreflight(null);
      setSnapshots([]);
      setAudits([]);
      setErrorMessage(getApiErrorDetail(caught, "Maaş hesaplama verisi alınamadı.").message);
    } finally {
      if (requestId === requestIdRef.current) {
        setIsLoading(false);
      }
    }
  }, [enabled, buildParams]);

  useEffect(() => {
    if (!enabled || !filters.subeId) {
      return;
    }
    void refetch();
  }, [enabled, filters.ay, filters.subeId, refetch]);

  return {
    preflight,
    snapshots,
    audits,
    isLoading,
    errorMessage,
    buildParams,
    refetch
  };
}
