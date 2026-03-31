import { useCallback, useEffect, useState, type FormEvent } from "react";
import {
  cancelFinansKalem,
  createFinansKalem,
  fetchFinansKalemList,
  updateFinansKalem
} from "../api/finans.api";
import { runDeduped } from "../lib/in-flight-dedupe";
import type { FinansKalem } from "../types/finans";

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
    throw new Error("Donem YYYY-MM formatinda olmali.");
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

const INITIAL_CREATE_FINANS_FORM: FinansFormState = {
  personelId: "",
  donem: toMonthInputValue(new Date()),
  kalemTuru: "AVANS",
  tutar: "",
  aciklama: ""
};

function listCacheKey(applied: FinansListQueryState["applied"], page: number) {
  return `finans|${applied.personelId}|${applied.donem}|${applied.kalemTuru}|${applied.state}|${page}`;
}

export function useFinans() {
  const [listQuery, setListQuery] = useState<FinansListQueryState>({
    draft: { personelId: "", donem: "", kalemTuru: "", state: "" },
    applied: { personelId: "", donem: "", kalemTuru: "", state: "" },
    page: 1
  });

  const [items, setItems] = useState<FinansKalem[]>([]);
  const [hasNextPage, setHasNextPage] = useState(false);
  const [totalPages, setTotalPages] = useState<number | null>(null);
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

  const refetch = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);
    const key = listCacheKey(applied, listPage);

    try {
      const result = await runDeduped(key, () =>
        fetchFinansKalemList({
          personel_id: parsePositiveInt(applied.personelId),
          donem: applied.donem || undefined,
          kalem_turu: applied.kalemTuru || undefined,
          state: applied.state || undefined,
          page: listPage,
          limit: PAGE_SIZE
        })
      );
      setItems(result.items);
      setHasNextPage(result.pagination.hasNextPage ?? result.items.length === PAGE_SIZE);
      setTotalPages(result.pagination.totalPages);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Finans kayitlari alinamadi.");
      setItems([]);
      setHasNextPage(false);
      setTotalPages(null);
    } finally {
      setIsLoading(false);
    }
  }, [applied.donem, applied.kalemTuru, applied.personelId, applied.state, listPage]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

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

  const createFinansHandler = useCallback(
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
        await createFinansKalem({
          personel_id: parseRequiredPositiveInt(createForm.personelId, "Personel ID"),
          donem: validateDonem(createForm.donem),
          kalem_turu: createForm.kalemTuru.trim(),
          tutar: parseRequiredPositiveNumber(createForm.tutar, "Tutar"),
          aciklama: createForm.aciklama.trim() || undefined
        });

        setIsCreateModalOpen(false);
        setCreateForm({ ...INITIAL_CREATE_FINANS_FORM });
        setListQuery((prev) => {
          if (prev.page !== 1) {
            return { ...prev, page: 1 };
          }
          return prev;
        });

        if (listPage === 1) {
          void refetch();
        }
      } catch (error) {
        setCreateErrorMessage(error instanceof Error ? error.message : "Finans kaydi olusturulamadi.");
      } finally {
        setIsCreateSubmitting(false);
      }
    },
    [createForm, isCreateSubmitting, listPage, refetch]
  );

  const openEditModal = useCallback((item: FinansKalem, canEdit: boolean) => {
    if (!canEdit) {
      setErrorMessage("Bu kaydi duzenlemek icin yetkin bulunmuyor.");
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
        setEditErrorMessage("Bu kaydi duzenlemek icin yetkin bulunmuyor.");
        return;
      }

      setEditErrorMessage(null);
      setIsEditSubmitting(true);

      try {
        await updateFinansKalem(editingItem.id, {
          personel_id: parseRequiredPositiveInt(editForm.personelId, "Personel ID"),
          donem: validateDonem(editForm.donem),
          kalem_turu: editForm.kalemTuru.trim(),
          tutar: parseRequiredPositiveNumber(editForm.tutar, "Tutar"),
          aciklama: editForm.aciklama.trim() || undefined
        });

        setEditingItem(null);
        setListQuery((prev) => {
          if (prev.page !== 1) {
            return { ...prev, page: 1 };
          }
          return prev;
        });

        if (listPage === 1) {
          void refetch();
        }
      } catch (error) {
        setEditErrorMessage(error instanceof Error ? error.message : "Finans kaydi guncellenemedi.");
      } finally {
        setIsEditSubmitting(false);
      }
    },
    [editForm, editingItem, isEditSubmitting, listPage, refetch]
  );

  const cancelFinansHandler = useCallback(
    async (item: FinansKalem, canCancel: boolean) => {
      if (!canCancel) {
        setErrorMessage("Bu kaydi iptal etmek icin yetkin bulunmuyor.");
        return;
      }

      const confirmed = window.confirm(`Finans kaydi #${item.id} iptal edilsin mi?`);
      if (!confirmed) {
        return;
      }

      setCancelOngoingId(item.id);
      try {
        await cancelFinansKalem(item.id);
        setListQuery((prev) => {
          if (prev.page !== 1) {
            return { ...prev, page: 1 };
          }
          return prev;
        });

        if (listPage === 1) {
          void refetch();
        }
      } catch (error) {
        setErrorMessage(error instanceof Error ? error.message : "Finans kaydi iptal edilemedi.");
      } finally {
        setCancelOngoingId(null);
      }
    },
    [listPage, refetch]
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
