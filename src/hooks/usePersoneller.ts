import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { getApiErrorMessage, isAbortedRequestError, shouldQueueOfflineMutation } from "../api/api-client";
import {
  createPersonel,
  fetchPersonelDetail,
  fetchPersonellerList,
  type CreatePersonelPayload
} from "../api/personeller.api";
import {
  fetchBagliAmirOptions,
  fetchBirimOptions,
  fetchBolumOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchPozisyonOptions,
  fetchPrimKuraliOptions,
  fetchSgkIsverenOptions,
  fetchCalismaLokasyonuOptions,
  fetchUcretTipiOptions
} from "../api/referans.api";
import { emptyPaginated, makeTempId, type PersonelReferenceBundle } from "../data/app-data.types";
import {
  dataCacheKeys,
  draftPersonelFromPayload,
  enqueueSyncOperation,
  fetchWithCacheMerge,
  getActiveSube,
  getCacheEntry,
  getSubeIdForApiRequest,
  optimisticPrependPersonel,
  processSyncQueue,
  useAppDataRevision
} from "../data/data-manager";
import { buildCreatePersonelPayload, parseOptionalPositiveInt } from "../features/personeller/personel-create-utils";
import type { PaginatedResult } from "../types/api";
import { acquireDedupedRequest, runDeduped } from "../lib/in-flight-dedupe";
import {
  PERSONEL_SEARCH_DEBOUNCE_MS,
  normalizePersonelSearchQuery
} from "../features/personeller/personel-search-query";
import type { Personel } from "../types/personel";
import {
  buildBagliAmirContext,
  buildBagliAmirFormGuidance,
  type BagliAmirContext
} from "../features/personeller/personel-edit-utils";
const PAGE_SIZE = 10;

export type PersonelListFilters = {
  search: string;
  aktiflik: "aktif" | "pasif" | "tum";
  departmanId: string;
  personelTipiId: string;
  calisanKapsami: "" | "IC_PERSONEL" | "DIS_KAYNAK";
  eksikBilgi: "tum" | "eksik";
};

/**
 * `draft` is what the inputs show (raw text included), `applied` is what the API
 * is actually queried with. Every filter except the text box is applied the moment
 * it changes; the text box lands in `applied` after the debounce (or immediately
 * on Enter / clear).
 */
export type PersonelListQueryState = {
  draft: PersonelListFilters;
  applied: PersonelListFilters;
  page: number;
};

const INITIAL_LIST_FILTERS: PersonelListFilters = {
  search: "",
  aktiflik: "aktif",
  departmanId: "",
  personelTipiId: "",
  calisanKapsami: "",
  eksikBilgi: "tum"
};

export type CreatePersonelFormState = {
  calisanKapsami: "IC_PERSONEL" | "DIS_KAYNAK";
  tcKimlikNo: string;
  ad: string;
  soyad: string;
  dogumTarihi: string;
  telefon: string;
  acilDurumKisi: string;
  acilDurumTelefon: string;
  iseGirisTarihi: string;
  subeId: string;
  sgkIsverenId: string;
  departmanId: string;
  bolumId: string;
  birimId: string;
  gorevId: string;
  pozisyonId: string;
  personelTipiId: string;
  dogumYeri: string;
  kanGrubu: string;
  bagliAmirId: string;
  ucretTipiId: string;
  maasTutari: string;
  primKuraliId: string;
};

export const INITIAL_CREATE_PERSONEL_FORM: CreatePersonelFormState = {
  calisanKapsami: "IC_PERSONEL",
  tcKimlikNo: "",
  ad: "",
  soyad: "",
  dogumTarihi: "",
  telefon: "",
  acilDurumKisi: "",
  acilDurumTelefon: "",
  iseGirisTarihi: "",
  subeId: "",
  sgkIsverenId: "",
  departmanId: "",
  bolumId: "",
  birimId: "",
  gorevId: "",
  pozisyonId: "",
  personelTipiId: "",
  dogumYeri: "",
  kanGrubu: "",
  bagliAmirId: "",
  ucretTipiId: "1",
  maasTutari: "",
  primKuraliId: ""
};

