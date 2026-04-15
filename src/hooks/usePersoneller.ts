import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { getApiErrorMessage, shouldQueueOfflineMutation } from "../api/api-client";
import {
  createPersonel,
  fetchPersonelDetail,
  fetchPersonellerList,
  updatePersonel,
  type CreatePersonelPayload,
  type UpdatePersonelPayload
} from "../api/personeller.api";
import { fetchBagliAmirOptions, fetchDepartmanOptions, fetchGorevOptions, fetchPersonelTipiOptions, fetchSurecTuruOptions } from "../api/referans.api";
import { createSurec, fetchSureclerList } from "../api/surecler.api";
import { createZimmet, fetchZimmetlerList } from "../api/zimmetler.api";
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
import {
  computeHasLifecycleDiff,
  lifecycleSnapshotToPersonelPatch,
  snapshotFromLifecycleForm,
  type LifecycleFormFields
} from "../lib/personel-lifecycle-diff";
import { sortSurecHistoryDescending } from "../lib/surec-history-sort";
import type { PaginatedResult } from "../types/api";
import { runDeduped } from "../lib/in-flight-dedupe";
import { useAuth } from "../state/auth.store";
import type { Personel } from "../types/personel";
import type { KeyOption } from "../types/referans";
import type { Surec } from "../types/surec";
import type { CreateZimmetPayload, Zimmet } from "../types/zimmet";
const PAGE_SIZE = 10;
const PERSONEL_DETAIL_SUREC_PAGE_SIZE = 20;
const PERSONEL_DETAIL_ZIMMET_PAGE_SIZE = 20;

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
  departmanId: string;
  gorevId: string;
  bagliAmirId: string;
  ucretTipi: string;
  maasTutari: string;
  primKuraliId: string;
  effectiveDate: string;
};

function pickLifecycleFormFields(form: EditPersonelFormState): LifecycleFormFields {
  return {
    departmanId: form.departmanId,
    gorevId: form.gorevId,
    bagliAmirId: form.bagliAmirId,
    ucretTipi: form.ucretTipi,
    maasTutari: form.maasTutari,
    primKuraliId: form.primKuraliId
  };
}

function personelToEditForm(personel: Personel): EditPersonelFormState {
  return {
    ad: personel.ad,
    soyad: personel.soyad,
    telefon: personel.telefon ?? "",
    departmanId: personel.departman_id != null ? String(personel.departman_id) : "",
    gorevId: personel.gorev_id != null ? String(personel.gorev_id) : "",
    bagliAmirId: personel.bagli_amir_id != null ? String(personel.bagli_amir_id) : "",
    ucretTipi: personel.ucret_tipi ?? "",
    maasTutari: personel.maas_tutari != null ? String(personel.maas_tutari) : "",
    primKuraliId: personel.prim_kurali_id != null ? String(personel.prim_kurali_id) : "",
    effectiveDate: ""
  };
}

function buildPersonelUpdatePayload(
  editForm: EditPersonelFormState,
  hasLifecycleDiff: boolean
): UpdatePersonelPayload {
  const payload: UpdatePersonelPayload = {
    ad: editForm.ad.trim(),
    soyad: editForm.soyad.trim(),
    telefon: editForm.telefon.trim()
  };

  if (!hasLifecycleDiff) {
    return payload;
  }

  const setOptionalId = (
    key: "departman_id" | "gorev_id" | "bagli_amir_id" | "prim_kurali_id",
    raw: string
  ) => {
    const trimmed = raw.trim();
    if (trimmed === "") {
      payload[key] = null;
      return;
    }

    const parsed = Number.parseInt(trimmed, 10);
    if (Number.isFinite(parsed) && parsed > 0) {
      payload[key] = parsed;
    }
  };

  setOptionalId("departman_id", editForm.departmanId);
  setOptionalId("gorev_id", editForm.gorevId);
  setOptionalId("bagli_amir_id", editForm.bagliAmirId);
  setOptionalId("prim_kurali_id", editForm.primKuraliId);

  const ucret = editForm.ucretTipi.trim();
  payload.ucret_tipi = ucret === "" ? null : ucret;

  const maasRaw = editForm.maasTutari.trim();
  if (maasRaw === "") {
    payload.maas_tutari = null;
  } else {
    const parsed = Number.parseFloat(maasRaw.replace(",", "."));
    payload.maas_tutari = Number.isFinite(parsed) ? parsed : null;
  }

  payload.effective_date = editForm.effectiveDate.trim();

  return payload;
}

