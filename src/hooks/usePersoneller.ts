import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import {
  createPersonel,
  fetchPersonelDetail,
  fetchPersonellerList,
  updatePersonel,
  type CreatePersonelPayload
} from "../api/personeller.api";
import { fetchBagliAmirOptions, fetchDepartmanOptions, fetchGorevOptions, fetchPersonelTipiOptions } from "../api/referans.api";
import { makeTempId, type PersonelReferenceBundle } from "../data/app-data.types";
import {
  dataCacheKeys,
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
import type { PaginatedResult } from "../types/api";
import { runDeduped } from "../lib/in-flight-dedupe";
import type { Personel } from "../types/personel";
const PAGE_SIZE = 10;

export type PersonelListQueryState = {
  draft: { search: string; aktiflik: "aktif" | "pasif" | "tum" };
  applied: { search: string; aktiflik: "aktif" | "pasif" | "tum" };
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

function parseRequiredPositiveInt(value: string, label: string) {
  const number = Number.parseInt(value, 10);
  if (Number.isNaN(number) || number <= 0) {
    throw new Error(`${label} pozitif sayi olmalidir.`);
  }
  return number;
}

function parseOptionalPositiveInt(value: string): number | undefined {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }
  const number = Number.parseInt(trimmed, 10);
  if (Number.isNaN(number) || number <= 0) {
    return undefined;
  }
  return number;
}

export function usePersoneller() {
  const revision = useAppDataRevision();
  const [listQuery, setListQuery] = useState<PersonelListQueryState>({
    draft: { search: "", aktiflik: "tum" },
    applied: { search: "", aktiflik: "tum" },
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
    () => dataCacheKeys.personellerList(activeSube, appliedFilters.search, appliedFilters.aktiflik, listPage),
    [activeSube, appliedFilters.aktiflik, appliedFilters.search, listPage]
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
          aktiflik: appliedFilters.aktiflik,
          sube_id: getSubeIdForApiRequest(),
          page: listPage,
          limit: PAGE_SIZE
        })
      )
    );
  }, [appliedFilters.aktiflik, appliedFilters.search, listKey, listPage]);

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
              aktiflik: appliedFilters.aktiflik,
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
  }, [appliedFilters.aktiflik, appliedFilters.search, listKey, listPage]);

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
          setReferenceError("Referans veriler su an guncellenemiyor, manuel giris kullanilabilir.");
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
      draft: { search: "", aktiflik: "tum" },
      applied: { search: "", aktiflik: "tum" },
      page: 1
    });
  }, []);

  const setDraftSearch = useCallback((search: string) => {
    setListQuery((prev) => ({ ...prev, draft: { ...prev.draft, search } }));
  }, []);

  const setDraftAktiflik = useCallback((aktiflik: "aktif" | "pasif" | "tum") => {
    setListQuery((prev) => ({ ...prev, draft: { ...prev.draft, aktiflik } }));
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
        setCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
        return;
      }

      setCreateErrorMessage(null);
      setIsCreateSubmitting(true);

      try {
        const bagliAmirId = parseOptionalPositiveInt(createForm.bagliAmirId);
        const payload: CreatePersonelPayload = {
          tc_kimlik_no: createForm.tcKimlikNo.trim(),
          ad: createForm.ad.trim(),
          soyad: createForm.soyad.trim(),
          dogum_tarihi: createForm.dogumTarihi,
          telefon: createForm.telefon.trim(),
          acil_durum_kisi: createForm.acilDurumKisi.trim(),
          acil_durum_telefon: createForm.acilDurumTelefon.trim(),
          sicil_no: createForm.sicilNo.trim(),
          ise_giris_tarihi: createForm.iseGirisTarihi,
          departman_id: parseRequiredPositiveInt(createForm.departmanId, "Departman ID"),
          gorev_id: parseRequiredPositiveInt(createForm.gorevId, "Gorev ID"),
          personel_tipi_id: parseRequiredPositiveInt(createForm.personelTipiId, "Personel Tipi ID"),
          aktif_durum: createForm.aktifDurum,
          ...(createForm.dogumYeri.trim() ? { dogum_yeri: createForm.dogumYeri.trim() } : {}),
          ...(createForm.kanGrubu.trim() ? { kan_grubu: createForm.kanGrubu.trim() } : {}),
          ...(bagliAmirId !== undefined ? { bagli_amir_id: bagliAmirId } : {})
        };

        const pageOneKey = dataCacheKeys.personellerList(
          activeSube,
          listQuery.applied.search,
          listQuery.applied.aktiflik,
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
                aktiflik: listQuery.applied.aktiflik,
                sube_id: getSubeIdForApiRequest(),
                page: 1,
                limit: PAGE_SIZE
              })
            )
          );
        } catch {
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
        setCreateErrorMessage(
          error instanceof Error ? error.message : "Personel kaydi sirasinda bir hata olustu."
        );
      } finally {
        setIsCreateSubmitting(false);
      }
    },
    [activeSube, createForm, isCreateSubmitting, listQuery.applied.aktiflik, listQuery.applied.search]
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
    setPage
  };
}

type EditPersonelFormState = {
  ad: string;
  soyad: string;
  telefon: string;
  aktifDurum: "AKTIF" | "PASIF";
};

export function usePersonelDetail(parsedPersonelId: number, hasValidId: boolean) {
  const revision = useAppDataRevision();
  const detailKey = useMemo(() => dataCacheKeys.personelDetail(parsedPersonelId), [parsedPersonelId]);
  const cached = useMemo(() => getCacheEntry<Personel>(detailKey), [detailKey, revision]);

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

  const refetch = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Gecerli bir personel id verilmedi.");
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
    } catch {
      if (!getCacheEntry<Personel>(detailKey)) {
        setErrorMessage("Personel detayi su an guncellenemiyor.");
      }
    } finally {
      setIsLoading(false);
    }
  }, [detailKey, hasValidId, parsedPersonelId]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

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
        setEditErrorMessage("Bu kaydi guncellemek icin yetkin bulunmuyor.");
        return;
      }

      setEditErrorMessage(null);
      setIsSubmitting(true);

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
      } catch {
        enqueueSyncOperation({
          op: "personeller.update",
          payload: { personelId: personel.id, body },
          meta: { detailKey }
        });
        void processSyncQueue();
        setEditErrorMessage(null);
        setIsEditing(false);
      } finally {
        setIsSubmitting(false);
      }
    },
    [detailKey, editForm, isSubmitting, personel]
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
    updatePersonelHandler
  };
}
