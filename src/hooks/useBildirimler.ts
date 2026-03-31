import { useCallback, useEffect, useState, type FormEvent } from "react";
import {
  cancelBildirim,
  createBildirim,
  fetchBildirimDetail,
  fetchBildirimlerList,
  markBildirimOkundu,
  updateBildirim
} from "../api/bildirimler.api";
import { fetchBildirimTuruOptions, fetchDepartmanOptions } from "../api/referans.api";
import { runDeduped } from "../lib/in-flight-dedupe";
import type { IdOption, KeyOption } from "../types/referans";
import type { Bildirim } from "../types/bildirim";

const PAGE_SIZE = 10;

type BildirimReferences = {
  departmanOptions: IdOption[];
  bildirimTuruOptions: KeyOption[];
};

let bildirimReferencesCache: BildirimReferences | null = null;
let bildirimReferencesPromise: Promise<BildirimReferences> | null = null;

async function loadBildirimReferences(): Promise<BildirimReferences> {
  if (bildirimReferencesCache) {
    return bildirimReferencesCache;
  }
  if (bildirimReferencesPromise) {
    return bildirimReferencesPromise;
  }

  bildirimReferencesPromise = (async () => {
    const [departmanOptions, bildirimTuruOptions] = await Promise.all([
      fetchDepartmanOptions(),
      fetchBildirimTuruOptions()
    ]);
    const snapshot = { departmanOptions, bildirimTuruOptions };
    bildirimReferencesCache = snapshot;
    return snapshot;
  })();

  try {
    return await bildirimReferencesPromise;
  } finally {
    bildirimReferencesPromise = null;
  }
}

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

function listCacheKey(applied: BildirimListQueryState["applied"], page: number) {
  return `bildirimler|${applied.personelId}|${applied.bildirimTuru}|${applied.tarih}|${page}`;
}

export function useBildirimler() {
  const [listQuery, setListQuery] = useState<BildirimListQueryState>({
    draft: { personelId: "", bildirimTuru: "", tarih: "" },
    applied: { personelId: "", bildirimTuru: "", tarih: "" },
    page: 1
  });

  const [bildirimler, setBildirimler] = useState<Bildirim[]>([]);
  const [hasNextPage, setHasNextPage] = useState(false);
  const [totalPages, setTotalPages] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [departmanOptions, setDepartmanOptions] = useState<IdOption[]>([]);
  const [bildirimTuruOptions, setBildirimTuruOptions] = useState<KeyOption[]>([]);
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

  const refetch = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);
    const key = listCacheKey(applied, listPage);

    try {
      const nextData = await runDeduped(key, () =>
        fetchBildirimlerList({
          personel_id: parsePositiveInt(applied.personelId),
          bildirim_turu: applied.bildirimTuru || undefined,
          tarih: applied.tarih || undefined,
          page: listPage,
          limit: PAGE_SIZE
        })
      );
      setBildirimler(nextData.items);
      setHasNextPage(nextData.pagination.hasNextPage ?? nextData.items.length === PAGE_SIZE);
      setTotalPages(nextData.pagination.totalPages);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Bildirim listesi alinamadi.");
      setHasNextPage(false);
      setTotalPages(null);
    } finally {
      setIsLoading(false);
    }
  }, [applied.bildirimTuru, applied.personelId, applied.tarih, listPage]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  useEffect(() => {
    let cancelled = false;

    void (async () => {
      setReferenceError(null);
      try {
        const snapshot = await loadBildirimReferences();
        if (cancelled) {
          return;
        }
        setDepartmanOptions(snapshot.departmanOptions);
        setBildirimTuruOptions(snapshot.bildirimTuruOptions);
      } catch (error) {
        if (cancelled) {
          return;
        }
        setReferenceError(
          error instanceof Error ? error.message : "Bildirim referanslari alinamadi, manuel giris aktif."
        );
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
        await createBildirim({
          tarih: createForm.tarih,
          departman_id: parseRequiredPositiveInt(createForm.departmanId, "Departman ID"),
          personel_id: parseRequiredPositiveInt(createForm.personelId, "Personel ID"),
          bildirim_turu: createForm.bildirimTuru.trim(),
          aciklama: createForm.aciklama.trim() || undefined
        });

        setIsCreateModalOpen(false);
        setCreateForm(INITIAL_BILDIRIM_FORM);
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
        setCreateErrorMessage(error instanceof Error ? error.message : "Bildirim kaydi yapilamadi.");
      } finally {
        setIsCreateSubmitting(false);
      }
    },
    [createForm, isCreateSubmitting, listPage, refetch]
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

      try {
        await updateBildirim(editingBildirim.id, {
          tarih: editForm.tarih,
          departman_id: parseRequiredPositiveInt(editForm.departmanId, "Departman ID"),
          personel_id: parseRequiredPositiveInt(editForm.personelId, "Personel ID"),
          bildirim_turu: editForm.bildirimTuru.trim(),
          aciklama: editForm.aciklama.trim() || undefined
        });

        setEditingBildirim(null);
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
        setEditErrorMessage(error instanceof Error ? error.message : "Bildirim guncellenemedi.");
      } finally {
        setIsEditSubmitting(false);
      }
    },
    [editForm, editingBildirim, isEditSubmitting, listPage, refetch]
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
      try {
        await cancelBildirim(bildirim.id);
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
        setErrorMessage(error instanceof Error ? error.message : "Bildirim iptal edilemedi.");
      } finally {
        setCancelingBildirimId(null);
      }
    },
    [listPage, refetch]
  );

  return {
    listQuery,
    updateDraft,
    bildirimler,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
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

const HEADER_PREVIEW_DEDUPE_KEY = "bildirimler:header-preview:1:8";

export function useBildirimlerHeaderPreview(enabled: boolean) {
  const [items, setItems] = useState<Bildirim[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const reload = useCallback(async () => {
    if (!enabled) {
      setItems([]);
      setErrorMessage(null);
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    try {
      const response = await runDeduped(HEADER_PREVIEW_DEDUPE_KEY, () =>
        fetchBildirimlerList({ page: 1, limit: 8 })
      );
      setItems(response.items);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Bildirimler yuklenemedi.");
      setItems([]);
    } finally {
      setIsLoading(false);
    }
  }, [enabled]);

  useEffect(() => {
    void reload();
  }, [reload]);

  const markOkundu = useCallback((id: number) => markBildirimOkundu(id), []);

  return { items, isLoading, errorMessage, reload, markOkundu };
}

export function useBildirimDetail(parsedBildirimId: number, hasValidId: boolean) {
  const [bildirim, setBildirim] = useState<Bildirim | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const refetch = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Gecerli bir bildirim id verilmedi.");
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);
    const key = `bildirim-detail|${parsedBildirimId}`;

    try {
      const data = await runDeduped(key, () => fetchBildirimDetail(parsedBildirimId));
      setBildirim(data);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Bildirim detayi alinamadi.");
    } finally {
      setIsLoading(false);
    }
  }, [hasValidId, parsedBildirimId]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  return { bildirim, isLoading, errorMessage, refetch };
}