type PersonelSurecFormState = {
  surecTuru: string;
  baslangicTarihi: string;
  bitisTarihi: string;
  aciklama: string;
};

type PersonelZimmetFormState = {
  urunTuru: string;
  teslimTarihi: string;
  teslimEden: string;
  aciklama: string;
  teslimDurumu: string;
};

const INITIAL_PERSONEL_SUREC_FORM: PersonelSurecFormState = {
  surecTuru: "",
  baslangicTarihi: "",
  bitisTarihi: "",
  aciklama: ""
};

const INITIAL_PERSONEL_ZIMMET_FORM: PersonelZimmetFormState = {
  urunTuru: "",
  teslimTarihi: "",
  teslimEden: "",
  aciklama: "",
  teslimDurumu: "YENI"
};

function mergeSurecHistoryRow(items: Surec[], next: Surec) {
  return sortSurecHistoryDescending([next, ...items.filter((item) => item.id !== next.id)]);
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

function normalizeZimmetDateValue(value: string | undefined) {
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

function sortZimmetHistory(items: Zimmet[]) {
  return [...items].sort((left, right) => {
    const rightDate = normalizeZimmetDateValue(right.teslim_tarihi);
    const leftDate = normalizeZimmetDateValue(left.teslim_tarihi);

    if (rightDate !== null && leftDate !== null && rightDate !== leftDate) {
      return rightDate - leftDate;
    }

    if (rightDate !== null) {
      return -1;
    }

    if (leftDate !== null) {
      return 1;
    }

    return right.id - left.id;
  });
}

function mergeZimmetHistoryRow(items: Zimmet[], next: Zimmet) {
  return sortZimmetHistory([next, ...items.filter((item) => item.id !== next.id)]);
}

function buildPersonelZimmetPayload(
  personelId: number,
  form: PersonelZimmetFormState
): CreateZimmetPayload {
  const urunTuru = form.urunTuru.trim();
  const teslimTarihi = form.teslimTarihi.trim();
  const teslimEden = form.teslimEden.trim();
  const teslimDurumu = form.teslimDurumu.trim();

  if (!urunTuru) {
    throw new Error("Urun turu zorunludur.");
  }

  if (!teslimTarihi) {
    throw new Error("Teslim tarihi zorunludur.");
  }

  if (!teslimEden) {
    throw new Error("Teslim eden bilgisi zorunludur.");
  }

  if (!teslimDurumu) {
    throw new Error("Teslim durumu zorunludur.");
  }

  return {
    personel_id: personelId,
    urun_turu: urunTuru,
    teslim_tarihi: teslimTarihi,
    teslim_eden: teslimEden,
    aciklama: form.aciklama.trim() || undefined,
    teslim_durumu: teslimDurumu
  };
}

export function usePersonelDetail(
  parsedPersonelId: number,
  hasValidId: boolean,
  options: {
    canViewSurecler?: boolean;
    canCreateSurec?: boolean;
    canCreateZimmet?: boolean;
  } = {}
) {
  const navigate = useNavigate();
  const { session } = useAuth();
  const activeSubeId = session?.active_sube_id ?? null;
  const revision = useAppDataRevision();
  const canAccessSurecler = Boolean(options.canViewSurecler || options.canCreateSurec);
  const canCreateSurec = Boolean(options.canCreateSurec);
  const canCreateZimmet = Boolean(options.canCreateZimmet);
  const detailKey = useMemo(
    () => dataCacheKeys.personelDetail(activeSubeId, parsedPersonelId),
    [activeSubeId, parsedPersonelId]
  );
  const surecHistoryKey = useMemo(
    () => dataCacheKeys.sureclerList(activeSubeId, String(parsedPersonelId), "", "", "", "", 1),
    [activeSubeId, parsedPersonelId]
  );
  const surecTuruRefKey = dataCacheKeys.surecTuruRef();
  const zimmetHistoryKey = useMemo(
    () => dataCacheKeys.zimmetlerList(activeSubeId, String(parsedPersonelId), 1),
    [activeSubeId, parsedPersonelId]
  );
  const cached = useMemo(() => getCacheEntry<Personel>(detailKey), [detailKey, revision]);
  const surecHistorySnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<Surec>>(surecHistoryKey),
    [revision, surecHistoryKey]
  );
  const surecTuruOptions = useMemo(
    () => getCacheEntry<KeyOption[]>(surecTuruRefKey) ?? [],
    [revision, surecTuruRefKey]
  );
  const zimmetHistorySnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<Zimmet>>(zimmetHistoryKey),
    [revision, zimmetHistoryKey]
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
    departmanId: "",
    gorevId: "",
    bagliAmirId: "",
    ucretTipi: "",
    maasTutari: "",
    primKuraliId: "",
    effectiveDate: ""
  });
  const [isSurecModalOpen, setIsSurecModalOpen] = useState(false);
  const [isSurecSubmitting, setIsSurecSubmitting] = useState(false);
  const [surecCreateErrorMessage, setSurecCreateErrorMessage] = useState<string | null>(null);
  const [surecHistoryErrorMessage, setSurecHistoryErrorMessage] = useState<string | null>(null);
  const [surecReferenceErrorMessage, setSurecReferenceErrorMessage] = useState<string | null>(null);
  const [isSurecHistoryLoading, setIsSurecHistoryLoading] = useState(false);
  const [surecForm, setSurecForm] = useState<PersonelSurecFormState>(INITIAL_PERSONEL_SUREC_FORM);
  const [isZimmetModalOpen, setIsZimmetModalOpen] = useState(false);
  const [isZimmetSubmitting, setIsZimmetSubmitting] = useState(false);
  const [zimmetCreateErrorMessage, setZimmetCreateErrorMessage] = useState<string | null>(null);
  const [zimmetHistoryErrorMessage, setZimmetHistoryErrorMessage] = useState<string | null>(null);
  const [isZimmetHistoryLoading, setIsZimmetHistoryLoading] = useState(false);
  const [zimmetForm, setZimmetForm] = useState<PersonelZimmetFormState>(INITIAL_PERSONEL_ZIMMET_FORM);

  useEffect(() => {
    if (cached) {
      setPersonel(cached);
      setEditForm(personelToEditForm(cached));
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
      setEditForm(personelToEditForm(data));
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
    setIsZimmetModalOpen(false);
    setZimmetCreateErrorMessage(null);
    setZimmetHistoryErrorMessage(null);
    setZimmetForm(INITIAL_PERSONEL_ZIMMET_FORM);
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

  useEffect(() => {
    if (!hasValidId) {
      setIsZimmetHistoryLoading(false);
      setZimmetHistoryErrorMessage(null);
      return;
    }

    let cancelled = false;
    const hasSeed = getCacheEntry<PaginatedResult<Zimmet>>(zimmetHistoryKey) !== undefined;
    setIsZimmetHistoryLoading(!hasSeed);
    setZimmetHistoryErrorMessage(null);

    void (async () => {
      try {
        await fetchWithCacheMerge(zimmetHistoryKey, () =>
          runDeduped(zimmetHistoryKey, () =>
            fetchZimmetlerList({
              personel_id: parsedPersonelId,
              sube_id: getSubeIdForApiRequest(),
              page: 1,
              limit: PERSONEL_DETAIL_ZIMMET_PAGE_SIZE
            })
          )
        );
      } catch {
        if (!getCacheEntry<PaginatedResult<Zimmet>>(zimmetHistoryKey)) {
          setZimmetHistoryErrorMessage("Zimmet kayitlari su an guncellenemiyor.");
        }
      } finally {
        if (!cancelled) {
          setIsZimmetHistoryLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [hasValidId, parsedPersonelId, zimmetHistoryKey]);

  const surecHistory = useMemo(() => {
    if (!canAccessSurecler) {
      return [];
    }

    return sortSurecHistoryDescending(surecHistorySnapshot?.items ?? []);
  }, [canAccessSurecler, surecHistorySnapshot]);

  const zimmetHistory = useMemo(
    () => sortZimmetHistory(zimmetHistorySnapshot?.items ?? []),
    [zimmetHistorySnapshot]
  );

  const personelRefs = useMemo((): PersonelReferenceBundle => {
    return (
      getCacheEntry<PersonelReferenceBundle>(dataCacheKeys.referansPersonel()) ?? {
        departmanOptions: [],
        gorevOptions: [],
        personelTipiOptions: [],
        bagliAmirOptions: []
      }
    );
  }, [revision]);

  const hasLifecycleDiff = useMemo(() => {
    if (!personel) {
      return false;
    }

    return computeHasLifecycleDiff(personel, pickLifecycleFormFields(editForm));
  }, [personel, editForm]);

  useEffect(() => {
    let cancelled = false;

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
          // Referans yuklenemedi; form manuel ID ile doldurulabilir.
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

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

  const openZimmetModal = useCallback(() => {
    if (!canCreateZimmet) {
      setZimmetCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
      return;
    }

    setZimmetCreateErrorMessage(null);
    setZimmetForm(INITIAL_PERSONEL_ZIMMET_FORM);
    setIsZimmetModalOpen(true);
  }, [canCreateZimmet]);

  const closeZimmetModal = useCallback(() => {
    setIsZimmetModalOpen(false);
  }, []);

  const discardEdit = useCallback(() => {
    if (!personel) {
      return;
    }
    setIsEditing(false);
    setEditErrorMessage(null);
    setEditForm(personelToEditForm(personel));
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

      if (hasLifecycleDiff && !editForm.effectiveDate.trim()) {
        setEditErrorMessage("Geçerlilik tarihi zorunludur.");
        setIsSubmitting(false);
        return;
      }

      const body = buildPersonelUpdatePayload(editForm, hasLifecycleDiff);

      const lifecycleSnap = snapshotFromLifecycleForm(pickLifecycleFormFields(editForm));
      const optimistic: Personel = {
        ...personel,
        ad: editForm.ad.trim(),
        soyad: editForm.soyad.trim(),
        telefon: editForm.telefon.trim(),
        ...lifecycleSnapshotToPersonelPatch(lifecycleSnap)
      };

      mergeCacheEntry<Personel>(detailKey, () => optimistic);
      setPersonel(optimistic);

      try {
        const updated = await updatePersonel(personel.id, body);
        mergeCacheEntry<Personel>(detailKey, () => updated);
        setPersonel(updated);
        setEditForm(personelToEditForm(updated));
        setIsEditing(false);

        if (canAccessSurecler) {
          void fetchWithCacheMerge(surecHistoryKey, () =>
            runDeduped(surecHistoryKey, () =>
              fetchSureclerList({
                personel_id: parsedPersonelId,
                sube_id: getSubeIdForApiRequest(),
                page: 1,
                limit: PERSONEL_DETAIL_SUREC_PAGE_SIZE
              })
            )
          );
        }
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
    [
      canAccessSurecler,
      detailKey,
      editForm,
      hasLifecycleDiff,
      isSubmitting,
      navigate,
      parsedPersonelId,
      personel,
      surecHistoryKey
    ]
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
            try {
              const refreshed = await fetchWithCacheMerge(detailKey, () =>
                runDeduped(detailKey, () => fetchPersonelDetail(parsedPersonelId))
              );
              setPersonel(refreshed);
              setEditForm(personelToEditForm(refreshed));
            } catch {
              // Personel yenilenemedi; mevcut kart korunur.
            }
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
    [canCreateSurec, detailKey, isSurecSubmitting, parsedPersonelId, personel, surecForm, surecHistoryKey]
  );

  const createZimmetHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      if (!personel || isZimmetSubmitting) {
        return;
      }

      if (!canCreateZimmet) {
        setZimmetCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
        return;
      }

      setZimmetCreateErrorMessage(null);
      setIsZimmetSubmitting(true);

      try {
        const payload = buildPersonelZimmetPayload(personel.id, zimmetForm);
        const created = await createZimmet(payload);
        mergeCacheEntry<PaginatedResult<Zimmet>>(zimmetHistoryKey, (prev) => {
          const base = prev ?? emptyPaginated<Zimmet>();
          return {
            ...base,
            items: mergeZimmetHistoryRow(base.items, created)
          };
        });
        setIsZimmetModalOpen(false);
        setZimmetForm(INITIAL_PERSONEL_ZIMMET_FORM);
      } catch (error) {
        setZimmetCreateErrorMessage(getApiErrorMessage(error, "Zimmet kaydi yapilamadi."));
      } finally {
        setIsZimmetSubmitting(false);
      }
    },
    [canCreateZimmet, isZimmetSubmitting, personel, zimmetForm, zimmetHistoryKey]
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
    hasLifecycleDiff,
    personelRefs,
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
    surecReferenceErrorMessage,
    isZimmetModalOpen,
    openZimmetModal,
    closeZimmetModal,
    zimmetForm,
    setZimmetForm,
    createZimmetHandler,
    isZimmetSubmitting,
    zimmetCreateErrorMessage,
    zimmetHistory,
    isZimmetHistoryLoading,
    zimmetHistoryErrorMessage
  };
}
