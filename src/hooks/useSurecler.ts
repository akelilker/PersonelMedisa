import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { getApiErrorMessage, shouldQueueOfflineMutation } from "../api/api-client";
import {
  cancelSurec,
  createSurec,
  fetchSurecDetail,
  fetchSureclerList,
  updateSurec,
  type CreateSurecPayload
} from "../api/surecler.api";
import { fetchSurecTuruOptions } from "../api/referans.api";
import { emptyPaginated, makeTempId } from "../data/app-data.types";
import {
  dataCacheKeys,
  deleteCacheEntry,
  enqueueSyncOperation,
  fetchWithCacheMerge,
  getActiveSube,
  getCacheEntry,
  getSubeIdForApiRequest,
  mergeCacheEntry,
  optimisticPrependToList,
  processSyncQueue,
  useAppDataRevision
} from "../data/data-manager";
import {
  SUBE_DETAIL_REDIRECT_MESSAGE,
  SUBE_DETAIL_REDIRECT_STATE_KEY,
  shouldRedirectDetailAfterSubeMismatch
} from "../lib/detail-sube-context";
import { runDeduped } from "../lib/in-flight-dedupe";
import type { PaginatedResult } from "../types/api";
import type { KeyOption } from "../types/referans";
import { useAuth } from "../state/auth.store";
import type { Surec } from "../types/surec";

const PAGE_SIZE = 10;

export type SurecListQueryState = {
  draft: {
    personelId: string;
    surecTuru: string;
    state: string;
    baslangicTarihi: string;
    bitisTarihi: string;
  };
  applied: {
    personelId: string;
    surecTuru: string;
    state: string;
    baslangicTarihi: string;
    bitisTarihi: string;
  };
  page: number;
};

export type SurecFormState = {
  personelId: string;
  surecTuru: string;
  altTur: string;
  baslangicTarihi: string;
  bitisTarihi: string;
  ucretliMi: boolean;
  aciklama: string;
};

export const INITIAL_SUREC_FORM: SurecFormState = {
  personelId: "",
  surecTuru: "",
  altTur: "",
  baslangicTarihi: "",
  bitisTarihi: "",
  ucretliMi: true,
  aciklama: ""
};

function parsePositiveInt(value: string) {
  const number = Number.parseInt(value, 10);
  if (Number.isNaN(number) || number <= 0) {
    return undefined;
  }
  return number;
}

function parseRequiredPositiveInt(value: string, label: string) {
  const number = parsePositiveInt(value);
  if (!number) {
    throw new Error(`${label} pozitif sayi olmalidir.`);
  }
  return number;
}

function toSurecFormState(surec: Surec): SurecFormState {
  return {
    personelId: String(surec.personel_id),
    surecTuru: surec.surec_turu,
    altTur: surec.alt_tur ?? "",
    baslangicTarihi: surec.baslangic_tarihi ?? "",
    bitisTarihi: surec.bitis_tarihi ?? "",
    ucretliMi: surec.ucretli_mi ?? true,
    aciklama: surec.aciklama ?? ""
  };
}

function draftSurecFromCreatePayload(payload: CreateSurecPayload, tempId: number): Surec {
  return {
    id: tempId,
    personel_id: payload.personel_id,
    surec_turu: payload.surec_turu,
    alt_tur: payload.alt_tur,
    baslangic_tarihi: payload.baslangic_tarihi,
    bitis_tarihi: payload.bitis_tarihi,
    ucretli_mi: payload.ucretli_mi,
    aciklama: payload.aciklama,
    state: "BEKLEMEDE"
  };
}

