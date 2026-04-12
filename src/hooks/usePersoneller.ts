import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { getApiErrorMessage, shouldQueueOfflineMutation } from "../api/api-client";
import {
  createPersonel,
  fetchPersonelDetail,
  fetchPersonellerList,
  updatePersonel,
  type CreatePersonelPayload
} from "../api/personeller.api";
import { fetchBagliAmirOptions, fetchDepartmanOptions, fetchGorevOptions, fetchPersonelTipiOptions, fetchSurecTuruOptions } from "../api/referans.api";
import { createSurec, fetchSureclerList } from "../api/surecler.api";
import { emptyPaginated, makeTempId, type PersonelReferenceBundle } from "../data/app-data.types";
import {
  dataCacheKeys,
  deleteCacheEntry,
  draftPersonelFromPayload,
  enqueueSyncOperation,
  fetchWithCacheMerge,
  getActiveSube,
  getCacheEntry,
  getSubeIdForApiRequest,
  mergeCacheEntry,
  optimisticPrependPersonel,
  processSyncQueue,
  useAppDataRevision
} from "../data/data-manager";
import {
  SUBE_DETAIL_REDIRECT_MESSAGE,
  SUBE_DETAIL_REDIRECT_STATE_KEY,
  shouldRedirectDetailAfterSubeMismatch
} from "../lib/detail-sube-context";
import {
  buildCreatePersonelPayload,
  parseOptionalPositiveInt
} from "../features/personeller/personel-create-utils";
import type { PaginatedResult } from "../types/api";
import { runDeduped } from "../lib/in-flight-dedupe";
import { useAuth } from "../state/auth.store";
import type { Personel } from "../types/personel";
import type { KeyOption } from "../types/referans";
import type { Surec } from "../types/surec";
const PAGE_SIZE = 10;
const PERSONEL_DETAIL_SUREC_PAGE_SIZE = 20;

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
  departmanId: string;
  gorevId: string;
  personelTipiId: string;
  aktifDurum: "AKTIF" | "PASIF";
  dogumYeri: string;
  kanGrubu: string;
  bagliAmirId: string;
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
  departmanId: "",
  gorevId: "",
  personelTipiId: "",
  aktifDurum: "AKTIF",
  dogumYeri: "",
  kanGrubu: "",
  bagliAmirId: ""
};

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
        bagliAmirOptions: []
      }
    );
  }, [revision]);

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
            const [departmanOptions, gorevOptions, personelTipiOptions, bagliAmirOptions] = await Promise.all([
              fetchDepartmanOptions(),
              fetchGorevOptions(),
              fetchPersonelTipiOptions(),
              fetchBagliAmirOptions()
            ]);
            return {
              departmanOptions,
              gorevOptions,
              personelTipiOptions,
              bagliAmirOptions
            } satisfies PersonelReferenceBundle;
          })
        );
      } catch {
        if (!cancelled) {
      setReferenceError("Referans veriler şu an güncellenemiyor, manuel giriş kullanılabilir.");
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
    setIsCreateModalOpen(true);
  }, []);

  const closeCreateModal = useCallback(() => {
    setIsCreateModalOpen(false);
  }, []);

  const createPersonelHandler = useCallback(
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

type EditPersonelFormState = {
  ad: string;
  soyad: string;
  telefon: string;
  aktifDurum: "AKTIF" | "PASIF";
};

type PersonelSurecFormState = {
  surecTuru: string;
  baslangicTarihi: string;
  bitisTarihi: string;
  aciklama: string;
};

const INITIAL_PERSONEL_SUREC_FORM: PersonelSurecFormState = {
  surecTuru: "",
  baslangicTarihi: "",
  bitisTarihi: "",
  aciklama: ""
};

function normalizeSurecDateValue(value: string | undefined) {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const parsed = Date.parse(`${trimmed}T00:00:00`);
  return Number.isFinite(parsed) ? parsed : null;
}

function sortSurecHistory(items: Surec[]) {
  return [...items].sort((left, right) => {
    const rightStart = normalizeSurecDateValue(right.baslangic_tarihi);
    const leftStart = normalizeSurecDateValue(left.baslangic_tarihi);

    if (rightStart !== null && leftStart !== null && rightStart !== leftStart) {
      return rightStart - leftStart;
    }

    if (rightStart !== null) {
      return -1;
    }

    if (leftStart !== null) {
      return 1;
    }

    const rightEnd = normalizeSurecDateValue(right.bitis_tarihi);
    const leftEnd = normalizeSurecDateValue(left.bitis_tarihi);
    if (rightEnd !== null && leftEnd !== null && rightEnd !== leftEnd) {
      return rightEnd - leftEnd;
    }
    if (rightEnd !== null) {
      return -1;
    }
    if (leftEnd !== null) {
      return 1;
    }

    return right.id - left.id;
  });
}

function mergeSurecHistoryRow(items: Surec[], next: Surec) {
  return sortSurecHistory([next, ...items.filter((item) => item.id !== next.id)]);
}

function buildPersonelSurecPayload(
  personelId: number,
  form: PersonelSurecFormState
) {
  const surecTuru = form.surecTuru.trim();
  const baslangicTarihi = form.baslangicTarihi.trim();

  if (!surecTuru) {
    throw new Error("Surec turu zorunludur.");
  }

  if (!baslangicTarihi) {
    throw new Error("Baslangic tarihi zorunludur.");
  }

  return {
    personel_id: personelId,
    surec_turu: surecTuru,
    baslangic_tarihi: baslangicTarihi,
    bitis_tarihi: form.bitisTarihi.trim() || undefined,
    aciklama: form.aciklama.trim() || undefined
  };
}

function draftSurecFromPayload(personelId: number, form: PersonelSurecFormState, tempId: number): Surec {
  return {
    id: tempId,
    personel_id: personelId,
    surec_turu: form.surecTuru.trim(),
    baslangic_tarihi: form.baslangicTarihi.trim(),
    bitis_tarihi: form.bitisTarihi.trim() || undefined,
    aciklama: form.aciklama.trim() || undefined,
    state: "BEKLEMEDE"
  };
}

function applyTerminationToPersonel(personel: Personel): Personel {
  return {
    ...personel,
    aktif_durum: "PASIF",
    pasiflik_durumu_etiketi: "Isten Ayrildi"
  };
}

export function usePersonelDetail(
  parsedPersonelId: number,
  hasValidId: boolean,
  options: {
    canViewSurecler?: boolean;
    canCreateSurec?: boolean;
  } = {}
) {
  const navigate = useNavigate();
  const { session } = useAuth();
  const activeSubeId = session?.active_sube_id ?? null;
  const revision = useAppDataRevision();
  const canAccessSurecler = Boolean(options.canViewSurecler || options.canCreateSurec);
  const canCreateSurec = Boolean(options.canCreateSurec);
  const detailKey = useMemo(
    () => dataCacheKeys.personelDetail(activeSubeId, parsedPersonelId),
    [activeSubeId, parsedPersonelId]
  );
  const surecHistoryKey = useMemo(
    () => dataCacheKeys.sureclerList(activeSubeId, String(parsedPersonelId), "", "", "", "", 1),
    [activeSubeId, parsedPersonelId]
  );
  const surecTuruRefKey = dataCacheKeys.surecTuruRef();
  const cached = useMemo(() => getCacheEntry<Personel>(detailKey), [detailKey, revision]);
  const surecHistorySnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<Surec>>(surecHistoryKey),
    [revision, surecHistoryKey]
  );
  const surecTuruOptions = useMemo(
    () => getCacheEntry<KeyOption[]>(surecTuruRefKey) ?? [],
    [revision, surecTuruRefKey]
  );

  const [personel, setPersonel] = useState<Personel | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isEditing, setIsEditing] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [editForm, setEditForm] = useState<EditPersonelFormState>({
    ad: "",
    soyad: "",
    telefon: "",
    aktifDurum: "AKTIF"
  });
  const [isSurecModalOpen, setIsSurecModalOpen] = useState(false);
  const [isSurecSubmitting, setIsSurecSubmitting] = useState(false);
  const [surecCreateErrorMessage, setSurecCreateErrorMessage] = useState<string | null>(null);
  const [surecHistoryErrorMessage, setSurecHistoryErrorMessage] = useState<string | null>(null);
  const [surecReferenceErrorMessage, setSurecReferenceErrorMessage] = useState<string | null>(null);
  const [isSurecHistoryLoading, setIsSurecHistoryLoading] = useState(false);
  const [surecForm, setSurecForm] = useState<PersonelSurecFormState>(INITIAL_PERSONEL_SUREC_FORM);

  useEffect(() => {
    if (cached) {
      setPersonel(cached);
      setEditForm({
        ad: cached.ad,
        soyad: cached.soyad,
        telefon: cached.telefon ?? "",
        aktifDurum: cached.aktif_durum
      });
    }
  }, [cached]);

  useEffect(() => {
    deleteCacheEntry(detailKey);
  }, [detailKey, activeSubeId]);

  const refetch = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Geçerli bir personel ID verilmedi.");
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    try {
      const data = await fetchWithCacheMerge(detailKey, () =>
        runDeduped(detailKey, () => fetchPersonelDetail(parsedPersonelId))
      );
      setPersonel(data);
      setEditForm({
        ad: data.ad,
        soyad: data.soyad,
        telefon: data.telefon ?? "",
        aktifDurum: data.aktif_durum
      });
    } catch (error) {
      if (shouldRedirectDetailAfterSubeMismatch(error)) {
        setPersonel(null);
        navigate("/personeller", {
          replace: true,
          state: { [SUBE_DETAIL_REDIRECT_STATE_KEY]: SUBE_DETAIL_REDIRECT_MESSAGE }
        });
        return;
      }
      if (!getCacheEntry<Personel>(detailKey)) {
        setErrorMessage("Personel detayi su an guncellenemiyor.");
      }
    } finally {
      setIsLoading(false);
    }
  }, [activeSubeId, detailKey, hasValidId, navigate, parsedPersonelId]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  useEffect(() => {
    setIsSurecModalOpen(false);
    setSurecCreateErrorMessage(null);
    setSurecHistoryErrorMessage(null);
    setSurecReferenceErrorMessage(null);
    setSurecForm(INITIAL_PERSONEL_SUREC_FORM);
  }, [parsedPersonelId]);

  useEffect(() => {
    if (!hasValidId || !canAccessSurecler) {
      setIsSurecHistoryLoading(false);
      setSurecHistoryErrorMessage(null);
      return;
    }

    let cancelled = false;
    const hasSeed = getCacheEntry<PaginatedResult<Surec>>(surecHistoryKey) !== undefined;
    setIsSurecHistoryLoading(!hasSeed);
    setSurecHistoryErrorMessage(null);

    void (async () => {
      try {
        await fetchWithCacheMerge(surecHistoryKey, () =>
          runDeduped(surecHistoryKey, () =>
            fetchSureclerList({
              personel_id: parsedPersonelId,
              sube_id: getSubeIdForApiRequest(),
              page: 1,
              limit: PERSONEL_DETAIL_SUREC_PAGE_SIZE
            })
          )
        );
      } catch {
        if (!getCacheEntry<PaginatedResult<Surec>>(surecHistoryKey)) {
          setSurecHistoryErrorMessage("Surec gecmisi su an guncellenemiyor.");
        }
      } finally {
        if (!cancelled) {
          setIsSurecHistoryLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [canAccessSurecler, hasValidId, parsedPersonelId, surecHistoryKey]);

  useEffect(() => {
    if (!canCreateSurec) {
      setSurecReferenceErrorMessage(null);
      return;
    }

    let cancelled = false;
    setSurecReferenceErrorMessage(null);

    void (async () => {
      try {
        await fetchWithCacheMerge(surecTuruRefKey, () =>
          runDeduped(surecTuruRefKey, () => fetchSurecTuruOptions())
        );
      } catch {
        if (!cancelled) {
          setSurecReferenceErrorMessage("Surec turleri su an guncellenemiyor.");
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [canCreateSurec, surecTuruRefKey]);

  const surecHistory = useMemo(() => {
    if (!canAccessSurecler) {
      return [];
    }

    return sortSurecHistory(surecHistorySnapshot?.items ?? []);
  }, [canAccessSurecler, surecHistorySnapshot]);

  const openSurecModal = useCallback(() => {
    if (!canCreateSurec) {
      setSurecCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
      return;
    }

    setSurecCreateErrorMessage(null);
    setSurecForm(INITIAL_PERSONEL_SUREC_FORM);
    setIsSurecModalOpen(true);
  }, [canCreateSurec]);

  const closeSurecModal = useCallback(() => {
    setIsSurecModalOpen(false);
  }, []);

  const discardEdit = useCallback(() => {
    if (!personel) {
      return;
    }
    setIsEditing(false);
    setEditErrorMessage(null);
    setEditForm({
      ad: personel.ad,
      soyad: personel.soyad,
      telefon: personel.telefon ?? "",
      aktifDurum: personel.aktif_durum
    });
  }, [personel]);

  const updatePersonelHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>, canEdit: boolean) => {
      event.preventDefault();
      if (!personel || isSubmitting) {
        return;
      }
      if (!canEdit) {
        setEditErrorMessage("Bu kaydı güncellemek için yetkin bulunmuyor.");
        return;
      }

      setEditErrorMessage(null);
      setIsSubmitting(true);

      const previousPersonel = personel;
      const body = {
        ad: editForm.ad.trim(),
        soyad: editForm.soyad.trim(),
        telefon: editForm.telefon.trim(),
        aktif_durum: editForm.aktifDurum
      };

      const optimistic: Personel = { ...personel, ...body };

      mergeCacheEntry<Personel>(detailKey, () => optimistic);
      setPersonel(optimistic);

      try {
        const updated = await updatePersonel(personel.id, body);
        mergeCacheEntry<Personel>(detailKey, () => updated);
        setPersonel(updated);
        setEditForm({
          ad: updated.ad,
          soyad: updated.soyad,
          telefon: updated.telefon ?? "",
          aktifDurum: updated.aktif_durum
        });
        setIsEditing(false);
      } catch (error) {
        if (shouldRedirectDetailAfterSubeMismatch(error)) {
          navigate("/personeller", {
            replace: true,
            state: { [SUBE_DETAIL_REDIRECT_STATE_KEY]: SUBE_DETAIL_REDIRECT_MESSAGE }
          });
          return;
        }

        if (shouldQueueOfflineMutation(error)) {
          enqueueSyncOperation({
            op: "personeller.update",
            payload: { personelId: personel.id, body },
            meta: { detailKey }
          });
          void processSyncQueue();
          setEditErrorMessage(null);
          setIsEditing(false);
          return;
        }

        mergeCacheEntry<Personel>(detailKey, () => previousPersonel);
        setPersonel(previousPersonel);
        setEditErrorMessage(getApiErrorMessage(error, "Personel kaydi guncellenemedi."));
      } finally {
        setIsSubmitting(false);
      }
    },
    [detailKey, editForm, isSubmitting, navigate, personel]
  );

  const createSurecHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      if (!personel || isSurecSubmitting) {
        return;
      }

      if (!canCreateSurec) {
        setSurecCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
        return;
      }

      setSurecCreateErrorMessage(null);
      setIsSurecSubmitting(true);

      try {
        const payload = buildPersonelSurecPayload(personel.id, surecForm);

        try {
          const created = await createSurec(payload);
          mergeCacheEntry<PaginatedResult<Surec>>(surecHistoryKey, (prev) => {
            const base = prev ?? emptyPaginated<Surec>();
            return {
              ...base,
              items: mergeSurecHistoryRow(base.items, created)
            };
          });

          if (payload.surec_turu === "ISTEN_AYRILMA") {
            const nextPersonel = applyTerminationToPersonel(personel);
            mergeCacheEntry<Personel>(detailKey, () => nextPersonel);
            setPersonel(nextPersonel);
            setEditForm((prev) => ({ ...prev, aktifDurum: "PASIF" }));
          }

          setIsSurecModalOpen(false);
          setSurecForm(INITIAL_PERSONEL_SUREC_FORM);
        } catch (error) {
          if (!shouldQueueOfflineMutation(error)) {
            throw error;
          }

          const tempId = makeTempId();
          const draft = draftSurecFromPayload(personel.id, surecForm, tempId);
          mergeCacheEntry<PaginatedResult<Surec>>(surecHistoryKey, (prev) => {
            const base = prev ?? emptyPaginated<Surec>();
            return {
              ...base,
              items: mergeSurecHistoryRow(base.items, draft)
            };
          });
          enqueueSyncOperation({
            op: "surecler.create",
            payload,
            meta: { listKey: surecHistoryKey, tempId }
          });

          if (payload.surec_turu === "ISTEN_AYRILMA") {
            const nextPersonel = applyTerminationToPersonel(personel);
            mergeCacheEntry<Personel>(detailKey, () => nextPersonel);
            setPersonel(nextPersonel);
            setEditForm((prev) => ({ ...prev, aktifDurum: "PASIF" }));
          }

          setIsSurecModalOpen(false);
          setSurecForm(INITIAL_PERSONEL_SUREC_FORM);
          void processSyncQueue();
        }
      } catch (error) {
        setSurecCreateErrorMessage(getApiErrorMessage(error, "Surec kaydi yapilamadi."));
      } finally {
        setIsSurecSubmitting(false);
      }
    },
    [canCreateSurec, detailKey, isSurecSubmitting, personel, surecForm, surecHistoryKey]
  );

  return {
    personel,
    isLoading,
    errorMessage,
    refetch,
    isEditing,
    setIsEditing,
    isSubmitting,
    editErrorMessage,
    editForm,
    setEditForm,
    discardEdit,
    updatePersonelHandler,
    isSurecModalOpen,
    openSurecModal,
    closeSurecModal,
    surecForm,
    setSurecForm,
    createSurecHandler,
    isSurecSubmitting,
    surecCreateErrorMessage,
    surecHistory,
    isSurecHistoryLoading,
    surecHistoryErrorMessage,
    surecTuruOptions,
    surecReferenceErrorMessage
  };
}
