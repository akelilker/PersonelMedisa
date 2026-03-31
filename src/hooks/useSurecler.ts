import { useCallback, useEffect, useState, type FormEvent } from "react";
import { cancelSurec, createSurec, fetchSurecDetail, fetchSureclerList, updateSurec } from "../api/surecler.api";
import { fetchSurecTuruOptions } from "../api/referans.api";
import { runDeduped } from "../lib/in-flight-dedupe";
import type { KeyOption } from "../types/referans";
import type { Surec } from "../types/surec";

const PAGE_SIZE = 10;

let surecTuruCache: KeyOption[] | null = null;
let surecTuruPromise: Promise<KeyOption[]> | null = null;

async function loadSurecTuruReferences(): Promise<KeyOption[]> {
  if (surecTuruCache) {
    return surecTuruCache;
  }
  if (surecTuruPromise) {
    return surecTuruPromise;
  }

  surecTuruPromise = fetchSurecTuruOptions()
    .then((options) => {
      surecTuruCache = options;
      return options;
    })
    .finally(() => {
      surecTuruPromise = null;
    });

  return surecTuruPromise;
}

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

function listCacheKey(applied: SurecListQueryState["applied"], page: number) {
  return `surecler|${applied.personelId}|${applied.surecTuru}|${applied.state}|${applied.baslangicTarihi}|${applied.bitisTarihi}|${page}`;
}

export function useSurecler() {
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

  const [surecler, setSurecler] = useState<Surec[]>([]);
  const [hasNextPage, setHasNextPage] = useState(false);
  const [totalPages, setTotalPages] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [surecTuruOptions, setSurecTuruOptions] = useState<KeyOption[]>([]);
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

  const refetch = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);
    const key = listCacheKey(applied, listPage);

    try {
      const nextData = await runDeduped(key, () =>
        fetchSureclerList({
          personel_id: parsePositiveInt(applied.personelId),
          surec_turu: applied.surecTuru || undefined,
          state: applied.state || undefined,
          baslangic_tarihi: applied.baslangicTarihi || undefined,
          bitis_tarihi: applied.bitisTarihi || undefined,
          page: listPage,
          limit: PAGE_SIZE
        })
      );
      setSurecler(nextData.items);
      setHasNextPage(nextData.pagination.hasNextPage ?? nextData.items.length === PAGE_SIZE);
      setTotalPages(nextData.pagination.totalPages);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Surec listesi alinamadi.");
      setHasNextPage(false);
      setTotalPages(null);
    } finally {
      setIsLoading(false);
    }
  }, [
    applied.baslangicTarihi,
    applied.bitisTarihi,
    applied.personelId,
    applied.state,
    applied.surecTuru,
    listPage
  ]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  useEffect(() => {
    let cancelled = false;

    void (async () => {
      setReferenceError(null);
      try {
        const options = await loadSurecTuruReferences();
        if (!cancelled) {
          setSurecTuruOptions(options);
        }
      } catch (error) {
        if (!cancelled) {
          setReferenceError(
            error instanceof Error ? error.message : "Surec turleri alinamadi, manuel giris aktif."
          );
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

  const createSurecHandler = useCallback(
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
        await createSurec({
          personel_id: parseRequiredPositiveInt(createForm.personelId, "Personel ID"),
          surec_turu: createForm.surecTuru.trim(),
          alt_tur: createForm.altTur.trim() || undefined,
          baslangic_tarihi: createForm.baslangicTarihi,
          bitis_tarihi: createForm.bitisTarihi,
          ucretli_mi: createForm.ucretliMi,
          aciklama: createForm.aciklama.trim() || undefined
        });

        setIsCreateModalOpen(false);
        setCreateForm(INITIAL_SUREC_FORM);
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
        setCreateErrorMessage(error instanceof Error ? error.message : "Surec kaydi yapilamadi.");
      } finally {
        setIsCreateSubmitting(false);
      }
    },
    [createForm, isCreateSubmitting, listPage, refetch]
  );

  const openEditModal = useCallback((surec: Surec, canEdit: boolean) => {
    if (!canEdit) {
      setErrorMessage("Bu sureci duzenlemek icin yetkin bulunmuyor.");
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
        setEditErrorMessage("Bu sureci duzenlemek icin yetkin bulunmuyor.");
        return;
      }

      setEditErrorMessage(null);
      setIsEditSubmitting(true);

      try {
        await updateSurec(editingSurec.id, {
          personel_id: parseRequiredPositiveInt(editForm.personelId, "Personel ID"),
          surec_turu: editForm.surecTuru.trim(),
          alt_tur: editForm.altTur.trim() || undefined,
          baslangic_tarihi: editForm.baslangicTarihi,
          bitis_tarihi: editForm.bitisTarihi,
          ucretli_mi: editForm.ucretliMi,
          aciklama: editForm.aciklama.trim() || undefined
        });

        setEditingSurec(null);
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
        setEditErrorMessage(error instanceof Error ? error.message : "Surec guncellenemedi.");
      } finally {
        setIsEditSubmitting(false);
      }
    },
    [editForm, editingSurec, isEditSubmitting, listPage, refetch]
  );

  const cancelSurecHandler = useCallback(
    async (surec: Surec, canCancel: boolean) => {
      if (!canCancel) {
        setErrorMessage("Bu sureci iptal etmek icin yetkin bulunmuyor.");
        return;
      }

      const confirmed = window.confirm(`Surec #${surec.id} kaydini iptal etmek istiyor musun?`);
      if (!confirmed) {
        return;
      }

      setCancelingSurecId(surec.id);
      try {
        await cancelSurec(surec.id);
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
        setErrorMessage(error instanceof Error ? error.message : "Surec iptal edilemedi.");
      } finally {
        setCancelingSurecId(null);
      }
    },
    [listPage, refetch]
  );

  return {
    listQuery,
    updateDraft,
    surecler,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
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
  const [surec, setSurec] = useState<Surec | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const refetch = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Gecerli bir surec id verilmedi.");
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);
    const key = `surec-detail|${parsedSurecId}`;

    try {
      const data = await runDeduped(key, () => fetchSurecDetail(parsedSurecId));
      setSurec(data);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Surec detayi alinamadi.");
    } finally {
      setIsLoading(false);
    }
  }, [hasValidId, parsedSurecId]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  return { surec, isLoading, errorMessage, refetch };
}
