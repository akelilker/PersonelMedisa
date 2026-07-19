import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ApiRequestError, getApiErrorDetail } from "../api/api-client";
import {
  applyBildirimPuantajEtkiAday,
  cakismaCozBildirimPuantajEtkiAdayi,
  dismissBildirimPuantajEtkiAday,
  fetchBildirimPuantajEtkiAdayDetail,
  fetchBildirimPuantajEtkiAdayList,
  fetchBildirimPuantajEtkiAdayOzet,
  generateBildirimPuantajEtkiAdaylari,
  manuelUygulaBildirimPuantajEtkiAdayi,
  type BildirimPuantajEtkiAdayListParams
} from "../api/bildirim-puantaj-etki-adaylari.api";
import { fetchGenelYoneticiBildirimOnayiOzet } from "../api/genel-yonetici-bildirim-onaylari.api";
import { getCurrentMonthValue, isValidAyValue } from "../lib/bildirim/aylik-bildirim-onay";
import {
  canResolveConflictForDetail,
  trimDismissGerekce
} from "../lib/bildirim-puantaj-etki-aday/display";
import type {
  BildirimPuantajEtkiAdayDetail,
  BildirimPuantajEtkiAdayListItem,
  BildirimPuantajEtkiAdayOzet,
  BildirimPuantajEtkiAdayState,
  BildirimPuantajEtkiConflictKararTuru,
  BildirimPuantajEtkiManualKararTuru
} from "../types/bildirim-puantaj-etki-aday";

export type BildirimPuantajEtkiAdayFilters = {
  personelId: string;
  state: "" | BildirimPuantajEtkiAdayState;
};

const EMPTY_FILTERS: BildirimPuantajEtkiAdayFilters = {
  personelId: "",
  state: ""
};

type PaginationState = {
  page: number;
  limit: number;
  total: number;
  totalPages: number;
  hasNextPage: boolean;
  hasPreviousPage: boolean;
};

const EMPTY_PAGINATION: PaginationState = {
  page: 1,
  limit: 20,
  total: 0,
  totalPages: 1,
  hasNextPage: false,
  hasPreviousPage: false
};

type UseBildirimPuantajEtkiAdaylariOptions = {
  enabled: boolean;
  canDismiss: boolean;
  canApply: boolean;
  canResolveConflict: boolean;
  canResolveGyViaOnayApi: boolean;
  canGenerate?: boolean;
  subeId: number | null;
  birimAmiriUserId: number | null;
  ay?: string;
  onAyChange?: (value: string) => void;
};