export function useSurecler() {
  const revision = useAppDataRevision();
  const [listQuery, setListQuery] = useState<SurecListQueryState>({
    draft: {
      personelId: "",
      surecTuru: "",
      state: "",
      baslangicTarihi: "",
      bitisTarihi: ""
    },
    applied: {
      personelId: "",
      surecTuru: "",
      state: "",
      baslangicTarihi: "",
      bitisTarihi: ""
    },
    page: 1
  });

  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [referenceError, setReferenceError] = useState<string | null>(null);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [createForm, setCreateForm] = useState<SurecFormState>(INITIAL_SUREC_FORM);
  const [createErrorMessage, setCreateErrorMessage] = useState<string | null>(null);
  const [isCreateSubmitting, setIsCreateSubmitting] = useState(false);

  const [editingSurec, setEditingSurec] = useState<Surec | null>(null);
  const [editForm, setEditForm] = useState<SurecFormState>(INITIAL_SUREC_FORM);
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [isEditSubmitting, setIsEditSubmitting] = useState(false);
  const [cancelingSurecId, setCancelingSurecId] = useState<number | null>(null);

  const applied = listQuery.applied;
  const listPage = listQuery.page;

  const activeSube = useMemo(() => getActiveSube(), [revision]);

  const listKey = useMemo(
    () =>
      dataCacheKeys.sureclerList(
        activeSube,
        applied.personelId,
        applied.surecTuru,
        applied.state,
        applied.baslangicTarihi,
        applied.bitisTarihi,
        listPage
      ),
    [
      activeSube,
      applied.baslangicTarihi,
      applied.bitisTarihi,
      applied.personelId,
      applied.state,
      applied.surecTuru,
      listPage
    ]
  );

  const listSnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<Surec>>(listKey),
    [listKey, revision]
  );

  const surecler = listSnapshot?.items ?? [];
  const hasNextPage = listSnapshot?.pagination.hasNextPage ?? false;
  const totalPages = listSnapshot?.pagination.totalPages ?? null;

  const surecTuruOptions = useMemo(
    () => getCacheEntry<KeyOption[]>(dataCacheKeys.surecTuruRef()) ?? [],
    [revision]
  );

  const refetch = useCallback(async () => {
    await fetchWithCacheMerge(listKey, () =>
      runDeduped(listKey, () =>
        fetchSureclerList({
          personel_id: parsePositiveInt(applied.personelId),
          surec_turu: applied.surecTuru || undefined,
          state: applied.state || undefined,
          baslangic_tarihi: applied.baslangicTarihi || undefined,
          bitis_tarihi: applied.bitisTarihi || undefined,
          sube_id: getSubeIdForApiRequest(),
          page: listPage,
          limit: PAGE_SIZE
        })
      )
    );
  }, [applied, listKey, listPage]);

  useEffect(() => {
    let cancelled = false;
    const hasSeed = getCacheEntry<PaginatedResult<Surec>>(listKey) !== undefined;
    setIsLoading(!hasSeed);
    setErrorMessage(null);

    void (async () => {
      try {
        await fetchWithCacheMerge(listKey, () =>
          runDeduped(listKey, () =>
            fetchSureclerList({
              personel_id: parsePositiveInt(applied.personelId),
              surec_turu: applied.surecTuru || undefined,
              state: applied.state || undefined,
              baslangic_tarihi: applied.baslangicTarihi || undefined,
              bitis_tarihi: applied.bitisTarihi || undefined,
              sube_id: getSubeIdForApiRequest(),
              page: listPage,
              limit: PAGE_SIZE
            })
          )
        );
      } catch {
        if (!getCacheEntry<PaginatedResult<Surec>>(listKey)) {
      setErrorMessage("Süreç listesi şu an güncellenemiyor.");
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
  }, [applied, listKey, listPage]);

  useEffect(() => {
    let cancelled = false;
    setReferenceError(null);

    void (async () => {
      try {
        await fetchWithCacheMerge(dataCacheKeys.surecTuruRef(), () =>
          runDeduped(dataCacheKeys.surecTuruRef(), () => fetchSurecTuruOptions())
        );
      } catch {
        if (!cancelled) {
      setReferenceError("Süreç türleri şu an güncellenemiyor, manuel giriş kullanılabilir.");
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
    const empty = {
      personelId: "",
      surecTuru: "",
      state: "",
      baslangicTarihi: "",
      bitisTarihi: ""
    };
    setListQuery({
      draft: { ...empty },
      applied: { ...empty },
      page: 1
    });
  }, []);

  const updateDraft = useCallback((partial: Partial<SurecListQueryState["draft"]>) => {
    setListQuery((prev) => ({ ...prev, draft: { ...prev.draft, ...partial } }));
  }, []);

  const setPage = useCallback((next: number | ((p: number) => number)) => {
    setListQuery((prev) => ({
      ...prev,
      page: typeof next === "function" ? next(prev.page) : next
    }));
  }, []);

  const openCreateModal = useCallback(() => {
    setCreateErrorMessage(null);
    setCreateForm(INITIAL_SUREC_FORM);
    setIsCreateModalOpen(true);
  }, []);

  const closeCreateModal = useCallback(() => {
    setIsCreateModalOpen(false);
  }, []);

  const refreshPageOne = useCallback(async () => {
    const pageOneKey = dataCacheKeys.sureclerList(
      activeSube,
      listQuery.applied.personelId,
      listQuery.applied.surecTuru,
      listQuery.applied.state,
      listQuery.applied.baslangicTarihi,
      listQuery.applied.bitisTarihi,
      1
    );
    await fetchWithCacheMerge(pageOneKey, () =>
      runDeduped(pageOneKey, () =>
        fetchSureclerList({
          personel_id: parsePositiveInt(listQuery.applied.personelId),
          surec_turu: listQuery.applied.surecTuru || undefined,
          state: listQuery.applied.state || undefined,
          baslangic_tarihi: listQuery.applied.baslangicTarihi || undefined,
          bitis_tarihi: listQuery.applied.bitisTarihi || undefined,
          sube_id: getSubeIdForApiRequest(),
          page: 1,
          limit: PAGE_SIZE
        })
      )
    );
  }, [activeSube, listQuery.applied]);

  const createSurecHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>, canCreate: boolean) => {
      event.preventDefault();
      if (isCreateSubmitting) {
        return;
      }
      if (!canCreate) {
      setCreateErrorMessage("Bu işlem için yetkin bulunmuyor.");
        return;
      }

      setCreateErrorMessage(null);
      setIsCreateSubmitting(true);

      try {
        const payload: CreateSurecPayload = {
          personel_id: parseRequiredPositiveInt(createForm.personelId, "Personel ID"),
          surec_turu: createForm.surecTuru.trim(),
          alt_tur: createForm.altTur.trim() || undefined,
          baslangic_tarihi: createForm.baslangicTarihi,
          bitis_tarihi: createForm.bitisTarihi,
          ucretli_mi: createForm.ucretliMi,
          aciklama: createForm.aciklama.trim() || undefined
        };

        const pageOneKey = dataCacheKeys.sureclerList(
          activeSube,
          listQuery.applied.personelId,
          listQuery.applied.surecTuru,
          listQuery.applied.state,
          listQuery.applied.baslangicTarihi,
          listQuery.applied.bitisTarihi,
          1
        );

        try {
          await createSurec(payload);
          setIsCreateModalOpen(false);
          setCreateForm(INITIAL_SUREC_FORM);
          setListQuery((prev) => ({ ...prev, page: 1 }));
          await refreshPageOne();
        } catch (error) {
          if (!shouldQueueOfflineMutation(error)) {
            throw error;
          }

          const tempId = makeTempId();
          optimisticPrependToList(pageOneKey, draftSurecFromCreatePayload(payload, tempId));
          enqueueSyncOperation({
            op: "surecler.create",
            payload,
            meta: { listKey: pageOneKey, tempId }
          });
          setIsCreateModalOpen(false);
          setCreateForm(INITIAL_SUREC_FORM);
          setListQuery((prev) => ({ ...prev, page: 1 }));
          void processSyncQueue();
        }
      } catch (error) {
        setCreateErrorMessage(getApiErrorMessage(error, "Surec kaydi yapilamadi."));
      } finally {
        setIsCreateSubmitting(false);
      }
    },
    [activeSube, createForm, isCreateSubmitting, listQuery.applied, refreshPageOne]
  );

  const openEditModal = useCallback((surec: Surec, canEdit: boolean) => {
    if (!canEdit) {
      setErrorMessage("Bu süreci düzenlemek için yetkin bulunmuyor.");
      return;
    }
    setEditErrorMessage(null);
    setEditingSurec(surec);
    setEditForm(toSurecFormState(surec));
  }, []);

  const closeEditModal = useCallback(() => {
    setEditingSurec(null);
  }, []);

  const updateSurecHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>, canEdit: boolean) => {
      event.preventDefault();
      if (!editingSurec || isEditSubmitting) {
        return;
      }
      if (!canEdit) {
        setEditErrorMessage("Bu süreci düzenlemek için yetkin bulunmuyor.");
        return;
      }

      setEditErrorMessage(null);
      setIsEditSubmitting(true);

      const previousSurec = editingSurec;
      const body = {
        personel_id: parseRequiredPositiveInt(editForm.personelId, "Personel ID"),
        surec_turu: editForm.surecTuru.trim(),
        alt_tur: editForm.altTur.trim() || undefined,
        baslangic_tarihi: editForm.baslangicTarihi,
        bitis_tarihi: editForm.bitisTarihi,
        ucretli_mi: editForm.ucretliMi,
        aciklama: editForm.aciklama.trim() || undefined
      };

      mergeCacheEntry<PaginatedResult<Surec>>(listKey, (prev) => {
        const base = prev ?? emptyPaginated<Surec>();
        return {
          ...base,
          items: base.items.map((row) => (row.id === editingSurec.id ? { ...row, ...body } : row))
        };
      });

      try {
        await updateSurec(editingSurec.id, body);
        setEditingSurec(null);
        setListQuery((prev) => ({ ...prev, page: 1 }));
        await refreshPageOne();
      } catch (error) {
        if (shouldQueueOfflineMutation(error)) {
          enqueueSyncOperation({
            op: "surecler.update",
            payload: { surecId: editingSurec.id, body },
            meta: { listKey }
          });
          setEditingSurec(null);
          void processSyncQueue();
          return;
        }

        mergeCacheEntry<PaginatedResult<Surec>>(listKey, (prev) => {
          const base = prev ?? emptyPaginated<Surec>();
          return {
            ...base,
            items: base.items.map((row) => (row.id === previousSurec.id ? previousSurec : row))
          };
        });
        setEditErrorMessage(getApiErrorMessage(error, "Surec kaydi guncellenemedi."));
      } finally {
        setIsEditSubmitting(false);
      }
    },
    [editForm, editingSurec, isEditSubmitting, listKey, refreshPageOne]
  );

  const cancelSurecHandler = useCallback(
    async (surec: Surec, canCancel: boolean) => {
      if (!canCancel) {
        setErrorMessage("Bu süreci iptal etmek için yetkin bulunmuyor.");
        return;
      }

      const confirmed = window.confirm(`Süreç #${surec.id} kaydını iptal etmek istiyor musun?`);
      if (!confirmed) {
        return;
      }

      setCancelingSurecId(surec.id);

      mergeCacheEntry<PaginatedResult<Surec>>(listKey, (prev) => {
        const base = prev ?? emptyPaginated<Surec>();
        return {
          ...base,
          items: base.items.map((row) => (row.id === surec.id ? { ...row, state: "IPTAL" } : row))
        };
      });

      try {
        await cancelSurec(surec.id);
        setListQuery((prev) => ({ ...prev, page: 1 }));
        await refreshPageOne();
      } catch (error) {
        if (shouldQueueOfflineMutation(error)) {
          enqueueSyncOperation({
            op: "surecler.cancel",
            payload: { surecId: surec.id },
            meta: { listKey }
          });
          void processSyncQueue();
          return;
        }

        mergeCacheEntry<PaginatedResult<Surec>>(listKey, (prev) => {
          const base = prev ?? emptyPaginated<Surec>();
          return {
            ...base,
            items: base.items.map((row) => (row.id === surec.id ? surec : row))
          };
        });
        setErrorMessage(getApiErrorMessage(error, "Surec iptal edilemedi."));
      } finally {
        setCancelingSurecId(null);
      }
    },
    [listKey, refreshPageOne]
  );

  return {
    listQuery,
    updateDraft,
    surecler,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
    setErrorMessage,
    refetch,
    surecTuruOptions,
    referenceError,
    isCreateModalOpen,
    openCreateModal,
    closeCreateModal,
    createForm,
    setCreateForm,
    createErrorMessage,
    isCreateSubmitting,
    createSurecHandler,
    editingSurec,
    openEditModal,
    closeEditModal,
    editForm,
    setEditForm,
    editErrorMessage,
    isEditSubmitting,
    updateSurecHandler,
    cancelingSurecId,
    cancelSurecHandler,
    submitFilters,
    clearFilters,
    setPage
  };
}

export function useSurecDetail(parsedSurecId: number, hasValidId: boolean) {
  const navigate = useNavigate();
  const { session } = useAuth();
  const activeSubeId = session?.active_sube_id ?? null;
  const revision = useAppDataRevision();
  const detailKey = useMemo(
    () => dataCacheKeys.surecDetail(activeSubeId, parsedSurecId),
    [activeSubeId, parsedSurecId]
  );
  const cached = useMemo(() => getCacheEntry<Surec>(detailKey), [detailKey, revision]);
  const [surec, setSurec] = useState<Surec | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    if (cached) {
      setSurec(cached);
    }
  }, [cached]);

  useEffect(() => {
    deleteCacheEntry(detailKey);
  }, [detailKey, activeSubeId]);

  const refetch = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Geçerli bir süreç ID verilmedi.");
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    try {
      const data = await fetchWithCacheMerge(detailKey, () =>
        runDeduped(detailKey, () => fetchSurecDetail(parsedSurecId))
      );
      setSurec(data);
    } catch (error) {
      if (shouldRedirectDetailAfterSubeMismatch(error)) {
        setSurec(null);
        navigate("/surecler", {
          replace: true,
          state: { [SUBE_DETAIL_REDIRECT_STATE_KEY]: SUBE_DETAIL_REDIRECT_MESSAGE }
        });
        return;
      }
      if (!getCacheEntry<Surec>(detailKey)) {
        setErrorMessage("Süreç detayı şu an güncellenemiyor.");
      }
    } finally {
      setIsLoading(false);
    }
  }, [activeSubeId, detailKey, hasValidId, navigate, parsedSurecId]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  return { surec, isLoading, errorMessage, refetch };
}
