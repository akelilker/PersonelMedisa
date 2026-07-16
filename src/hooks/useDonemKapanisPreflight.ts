import { useCallback, useEffect, useRef, useState } from "react";
import { getApiErrorDetail } from "../api/api-client";
import {
  fetchDonemKapanisAudits,
  fetchDonemKapanisPreflight,
  type DonemKapanisAudit,
  type DonemKapanisPreflightParams,
  type DonemKapanisPreflightSummary
} from "../api/donem-kapanis.api";

export type DonemKapanisFilterState = {
  ay: string;
  subeId: string;
  departmanId: string;
  personelId: string;
};

type UseDonemKapanisPreflightOptions = {
  enabled: boolean;
  filters: DonemKapanisFilterState;
  yil: number;
  ay: number;
  subeId: number | null;
};

export function useDonemKapanisPreflight(options: UseDonemKapanisPreflightOptions) {
  const { enabled, filters, yil, ay, subeId } = options;
  const [summary, setSummary] = useState<DonemKapanisPreflightSummary | null>(null);
  const [audits, setAudits] = useState<DonemKapanisAudit[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [isAuditsLoading, setIsAuditsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [auditsErrorMessage, setAuditsErrorMessage] = useState<string | null>(null);
  const requestIdRef = useRef(0);

  const buildParams = useCallback((): DonemKapanisPreflightParams | null => {
    if (!subeId) {
      return null;
    }

    const departmanId = filters.departmanId.trim()
      ? Number.parseInt(filters.departmanId, 10)
      : undefined;
    const personelId = filters.personelId.trim() ? Number.parseInt(filters.personelId, 10) : undefined;

    return {
      sube_id: subeId,
      yil,
      ay,
      ...(departmanId && Number.isFinite(departmanId) ? { departman_id: departmanId } : {}),
      ...(personelId && Number.isFinite(personelId) ? { personel_id: personelId } : {})
    };
  }, [subeId, yil, ay, filters.departmanId, filters.personelId]);

  const refetch = useCallback(async () => {
    if (!enabled) {
      return;
    }

    const params = buildParams();
    if (!params) {
      setSummary(null);
      setErrorMessage("Dönem kapanış özeti için şube seçilmelidir.");
      return;
    }

    const requestId = ++requestIdRef.current;
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const next = await fetchDonemKapanisPreflight(params);
      if (requestId !== requestIdRef.current) {
        return;
      }
      setSummary(next);
    } catch (caught) {
      if (requestId !== requestIdRef.current) {
        return;
      }
      setSummary(null);
      setErrorMessage(getApiErrorDetail(caught, "Dönem kapanış özeti yüklenemedi.").message);
    } finally {
      if (requestId === requestIdRef.current) {
        setIsLoading(false);
      }
    }
  }, [enabled, buildParams]);

  const refetchAudits = useCallback(async () => {
    if (!enabled) {
      return;
    }

    const params = buildParams();
    if (!params) {
      setAudits([]);
      return;
    }

    setIsAuditsLoading(true);
    setAuditsErrorMessage(null);

    try {
      const result = await fetchDonemKapanisAudits({
        sube_id: params.sube_id,
        yil: params.yil,
        ay: params.ay,
        page: 1,
        limit: 20
      });
      setAudits(result.items);
    } catch (caught) {
      setAudits([]);
      setAuditsErrorMessage(getApiErrorDetail(caught, "Kapanış audit kayıtları yüklenemedi.").message);
    } finally {
      setIsAuditsLoading(false);
    }
  }, [enabled, buildParams]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  useEffect(() => {
    void refetchAudits();
  }, [refetchAudits]);

  return {
    summary,
    audits,
    isLoading,
    isAuditsLoading,
    errorMessage,
    auditsErrorMessage,
    buildParams,
    refetch,
    refetchAudits
  };
}
