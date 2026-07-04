import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { getApiErrorMessage, shouldQueueOfflineMutation } from "../api/api-client";
import { fetchPersonelDetail, updatePersonel } from "../api/personeller.api";
import {
  fetchBagliAmirOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchPrimKuraliOptions,
  fetchUcretTipiOptions
} from "../api/referans.api";
import { createSurec, fetchSureclerList } from "../api/surecler.api";
import { fetchZimmetlerList } from "../api/zimmetler.api";
import { emptyPaginated, type PersonelReferenceBundle } from "../data/app-data.types";
import {
  dataCacheKeys,
  deleteCacheEntry,
  enqueueSyncOperation,
  fetchWithCacheMerge,
  getCacheEntry,
  getSubeIdForApiRequest,
  mergeCacheEntry,
  processSyncQueue,
  useAppDataRevision
} from "../data/data-manager";
import {
  SUBE_DETAIL_REDIRECT_MESSAGE,
  SUBE_DETAIL_REDIRECT_STATE_KEY,
  shouldRedirectDetailAfterSubeMismatch
} from "../lib/detail-sube-context";
import { parseOptionalPositiveInt } from "../features/personeller/personel-create-utils";
import {
  computeHasLifecycleDiff,
  lifecycleSnapshotToPersonelPatch,
  snapshotFromLifecycleForm
} from "../lib/personel-lifecycle-diff";
import { sortSurecHistoryDescending } from "../lib/surec-history-sort";
import type { PaginatedResult, PaginationMeta } from "../types/api";
import { runDeduped } from "../lib/in-flight-dedupe";
import { useAuth } from "../state/auth.store";
import type { Personel } from "../types/personel";
import type { Surec } from "../types/surec";
import type { Zimmet } from "../types/zimmet";
import {
  buildBagliAmirContext,
  buildBagliAmirFormGuidance,
  buildBagliAmirSurecPayloads,
  buildPersonelUpdatePayload,
  personelToEditForm,
  pickLifecycleFormFields,
  type BagliAmirContext,
  type EditPersonelFormState
} from "../features/personeller/personel-edit-utils";
import { usePersonelZimmetCreate } from "./usePersonelZimmetCreate";

const PERSONEL_DETAIL_SUREC_PAGE_SIZE = 20;
const PERSONEL_DETAIL_ZIMMET_PAGE_SIZE = 20;

export function resolveHistoryHasMore(
  pagination: PaginationMeta | undefined,
  itemCount: number
): boolean {
  if (!pagination) {
    return false;
  }

  if (pagination.hasNextPage === true) {
    return true;
  }

  return pagination.total != null && itemCount < pagination.total;
}

const INITIAL_EDIT_PERSONEL_FORM: EditPersonelFormState = {
  ad: "",
  soyad: "",
  telefon: "",
  departmanId: "",
  gorevId: "",
  bagliAmirId: "",
  ucretTipiId: "",
  maasTutari: "",
  primKuraliId: "",
  effectiveDate: ""
};

