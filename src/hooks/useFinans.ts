import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import {
  cancelFinansKalem,
  createFinansKalem,
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
  optimisticPrependToList,
  processSyncQueue,
  useAppDataRevision
} from "../data/data-manager";
import { runDeduped } from "../lib/in-flight-dedupe";
import type { PaginatedResult } from "../types/api";
import type { CreateFinansKalemPayload, FinansKalem } from "../types/finans";

const PAGE_SIZE = 10;

function toMonthInputValue(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

export type FinansListQueryState = {
  draft: { personelId: string; donem: string; kalemTuru: string; state: string };
  applied: { personelId: string; donem: string; kalemTuru: string; state: string };
  page: number;
};

export type FinansFormState = {
  personelId: string;
  donem: string;
  kalemTuru: string;
  tutar: string;
  aciklama: string;
};

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

function parseRequiredPositiveInt(value: string, label: string): number {
  const parsed = parsePositiveInt(value);
  if (!parsed) {
    throw new Error(`${label} pozitif sayi olmalidir.`);
  }
  return parsed;
}

function parseRequiredPositiveNumber(value: string, label: string): number {
  const trimmed = value.trim();
  const parsed = Number.parseFloat(trimmed);
  if (!trimmed || Number.isNaN(parsed) || parsed <= 0) {
    throw new Error(`${label} sifirdan buyuk olmali.`);
  }
  return parsed;
}

function validateDonem(donem: string): string {
  const value = donem.trim();
  if (!/^\d{4}-\d{2}$/.test(value)) {
    throw new Error("Dönem YYYY-MM formatında olmalı.");
  }
  return value;
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

function draftFinansFromPayload(payload: CreateFinansKalemPayload, tempId: number): FinansKalem {
  return {
    id: tempId,
    personel_id: payload.personel_id,
    donem: payload.donem,
    kalem_turu: payload.kalem_turu,
    tutar: payload.tutar,
    aciklama: payload.aciklama,
    state: "AKTIF"
  };
}

const INITIAL_CREATE_FINANS_FORM: FinansFormState = {
  personelId: "",
  donem: toMonthInputValue(new Date()),
  kalemTuru: "AVANS",
  tutar: "",
  aciklama: ""
};

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
  const [createForm, setCreateForm] = useState<FinansFormState>({ ...INITIAL_CREATE_FINANS_FORM });
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
        const payload: CreateFinansKalemPayload = {
          personel_id: parseRequiredPositiveInt(createForm.personelId, "Personel ID"),
          donem: validateDonem(createForm.donem),
          kalem_turu: createForm.kalemTuru.trim(),
          tutar: parseRequiredPositiveNumber(createForm.tutar, "Tutar"),
          aciklama: createForm.aciklama.trim() || undefined
        };

        const pageOneKey = dataCacheKeys.finansList(
          activeSube,
          listQuery.applied.personelId,
          listQuery.applied.donem,
          listQuery.applied.kalemTuru,
          listQuery.applied.state,
          1
        );

        try {
          await createFinansKalem(payload);
          setIsCreateModalOpen(false);
          setCreateForm({ ...INITIAL_CREATE_FINANS_FORM });
          setListQuery((prev) => ({ ...prev, page: 1 }));
          await refreshPageOne();
        } catch {
          const tempId = makeTempId();
          optimisticPrependToList(pageOneKey, draftFinansFromPayload(payload, tempId));
          enqueueSyncOperation({
            op: "finans.create",
            payload,
            meta: { listKey: pageOneKey, tempId }
          });
          setIsCreateModalOpen(false);
          setCreateForm({ ...INITIAL_CREATE_FINANS_FORM });
          setListQuery((prev) => ({ ...prev, page: 1 }));
          void processSyncQueue();
        }
      } catch (error) {
        setCreateErrorMessage(error instanceof Error ? error.message : "Finans kaydı oluşturulamadı.");
      } finally {
        setIsCreateSubmitting(false);
      }
    },
    [activeSube, createForm, isCreateSubmitting, listQuery.applied, refreshPageOne]
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
          items: base.items.map((row) =>
            row.id === editingItem.id ? { ...row, ...body } : row
          )
        };
      });

      try {
        await updateFinansKalem(editingItem.id, body);
        setEditingItem(null);
        setListQuery((prev) => ({ ...prev, page: 1 }));
        await refreshPageOne();
      } catch {
        enqueueSyncOperation({
          op: "finans.update",
          payload: { kalemId: editingItem.id, body },
          meta: { listKey }
        });
        setEditingItem(null);
        void processSyncQueue();
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
          items: base.items.map((row) =>
            row.id === item.id ? { ...row, state: "IPTAL" } : row
          )
        };
      });

      try {
        await cancelFinansKalem(item.id);
        setListQuery((prev) => ({ ...prev, page: 1 }));
        await refreshPageOne();
      } catch {
        enqueueSyncOperation({
          op: "finans.cancel",
          payload: { kalemId: item.id },
          meta: { listKey }
        });
        void processSyncQueue();
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
