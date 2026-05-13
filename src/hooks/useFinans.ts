import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { getApiErrorMessage, shouldQueueOfflineMutation } from "../api/api-client";
import {
  cancelFinansKalem,
  fetchFinansKalemList,
  updateFinansKalem
} from "../api/finans.api";
import { emptyPaginated, makeTempId } from "../data/app-data.types";
import {
  dataCacheKeys,
  enqueueSyncOperation,
  fetchWithCacheMerge,
  getActiveSube,
  getCacheEntry,
  getSubeIdForApiRequest,
  mergeCacheEntry,
  processSyncQueue,
  useAppDataRevision
} from "../data/data-manager";
import {
  buildCreateFinansKalemPayload,
  commitFinansKalemCreate,
  createEmptyFinansCreateForm,
  createEmptyFinansMaliFields,
  parseRequiredPositiveInt,
  parseRequiredPositiveNumber,
  validateDonem,
  type FinansCreateFormInput,
  type FinansListAppliedFilters,
  type FinansMaliFieldsState
} from "../lib/finans/finans-create-commit";
import { runDeduped } from "../lib/in-flight-dedupe";
import type { PaginatedResult } from "../types/api";
import type { CreateFinansKalemPayload, FinansKalem } from "../types/finans";

const PAGE_SIZE = 10;

export type FinansListQueryState = {
  draft: { personelId: string; donem: string; kalemTuru: string; state: string };
  applied: { personelId: string; donem: string; kalemTuru: string; state: string };
  page: number;
};

export type FinansFormState = FinansCreateFormInput;

function parsePositiveInt(value: string): number | undefined {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }
  const parsed = Number.parseInt(trimmed, 10);
  if (Number.isNaN(parsed) || parsed <= 0) {
    return undefined;
  }
  return parsed;
}

function toFormState(item: FinansKalem): FinansFormState {
  return {
    personelId: String(item.personel_id),
    donem: item.donem,
    kalemTuru: item.kalem_turu,
    tutar: String(item.tutar),
    aciklama: item.aciklama ?? ""
  };
}

