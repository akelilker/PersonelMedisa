import { useCallback, useEffect, useState, type FormEvent } from "react";
import {
  createPersonel,
  fetchPersonelDetail,
  fetchPersonellerList,
  updatePersonel,
  type CreatePersonelPayload
} from "../api/personeller.api";
import {
  fetchBagliAmirOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions
} from "../api/referans.api";
import { runDeduped } from "../lib/in-flight-dedupe";
import type { Personel } from "../types/personel";
import type { IdOption } from "../types/referans";

const PAGE_SIZE = 10;

type PersonelReferences = {
  departmanOptions: IdOption[];
  gorevOptions: IdOption[];
  personelTipiOptions: IdOption[];
  bagliAmirOptions: IdOption[];
};

let personelReferencesCache: PersonelReferences | null = null;
let personelReferencesPromise: Promise<PersonelReferences> | null = null;

async function loadPersonelReferences(): Promise<PersonelReferences> {
  if (personelReferencesCache) {
    return personelReferencesCache;
  }
  if (personelReferencesPromise) {
    return personelReferencesPromise;
  }

  personelReferencesPromise = (async () => {
    const [departmanOptions, gorevOptions, personelTipiOptions, bagliAmirOptions] = await Promise.all([
      fetchDepartmanOptions(),
      fetchGorevOptions(),
      fetchPersonelTipiOptions(),
      fetchBagliAmirOptions()
    ]);
    const snapshot: PersonelReferences = {
      departmanOptions,
      gorevOptions,
      personelTipiOptions,
      bagliAmirOptions
    };
    personelReferencesCache = snapshot;
    return snapshot;
  })();

  try {
    return await personelReferencesPromise;
  } finally {
    personelReferencesPromise = null;
  }
}

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

function listCacheKey(query: PersonelListQueryState["applied"], page: number) {
  return `personeller|${query.search}|${query.aktiflik}|${page}`;
}

export function usePersoneller() {
  const [listQuery, setListQuery] = useState<PersonelListQueryState>({
    draft: { search: "", aktiflik: "tum" },
    applied: { search: "", aktiflik: "tum" },
    page: 1
  });

  const [personeller, setPersoneller] = useState<Personel[]>([]);
  const [hasNextPage, setHasNextPage] = useState(false);
  const [totalPages, setTotalPages] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [refs, setRefs] = useState<PersonelReferences>({
    departmanOptions: [],
    gorevOptions: [],
    personelTipiOptions: [],
    bagliAmirOptions: []
  });
  const [referenceError, setReferenceError] = useState<string | null>(null);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isCreateSubmitting, setIsCreateSubmitting] = useState(false);
  const [createErrorMessage, setCreateErrorMessage] = useState<string | null>(null);
  const [createForm, setCreateForm] = useState<CreatePersonelFormState>(INITIAL_CREATE_PERSONEL_FORM);

  const appliedFilters = listQuery.applied;
  const listPage = listQuery.page;

  const refetch = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);
    const key = listCacheKey(appliedFilters, listPage);

    try {
      const nextData = await runDeduped(key, () =>
        fetchPersonellerList({
          search: appliedFilters.search || undefined,
          aktiflik: appliedFilters.aktiflik,
          page: listPage,
          limit: PAGE_SIZE
        })
      );
      setPersoneller(nextData.items);
      setHasNextPage(nextData.pagination.hasNextPage ?? nextData.items.length === PAGE_SIZE);
      setTotalPages(nextData.pagination.totalPages);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Personel listesi alinamadi.");
      setHasNextPage(false);
      setTotalPages(null);
    } finally {
      setIsLoading(false);
    }
  }, [appliedFilters.aktiflik, appliedFilters.search, listPage]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  useEffect(() => {
    let cancelled = false;

    void (async () => {
      setReferenceError(null);
      try {
        const snapshot = await loadPersonelReferences();
        if (cancelled) {
          return;
        }
        setRefs(snapshot);
      } catch (error) {
        if (cancelled) {
          return;
        }
        setReferenceError(
          error instanceof Error ? error.message : "Referans veriler alinamadi, manuel giris aktif."
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
        await createPersonel({
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
        } satisfies CreatePersonelPayload);

        setIsCreateModalOpen(false);
        setCreateForm(INITIAL_CREATE_PERSONEL_FORM);
        setListQuery((prev) => {
          if (prev.page === 1) {
            return prev;
          }
          return { ...prev, page: 1 };
        });

        if (listPage === 1) {
          void refetch();
        }
      } catch (error) {
        setCreateErrorMessage(
          error instanceof Error ? error.message : "Personel kaydi sirasinda bir hata olustu."
        );
      } finally {
        setIsCreateSubmitting(false);
      }
    },
    [createForm, isCreateSubmitting, listPage, refetch]
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

  const refetch = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Gecerli bir personel id verilmedi.");
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);
    const key = `personel-detail|${parsedPersonelId}`;

    try {
      const data = await runDeduped(key, () => fetchPersonelDetail(parsedPersonelId));
      setPersonel(data);
      setEditForm({
        ad: data.ad,
        soyad: data.soyad,
        telefon: data.telefon ?? "",
        aktifDurum: data.aktif_durum
      });
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Personel detayi alinamadi.");
    } finally {
      setIsLoading(false);
    }
  }, [hasValidId, parsedPersonelId]);

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

      try {
        const updated = await updatePersonel(personel.id, {
          ad: editForm.ad.trim(),
          soyad: editForm.soyad.trim(),
          telefon: editForm.telefon.trim(),
          aktif_durum: editForm.aktifDurum
        });

        setPersonel(updated);
        setEditForm({
          ad: updated.ad,
          soyad: updated.soyad,
          telefon: updated.telefon ?? "",
          aktifDurum: updated.aktif_durum
        });
        setIsEditing(false);
      } catch (error) {
        setEditErrorMessage(error instanceof Error ? error.message : "Kayit guncellenemedi.");
      } finally {
        setIsSubmitting(false);
      }
    },
    [editForm, isSubmitting, personel]
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
