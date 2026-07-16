import { useCallback, useEffect, useRef, useState } from "react";
import { getApiErrorDetail } from "../api/api-client";
import {
  fetchBildirimEtkiRapor,
  type BildirimEtkiRaporFilters,
  type BildirimEtkiRaporResult,
  type BildirimEtkiRaporRow,
  type BildirimEtkiRaporSummary
} from "../api/bildirim-etki-rapor.api";

const EMPTY_SUMMARY: BildirimEtkiRaporSummary = {
  toplam_aday: 0,
  otomatik_uygulanan: 0,
  manuel_uygulanan: 0,
  koru: 0,
  revize: 0,
  yok_sayilan: 0,
  bekleyen: 0,
  conflict_dagilimi: {},
  toplam_gec_kalma_dakika: 0,
  toplam_erken_cikis_dakika: 0,
  toplam_devamsizlik_gun: 0
};

type UseBildirimEtkiRaporOptions = {
  enabled: boolean;
  filters: BildirimEtkiRaporFilters | null;
  autoRun?: boolean;
};

export function useBildirimEtkiRapor(options: UseBildirimEtkiRaporOptions) {
  const { enabled, filters, autoRun = false } = options;
  const [rows, setRows] = useState<BildirimEtkiRaporRow[]>([]);
  const [summary, setSummary] = useState<BildirimEtkiRaporSummary>(EMPTY_SUMMARY);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [totalPages, setTotalPages] = useState(1);
  const [hasNextPage, setHasNextPage] = useState(false);
  const [hasPrevPage, setHasPrevPage] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [hasSearched, setHasSearched] = useState(false);
  const requestIdRef = useRef(0);

  const load = useCallback(
    async (nextPage = 1, activeFilters = filters): Promise<BildirimEtkiRaporResult | null> => {
      if (!enabled || !activeFilters?.sube_id || !activeFilters.ay) {
        return null;
      }

      const requestId = ++requestIdRef.current;
      setIsLoading(true);
      setErrorMessage(null);

      try {
        const result = await fetchBildirimEtkiRapor({ ...activeFilters, page: nextPage });
        if (requestId !== requestIdRef.current) {
          return null;
        }
        setRows(result.items);
        setSummary(result.summary);
        setPage(result.page);
        setTotal(result.total);
        setTotalPages(result.total_pages);
        setHasNextPage(result.has_next_page);
        setHasPrevPage(result.has_prev_page);
        setHasSearched(true);
        return result;
      } catch (caught) {
        if (requestId !== requestIdRef.current) {
          return null;
        }
        setRows([]);
        setSummary(EMPTY_SUMMARY);
        setErrorMessage(getApiErrorDetail(caught, "Etki adayı raporu yüklenemedi.").message);
        return null;
      } finally {
        if (requestId === requestIdRef.current) {
          setIsLoading(false);
        }
      }
    },
    [enabled, filters]
  );

  useEffect(() => {
    if (autoRun && filters) {
      void load(1, filters);
    }
  }, [autoRun, filters, load]);

  return {
    rows,
    summary,
    page,
    total,
    totalPages,
    hasNextPage,
    hasPrevPage,
    isLoading,
    errorMessage,
    hasSearched,
    load,
    setPage
  };
}
