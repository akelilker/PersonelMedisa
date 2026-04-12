import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { listIsgMakineler } from "../api/isg.api";
import {
  dataCacheKeys,
  fetchWithCacheMerge,
  getActiveSube,
  getCacheEntry,
  getSubeIdForApiRequest,
  useAppDataRevision
} from "../data/data-manager";
import { runDeduped } from "../lib/in-flight-dedupe";
import type { PaginatedResult } from "../types/api";
import type { IsgMakineListItem, IsgMakineDurum } from "../types/isg";

const PAGE_SIZE = 10;

type IsgListFilters = {
  search: string;
  durum: IsgMakineDurum | "tum";
  tip: string;
};

export type IsgListQueryState = {
  draft: IsgListFilters;
  applied: IsgListFilters;
  page: number;
};

const INITIAL_FILTERS: IsgListFilters = {
  search: "",
  durum: "tum",
  tip: ""
};

export function useIsgMakineler() {
  const revision = useAppDataRevision();
  const [query, setQuery] = useState<IsgListQueryState>({
    draft: INITIAL_FILTERS,
    applied: INITIAL_FILTERS,
    page: 1
  });
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const activeSube = useMemo(() => getActiveSube(), [revision]);
  const listKey = useMemo(
    () =>
      dataCacheKeys.isgMakinelerList(
        activeSube,
        query.applied.search,
        query.applied.durum,
        query.applied.tip,
        query.page
      ),
    [activeSube, query.applied.durum, query.applied.search, query.applied.tip, query.page]
  );

  const listSnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<IsgMakineListItem>>(listKey),
    [listKey, revision]
  );

  const makineler = listSnapshot?.items ?? [];
  const pagination = listSnapshot?.pagination ?? null;

  const refetch = useCallback(async () => {
    await fetchWithCacheMerge(listKey, () =>
      runDeduped(listKey, () =>
        listIsgMakineler({
          search: query.applied.search || undefined,
          durum: query.applied.durum,
          tip: query.applied.tip || undefined,
          sube_id: getSubeIdForApiRequest(),
          page: query.page,
          limit: PAGE_SIZE
        })
      )
    );
  }, [listKey, query.applied.durum, query.applied.search, query.applied.tip, query.page]);

  useEffect(() => {
    let cancelled = false;
    const hasSeed = getCacheEntry<PaginatedResult<IsgMakineListItem>>(listKey) !== undefined;
    setIsLoading(!hasSeed);
    setErrorMessage(null);

    void (async () => {
      try {
        await refetch();
      } catch {
        if (!getCacheEntry<PaginatedResult<IsgMakineListItem>>(listKey)) {
          setErrorMessage("Makine listesi şu an güncellenemiyor.");
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [listKey, refetch]);

  const submitFilters = useCallback((event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setQuery((prev) => ({
      ...prev,
      applied: { ...prev.draft },
      page: 1
    }));
  }, []);

  const clearFilters = useCallback(() => {
    setQuery({
      draft: INITIAL_FILTERS,
      applied: INITIAL_FILTERS,
      page: 1
    });
  }, []);

  const setDraftSearch = useCallback((search: string) => {
    setQuery((prev) => ({ ...prev, draft: { ...prev.draft, search } }));
  }, []);

  const setDraftDurum = useCallback((durum: IsgMakineDurum | "tum") => {
    setQuery((prev) => ({ ...prev, draft: { ...prev.draft, durum } }));
  }, []);

  const setDraftTip = useCallback((tip: string) => {
    setQuery((prev) => ({ ...prev, draft: { ...prev.draft, tip } }));
  }, []);

  const goPrevPage = useCallback(() => {
    setQuery((prev) => ({ ...prev, page: Math.max(1, prev.page - 1) }));
  }, []);

  const goNextPage = useCallback(() => {
    setQuery((prev) => ({ ...prev, page: prev.page + 1 }));
  }, []);

  return {
    activeSube,
    draftFilters: query.draft,
    appliedFilters: query.applied,
    page: query.page,
    makineler,
    pagination,
    isLoading,
    errorMessage,
    submitFilters,
    clearFilters,
    setDraftSearch,
    setDraftDurum,
    setDraftTip,
    refetch,
    goPrevPage,
    goNextPage
  };
}