async function fetchBagliAmirContext(amirId: number): Promise<BagliAmirContext | null> {
  try {
    const personel = await fetchPersonelDetail(amirId);
    return buildBagliAmirContext(personel);
  } catch {
    return null;
  }
}

export function usePersoneller() {
  const revision = useAppDataRevision();
  const [listQuery, setListQuery] = useState<PersonelListQueryState>({
    draft: { ...INITIAL_LIST_FILTERS },
    applied: { ...INITIAL_LIST_FILTERS },
    page: 1
  });

  const [isFetching, setIsFetching] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [referenceError, setReferenceError] = useState<string | null>(null);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isCreateSubmitting, setIsCreateSubmitting] = useState(false);
  const [createErrorMessage, setCreateErrorMessage] = useState<string | null>(null);
  const [createForm, setCreateForm] = useState<CreatePersonelFormState>(INITIAL_CREATE_PERSONEL_FORM);
  const [createBagliAmirContext, setCreateBagliAmirContext] = useState<BagliAmirContext | null>(null);

  const appliedFilters = listQuery.applied;
  const listPage = listQuery.page;

  const activeSube = useMemo(() => getActiveSube(), [revision]);

  const listKey = useMemo(
    () =>
      dataCacheKeys.personellerList(
        activeSube,
        appliedFilters.search,
        appliedFilters.aktiflik,
        appliedFilters.departmanId,
        appliedFilters.personelTipiId,
        listPage,
        appliedFilters.calisanKapsami,
        appliedFilters.eksikBilgi
      ),
    [
      activeSube,
      appliedFilters.aktiflik,
      appliedFilters.departmanId,
      appliedFilters.personelTipiId,
      appliedFilters.calisanKapsami,
      appliedFilters.eksikBilgi,
      appliedFilters.search,
      listPage
    ]
  );

  const listSnapshot = useMemo(
    () =>
      getCacheEntry<PaginatedResult<Personel> & { missingPersonelTotal?: number | null }>(listKey),
    [listKey, revision]
  );

  // The previous result stays on screen while the next query is in flight, so the
  // list does not blank out and flicker on every keystroke.
  const [lastLoadedSnapshot, setLastLoadedSnapshot] = useState<
    (PaginatedResult<Personel> & { missingPersonelTotal?: number | null }) | null
  >(null);

  useEffect(() => {
    if (listSnapshot !== undefined) {
      setLastLoadedSnapshot(listSnapshot);
    }
  }, [listSnapshot]);

  const shownSnapshot = listSnapshot ?? lastLoadedSnapshot ?? undefined;
  /** True once the API answered the query currently in `applied`. */
  const isCurrentQueryResolved = listSnapshot !== undefined;

  const personeller = shownSnapshot?.items ?? [];
  const hasNextPage = shownSnapshot?.pagination.hasNextPage ?? false;
  const totalPages = shownSnapshot?.pagination.totalPages ?? null;
  const missingPersonelTotal = shownSnapshot?.missingPersonelTotal ?? null;

  const refs = useMemo((): PersonelReferenceBundle => {
    return (
      getCacheEntry<PersonelReferenceBundle>(dataCacheKeys.referansPersonel()) ?? {
        departmanOptions: [],
        bolumOptions: [],
        birimOptions: [],
        gorevOptions: [],
        pozisyonOptions: [],
        personelTipiOptions: [],
        sgkIsverenOptions: [],
        calismaLokasyonuOptions: [],
        bagliAmirOptions: [],
        ucretTipiOptions: [],
        primKuraliOptions: []
      }
    );
  }, [revision]);

  const createBagliAmirGuidance = useMemo(
    () => buildBagliAmirFormGuidance(createForm.departmanId, createBagliAmirContext, activeSube),
    [activeSube, createBagliAmirContext, createForm.departmanId]
  );

  const listRequestParams = useCallback(
    (page: number, signal?: AbortSignal) => ({
      search: appliedFilters.search || undefined,
      departman_id: parseOptionalPositiveInt(appliedFilters.departmanId),
      aktiflik: appliedFilters.aktiflik,
      personel_tipi_id: parseOptionalPositiveInt(appliedFilters.personelTipiId),
      calisan_kapsami: appliedFilters.calisanKapsami || undefined,
      eksik_bilgi: appliedFilters.eksikBilgi === "eksik",
      sube_id: getSubeIdForApiRequest(),
      page,
      limit: PAGE_SIZE,
      signal
    }),
    [
      appliedFilters.aktiflik,
      appliedFilters.departmanId,
      appliedFilters.personelTipiId,
      appliedFilters.calisanKapsami,
      appliedFilters.eksikBilgi,
      appliedFilters.search
    ]
  );

  const refetch = useCallback(async () => {
    const lease = acquireDedupedRequest(listKey, (signal) =>
      fetchPersonellerList(listRequestParams(listPage, signal))
    );
    try {
      await fetchWithCacheMerge(listKey, () => lease.promise);
    } finally {
      lease.release();
    }
  }, [listKey, listPage, listRequestParams]);

  // Monotonic id: only the newest query may touch loading/error state, so a slow
  // response that lands after a newer one can never overwrite the current result.
  const listRequestSeqRef = useRef(0);
  const isMountedRef = useRef(true);

  useEffect(() => {
    isMountedRef.current = true;
    return () => {
      isMountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    const requestId = listRequestSeqRef.current + 1;
    listRequestSeqRef.current = requestId;

    setIsFetching(true);
    setErrorMessage(null);

    const lease = acquireDedupedRequest(listKey, (signal) =>
      fetchPersonellerList(listRequestParams(listPage, signal))
    );

    void (async () => {
      try {
        await fetchWithCacheMerge(listKey, () => lease.promise);
      } catch (error) {
        if (
          isAbortedRequestError(error) ||
          requestId !== listRequestSeqRef.current ||
          !isMountedRef.current
        ) {
          // Superseded or unmounted: not a user-facing failure.
          return;
        }
        if (
          !getCacheEntry<PaginatedResult<Personel> & { missingPersonelTotal?: number | null }>(listKey)
        ) {
          setErrorMessage("Personel listesi su an guncellenemiyor.");
        }
      } finally {
        if (requestId === listRequestSeqRef.current && isMountedRef.current) {
          setIsFetching(false);
        }
      }
    })();

    return () => {
      lease.release();
    };
  }, [listKey, listPage, listRequestParams]);

  useEffect(() => {
    let cancelled = false;
    setReferenceError(null);

    void (async () => {
      try {
        await fetchWithCacheMerge(dataCacheKeys.referansPersonel(), () =>
          runDeduped(dataCacheKeys.referansPersonel(), async () => {
            const [
              departmanOptions,
              bolumOptions,
              birimOptions,
              gorevOptions,
              pozisyonOptions,
              personelTipiOptions,
              sgkIsverenOptions,
              calismaLokasyonuOptions,
              bagliAmirOptions,
              ucretTipiOptions,
              primKuraliOptions
            ] = await Promise.all([
              fetchDepartmanOptions(),
              fetchBolumOptions(),
              fetchBirimOptions(),
              fetchGorevOptions(),
              fetchPozisyonOptions(),
              fetchPersonelTipiOptions(),
              fetchSgkIsverenOptions(),
              fetchCalismaLokasyonuOptions(),
              fetchBagliAmirOptions(),
              fetchUcretTipiOptions(),
              fetchPrimKuraliOptions()
            ]);
            return {
              departmanOptions,
              bolumOptions,
              birimOptions,
              gorevOptions,
              pozisyonOptions,
              personelTipiOptions,
              sgkIsverenOptions,
              calismaLokasyonuOptions,
              bagliAmirOptions,
              ucretTipiOptions,
              primKuraliOptions
            } satisfies PersonelReferenceBundle;
          })
        );
      } catch {
        if (!cancelled) {
          setReferenceError("Referans listeleri yÃ¼klenemedi. LÃ¼tfen sayfayÄ± yenileyin.");
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const isComposingRef = useRef(false);
  const [searchCommitTick, setSearchCommitTick] = useState(0);

  const cancelPendingSearch = useCallback(() => {
    if (searchDebounceRef.current !== null) {
      clearTimeout(searchDebounceRef.current);
      searchDebounceRef.current = null;
    }
  }, []);

  useEffect(() => cancelPendingSearch, [cancelPendingSearch]);

  /** Commits the normalized search text and restarts pagination. */
  const applySearch = useCallback((search: string) => {
    setListQuery((prev) =>
      prev.applied.search === search && prev.page === 1
        ? prev
        : { ...prev, applied: { ...prev.applied, search }, page: 1 }
    );
  }, []);

  /**
   * Dropdowns, scope, activity and every other non-text filter take effect the
   * moment they change. A search still waiting for its debounce is committed
   * together with them, so no keystroke is lost.
   */
  const applyFilterPatch = useCallback(
    (patch: Partial<Omit<PersonelListFilters, "search">>) => {
      cancelPendingSearch();
      setListQuery((prev) => {
        const draft = { ...prev.draft, ...patch };
        return {
          draft,
          applied: { ...draft, search: normalizePersonelSearchQuery(draft.search) },
          page: 1
        };
      });
    },
    [cancelPendingSearch]
  );

  const setDraftSearch = useCallback((search: string) => {
    setListQuery((prev) => ({ ...prev, draft: { ...prev.draft, search } }));
  }, []);

  const draftSearch = listQuery.draft.search;

  useEffect(() => {
    const nextSearch = normalizePersonelSearchQuery(draftSearch);
    if (nextSearch === appliedFilters.search) {
      cancelPendingSearch();
      return undefined;
    }
    // Half-composed IME text must not be queried; onCompositionEnd re-runs this.
    if (isComposingRef.current) {
      return undefined;
    }

    cancelPendingSearch();
    if (nextSearch === "") {
      // Cleared input returns to the default list right away.
      applySearch(nextSearch);
      return undefined;
    }

    searchDebounceRef.current = setTimeout(() => {
      searchDebounceRef.current = null;
      applySearch(nextSearch);
    }, PERSONEL_SEARCH_DEBOUNCE_MS);

    return cancelPendingSearch;
  }, [applySearch, appliedFilters.search, cancelPendingSearch, draftSearch, searchCommitTick]);

  /** Enter (or any form submit) skips the remaining debounce. */
  const submitFilters = useCallback(
    (event?: FormEvent<HTMLFormElement>) => {
      event?.preventDefault();
      cancelPendingSearch();
      setListQuery((prev) => ({
        ...prev,
        applied: { ...prev.draft, search: normalizePersonelSearchQuery(prev.draft.search) },
        page: 1
      }));
    },
    [cancelPendingSearch]
  );

  const setSearchComposing = useCallback(
    (composing: boolean) => {
      isComposingRef.current = composing;
      if (composing) {
        cancelPendingSearch();
        return;
      }
      // Re-runs the debounce effect against the now-complete text.
      setSearchCommitTick((tick) => tick + 1);
    },
    [cancelPendingSearch]
  );

  const clearFilters = useCallback(() => {
    cancelPendingSearch();
    setListQuery({
      draft: { ...INITIAL_LIST_FILTERS },
      applied: { ...INITIAL_LIST_FILTERS },
      page: 1
    });
  }, [cancelPendingSearch]);

  const setDraftAktiflik = useCallback(
    (aktiflik: "aktif" | "pasif" | "tum") => applyFilterPatch({ aktiflik }),
    [applyFilterPatch]
  );

  const setDraftDepartmanId = useCallback(
    (departmanId: string) => applyFilterPatch({ departmanId }),
    [applyFilterPatch]
  );

  const setDraftPersonelTipiId = useCallback(
    (personelTipiId: string) => applyFilterPatch({ personelTipiId }),
    [applyFilterPatch]
  );

  const setDraftCalisanKapsami = useCallback(
    (calisanKapsami: "" | "IC_PERSONEL" | "DIS_KAYNAK") => applyFilterPatch({ calisanKapsami }),
    [applyFilterPatch]
  );

  const setDraftEksikBilgi = useCallback(
    (eksikBilgi: "tum" | "eksik") => applyFilterPatch({ eksikBilgi }),
    [applyFilterPatch]
  );

  const setPage = useCallback((next: number | ((p: number) => number)) => {
    setListQuery((prev) => ({
      ...prev,
      page: typeof next === "function" ? next(prev.page) : next
    }));
  }, []);

  const openCreateModal = useCallback(() => {
    setCreateErrorMessage(null);
    const amirId = parseOptionalPositiveInt(createForm.bagliAmirId);
    if (amirId === undefined) {
      setCreateBagliAmirContext(null);
    } else {
      void (async () => {
        const context = await fetchBagliAmirContext(amirId);
        setCreateBagliAmirContext(context);
      })();
    }
    setIsCreateModalOpen(true);
  }, [createForm.bagliAmirId]);

  const closeCreateModal = useCallback(() => {
    setIsCreateModalOpen(false);
  }, []);

  const handleCreateDepartmanChange = useCallback((departmanId: string) => {
    setCreateForm((prev) => {
      const nextBolumId =
        prev.bolumId &&
        refs.bolumOptions.some(
          (opt) => String(opt.id) === prev.bolumId && String(opt.parentId ?? "") === departmanId
        )
          ? prev.bolumId
          : "";
      const nextBirimId =
        nextBolumId &&
        prev.birimId &&
        refs.birimOptions.some(
          (opt) => String(opt.id) === prev.birimId && String(opt.parentId ?? "") === nextBolumId
        )
          ? prev.birimId
          : "";
      return { ...prev, departmanId, bolumId: nextBolumId, birimId: nextBirimId };
    });
  }, [refs.birimOptions, refs.bolumOptions]);

  const handleCreateBolumChange = useCallback((bolumId: string) => {
    setCreateForm((prev) => {
      const nextBirimId =
        bolumId &&
        prev.birimId &&
        refs.birimOptions.some(
          (opt) => String(opt.id) === prev.birimId && String(opt.parentId ?? "") === bolumId
        )
          ? prev.birimId
          : "";
      return { ...prev, bolumId, birimId: nextBirimId };
    });
  }, [refs.birimOptions]);

  const handleCreateBagliAmirChange = useCallback((bagliAmirId: string) => {
    setCreateForm((prev) => ({ ...prev, bagliAmirId }));

    const amirId = parseOptionalPositiveInt(bagliAmirId);
    if (amirId === undefined) {
      setCreateBagliAmirContext(null);
      return;
    }

    void (async () => {
      const context = await fetchBagliAmirContext(amirId);
      setCreateBagliAmirContext(context);
      if (!context?.departmanId) {
        return;
      }

      setCreateForm((prev) =>
        prev.bagliAmirId === bagliAmirId ? { ...prev, departmanId: context.departmanId } : prev
      );
    })();
  }, []);

  const createPersonelHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>, canCreate: boolean) => {
      event.preventDefault();
      if (isCreateSubmitting) {
        return;
      }
      if (!canCreate) {
      setCreateErrorMessage("Bu iÅŸlem iÃ§in yetkin bulunmuyor.");
        return;
      }

      setCreateErrorMessage(null);
      setIsCreateSubmitting(true);

      try {
        const payload: CreatePersonelPayload = buildCreatePersonelPayload(createForm);

        const pageOneKey = dataCacheKeys.personellerList(
          activeSube,
          listQuery.applied.search,
          listQuery.applied.aktiflik,
          listQuery.applied.departmanId,
          listQuery.applied.personelTipiId,
          1,
          listQuery.applied.calisanKapsami,
          listQuery.applied.eksikBilgi
        );

        try {
          await createPersonel(payload);
          setIsCreateModalOpen(false);
          setCreateForm(INITIAL_CREATE_PERSONEL_FORM);
          setCreateBagliAmirContext(null);
          setListQuery((prev) => ({ ...prev, page: 1 }));
          await fetchWithCacheMerge(pageOneKey, () =>
            runDeduped(pageOneKey, () =>
              fetchPersonellerList({
                search: listQuery.applied.search || undefined,
                departman_id: parseOptionalPositiveInt(listQuery.applied.departmanId),
                aktiflik: listQuery.applied.aktiflik,
                personel_tipi_id: parseOptionalPositiveInt(listQuery.applied.personelTipiId),
                calisan_kapsami: listQuery.applied.calisanKapsami || undefined,
                eksik_bilgi: listQuery.applied.eksikBilgi === "eksik",
                sube_id: getSubeIdForApiRequest(),
                page: 1,
                limit: PAGE_SIZE
              })
            )
          );
        } catch (error) {
          if (!shouldQueueOfflineMutation(error)) {
            throw error;
          }

          const tempId = makeTempId();
          const draft = draftPersonelFromPayload(payload, tempId);
          optimisticPrependPersonel(pageOneKey, draft);
          enqueueSyncOperation({
            op: "personeller.create",
            payload,
            meta: { listKey: pageOneKey, tempId }
          });
          setIsCreateModalOpen(false);
          setCreateForm(INITIAL_CREATE_PERSONEL_FORM);
          setCreateBagliAmirContext(null);
          setListQuery((prev) => ({ ...prev, page: 1 }));
          void processSyncQueue();
        }
      } catch (error) {
        setCreateErrorMessage(getApiErrorMessage(error, "Personel kaydi sirasinda bir hata olustu."));
      } finally {
        setIsCreateSubmitting(false);
      }
    },
    [activeSube, createForm, isCreateSubmitting, listQuery.applied]
  );

  return {
    listQuery,
    personeller,
    hasNextPage,
    totalPages,
    missingPersonelTotal,
    /** First load only: there is nothing to keep on screen yet. */
    isLoading: isFetching && personeller.length === 0,
    /** A newer query is in flight while previous rows stay visible. */
    isRefreshing: isFetching && personeller.length > 0,
    isCurrentQueryResolved,
    errorMessage,
    refetch,
    refs,
    referenceError,
    isCreateModalOpen,
    openCreateModal,
    closeCreateModal,
    isCreateSubmitting,
    createErrorMessage,
    createForm,
    setCreateForm,
    handleCreateDepartmanChange,
    handleCreateBolumChange,
    handleCreateBagliAmirChange,
    createBagliAmirGuidance,
    createPersonelHandler,
    submitFilters,
    clearFilters,
    setDraftSearch,
    setSearchComposing,
    setDraftAktiflik,
    setDraftDepartmanId,
    setDraftPersonelTipiId,
    setDraftCalisanKapsami,
    setDraftEksikBilgi,
    setPage
  };
}

export {
  INITIAL_PERSONEL_ZIMMET_FORM,
  usePersonelZimmetCreate,
  type PersonelZimmetFormState
} from "./usePersonelZimmetCreate";
export { usePersonelDetail } from "./usePersonelDetail";
