import { useCallback, useEffect, useRef, useState } from "react";
import { getApiErrorDetail } from "../api/api-client";
import {
  fetchMaasHesaplamaAudits,
  fetchMaasHesaplamaCalculationPreflight,
  fetchMaasHesaplamaCalistirmalar,
  fetchMaasHesaplamaDevirler,
  fetchMaasHesaplamaPreflight,
  fetchMaasHesaplamaSnapshots,
  type MaasHesaplamaAudit,
  type MaasHesaplamaCalculationPreflight,
  type MaasHesaplamaCalistirma,
  type MaasHesaplamaDevir,
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
  loadCalculationCandidates?: boolean;
};

export function useMaasHesaplama(options: UseMaasHesaplamaOptions) {
  const { enabled, filters, yil, ay, subeId, loadCalculationCandidates = false } = options;
  const [preflight, setPreflight] = useState<MaasHesaplamaPreflight | null>(null);
  const [snapshots, setSnapshots] = useState<MaasHesaplamaSnapshot[]>([]);
  const [audits, setAudits] = useState<MaasHesaplamaAudit[]>([]);
  const [calculationPreflight, setCalculationPreflight] = useState<MaasHesaplamaCalculationPreflight | null>(null);
  const [calistirmalar, setCalistirmalar] = useState<MaasHesaplamaCalistirma[]>([]);
  const [devirler, setDevirler] = useState<MaasHesaplamaDevir[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [calculationErrorMessage, setCalculationErrorMessage] = useState<string | null>(null);
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
      setCalculationPreflight(null);
      setCalistirmalar([]);
      setDevirler([]);
      setErrorMessage("Maaş hesaplama için şube seçilmelidir.");
      setCalculationErrorMessage(null);
      return;
    }

    const requestId = ++requestIdRef.current;
    setIsLoading(true);
    setErrorMessage(null);
    setCalculationErrorMessage(null);
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
      const activeSnapshot = nextSnapshots.find((item) => item.state === "OLUSTURULDU") ?? null;
      if (!loadCalculationCandidates || !activeSnapshot) {
        setCalculationPreflight(null);
        setCalistirmalar([]);
        setDevirler([]);
        return;
      }

      try {
        const [nextCalculationPreflight, nextCalistirmalar, nextDevirler] = await Promise.all([
          fetchMaasHesaplamaCalculationPreflight(activeSnapshot.id),
          fetchMaasHesaplamaCalistirmalar(params),
          fetchMaasHesaplamaDevirler(params)
        ]);
        if (requestId !== requestIdRef.current) {
          return;
        }
        setCalculationPreflight(nextCalculationPreflight);
        setCalistirmalar(nextCalistirmalar);
        setDevirler(nextDevirler);
      } catch (caught) {
        if (requestId !== requestIdRef.current) {
          return;
        }
        setCalculationPreflight(null);
        setCalistirmalar([]);
        setDevirler([]);
        setCalculationErrorMessage(
          getApiErrorDetail(caught, "Maaş hesaplama aday/devir verisi alınamadı.").message
        );
      }
    } catch (caught) {
      if (requestId !== requestIdRef.current) {
        return;
      }
      setPreflight(null);
      setSnapshots([]);
      setAudits([]);
      setCalculationPreflight(null);
      setCalistirmalar([]);
      setDevirler([]);
      setErrorMessage(getApiErrorDetail(caught, "Maaş hesaplama verisi alınamadı.").message);
    } finally {
      if (requestId === requestIdRef.current) {
        setIsLoading(false);
      }
    }
  }, [enabled, buildParams, loadCalculationCandidates]);

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
    calculationPreflight,
    calistirmalar,
    devirler,
    isLoading,
    errorMessage,
    calculationErrorMessage,
    buildParams,
    refetch
  };
}
