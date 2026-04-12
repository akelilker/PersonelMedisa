import { useCallback, useEffect, useMemo, useState } from "react";
import { fetchIsgMakineBakimlari, fetchIsgMakineDetail } from "../api/isg.api";
import {
  dataCacheKeys,
  fetchWithCacheMerge,
  getActiveSube,
  getCacheEntry,
  getSubeIdForApiRequest,
  useAppDataRevision
} from "../data/data-manager";
import { emptyPaginated } from "../data/app-data.types";
import { buildIsgBakimProjection } from "../features/isg/isg-bakim-utils";
import { runDeduped } from "../lib/in-flight-dedupe";
import { sessionAllowsSubeAccess } from "../lib/authorization/role-permissions";
import { useAuth } from "../state/auth.store";
import type { PaginatedResult } from "../types/api";
import type { IsgBakimKaydi, IsgMakineDetail } from "../types/isg";

const HISTORY_PAGE_SIZE = 10;

type ScopedBakimKaydi = IsgBakimKaydi & {
  normalizedDate: string | null;
};

function isDetailVisible(
  detail: IsgMakineDetail,
  activeSube: number | null,
  sessionSubeCheck: (subeId: number) => boolean
) {
  if (detail.subeId === null) {
    return activeSube === null;
  }

  if (activeSube !== null && detail.subeId !== activeSube) {
    return false;
  }

  return sessionSubeCheck(detail.subeId);
}

export function useIsgMakineDetay(makineId: number, hasValidId: boolean) {
  const { session } = useAuth();
  const revision = useAppDataRevision();
  const [page, setPage] = useState(1);
  const [detailLoading, setDetailLoading] = useState(hasValidId);
  const [historyLoading, setHistoryLoading] = useState(hasValidId);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [historyError, setHistoryError] = useState<string | null>(null);

  useEffect(() => {
    setPage(1);
  }, [makineId]);

  const activeSube = useMemo(() => getActiveSube(), [revision]);
  const requestSubeId = useMemo(() => getSubeIdForApiRequest(), [revision]);

  const detailKey = useMemo(
    () => dataCacheKeys.isgMakineDetail(activeSube, makineId),
    [activeSube, makineId]
  );
  const historyKey = useMemo(
    () => dataCacheKeys.isgMakineBakimlari(activeSube, makineId, page),
    [activeSube, makineId, page]
  );

  const detailSnapshot = useMemo(
    () => getCacheEntry<IsgMakineDetail | null>(detailKey) ?? null,
    [detailKey, revision]
  );
  const historySnapshot = useMemo(
    () => getCacheEntry<PaginatedResult<IsgBakimKaydi>>(historyKey) ?? emptyPaginated<IsgBakimKaydi>(),
    [historyKey, revision]
  );

  const refetchDetail = useCallback(async () => {
    if (!hasValidId) {
      return null;
    }

    return fetchWithCacheMerge(detailKey, () =>
      runDeduped(detailKey, () => fetchIsgMakineDetail(makineId, requestSubeId))
    );
  }, [detailKey, hasValidId, makineId, requestSubeId]);

  const refetchHistory = useCallback(async () => {
    if (!hasValidId) {
      return emptyPaginated<IsgBakimKaydi>();
    }

    return fetchWithCacheMerge(historyKey, () =>
      runDeduped(historyKey, () =>
        fetchIsgMakineBakimlari(makineId, requestSubeId, page, HISTORY_PAGE_SIZE)
      )
    );
  }, [hasValidId, historyKey, makineId, page, requestSubeId]);

  useEffect(() => {
    let cancelled = false;

    if (!hasValidId) {
      setDetailLoading(false);
      setDetailError(null);
      return () => {
        cancelled = true;
      };
    }

    const hasSeed = getCacheEntry<IsgMakineDetail | null>(detailKey) !== undefined;
    setDetailLoading(!hasSeed);
    setDetailError(null);

    void (async () => {
      try {
        await refetchDetail();
      } catch {
        if (getCacheEntry<IsgMakineDetail | null>(detailKey) === undefined) {
          setDetailError("Makine detayi su an yuklenemiyor.");
        }
      } finally {
        if (!cancelled) {
          setDetailLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [detailKey, hasValidId, refetchDetail]);

  useEffect(() => {
    let cancelled = false;

    if (!hasValidId) {
      setHistoryLoading(false);
      setHistoryError(null);
      return () => {
        cancelled = true;
      };
    }

    const hasSeed = getCacheEntry<PaginatedResult<IsgBakimKaydi>>(historyKey) !== undefined;
    setHistoryLoading(!hasSeed);
    setHistoryError(null);

    void (async () => {
      try {
        await refetchHistory();
      } catch {
        if (getCacheEntry<PaginatedResult<IsgBakimKaydi>>(historyKey) === undefined) {
          setHistoryError("Bakim gecmisi su an yuklenemiyor.");
        }
      } finally {
        if (!cancelled) {
          setHistoryLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [hasValidId, historyKey, refetchHistory]);

  const scopedDetail = useMemo(() => {
    if (!detailSnapshot) {
      return null;
    }

    return isDetailVisible(detailSnapshot, activeSube, (subeId) => sessionAllowsSubeAccess(session, subeId))
      ? detailSnapshot
      : null;
  }, [activeSube, detailSnapshot, session]);

  const notFoundReason = useMemo<"not_found" | "out_of_scope" | null>(() => {
    if (detailLoading) {
      return null;
    }
    if (detailSnapshot && !scopedDetail) {
      return "out_of_scope";
    }
    if (!detailSnapshot) {
      return "not_found";
    }
    return null;
  }, [detailLoading, detailSnapshot, scopedDetail]);

  const bakimProjection = useMemo(() => {
    return buildIsgBakimProjection({
      items: scopedDetail ? historySnapshot.items : [],
      durum: scopedDetail?.durum ?? "aktif",
      bakimPeriyotGun: scopedDetail?.bakimPeriyotGun ?? null,
      getDate: (item) => item.bakimTarihi
    });
  }, [historySnapshot.items, scopedDetail]);

  const bakimKayitlari = useMemo<ScopedBakimKaydi[]>(
    () =>
      bakimProjection.items.map(({ item, normalizedDate }) => ({
        ...item,
        normalizedDate
      })),
    [bakimProjection.items]
  );

  const makine = useMemo(() => {
    if (!scopedDetail) {
      return null;
    }

    return {
      ...scopedDetail,
      sonBakim: bakimProjection.sonBakim,
      sonrakiBakim: bakimProjection.sonrakiBakim,
      gecikmeGun: bakimProjection.gecikmeGun,
      uyariDurumu: bakimProjection.uyariDurumu
    };
  }, [bakimProjection.gecikmeGun, bakimProjection.sonBakim, bakimProjection.sonrakiBakim, bakimProjection.uyariDurumu, scopedDetail]);

  const refetch = useCallback(async () => {
    await Promise.all([refetchDetail(), refetchHistory()]);
  }, [refetchDetail, refetchHistory]);

  const goPrevPage = useCallback(() => {
    setPage((prev) => Math.max(1, prev - 1));
  }, []);

  const goNextPage = useCallback(() => {
    setPage((prev) => prev + 1);
  }, []);

  return {
    activeSube,
    page,
    makine,
    bakimKayitlari,
    pagination: historySnapshot.pagination,
    isLoading: detailLoading,
    isHistoryLoading: historyLoading,
    errorMessage: detailError ?? historyError,
    notFoundReason,
    refetch,
    goPrevPage,
    goNextPage
  };
}
