import { useCallback, useEffect, useRef, useState } from "react";
import { getApiErrorDetail } from "../api/api-client";
import {
  fetchDonemKapanisPreflightItems,
  type DonemKapanisPreflightItem,
  type DonemKapanisPreflightParams,
  type DonemKapanisSeverity
} from "../api/donem-kapanis.api";

type UseDonemKapanisItemsOptions = {
  enabled: boolean;
  params: DonemKapanisPreflightParams | null;
  code: string | null;
  severity?: DonemKapanisSeverity | "";
  page?: number;
  limit?: number;
};

export function useDonemKapanisItems(options: UseDonemKapanisItemsOptions) {
  const { enabled, params, code, severity = "", page = 1, limit = 20 } = options;
  const [items, setItems] = useState<DonemKapanisPreflightItem[]>([]);
  const [total, setTotal] = useState(0);
  const [totalPages, setTotalPages] = useState(1);
  const [hasNextPage, setHasNextPage] = useState(false);
  const [hasPrevPage, setHasPrevPage] = useState(false);
  const [currentPage, setCurrentPage] = useState(page);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const requestIdRef = useRef(0);

  const refetch = useCallback(
    async (nextPage = page) => {
      if (!enabled || !params || !code) {
        setItems([]);
        return;
      }

      const requestId = ++requestIdRef.current;
      setIsLoading(true);
      setErrorMessage(null);

      try {
        const result = await fetchDonemKapanisPreflightItems({
          ...params,
          code,
          severity,
          page: nextPage,
          limit
        });
        if (requestId !== requestIdRef.current) {
          return;
        }
        setItems(result.items);
        setTotal(result.total);
        setTotalPages(result.total_pages);
        setHasNextPage(result.has_next_page);
        setHasPrevPage(result.has_prev_page);
        setCurrentPage(result.page);
      } catch (caught) {
        if (requestId !== requestIdRef.current) {
          return;
        }
        setItems([]);
        setErrorMessage(getApiErrorDetail(caught, "Kapanış detay listesi yüklenemedi.").message);
      } finally {
        if (requestId === requestIdRef.current) {
          setIsLoading(false);
        }
      }
    },
    [enabled, params, code, severity, limit, page]
  );

  useEffect(() => {
    setCurrentPage(page);
    void refetch(page);
  }, [refetch, page, code, params?.sube_id, params?.yil, params?.ay]);

  return {
    items,
    total,
    totalPages,
    currentPage,
    hasNextPage,
    hasPrevPage,
    isLoading,
    errorMessage,
    refetch
  };
}