export function useBildirimPuantajEtkiAdaylari(options: UseBildirimPuantajEtkiAdaylariOptions) {
  const {
    enabled,
    canDismiss,
    canApply,
    canResolveConflict,
    canResolveGyViaOnayApi,
    canGenerate = false,
    subeId,
    birimAmiriUserId,
    ay: controlledAy,
    onAyChange
  } = options;
  const [internalAy, setInternalAy] = useState(getCurrentMonthValue);
  const ay = controlledAy ?? internalAy;
  const [draftFilters, setDraftFilters] = useState<BildirimPuantajEtkiAdayFilters>(EMPTY_FILTERS);
  const [appliedFilters, setAppliedFilters] = useState<BildirimPuantajEtkiAdayFilters>(EMPTY_FILTERS);
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<BildirimPuantajEtkiAdayListItem[]>([]);
  const [ozet, setOzet] = useState<BildirimPuantajEtkiAdayOzet | null>(null);
  const [pagination, setPagination] = useState<PaginationState>(EMPTY_PAGINATION);
  const resolvedGyIdRef = useRef<number | null>(null);
  const [detail, setDetail] = useState<BildirimPuantajEtkiAdayDetail | null>(null);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [isListLoading, setIsListLoading] = useState(false);
  const [isOzetLoading, setIsOzetLoading] = useState(false);
  const [isDetailLoading, setIsDetailLoading] = useState(false);
  const [listError, setListError] = useState<string | null>(null);
  const [ozetError, setOzetError] = useState<string | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);
  const [dismissTarget, setDismissTarget] = useState<BildirimPuantajEtkiAdayListItem | null>(null);
  const [dismissGerekce, setDismissGerekce] = useState("");
  const [dismissFieldError, setDismissFieldError] = useState<string | null>(null);
  const [dismissError, setDismissError] = useState<string | null>(null);
  const [isDismissing, setIsDismissing] = useState(false);
  const [applyTarget, setApplyTarget] = useState<BildirimPuantajEtkiAdayDetail | null>(null);
  const [applyError, setApplyError] = useState<string | null>(null);
  const [isApplying, setIsApplying] = useState(false);
  const [manualTarget, setManualTarget] = useState<BildirimPuantajEtkiAdayDetail | null>(null);
  const [manualKararTuru, setManualKararTuru] = useState<BildirimPuantajEtkiManualKararTuru | "">("");
  const [manualMiktar, setManualMiktar] = useState("");
  const [manualGerekce, setManualGerekce] = useState("");
  const [manualFieldError, setManualFieldError] = useState<string | null>(null);
  const [manualError, setManualError] = useState<string | null>(null);
  const [isManualApplying, setIsManualApplying] = useState(false);
  const [conflictTarget, setConflictTarget] = useState<BildirimPuantajEtkiAdayDetail | null>(null);
  const [conflictKararTuru, setConflictKararTuru] = useState<BildirimPuantajEtkiConflictKararTuru>("MEVCUT_PUANTAJI_KORU");
  const [conflictGerekce, setConflictGerekce] = useState("");
  const [conflictFieldError, setConflictFieldError] = useState<string | null>(null);
  const [conflictError, setConflictError] = useState<string | null>(null);
  const [isConflictResolving, setIsConflictResolving] = useState(false);
  const [isGenerating, setIsGenerating] = useState(false);
  const [generateError, setGenerateError] = useState<string | null>(null);
  const listRequestIdRef = useRef(0);
  const ozetRequestIdRef = useRef(0);
  const detailRequestIdRef = useRef(0);
  const dismissingRef = useRef(false);
  const applyingRef = useRef(false);
  const manualApplyingRef = useRef(false);
  const conflictResolvingRef = useRef(false);
  const generatingRef = useRef(false);

  const STALE_CONFLICT_CODES = new Set([
    "PUANTAJ_STALE",
    "PUANTAJ_ARTIK_YOK",
    "STATE_STALE"
  ]);

  const REFETCH_CONFLICT_CODES = new Set([
    ...STALE_CONFLICT_CODES,
    "REVISION_DECISION_CONFLICT",
    "REVISION_INTEGRITY_FAILED",
    "SOURCE_INTEGRITY_FAILED"
  ]);

  const contextReady = Boolean(
    enabled &&
      isValidAyValue(ay) &&
      typeof subeId === "number" &&
      subeId > 0 &&
      typeof birimAmiriUserId === "number" &&
      birimAmiriUserId > 0
  );

  const contextKey = useMemo(
    () => `${subeId ?? "none"}|${birimAmiriUserId ?? "none"}|${ay}`,
    [ay, birimAmiriUserId, subeId]
  );

  const listParams = useMemo((): BildirimPuantajEtkiAdayListParams | null => {
    if (!contextReady || !birimAmiriUserId) {
      return null;
    }
    const personelId = appliedFilters.personelId.trim()
      ? Number.parseInt(appliedFilters.personelId.trim(), 10)
      : undefined;
    return {
      ay,
      birim_amiri_user_id: birimAmiriUserId,
      personel_id: Number.isFinite(personelId) ? personelId : undefined,
      state: appliedFilters.state || undefined,
      page,
      limit: 20
    };
  }, [appliedFilters.personelId, appliedFilters.state, ay, birimAmiriUserId, contextReady, page]);

  const closeDismissModal = useCallback(() => {
    setDismissTarget(null);
    setDismissGerekce("");
    setDismissFieldError(null);
    setDismissError(null);
    setIsDismissing(false);
    dismissingRef.current = false;
  }, []);

  const closeApplyModal = useCallback(() => {
    setApplyTarget(null);
    setApplyError(null);
    setIsApplying(false);
    applyingRef.current = false;
  }, []);

  const closeManualApplyModal = useCallback(() => {
    setManualTarget(null);
    setManualKararTuru("");
    setManualMiktar("");
    setManualGerekce("");
    setManualFieldError(null);
    setManualError(null);
    setIsManualApplying(false);
    manualApplyingRef.current = false;
  }, []);

  const closeConflictModal = useCallback(() => {
    setConflictTarget(null);
    setConflictKararTuru("MEVCUT_PUANTAJI_KORU");
    setConflictGerekce("");
    setConflictFieldError(null);
    setConflictError(null);
    setIsConflictResolving(false);
    conflictResolvingRef.current = false;
  }, []);

  const resetTransientState = useCallback(() => {
    setItems([]);
    setOzet(null);
    setPagination(EMPTY_PAGINATION);
    resolvedGyIdRef.current = null;
    setDetail(null);
    setDetailId(null);
    setListError(null);
    setOzetError(null);
    setDetailError(null);
    setSuccessMessage(null);
    setInfoMessage(null);
    setDismissTarget(null);
    setDismissGerekce("");
    setDismissFieldError(null);
    setDismissError(null);
    setIsDismissing(false);
    dismissingRef.current = false;
    setApplyTarget(null);
    setApplyError(null);
    setIsApplying(false);
    applyingRef.current = false;
    setManualTarget(null);
    setManualKararTuru("");
    setManualMiktar("");
    setManualGerekce("");
    setManualFieldError(null);
    setManualError(null);
    setIsManualApplying(false);
    manualApplyingRef.current = false;
    setConflictTarget(null);
    setConflictKararTuru("MEVCUT_PUANTAJI_KORU");
    setConflictGerekce("");
    setConflictFieldError(null);
    setConflictError(null);
    setIsConflictResolving(false);
    conflictResolvingRef.current = false;
    setIsGenerating(false);
    setGenerateError(null);
    generatingRef.current = false;
  }, []);

  const closeDetail = useCallback(() => {
    setDetail(null);
    setDetailId(null);
    setDetailError(null);
    setIsDetailLoading(false);
  }, []);

  const refreshOzet = useCallback(
    async (gyId: number) => {
      if (!contextReady) {
        return;
      }
      setIsOzetLoading(true);
      setOzetError(null);
      const requestId = ++ozetRequestIdRef.current;
      try {
        const data = await fetchBildirimPuantajEtkiAdayOzet(gyId, { subeId });
        if (requestId === ozetRequestIdRef.current) {
          setOzet(data);
        }
      } catch (caught) {
        if (requestId === ozetRequestIdRef.current) {
          setOzet(null);
          setOzetError(getApiErrorDetail(caught, "Puantaj etki adayı özeti yüklenemedi.").message);
        }
      } finally {
        if (requestId === ozetRequestIdRef.current) {
          setIsOzetLoading(false);
        }
      }
    },
    [contextReady, subeId]
  );

  const resolveGyOnayId = useCallback(
    async (listItems: BildirimPuantajEtkiAdayListItem[]): Promise<number | null> => {
      const fromList = listItems[0]?.genel_yonetici_bildirim_onayi_id ?? null;
      if (typeof fromList === "number" && fromList > 0) {
        return fromList;
      }
      if (canResolveGyViaOnayApi && contextReady && subeId !== null && birimAmiriUserId !== null) {
        try {
          const gyOzet = await fetchGenelYoneticiBildirimOnayiOzet(ay, subeId, birimAmiriUserId);
          const gyId = gyOzet.genel_yonetici_bildirim_onayi?.id;
          if (typeof gyId === "number" && gyId > 0) {
            return gyId;
          }
        } catch {
          // MUHASEBE/BOLUM bu API'yi cagiramaz; list/cache kaynagina dusulur.
        }
      }
      return resolvedGyIdRef.current;
    },
    [ay, birimAmiriUserId, canResolveGyViaOnayApi, contextReady, subeId]
  );

  const refreshList = useCallback(async () => {
    if (!listParams || !contextReady || subeId === null || birimAmiriUserId === null) {
      return;
    }
    setIsListLoading(true);
    setListError(null);
    const requestId = ++listRequestIdRef.current;
    try {
      const result = await fetchBildirimPuantajEtkiAdayList(listParams, { subeId });
      if (requestId !== listRequestIdRef.current) {
        return;
      }
      setItems(result.items);
      setPagination({
        page: result.pagination.page ?? listParams.page ?? 1,
        limit: result.pagination.limit ?? listParams.limit ?? 20,
        total: result.pagination.total ?? result.items.length,
        totalPages: result.pagination.totalPages ?? 1,
        hasNextPage: Boolean(result.pagination.hasNextPage),
        hasPreviousPage: Boolean(result.pagination.hasPreviousPage)
      });
      const gyId = await resolveGyOnayId(result.items);
      if (gyId) {
        resolvedGyIdRef.current = gyId;
        await refreshOzet(gyId);
      } else {
        resolvedGyIdRef.current = null;
        setOzet(null);
      }
    } catch (caught) {
      if (requestId === listRequestIdRef.current) {
        setItems([]);
        setPagination(EMPTY_PAGINATION);
        setOzet(null);
        resolvedGyIdRef.current = null;
        setListError(getApiErrorDetail(caught, "Puantaj etki adayları yüklenemedi.").message);
      }
    } finally {
      if (requestId === listRequestIdRef.current) {
        setIsListLoading(false);
      }
    }
  }, [birimAmiriUserId, contextReady, listParams, refreshOzet, resolveGyOnayId, subeId]);

  const refreshDetail = useCallback(
    async (id: number) => {
      if (!contextReady) {
        return;
      }
      setIsDetailLoading(true);
      setDetailError(null);
      const requestId = ++detailRequestIdRef.current;
      try {
        const data = await fetchBildirimPuantajEtkiAdayDetail(id, { subeId });
        if (requestId === detailRequestIdRef.current) {
          setDetail(data);
        }
      } catch (caught) {
        if (requestId === detailRequestIdRef.current) {
          setDetail(null);
          setDetailError(getApiErrorDetail(caught, "Puantaj etki adayı detayı yüklenemedi.").message);
        }
      } finally {
        if (requestId === detailRequestIdRef.current) {
          setIsDetailLoading(false);
        }
      }
    },
    [contextReady, subeId]
  );

  const refreshAll = useCallback(async () => {
    await refreshList();
    if (detailId !== null) {
      await refreshDetail(detailId);
    }
  }, [detailId, refreshDetail, refreshList]);

  useEffect(() => {
    listRequestIdRef.current += 1;
    ozetRequestIdRef.current += 1;
    detailRequestIdRef.current += 1;
    resetTransientState();
    setPage(1);
    setDraftFilters(EMPTY_FILTERS);
    setAppliedFilters(EMPTY_FILTERS);
  }, [contextKey, enabled, resetTransientState]);

  useEffect(() => {
    if (!contextReady) {
      return;
    }
    void refreshList();
  }, [contextReady, refreshList]);

  useEffect(() => {
    if (detailId === null) {
      return;
    }
    if (!items.some((item) => item.id === detailId)) {
      closeDetail();
    }
  }, [closeDetail, detailId, items]);

  useEffect(() => {
    if (!contextReady || detailId === null) {
      return;
    }
    void refreshDetail(detailId);
  }, [contextReady, detailId, refreshDetail]);

  const setAy = useCallback(
    (value: string) => {
      if (controlledAy === undefined) {
        setInternalAy(value);
      }
      onAyChange?.(value);
    },
    [controlledAy, onAyChange]
  );

  const updateDraftFilters = useCallback((patch: Partial<BildirimPuantajEtkiAdayFilters>) => {
    setDraftFilters((prev) => ({ ...prev, ...patch }));
  }, []);

  const submitFilters = useCallback(() => {
    setAppliedFilters(draftFilters);
    setPage(1);
  }, [draftFilters]);

  const clearFilters = useCallback(() => {
    setDraftFilters(EMPTY_FILTERS);
    setAppliedFilters(EMPTY_FILTERS);
    setPage(1);
  }, []);

  const openDetail = useCallback((item: BildirimPuantajEtkiAdayListItem) => {
    setDetailId(item.id);
    setDetail(null);
    setDetailError(null);
  }, []);

  const openDismissModal = useCallback(
    (item: BildirimPuantajEtkiAdayListItem) => {
      if (!canDismiss) {
        return;
      }
      setApplyTarget(null);
      setApplyError(null);
      setDismissTarget(item);
      setDismissGerekce("");
      setDismissFieldError(null);
      setDismissError(null);
    },
    [canDismiss]
  );

  const openApplyModal = useCallback(
    (item: BildirimPuantajEtkiAdayDetail) => {
      if (!canApply || item.state !== "HAZIR" || isDetailLoading || isApplying || isManualApplying) {
        return;
      }
      setDismissTarget(null);
      setDismissError(null);
      setManualTarget(null);
      setManualError(null);
      setApplyTarget(item);
      setApplyError(null);
    },
    [canApply, isApplying, isDetailLoading, isManualApplying]
  );

  const openManualApplyModal = useCallback(
    (item: BildirimPuantajEtkiAdayDetail) => {
      if (!canApply || item.state !== "INCELEME_GEREKLI" || isDetailLoading || isApplying || isManualApplying) {
        return;
      }
      setDismissTarget(null);
      setDismissError(null);
      setApplyTarget(null);
      setApplyError(null);
      setManualTarget(item);
      setManualKararTuru("");
      setManualMiktar("");
      setManualGerekce("");
      setManualFieldError(null);
      setManualError(null);
    },
    [canApply, isApplying, isDetailLoading, isManualApplying]
  );

  const dismissAday = useCallback(async () => {
    if (!canDismiss || !dismissTarget || dismissingRef.current || isDismissing || isApplying || isManualApplying) {
      return;
    }
    const trimmed = trimDismissGerekce(dismissGerekce);
    if (trimmed.length < 5 || [...trimmed].length > 500) {
      return;
    }
    if (dismissTarget.state !== "HAZIR" && dismissTarget.state !== "INCELEME_GEREKLI") {
      return;
    }

    dismissingRef.current = true;
    setIsDismissing(true);
    setDismissFieldError(null);
    setDismissError(null);
    setSuccessMessage(null);
    setInfoMessage(null);

    try {
      const result = await dismissBildirimPuantajEtkiAday(
        dismissTarget.id,
        {
          expected_state: dismissTarget.state,
          gerekce: trimmed
        },
        { subeId }
      );
      closeDismissModal();
      if (result.idempotent) {
        setInfoMessage("Bu aday daha önce aynı gerekçeyle yok sayılmış.");
      } else {
        setSuccessMessage("Puantaj etki adayı yok sayıldı.");
      }
      await refreshAll();
    } catch (caught) {
      const detail = getApiErrorDetail(caught, "Puantaj etki adayı yok sayılamadı.");
      if (detail.code === "VALIDATION_ERROR" && detail.field === "gerekce") {
        setDismissFieldError(detail.message);
      } else if (detail.code === "STATE_STALE") {
        closeDismissModal();
        setInfoMessage("Aday durumu değişmiş. Liste yenilendi.");
        await refreshAll();
      } else if (detail.code === "STATE_CONFLICT") {
        setDismissError(detail.message);
        await refreshAll();
      } else {
        setDismissError(detail.message);
      }
    } finally {
      dismissingRef.current = false;
      setIsDismissing(false);
    }
  }, [
    canDismiss,
    closeDismissModal,
    dismissGerekce,
    dismissTarget,
    isApplying,
    isDismissing,
    refreshAll,
    subeId
  ]);

  const openConflictModal = useCallback((target: BildirimPuantajEtkiAdayDetail) => {
    setConflictTarget(target);
    setConflictKararTuru(
      target.conflict_default_karar === "ADAY_ETKISIYLE_REVIZE_ET"
        ? "ADAY_ETKISIYLE_REVIZE_ET"
        : "MEVCUT_PUANTAJI_KORU"
    );
    setConflictGerekce("");
    setConflictFieldError(null);
    setConflictError(null);
  }, []);

  const maybeOpenConflictFromDetail = useCallback(
    async (targetId: number) => {
      if (!canResolveConflict || !subeId) {
        return null;
      }
      try {
        const fresh = await fetchBildirimPuantajEtkiAdayDetail(targetId, { subeId });
        if (canResolveConflictForDetail(fresh)) {
          openConflictModal(fresh);
          setDetail(fresh);
          return fresh;
        }
        return fresh;
      } catch {
        return null;
      }
    },
    [canResolveConflict, openConflictModal, subeId]
  );

  const resolveConflictAday = useCallback(async (kararOverride?: BildirimPuantajEtkiConflictKararTuru) => {
    if (
      !canResolveConflict ||
      !conflictTarget ||
      conflictResolvingRef.current ||
      isConflictResolving ||
      isApplying ||
      isDismissing ||
      isManualApplying
    ) {
      return;
    }
    const effectiveKarar = kararOverride ?? conflictKararTuru;
    const trimmedGerekce = trimDismissGerekce(conflictGerekce);
    if (trimmedGerekce.length < 5 || [...trimmedGerekce].length > 500) {
      return;
    }
    const puantaj = conflictTarget.mevcut_puantaj;
    const hash = conflictTarget.current_puantaj_hash;
    if (!puantaj?.id || !hash) {
      return;
    }

    conflictResolvingRef.current = true;
    setIsConflictResolving(true);
    setConflictFieldError(null);
    setConflictError(null);
    setSuccessMessage(null);
    setInfoMessage(null);

    try {
      const result = await cakismaCozBildirimPuantajEtkiAdayi(
        conflictTarget.id,
        {
          expected_state: conflictTarget.state === "HAZIR" ? "HAZIR" : "INCELEME_GEREKLI",
          karar_turu: effectiveKarar,
          gerekce: trimmedGerekce,
          expected_puantaj_id: puantaj.id,
          expected_puantaj_hash: hash
        },
        { subeId }
      );
      closeConflictModal();
      closeApplyModal();
      closeManualApplyModal();
      if (result.idempotent) {
        setInfoMessage("Bu çakışma kararı daha önce kaydedilmiş.");
      } else if (effectiveKarar === "MEVCUT_PUANTAJI_KORU") {
        setSuccessMessage("Mevcut puantaj korunarak aday kapatıldı.");
      } else {
        setSuccessMessage("Aday etkisi mevcut puantaja kontrollü biçimde uygulandı.");
      }
      setDetail(result.aday);
      await refreshAll();
    } catch (caught) {
      const errorDetail = getApiErrorDetail(caught, "Puantaj çakışması çözülemedi.");
      if (errorDetail.code === "VALIDATION_ERROR" && errorDetail.field) {
        setConflictFieldError(errorDetail.message);
      } else if (errorDetail.code && REFETCH_CONFLICT_CODES.has(errorDetail.code)) {
        setConflictError(errorDetail.message);
        const refreshed = await maybeOpenConflictFromDetail(conflictTarget.id);
        if (!refreshed || !canResolveConflictForDetail(refreshed)) {
          closeConflictModal();
        }
        await refreshAll();
      } else {
        setConflictError(errorDetail.message);
        if (errorDetail.code === "PERIOD_LOCKED" || errorDetail.code === "PUANTAJ_SOURCE_PROTECTED") {
          await refreshAll();
        }
      }
    } finally {
      conflictResolvingRef.current = false;
      setIsConflictResolving(false);
    }
  }, [
    canResolveConflict,
    closeApplyModal,
    closeConflictModal,
    closeManualApplyModal,
    conflictGerekce,
    conflictKararTuru,
    conflictTarget,
    isApplying,
    isConflictResolving,
    isDismissing,
    isManualApplying,
    maybeOpenConflictFromDetail,
    refreshAll,
    subeId
  ]);

  const applyAday = useCallback(async () => {
    if (!canApply || !applyTarget || applyingRef.current || isApplying || isDismissing) {
      return;
    }
    if (applyTarget.state !== "HAZIR") {
      return;
    }

    applyingRef.current = true;
    setIsApplying(true);
    setApplyError(null);
    setSuccessMessage(null);
    setInfoMessage(null);

    try {
      const result = await applyBildirimPuantajEtkiAday(
        applyTarget.id,
        { expected_state: "HAZIR" },
        { subeId }
      );
      closeApplyModal();
      if (result.idempotent) {
        setInfoMessage("Bu aday daha önce uygulanmış.");
      } else {
        setSuccessMessage("Puantaj etki adayı günlük puantaja uygulandı.");
      }
      await refreshAll();
    } catch (caught) {
      const detail = getApiErrorDetail(caught, "Puantaj etki adayı uygulanamadı.");
      if (detail.code === "STATE_STALE") {
        closeApplyModal();
        setInfoMessage("Aday durumu değişmiş. Liste yenilendi.");
        await refreshAll();
      } else if (
        detail.code === "STATE_CONFLICT" ||
        detail.code === "PERIOD_LOCKED" ||
        detail.code === "PUANTAJ_OLUSTU" ||
        detail.code === "APPLY_UNSUPPORTED" ||
        detail.code === "APPLY_INTEGRITY_CONFLICT"
      ) {
        if (detail.code === "PUANTAJ_OLUSTU" && canResolveConflict) {
          closeApplyModal();
          await maybeOpenConflictFromDetail(applyTarget.id);
        } else {
          setApplyError(detail.message);
        }
        await refreshAll();
      } else {
        setApplyError(detail.message);
      }
    } finally {
      applyingRef.current = false;
      setIsApplying(false);
    }
  }, [
    applyTarget,
    canApply,
    canResolveConflict,
    closeApplyModal,
    isApplying,
    isDismissing,
    maybeOpenConflictFromDetail,
    refreshAll,
    subeId
  ]);

  const manualApplyAday = useCallback(async () => {
    if (!canApply || !manualTarget || manualApplyingRef.current || isManualApplying || isApplying || isDismissing) {
      return;
    }
    if (manualTarget.state !== "INCELEME_GEREKLI" || !manualKararTuru) {
      return;
    }
    const trimmedGerekce = trimDismissGerekce(manualGerekce);
    if (trimmedGerekce.length < 5 || [...trimmedGerekce].length > 500) {
      return;
    }

    manualApplyingRef.current = true;
    setIsManualApplying(true);
    setManualFieldError(null);
    setManualError(null);
    setSuccessMessage(null);
    setInfoMessage(null);

    try {
      const result = await manuelUygulaBildirimPuantajEtkiAdayi(
        manualTarget.id,
        {
          expected_state: "INCELEME_GEREKLI",
          karar_etki_turu: manualKararTuru,
          etki_miktari:
            manualKararTuru === "GEC_KALMA_DAKIKA" || manualKararTuru === "ERKEN_CIKIS_DAKIKA"
              ? Number.parseInt(manualMiktar.trim(), 10)
              : null,
          gerekce: trimmedGerekce
        },
        { subeId }
      );
      closeManualApplyModal();
      if (result.idempotent) {
        setInfoMessage("Bu aday daha önce aynı manuel kararla uygulanmış.");
      } else {
        setSuccessMessage("Manuel inceleme kararı günlük puantaja uygulandı.");
      }
      await refreshAll();
    } catch (caught) {
      const detail = getApiErrorDetail(caught, "Manuel inceleme kararı uygulanamadı.");
      if (detail.code === "VALIDATION_ERROR" && detail.field) {
        setManualFieldError(detail.message);
      } else if (detail.code === "STATE_STALE") {
        closeManualApplyModal();
        setInfoMessage("Adayın durumu başka bir işlemle değişti. Kayıt yenileniyor.");
        await refreshAll();
      } else if (
        detail.code === "SOURCE_INTEGRITY_FAILED" ||
        detail.code === "MANUAL_DECISION_CONFLICT" ||
        detail.code === "STATE_CONFLICT" ||
        detail.code === "PERIOD_LOCKED" ||
        detail.code === "PUANTAJ_OLUSTU" ||
        detail.code === "APPLY_INTEGRITY_CONFLICT"
      ) {
        if (detail.code === "SOURCE_INTEGRITY_FAILED") {
          setManualError("Adayın kaynak verisi doğrulanamadı. Kayıt uygulanmadan yenilendi.");
        } else if (detail.code === "MANUAL_DECISION_CONFLICT") {
          setManualError("Bu aday daha önce farklı bir manuel kararla uygulanmış.");
        } else if (detail.code === "PUANTAJ_OLUSTU") {
          if (canResolveConflict) {
            closeManualApplyModal();
            await maybeOpenConflictFromDetail(manualTarget.id);
          } else {
            setManualError("Bu personel ve tarih için puantaj kaydı zaten oluşmuş. Mevcut kayıt değiştirilmedi.");
          }
        } else if (detail.code === "PERIOD_LOCKED") {
          setManualError("Bu dönem mühürlendiği için manuel karar uygulanamaz.");
        } else {
          setManualError(detail.message);
        }
        await refreshAll();
      } else {
        setManualError(detail.message);
      }
    } finally {
      manualApplyingRef.current = false;
      setIsManualApplying(false);
    }
  }, [
    canApply,
    canResolveConflict,
    closeManualApplyModal,
    isApplying,
    isDismissing,
    isManualApplying,
    manualGerekce,
    manualKararTuru,
    manualMiktar,
    manualTarget,
    maybeOpenConflictFromDetail,
    refreshAll,
    subeId
  ]);

  const generateAdaylar = useCallback(async () => {
    if (!canGenerate || !ozet?.hazirlanabilir_mi || generatingRef.current || isGenerating) {
      return;
    }
    const gyId = ozet.context.genel_yonetici_bildirim_onayi_id;
    if (!gyId) {
      return;
    }
    generatingRef.current = true;
    setIsGenerating(true);
    setGenerateError(null);
    setSuccessMessage(null);
    setInfoMessage(null);
    try {
      await generateBildirimPuantajEtkiAdaylari(
        { genel_yonetici_bildirim_onayi_id: gyId },
        { subeId }
      );
      setSuccessMessage("Puantaj etki adayları hazırlandı.");
      await refreshAll();
    } catch (caught) {
      setGenerateError(getApiErrorDetail(caught, "Puantaj etki adayları hazırlanamadı.").message);
    } finally {
      generatingRef.current = false;
      setIsGenerating(false);
    }
  }, [canGenerate, isGenerating, ozet, refreshAll, subeId]);

  const isLoading = isListLoading || isOzetLoading;

  return {
    ay,
    setAy,
    draftFilters,
    appliedFilters,
    updateDraftFilters,
    submitFilters,
    clearFilters,
    page,
    setPage,
    pagination,
    items,
    ozet,
    detail,
    detailId,
    isLoading,
    isListLoading,
    isOzetLoading,
    isDetailLoading,
    listError,
    ozetError,
    detailError,
    successMessage,
    infoMessage,
    dismissTarget,
    dismissGerekce,
    setDismissGerekce,
    dismissFieldError,
    dismissError,
    isDismissing,
    applyTarget,
    applyError,
    isApplying,
    manualTarget,
    manualKararTuru,
    setManualKararTuru,
    manualMiktar,
    setManualMiktar,
    manualGerekce,
    setManualGerekce,
    manualFieldError,
    manualError,
    isManualApplying,
    conflictTarget,
    conflictKararTuru,
    setConflictKararTuru,
    conflictGerekce,
    setConflictGerekce,
    conflictFieldError,
    conflictError,
    isConflictResolving,
    isGenerating,
    generateError,
    contextReady,
    refreshList,
    refreshAll,
    openDetail,
    closeDetail,
    openDismissModal,
    closeDismissModal,
    dismissAday,
    openApplyModal,
    closeApplyModal,
    applyAday,
    openManualApplyModal,
    closeManualApplyModal,
    manualApplyAday,
    openConflictModal,
    closeConflictModal,
    resolveConflictAday,
    generateAdaylar
  };
}