async function fetchBagliAmirContext(amirId: number): Promise<BagliAmirContext | null> {
  try {
    const personel = await fetchPersonelDetail(amirId);
    return buildBagliAmirContext(personel);
  } catch {
    return null;
  }
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

type PersonelDetailOptions = {
  canViewSurecler?: boolean;
  canCreateSurec?: boolean;
  canCreateZimmet?: boolean;
};

type PersonelDetailCacheKeys = {
  activeSubeId: number | null;
  detailKey: string;
  surecHistoryKey: string;
  zimmetHistoryKey: string;
};

function usePersonelDetailCacheKeys(parsedPersonelId: number) {
  const { session } = useAuth();
  const activeSubeId = session?.active_sube_id ?? null;

  const detailKey = useMemo(
    () => dataCacheKeys.personelDetail(activeSubeId, parsedPersonelId),
    [activeSubeId, parsedPersonelId]
  );
  const surecHistoryKey = useMemo(
    () => dataCacheKeys.sureclerList(activeSubeId, String(parsedPersonelId), "", "", "", "", 1),
    [activeSubeId, parsedPersonelId]
  );
  const zimmetHistoryKey = useMemo(
    () => dataCacheKeys.zimmetlerList(activeSubeId, String(parsedPersonelId), 1),
    [activeSubeId, parsedPersonelId]
  );

  return { activeSubeId, detailKey, surecHistoryKey, zimmetHistoryKey };
}

function usePersonelDetailData(
  parsedPersonelId: number,
  hasValidId: boolean,
  canAccessSurecler: boolean,
  cacheKeys: PersonelDetailCacheKeys
) {
  const navigate = useNavigate();
  const revision = useAppDataRevision();
  const { activeSubeId, detailKey, surecHistoryKey, zimmetHistoryKey } = cacheKeys;

  const cached = useMemo(() => getCacheEntry<Personel>(detailKey), [detailKey, revision]);
  const surecHistorySnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<Surec>>(surecHistoryKey),
    [revision, surecHistoryKey]
  );
  const zimmetHistorySnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<Zimmet>>(zimmetHistoryKey),
    [revision, zimmetHistoryKey]
  );

  const [personel, setPersonel] = useState<Personel | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [surecHistoryErrorMessage, setSurecHistoryErrorMessage] = useState<string | null>(null);
  const [isSurecHistoryLoading, setIsSurecHistoryLoading] = useState(false);
  const [zimmetHistoryErrorMessage, setZimmetHistoryErrorMessage] = useState<string | null>(null);
  const [isZimmetHistoryLoading, setIsZimmetHistoryLoading] = useState(false);
  const detailRequestSeq = useRef(0);

  useEffect(() => {
    setPersonel(null);
  }, [detailKey, parsedPersonelId]);

  useEffect(() => {
    if (cached && cached.id === parsedPersonelId) {
      setPersonel(cached);
    }
  }, [cached, parsedPersonelId]);

  useEffect(() => {
    deleteCacheEntry(detailKey);
  }, [detailKey, activeSubeId]);

  const refetch = useCallback(async () => {
    const requestSeq = detailRequestSeq.current + 1;
    detailRequestSeq.current = requestSeq;
    const isCurrentRequest = () => detailRequestSeq.current === requestSeq;

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
      if (!isCurrentRequest()) {
        return;
      }
      setPersonel(data);
    } catch (error) {
      if (!isCurrentRequest()) {
        return;
      }
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
      if (isCurrentRequest()) {
        setIsLoading(false);
      }
    }
  }, [activeSubeId, detailKey, hasValidId, navigate, parsedPersonelId]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  useEffect(() => {
    setSurecHistoryErrorMessage(null);
    setZimmetHistoryErrorMessage(null);
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

  const surecHistoryHasMore = useMemo(() => {
    if (!canAccessSurecler) {
      return false;
    }

    const items = surecHistorySnapshot?.items ?? [];
    return resolveHistoryHasMore(surecHistorySnapshot?.pagination, items.length);
  }, [canAccessSurecler, surecHistorySnapshot]);

  const zimmetHistoryHasMore = useMemo(() => {
    const items = zimmetHistorySnapshot?.items ?? [];
    return resolveHistoryHasMore(zimmetHistorySnapshot?.pagination, items.length);
  }, [zimmetHistorySnapshot]);

  return {
    personel,
    setPersonel,
    isLoading,
    errorMessage,
    refetch,
    surecHistory,
    surecHistoryHasMore,
    isSurecHistoryLoading,
    surecHistoryErrorMessage,
    zimmetHistory,
    zimmetHistoryHasMore,
    isZimmetHistoryLoading,
    zimmetHistoryErrorMessage
  };
}

function usePersonelDetailEdit(
  parsedPersonelId: number,
  canAccessSurecler: boolean,
  cacheKeys: PersonelDetailCacheKeys,
  personel: Personel | null,
  setPersonel: (value: Personel | null | ((prev: Personel | null) => Personel | null)) => void
) {
  const navigate = useNavigate();
  const revision = useAppDataRevision();
  const { session } = useAuth();
  const activeSubeId = session?.active_sube_id ?? null;
  const { detailKey, surecHistoryKey } = cacheKeys;

  const [isEditing, setIsEditing] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [editForm, setEditForm] = useState<EditPersonelFormState>(INITIAL_EDIT_PERSONEL_FORM);
  const [editBagliAmirContext, setEditBagliAmirContext] = useState<BagliAmirContext | null>(null);

  useEffect(() => {
    setEditForm(INITIAL_EDIT_PERSONEL_FORM);
    setEditErrorMessage(null);
    setIsEditing(false);
  }, [cacheKeys.detailKey, parsedPersonelId]);

  useEffect(() => {
    if (personel) {
      setEditForm(personelToEditForm(personel));
    }
  }, [personel]);

  const personelRefs = useMemo((): PersonelReferenceBundle => {
    return (
      getCacheEntry<PersonelReferenceBundle>(dataCacheKeys.referansPersonel()) ?? {
        departmanOptions: [],
        gorevOptions: [],
        personelTipiOptions: [],
        bagliAmirOptions: [],
        ucretTipiOptions: [],
        primKuraliOptions: []
      }
    );
  }, [revision]);

  const hasLifecycleDiff = useMemo(() => {
    if (!personel) {
      return false;
    }

    return computeHasLifecycleDiff(personel, pickLifecycleFormFields(editForm));
  }, [personel, editForm]);

  const editBagliAmirGuidance = useMemo(
    () => buildBagliAmirFormGuidance(editForm.departmanId, editBagliAmirContext, activeSubeId),
    [activeSubeId, editBagliAmirContext, editForm.departmanId]
  );

  useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        await fetchWithCacheMerge(dataCacheKeys.referansPersonel(), () =>
          runDeduped(dataCacheKeys.referansPersonel(), async () => {
            const [
              departmanOptions,
              gorevOptions,
              personelTipiOptions,
              bagliAmirOptions,
              ucretTipiOptions,
              primKuraliOptions
            ] = await Promise.all([
              fetchDepartmanOptions(),
              fetchGorevOptions(),
              fetchPersonelTipiOptions(),
              fetchBagliAmirOptions(),
              fetchUcretTipiOptions(),
              fetchPrimKuraliOptions()
            ]);
            return {
              departmanOptions,
              gorevOptions,
              personelTipiOptions,
              bagliAmirOptions,
              ucretTipiOptions,
              primKuraliOptions
            } satisfies PersonelReferenceBundle;
          })
        );
      } catch {
        if (!cancelled) {
          /* referans yuklenemedi; select alanlari bos kalir */
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    let cancelled = false;

    const amirId = personel?.bagli_amir_id;
    if (typeof amirId !== "number") {
      setEditBagliAmirContext(null);
      return () => {
        cancelled = true;
      };
    }

    void (async () => {
      const context = await fetchBagliAmirContext(amirId);
      if (!cancelled) {
        setEditBagliAmirContext(context);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [personel?.bagli_amir_id]);

  const discardEdit = useCallback(() => {
    if (!personel) {
      return;
    }
    setIsEditing(false);
    setEditErrorMessage(null);
    setEditForm(personelToEditForm(personel));
    const amirId = personel.bagli_amir_id;
    if (typeof amirId !== "number") {
      setEditBagliAmirContext(null);
      return;
    }

    void (async () => {
      const context = await fetchBagliAmirContext(amirId);
      setEditBagliAmirContext(context);
    })();
  }, [personel]);

  const handleEditDepartmanChange = useCallback((departmanId: string) => {
    setEditForm((prev) => ({ ...prev, departmanId }));
  }, []);

  const handleEditBagliAmirChange = useCallback((bagliAmirId: string) => {
    setEditForm((prev) => ({ ...prev, bagliAmirId }));

    const amirId = parseOptionalPositiveInt(bagliAmirId);
    if (amirId === undefined) {
      setEditBagliAmirContext(null);
      return;
    }

    void (async () => {
      const context = await fetchBagliAmirContext(amirId);
      setEditBagliAmirContext(context);
      if (!context?.departmanId) {
        return;
      }

      setEditForm((prev) =>
        prev.bagliAmirId === bagliAmirId ? { ...prev, departmanId: context.departmanId } : prev
      );
    })();
  }, []);

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
        ad: body.ad ?? personel.ad,
        soyad: body.soyad ?? personel.soyad,
        telefon: body.telefon ?? personel.telefon,
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

        if (hasLifecycleDiff && editForm.effectiveDate.trim()) {
          const bagliAmirSurecPayloads = buildBagliAmirSurecPayloads(
            previousPersonel,
            updated,
            editForm.effectiveDate.trim(),
            personelRefs.bagliAmirOptions
          );

          if (bagliAmirSurecPayloads.length > 0) {
            const settled = await Promise.allSettled(
              bagliAmirSurecPayloads.map((payload) => createSurec(payload))
            );

            const createdBagliAmirSurecleri = settled
              .filter(
                (result): result is PromiseFulfilledResult<Surec> => result.status === "fulfilled"
              )
              .map((result) => result.value);

            if (createdBagliAmirSurecleri.length > 0) {
              mergeCacheEntry<PaginatedResult<Surec>>(surecHistoryKey, (prev) => {
                const base = prev ?? emptyPaginated<Surec>();
                return {
                  ...base,
                  items: sortSurecHistoryDescending([...createdBagliAmirSurecleri, ...base.items])
                };
              });
            }
          }
        }

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
      personelRefs.bagliAmirOptions,
      setPersonel,
      surecHistoryKey
    ]
  );

  return {
    isEditing,
    setIsEditing,
    isSubmitting,
    editErrorMessage,
    editForm,
    setEditForm,
    handleEditDepartmanChange,
    handleEditBagliAmirChange,
    editBagliAmirGuidance,
    discardEdit,
    updatePersonelHandler,
    hasLifecycleDiff,
    personelRefs
  };
}

function usePersonelDetailZimmetModal(
  parsedPersonelId: number,
  hasValidId: boolean,
  canCreateZimmet: boolean,
  personel: Personel | null
) {
  const [isZimmetModalOpen, setIsZimmetModalOpen] = useState(false);

  const closeZimmetModalAfterCreate = useCallback(() => {
    setIsZimmetModalOpen(false);
  }, []);

  const {
    zimmetForm,
    setZimmetForm,
    createZimmetHandler,
    isZimmetSubmitting,
    zimmetCreateErrorMessage,
    setZimmetCreateErrorMessage,
    resetZimmetForm
  } = usePersonelZimmetCreate(parsedPersonelId, hasValidId, canCreateZimmet, {
    canSubmit: Boolean(personel),
    onCreateSuccess: closeZimmetModalAfterCreate
  });

  useEffect(() => {
    setIsZimmetModalOpen(false);
  }, [parsedPersonelId]);

  const openZimmetModal = useCallback(() => {
    if (!canCreateZimmet) {
      setZimmetCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
      return;
    }

    resetZimmetForm();
    setIsZimmetModalOpen(true);
  }, [canCreateZimmet, resetZimmetForm, setZimmetCreateErrorMessage]);

  const closeZimmetModal = useCallback(() => {
    setIsZimmetModalOpen(false);
  }, []);

  return {
    isZimmetModalOpen,
    openZimmetModal,
    closeZimmetModal,
    zimmetForm,
    setZimmetForm,
    createZimmetHandler,
    isZimmetSubmitting,
    zimmetCreateErrorMessage
  };
}

export function usePersonelDetail(
  parsedPersonelId: number,
  hasValidId: boolean,
  options: PersonelDetailOptions = {}
) {
  const canAccessSurecler = Boolean(options.canViewSurecler || options.canCreateSurec);
  const canCreateZimmet = Boolean(options.canCreateZimmet);
  const cacheKeys = usePersonelDetailCacheKeys(parsedPersonelId);

  const data = usePersonelDetailData(parsedPersonelId, hasValidId, canAccessSurecler, cacheKeys);
  const edit = usePersonelDetailEdit(
    parsedPersonelId,
    canAccessSurecler,
    cacheKeys,
    data.personel,
    data.setPersonel
  );
  const zimmet = usePersonelDetailZimmetModal(parsedPersonelId, hasValidId, canCreateZimmet, data.personel);

  return {
    ...data,
    ...edit,
    ...zimmet
  };
}