export function useFinans() {
  const revision = useAppDataRevision();
  const [listQuery, setListQuery] = useState<FinansListQueryState>({
    draft: { personelId: "", donem: "", kalemTuru: "", state: "" },
    applied: { personelId: "", donem: "", kalemTuru: "", state: "" },
    page: 1
  });

  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [createForm, setCreateForm] = useState<FinansFormState>(() => createEmptyFinansCreateForm());
  const [createErrorMessage, setCreateErrorMessage] = useState<string | null>(null);
  const [isCreateSubmitting, setIsCreateSubmitting] = useState(false);

  const [editingItem, setEditingItem] = useState<FinansKalem | null>(null);
  const [editForm, setEditForm] = useState<FinansFormState>({
    personelId: "",
    donem: "",
    kalemTuru: "",
    tutar: "",
    aciklama: ""
  });
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [isEditSubmitting, setIsEditSubmitting] = useState(false);
  const [cancelOngoingId, setCancelOngoingId] = useState<number | null>(null);

  const applied = listQuery.applied;
  const listPage = listQuery.page;

  const activeSube = useMemo(() => getActiveSube(), [revision]);

  const listKey = useMemo(
    () =>
      dataCacheKeys.finansList(
        activeSube,
        applied.personelId,
        applied.donem,
        applied.kalemTuru,
        applied.state,
        listPage
      ),
    [activeSube, applied.donem, applied.kalemTuru, applied.personelId, applied.state, listPage]
  );

  const listSnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<FinansKalem>>(listKey),
    [listKey, revision]
  );

  const items = listSnapshot?.items ?? [];
  const hasNextPage = listSnapshot?.pagination.hasNextPage ?? false;
  const totalPages = listSnapshot?.pagination.totalPages ?? null;

  const refetch = useCallback(async () => {
    await fetchWithCacheMerge(listKey, () =>
      runDeduped(listKey, () =>
        fetchFinansKalemList({
          personel_id: parsePositiveInt(applied.personelId),
          donem: applied.donem || undefined,
          kalem_turu: applied.kalemTuru || undefined,
          state: applied.state || undefined,
          sube_id: getSubeIdForApiRequest(),
          page: listPage,
          limit: PAGE_SIZE
        })
      )
    );
  }, [applied, listKey, listPage]);

  useEffect(() => {
    let cancelled = false;
    const hasSeed = getCacheEntry<PaginatedResult<FinansKalem>>(listKey) !== undefined;
    setIsLoading(!hasSeed);
    setErrorMessage(null);

    void (async () => {
      try {
        await fetchWithCacheMerge(listKey, () =>
          runDeduped(listKey, () =>
            fetchFinansKalemList({
              personel_id: parsePositiveInt(applied.personelId),
              donem: applied.donem || undefined,
              kalem_turu: applied.kalemTuru || undefined,
              state: applied.state || undefined,
              sube_id: getSubeIdForApiRequest(),
              page: listPage,
              limit: PAGE_SIZE
            })
          )
        );
      } catch {
        if (!getCacheEntry<PaginatedResult<FinansKalem>>(listKey)) {
          setErrorMessage("Finans kayıtları şu an güncellenemiyor.");
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

  const submitFilters = useCallback((event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setListQuery((prev) => ({
      ...prev,
      applied: { ...prev.draft },
      page: 1
    }));
  }, []);

  const clearFilters = useCallback(() => {
    const empty = { personelId: "", donem: "", kalemTuru: "", state: "" };
    setListQuery({
      draft: { ...empty },
      applied: { ...empty },
      page: 1
    });
  }, []);

  const updateDraft = useCallback((partial: Partial<FinansListQueryState["draft"]>) => {
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
    setIsCreateModalOpen(true);
  }, []);

  const closeCreateModal = useCallback(() => {
    setIsCreateModalOpen(false);
  }, []);

  const refreshPageOne = useCallback(async () => {
    const pageOneKey = dataCacheKeys.finansList(
      activeSube,
      listQuery.applied.personelId,
      listQuery.applied.donem,
      listQuery.applied.kalemTuru,
      listQuery.applied.state,
      1
    );
    await fetchWithCacheMerge(pageOneKey, () =>
      runDeduped(pageOneKey, () =>
        fetchFinansKalemList({
          personel_id: parsePositiveInt(listQuery.applied.personelId),
          donem: listQuery.applied.donem || undefined,
          kalem_turu: listQuery.applied.kalemTuru || undefined,
          state: listQuery.applied.state || undefined,
          sube_id: getSubeIdForApiRequest(),
          page: 1,
          limit: PAGE_SIZE
        })
      )
    );
  }, [activeSube, listQuery.applied]);

  const createFinansHandler = useCallback(
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
        let payload: CreateFinansKalemPayload;
        try {
          payload = buildCreateFinansKalemPayload(createForm);
        } catch (error) {
          setCreateErrorMessage(getApiErrorMessage(error, "Finans kaydi olusturulamadi."));
          return;
        }

        const result = await commitFinansKalemCreate({
          payload,
          activeSube,
          applied: listQuery.applied
        });

        if (result.outcome === "error") {
          setCreateErrorMessage(result.message);
          return;
        }

        setIsCreateModalOpen(false);
        setCreateForm(createEmptyFinansCreateForm());
        setListQuery((prev) => ({ ...prev, page: 1 }));
      } finally {
        setIsCreateSubmitting(false);
      }
    },
    [activeSube, createForm, isCreateSubmitting, listQuery.applied]
  );

  const openEditModal = useCallback((item: FinansKalem, canEdit: boolean) => {
    if (!canEdit) {
      setErrorMessage("Bu kaydı düzenlemek için yetkin bulunmuyor.");
      return;
    }
    setEditingItem(item);
    setEditForm(toFormState(item));
    setEditErrorMessage(null);
  }, []);

  const closeEditModal = useCallback(() => {
    setEditingItem(null);
  }, []);

  const updateFinansHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>, canEdit: boolean) => {
      event.preventDefault();
      if (!editingItem || isEditSubmitting) {
        return;
      }
      if (!canEdit) {
        setEditErrorMessage("Bu kaydı düzenlemek için yetkin bulunmuyor.");
        return;
      }

      setEditErrorMessage(null);
      setIsEditSubmitting(true);

      const previousItem = editingItem;
      const body = {
        personel_id: parseRequiredPositiveInt(editForm.personelId, "Personel ID"),
        donem: validateDonem(editForm.donem),
        kalem_turu: editForm.kalemTuru.trim(),
        tutar: parseRequiredPositiveNumber(editForm.tutar, "Tutar"),
        aciklama: editForm.aciklama.trim() || undefined
      };

      mergeCacheEntry<PaginatedResult<FinansKalem>>(listKey, (prev) => {
        const base = prev ?? emptyPaginated<FinansKalem>();
        return {
          ...base,
          items: base.items.map((row) => (row.id === editingItem.id ? { ...row, ...body } : row))
        };
      });

      try {
        await updateFinansKalem(editingItem.id, body);
        setEditingItem(null);
        setListQuery((prev) => ({ ...prev, page: 1 }));
        await refreshPageOne();
      } catch (error) {
        if (shouldQueueOfflineMutation(error)) {
          enqueueSyncOperation({
            op: "finans.update",
            payload: { kalemId: editingItem.id, body },
            meta: { listKey }
          });
          setEditingItem(null);
          void processSyncQueue();
          return;
        }

        mergeCacheEntry<PaginatedResult<FinansKalem>>(listKey, (prev) => {
          const base = prev ?? emptyPaginated<FinansKalem>();
          return {
            ...base,
            items: base.items.map((row) => (row.id === previousItem.id ? previousItem : row))
          };
        });
        setEditErrorMessage(getApiErrorMessage(error, "Finans kaydi guncellenemedi."));
      } finally {
        setIsEditSubmitting(false);
      }
    },
    [editForm, editingItem, isEditSubmitting, listKey, refreshPageOne]
  );

  const cancelFinansHandler = useCallback(
    async (item: FinansKalem, canCancel: boolean) => {
      if (!canCancel) {
        setErrorMessage("Bu kaydı iptal etmek için yetkin bulunmuyor.");
        return;
      }

      const confirmed = window.confirm(`Finans kaydı #${item.id} iptal edilsin mi?`);
      if (!confirmed) {
        return;
      }

      setCancelOngoingId(item.id);

      mergeCacheEntry<PaginatedResult<FinansKalem>>(listKey, (prev) => {
        const base = prev ?? emptyPaginated<FinansKalem>();
        return {
          ...base,
          items: base.items.map((row) => (row.id === item.id ? { ...row, state: "IPTAL" } : row))
        };
      });

      try {
        await cancelFinansKalem(item.id);
        setListQuery((prev) => ({ ...prev, page: 1 }));
        await refreshPageOne();
      } catch (error) {
        if (shouldQueueOfflineMutation(error)) {
          enqueueSyncOperation({
            op: "finans.cancel",
            payload: { kalemId: item.id },
            meta: { listKey }
          });
          void processSyncQueue();
          return;
        }

        mergeCacheEntry<PaginatedResult<FinansKalem>>(listKey, (prev) => {
          const base = prev ?? emptyPaginated<FinansKalem>();
          return {
            ...base,
            items: base.items.map((row) => (row.id === item.id ? item : row))
          };
        });
        setErrorMessage(getApiErrorMessage(error, "Finans kaydi iptal edilemedi."));
      } finally {
        setCancelOngoingId(null);
      }
    },
    [listKey, refreshPageOne]
  );

  return {
    listQuery,
    updateDraft,
    items,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
    refetch,
    isCreateModalOpen,
    openCreateModal,
    closeCreateModal,
    createForm,
    setCreateForm,
    createErrorMessage,
    isCreateSubmitting,
    createFinansHandler,
    editingItem,
    openEditModal,
    closeEditModal,
    editForm,
    setEditForm,
    editErrorMessage,
    isEditSubmitting,
    cancelOngoingId,
    cancelFinansHandler,
    updateFinansHandler,
    submitFilters,
    clearFilters,
    setPage
  };
}

type UsePersonelFinansCreateOptions = {
  canSubmit: boolean;
  onCreateSuccess?: () => void;
  initialKalemTuru?: string;
};

export function usePersonelFinansCreate(
  parsedPersonelId: number,
  hasValidId: boolean,
  canCreateFinans: boolean,
  options: UsePersonelFinansCreateOptions
) {
  const { canSubmit, onCreateSuccess, initialKalemTuru = "AVANS" } = options;
  const revision = useAppDataRevision();
  const activeSube = useMemo(() => getActiveSube(), [revision]);
  const defaultKalemTuru = useMemo(() => initialKalemTuru.trim() || "AVANS", [initialKalemTuru]);

  const [finansFields, setFinansFields] = useState<FinansMaliFieldsState>(() =>
    createEmptyFinansMaliFields(defaultKalemTuru)
  );
  const [finansCreateErrorMessage, setFinansCreateErrorMessage] = useState<string | null>(null);
  const [isFinansSubmitting, setIsFinansSubmitting] = useState(false);

  const appliedForCommit = useMemo<FinansListAppliedFilters>(
    () => ({
      personelId: String(parsedPersonelId),
      donem: "",
      kalemTuru: "",
      state: ""
    }),
    [parsedPersonelId]
  );

  useEffect(() => {
    setFinansFields(createEmptyFinansMaliFields(defaultKalemTuru));
    setFinansCreateErrorMessage(null);
    setIsFinansSubmitting(false);
  }, [defaultKalemTuru, parsedPersonelId]);

  const createPersonelFinansHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      if (!hasValidId || !canSubmit || isFinansSubmitting) {
        return;
      }
      if (!canCreateFinans) {
        setFinansCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
        return;
      }

      setFinansCreateErrorMessage(null);
      setIsFinansSubmitting(true);

      try {
        let payload: CreateFinansKalemPayload;
        try {
          payload = buildCreateFinansKalemPayload({
            personelId: String(parsedPersonelId),
            ...finansFields
          });
        } catch (error) {
          setFinansCreateErrorMessage(getApiErrorMessage(error, "Finans kaydi olusturulamadi."));
          return;
        }

        const result = await commitFinansKalemCreate({
          payload,
          activeSube,
          applied: appliedForCommit
        });

        if (result.outcome === "error") {
          setFinansCreateErrorMessage(result.message);
          return;
        }

        setFinansFields(createEmptyFinansMaliFields(defaultKalemTuru));
        onCreateSuccess?.();
      } finally {
        setIsFinansSubmitting(false);
      }
    },
    [
      activeSube,
      appliedForCommit,
      canCreateFinans,
      canSubmit,
      defaultKalemTuru,
      hasValidId,
      isFinansSubmitting,
      finansFields,
      onCreateSuccess,
      parsedPersonelId
    ]
  );

  return {
    finansFields,
    setFinansFields,
    createPersonelFinansHandler,
    isFinansSubmitting,
    finansCreateErrorMessage,
    setFinansCreateErrorMessage
  };
}
