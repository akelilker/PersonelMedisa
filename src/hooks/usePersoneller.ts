import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { getApiErrorMessage, shouldQueueOfflineMutation } from "../api/api-client";
import {
  createPersonel,
  fetchPersonelDetail,
  fetchPersonellerList,
  type CreatePersonelPayload
} from "../api/personeller.api";
import {
  fetchBagliAmirOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchPrimKuraliOptions,
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
import { runDeduped } from "../lib/in-flight-dedupe";
import type { Personel } from "../types/personel";
import {
  buildBagliAmirContext,
  buildBagliAmirFormGuidance,
  type BagliAmirContext
} from "../features/personeller/personel-edit-utils";
const PAGE_SIZE = 10;

export type PersonelListQueryState = {
  draft: {
    search: string;
    aktiflik: "aktif" | "pasif" | "tum";
    departmanId: string;
    personelTipiId: string;
  };
  applied: {
    search: string;
    aktiflik: "aktif" | "pasif" | "tum";
    departmanId: string;
    personelTipiId: string;
  };
  page: number;
};

export type CreatePersonelFormState = {
  tcKimlikNo: string;
  ad: string;
  soyad: string;
  dogumTarihi: string;
  telefon: string;
  acilDurumKisi: string;
  acilDurumTelefon: string;
  sicilNo: string;
  iseGirisTarihi: string;
  subeId: string;
  departmanId: string;
  gorevId: string;
  personelTipiId: string;
  dogumYeri: string;
  kanGrubu: string;
  bagliAmirId: string;
  ucretTipiId: string;
  maasTutari: string;
  primKuraliId: string;
};

export const INITIAL_CREATE_PERSONEL_FORM: CreatePersonelFormState = {
  tcKimlikNo: "",
  ad: "",
  soyad: "",
  dogumTarihi: "",
  telefon: "",
  acilDurumKisi: "",
  acilDurumTelefon: "",
  sicilNo: "",
  iseGirisTarihi: "",
  subeId: "",
  departmanId: "",
  gorevId: "",
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
    draft: { search: "", aktiflik: "tum", departmanId: "", personelTipiId: "" },
    applied: { search: "", aktiflik: "tum", departmanId: "", personelTipiId: "" },
    page: 1
  });

  const [isLoading, setIsLoading] = useState(true);
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
        listPage
      ),
    [
      activeSube,
      appliedFilters.aktiflik,
      appliedFilters.departmanId,
      appliedFilters.personelTipiId,
      appliedFilters.search,
      listPage
    ]
  );

  const listSnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<Personel>>(listKey),
    [listKey, revision]
  );

  const personeller = listSnapshot?.items ?? [];
  const hasNextPage = listSnapshot?.pagination.hasNextPage ?? false;
  const totalPages = listSnapshot?.pagination.totalPages ?? null;

  const refs = useMemo((): PersonelReferenceBundle => {
    return (
      getCacheEntry<PersonelReferenceBundle>(dataCacheKeys.referansPersonel()) ?? {
        departmanOptions: [],
        gorevOptions: [],
        personelTipiOptions: [],
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

  const refetch = useCallback(async () => {
    await fetchWithCacheMerge(listKey, () =>
      runDeduped(listKey, () =>
        fetchPersonellerList({
          search: appliedFilters.search || undefined,
          departman_id: parseOptionalPositiveInt(appliedFilters.departmanId),
          aktiflik: appliedFilters.aktiflik,
          personel_tipi_id: parseOptionalPositiveInt(appliedFilters.personelTipiId),
          sube_id: getSubeIdForApiRequest(),
          page: listPage,
          limit: PAGE_SIZE
        })
      )
    );
  }, [
    appliedFilters.aktiflik,
    appliedFilters.departmanId,
    appliedFilters.personelTipiId,
    appliedFilters.search,
    listKey,
    listPage
  ]);

  useEffect(() => {
    let cancelled = false;
    const hasSeed = getCacheEntry<PaginatedResult<Personel>>(listKey) !== undefined;
    setIsLoading(!hasSeed);
    setErrorMessage(null);

    void (async () => {
      try {
        await fetchWithCacheMerge(listKey, () =>
          runDeduped(listKey, () =>
            fetchPersonellerList({
              search: appliedFilters.search || undefined,
              departman_id: parseOptionalPositiveInt(appliedFilters.departmanId),
              aktiflik: appliedFilters.aktiflik,
              personel_tipi_id: parseOptionalPositiveInt(appliedFilters.personelTipiId),
              sube_id: getSubeIdForApiRequest(),
              page: listPage,
              limit: PAGE_SIZE
            })
          )
        );
      } catch {
        if (!getCacheEntry<PaginatedResult<Personel>>(listKey)) {
          setErrorMessage("Personel listesi su an guncellenemiyor.");
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
  }, [
    appliedFilters.aktiflik,
    appliedFilters.departmanId,
    appliedFilters.personelTipiId,
    appliedFilters.search,
    listKey,
    listPage
  ]);

  useEffect(() => {
    let cancelled = false;
    setReferenceError(null);

    void (async () => {
      try {
        await fetchWithCacheMerge(dataCacheKeys.referansPersonel(), () =>
          runDeduped(dataCacheKeys.referansPersonel(), async () => {
            const [
              departmanOptions,
              gorevOptions,
              personelTipiOptions,
              bagliAmirOptions,
              ucretTipiOptions,
              primKuraliOptions
            ] = await Promise.all([
              fetchDepartmanOptions(),
              fetchGorevOptions(),
              fetchPersonelTipiOptions(),
              fetchBagliAmirOptions(),
              fetchUcretTipiOptions(),
              fetchPrimKuraliOptions()
            ]);
            return {
              departmanOptions,
              gorevOptions,
              personelTipiOptions,
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

  const submitFilters = useCallback((event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setListQuery((prev) => ({
      ...prev,
      applied: { ...prev.draft },
      page: 1
    }));
  }, []);

  const clearFilters = useCallback(() => {
    setListQuery({
      draft: { search: "", aktiflik: "tum", departmanId: "", personelTipiId: "" },
      applied: { search: "", aktiflik: "tum", departmanId: "", personelTipiId: "" },
      page: 1
    });
  }, []);

  const setDraftSearch = useCallback((search: string) => {
    setListQuery((prev) => ({ ...prev, draft: { ...prev.draft, search } }));
  }, []);

  const setDraftAktiflik = useCallback((aktiflik: "aktif" | "pasif" | "tum") => {
    setListQuery((prev) => ({ ...prev, draft: { ...prev.draft, aktiflik } }));
  }, []);

  const setDraftDepartmanId = useCallback((departmanId: string) => {
    setListQuery((prev) => ({ ...prev, draft: { ...prev.draft, departmanId } }));
  }, []);

  const setDraftPersonelTipiId = useCallback((personelTipiId: string) => {
    setListQuery((prev) => ({ ...prev, draft: { ...prev.draft, personelTipiId } }));
  }, []);

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
    setCreateForm((prev) => ({ ...prev, departmanId }));
  }, []);

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
          1
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
    isLoading,
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
    handleCreateBagliAmirChange,
    createBagliAmirGuidance,
    createPersonelHandler,
    submitFilters,
    clearFilters,
    setDraftSearch,
    setDraftAktiflik,
    setDraftDepartmanId,
    setDraftPersonelTipiId,
    setPage
  };
}

export {
  INITIAL_PERSONEL_ZIMMET_FORM,
  usePersonelZimmetCreate,
  type PersonelZimmetFormState
} from "./usePersonelZimmetCreate";
export { usePersonelDetail } from "./usePersonelDetail";
