import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import {
  cancelBildirim,
  createBildirim,
  fetchBildirimDetail,
  fetchBildirimlerList,
  markBildirimOkundu,
  updateBildirim,
  type CreateBildirimPayload
} from "../api/bildirimler.api";
import { fetchBildirimTuruOptions, fetchDepartmanOptions } from "../api/referans.api";
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
import type { Bildirim } from "../types/bildirim";
import { useAuth } from "../state/auth.store";
import type { IdOption, KeyOption } from "../types/referans";

const PAGE_SIZE = 10;

export type BildirimListQueryState = {
  draft: { personelId: string; bildirimTuru: string; tarih: string };
  applied: { personelId: string; bildirimTuru: string; tarih: string };
  page: number;
};

export type BildirimFormState = {
  tarih: string;
  departmanId: string;
  personelId: string;
  bildirimTuru: string;
  aciklama: string;
};

export const INITIAL_BILDIRIM_FORM: BildirimFormState = {
  tarih: "",
  departmanId: "",
  personelId: "",
  bildirimTuru: "",
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

function toBildirimFormState(bildirim: Bildirim): BildirimFormState {
  return {
    tarih: bildirim.tarih ?? "",
    departmanId: bildirim.departman_id ? String(bildirim.departman_id) : "",
    personelId: bildirim.personel_id ? String(bildirim.personel_id) : "",
    bildirimTuru: bildirim.bildirim_turu,
    aciklama: bildirim.aciklama ?? ""
  };
}

function draftBildirimFromPayload(payload: CreateBildirimPayload, tempId: number): Bildirim {
  return {
    id: tempId,
    tarih: payload.tarih,
    departman_id: payload.departman_id,
    personel_id: payload.personel_id,
    bildirim_turu: payload.bildirim_turu,
    aciklama: payload.aciklama,
    state: "AKTIF",
    okundu_mi: false
  };
}

export function useBildirimler() {
  const revision = useAppDataRevision();
  const [listQuery, setListQuery] = useState<BildirimListQueryState>({
    draft: { personelId: "", bildirimTuru: "", tarih: "" },
    applied: { personelId: "", bildirimTuru: "", tarih: "" },
    page: 1
  });

  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [referenceError, setReferenceError] = useState<string | null>(null);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [createForm, setCreateForm] = useState<BildirimFormState>(INITIAL_BILDIRIM_FORM);
  const [createErrorMessage, setCreateErrorMessage] = useState<string | null>(null);
  const [isCreateSubmitting, setIsCreateSubmitting] = useState(false);

  const [editingBildirim, setEditingBildirim] = useState<Bildirim | null>(null);
  const [editForm, setEditForm] = useState<BildirimFormState>(INITIAL_BILDIRIM_FORM);
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [isEditSubmitting, setIsEditSubmitting] = useState(false);
  const [cancelingBildirimId, setCancelingBildirimId] = useState<number | null>(null);

  const applied = listQuery.applied;
  const listPage = listQuery.page;

  const activeSube = useMemo(() => getActiveSube(), [revision]);

  const listKey = useMemo(
    () =>
      dataCacheKeys.bildirimlerList(
        activeSube,
        applied.personelId,
        applied.bildirimTuru,
        applied.tarih,
        listPage
      ),
    [activeSube, applied.bildirimTuru, applied.personelId, applied.tarih, listPage]
  );

  const listSnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<Bildirim>>(listKey),
    [listKey, revision]
  );

  const bildirimler = listSnapshot?.items ?? [];
  const hasNextPage = listSnapshot?.pagination.hasNextPage ?? false;
  const totalPages = listSnapshot?.pagination.totalPages ?? null;

  const refMeta = useMemo(
    () =>
      getCacheEntry<{ departman: IdOption[]; bildirimTuru: KeyOption[] }>(dataCacheKeys.bildirimRef()) ?? {
        departman: [],
        bildirimTuru: []
      },
    [revision]
  );
  const departmanOptions = refMeta.departman;
  const bildirimTuruOptions = refMeta.bildirimTuru;

  const refetch = useCallback(async () => {
    await fetchWithCacheMerge(listKey, () =>
      runDeduped(listKey, () =>
        fetchBildirimlerList({
          personel_id: parsePositiveInt(applied.personelId),
          bildirim_turu: applied.bildirimTuru || undefined,
          tarih: applied.tarih || undefined,
          sube_id: getSubeIdForApiRequest(),
          page: listPage,
          limit: PAGE_SIZE
        })
      )
    );
  }, [applied, listKey, listPage]);

  useEffect(() => {
    let cancelled = false;
    const hasSeed = getCacheEntry<PaginatedResult<Bildirim>>(listKey) !== undefined;
    setIsLoading(!hasSeed);
    setErrorMessage(null);

    void (async () => {
      try {
        await fetchWithCacheMerge(listKey, () =>
          runDeduped(listKey, () =>
            fetchBildirimlerList({
              personel_id: parsePositiveInt(applied.personelId),
              bildirim_turu: applied.bildirimTuru || undefined,
              tarih: applied.tarih || undefined,
              sube_id: getSubeIdForApiRequest(),
              page: listPage,
              limit: PAGE_SIZE
            })
          )
        );
      } catch {
        if (!getCacheEntry<PaginatedResult<Bildirim>>(listKey)) {
          setErrorMessage("Bildirim listesi su an guncellenemiyor.");
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
        await fetchWithCacheMerge(dataCacheKeys.bildirimRef(), () =>
          runDeduped(dataCacheKeys.bildirimRef(), async () => {
            const [departman, bildirimTuru] = await Promise.all([
              fetchDepartmanOptions(),
              fetchBildirimTuruOptions()
            ]);
            return { departman, bildirimTuru };
          })
        );
      } catch {
        if (!cancelled) {
          setReferenceError("Bildirim referanslari su an guncellenemiyor, manuel giris kullanilabilir.");
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
    const empty = { personelId: "", bildirimTuru: "", tarih: "" };
    setListQuery({
      draft: { ...empty },
      applied: { ...empty },
      page: 1
    });
  }, []);

  const updateDraft = useCallback((partial: Partial<BildirimListQueryState["draft"]>) => {
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
    setCreateForm(INITIAL_BILDIRIM_FORM);
    setIsCreateModalOpen(true);
  }, []);

  const closeCreateModal = useCallback(() => {
    setIsCreateModalOpen(false);
  }, []);

  const refreshPageOne = useCallback(async () => {
    const pageOneKey = dataCacheKeys.bildirimlerList(
      activeSube,
      listQuery.applied.personelId,
      listQuery.applied.bildirimTuru,
      listQuery.applied.tarih,
      1
    );
    await fetchWithCacheMerge(pageOneKey, () =>
      runDeduped(pageOneKey, () =>
        fetchBildirimlerList({
          personel_id: parsePositiveInt(listQuery.applied.personelId),
          bildirim_turu: listQuery.applied.bildirimTuru || undefined,
          tarih: listQuery.applied.tarih || undefined,
          sube_id: getSubeIdForApiRequest(),
          page: 1,
          limit: PAGE_SIZE
        })
      )
    );
  }, [activeSube, listQuery.applied]);

  const createBildirimHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>, canCreate: boolean) => {
      event.preventDefault();
      if (isCreateSubmitting) {
        return;
      }
      if (!canCreate) {
        setCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
        return;
      }

      setCreateErrorMessage(null);
      setIsCreateSubmitting(true);

      try {
        const payload: CreateBildirimPayload = {
          tarih: createForm.tarih,
          departman_id: parseRequiredPositiveInt(createForm.departmanId, "Departman ID"),
          personel_id: parseRequiredPositiveInt(createForm.personelId, "Personel ID"),
          bildirim_turu: createForm.bildirimTuru.trim(),
          aciklama: createForm.aciklama.trim() || undefined
        };

        const pageOneKey = dataCacheKeys.bildirimlerList(
          activeSube,
          listQuery.applied.personelId,
          listQuery.applied.bildirimTuru,
          listQuery.applied.tarih,
          1
        );

        try {
          await createBildirim(payload);
          setIsCreateModalOpen(false);
          setCreateForm(INITIAL_BILDIRIM_FORM);
          setListQuery((prev) => ({ ...prev, page: 1 }));
          await refreshPageOne();
        } catch {
          const tempId = makeTempId();
          optimisticPrependToList(pageOneKey, draftBildirimFromPayload(payload, tempId));
          enqueueSyncOperation({
            op: "bildirimler.create",
            payload,
            meta: { listKey: pageOneKey, tempId }
          });
          setIsCreateModalOpen(false);
          setCreateForm(INITIAL_BILDIRIM_FORM);
          setListQuery((prev) => ({ ...prev, page: 1 }));
          void processSyncQueue();
        }
      } catch (error) {
        setCreateErrorMessage(error instanceof Error ? error.message : "Bildirim kaydi yapilamadi.");
      } finally {
        setIsCreateSubmitting(false);
      }
    },
    [activeSube, createForm, isCreateSubmitting, listQuery.applied, refreshPageOne]
  );

  const openEditModal = useCallback((bildirim: Bildirim, canEdit: boolean) => {
    if (!canEdit) {
      setErrorMessage("Bu bildirimi duzenlemek icin yetkin bulunmuyor.");
      return;
    }
    setEditErrorMessage(null);
    setEditingBildirim(bildirim);
    setEditForm(toBildirimFormState(bildirim));
  }, []);

  const closeEditModal = useCallback(() => {
    setEditingBildirim(null);
  }, []);

  const updateBildirimHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>, canEdit: boolean) => {
      event.preventDefault();
      if (!editingBildirim || isEditSubmitting) {
        return;
      }
      if (!canEdit) {
        setEditErrorMessage("Bu bildirimi duzenlemek icin yetkin bulunmuyor.");
        return;
      }

      setEditErrorMessage(null);
      setIsEditSubmitting(true);

      const body = {
        tarih: editForm.tarih,
        departman_id: parseRequiredPositiveInt(editForm.departmanId, "Departman ID"),
        personel_id: parseRequiredPositiveInt(editForm.personelId, "Personel ID"),
        bildirim_turu: editForm.bildirimTuru.trim(),
        aciklama: editForm.aciklama.trim() || undefined
      };

      mergeCacheEntry<PaginatedResult<Bildirim>>(listKey, (prev) => {
        const base = prev ?? emptyPaginated<Bildirim>();
        return {
          ...base,
          items: base.items.map((row) => (row.id === editingBildirim.id ? { ...row, ...body } : row))
        };
      });

      try {
        await updateBildirim(editingBildirim.id, body);
        setEditingBildirim(null);
        setListQuery((prev) => ({ ...prev, page: 1 }));
        await refreshPageOne();
      } catch {
        enqueueSyncOperation({
          op: "bildirimler.update",
          payload: { bildirimId: editingBildirim.id, body },
          meta: { listKey }
        });
        setEditingBildirim(null);
        void processSyncQueue();
      } finally {
        setIsEditSubmitting(false);
      }
    },
    [editForm, editingBildirim, isEditSubmitting, listKey, refreshPageOne]
  );

  const cancelBildirimHandler = useCallback(
    async (bildirim: Bildirim, canCancel: boolean) => {
      if (!canCancel) {
        setErrorMessage("Bu bildirimi iptal etmek icin yetkin bulunmuyor.");
        return;
      }

      const confirmed = window.confirm(`Bildirim #${bildirim.id} kaydini iptal etmek istiyor musun?`);
      if (!confirmed) {
        return;
      }

      setCancelingBildirimId(bildirim.id);

      mergeCacheEntry<PaginatedResult<Bildirim>>(listKey, (prev) => {
        const base = prev ?? emptyPaginated<Bildirim>();
        return {
          ...base,
          items: base.items.map((row) => (row.id === bildirim.id ? { ...row, state: "IPTAL" } : row))
        };
      });

      try {
        await cancelBildirim(bildirim.id);
        setListQuery((prev) => ({ ...prev, page: 1 }));
        await refreshPageOne();
      } catch {
        enqueueSyncOperation({
          op: "bildirimler.cancel",
          payload: { bildirimId: bildirim.id },
          meta: { listKey }
        });
        void processSyncQueue();
      } finally {
        setCancelingBildirimId(null);
      }
    },
    [listKey, refreshPageOne]
  );

  return {
    listQuery,
    updateDraft,
    bildirimler,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
    setErrorMessage,
    refetch,
    departmanOptions,
    bildirimTuruOptions,
    referenceError,
    isCreateModalOpen,
    openCreateModal,
    closeCreateModal,
    createForm,
    setCreateForm,
    createErrorMessage,
    isCreateSubmitting,
    createBildirimHandler,
    editingBildirim,
    openEditModal,
    closeEditModal,
    editForm,
    setEditForm,
    editErrorMessage,
    isEditSubmitting,
    updateBildirimHandler,
    cancelingBildirimId,
    cancelBildirimHandler,
    submitFilters,
    clearFilters,
    setPage
  };
}

export function useBildirimlerHeaderPreview(enabled: boolean) {
  const revision = useAppDataRevision();
  const activeSube = useMemo(() => getActiveSube(), [revision]);
  const key = useMemo(() => dataCacheKeys.bildirimlerHeader(activeSube), [activeSube]);

  const items = useMemo(() => {
    if (!enabled) {
      return [];
    }
    return getCacheEntry<PaginatedResult<Bildirim>>(key)?.items ?? [];
  }, [enabled, key, revision]);

  const isLoading = useMemo(() => {
    if (!enabled) {
      return false;
    }
    return getCacheEntry<PaginatedResult<Bildirim>>(key) === undefined;
  }, [enabled, key, revision]);

  const reload = useCallback(async () => {
    if (!enabled) {
      return;
    }
    await fetchWithCacheMerge(key, () =>
      runDeduped(key, () =>
        fetchBildirimlerList({ page: 1, limit: 8, sube_id: getSubeIdForApiRequest() })
      )
    );
  }, [enabled, key]);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      if (!enabled) {
        return;
      }
      try {
        await fetchWithCacheMerge(key, () =>
          runDeduped(key, () =>
            fetchBildirimlerList({ page: 1, limit: 8, sube_id: getSubeIdForApiRequest() })
          )
        );
      } finally {
        if (!cancelled) {
          /* revision notifies */
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [enabled, key]);

  const markOkundu = useCallback((id: number) => markBildirimOkundu(id), []);

  return { items, isLoading, errorMessage: null as string | null, reload, markOkundu };
}

export function useBildirimDetail(parsedBildirimId: number, hasValidId: boolean) {
  const navigate = useNavigate();
  const { session } = useAuth();
  const activeSubeId = session?.active_sube_id ?? null;
  const revision = useAppDataRevision();
  const detailKey = useMemo(() => dataCacheKeys.bildirimDetail(parsedBildirimId), [parsedBildirimId]);
  const cached = useMemo(() => getCacheEntry<Bildirim>(detailKey), [detailKey, revision]);

  const [bildirim, setBildirim] = useState<Bildirim | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    if (cached) {
      setBildirim(cached);
    }
  }, [cached]);

  useEffect(() => {
    deleteCacheEntry(detailKey);
  }, [detailKey, activeSubeId]);

  const refetch = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Gecerli bir bildirim id verilmedi.");
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    try {
      const data = await fetchWithCacheMerge(detailKey, () =>
        runDeduped(detailKey, () => fetchBildirimDetail(parsedBildirimId))
      );
      setBildirim(data);
    } catch (error) {
      if (shouldRedirectDetailAfterSubeMismatch(error)) {
        setBildirim(null);
        navigate("/bildirimler", {
          replace: true,
          state: { [SUBE_DETAIL_REDIRECT_STATE_KEY]: SUBE_DETAIL_REDIRECT_MESSAGE }
        });
        return;
      }
      if (!getCacheEntry<Bildirim>(detailKey)) {
        setErrorMessage("Bildirim detayi su an guncellenemiyor.");
      }
    } finally {
      setIsLoading(false);
    }
  }, [activeSubeId, detailKey, hasValidId, navigate, parsedBildirimId]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  return { bildirim, isLoading, errorMessage, refetch };
}
