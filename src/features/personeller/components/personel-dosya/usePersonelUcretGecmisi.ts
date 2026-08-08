import { useEffect, useState } from "react";
import {
  cancelPersonelUcret,
  createPersonelUcret,
  fetchPersonelAktifUcret,
  fetchPersonelUcretList,
  getUcretApiErrorMessage
} from "../../../../api/ucretler.api";
import { fetchPersonelDetail } from "../../../../api/personeller.api";
import {
  commitPersonelUpdateToCaches,
  dataCacheKeys,
  deleteCacheEntry,
  fetchWithCacheMerge,
  getActiveSube
} from "../../../../data/data-manager";
import { runDeduped } from "../../../../lib/in-flight-dedupe";
import type { CreatePersonelUcretPayload, PersonelUcretKaydi } from "../../../../types/ucret";
import type { Personel } from "../../../../types/personel";
import { sortUcretKayitlari } from "./personel-ucret-utils";

export function usePersonelUcretGecmisi({
  personel,
  canViewUcret,
  isActive,
  onBusyChange,
  onSalaryMutationSuccess
}: {
  personel: Personel;
  canViewUcret: boolean;
  isActive: boolean;
  onBusyChange?: (busy: boolean) => void;
  /** Optional; Process workspace reconciles local personel. Card may omit. */
  onSalaryMutationSuccess?: (updated: Personel) => void;
}) {
  const [ucretler, setUcretler] = useState<PersonelUcretKaydi[]>([]);
  const [aktifUcret, setAktifUcret] = useState<PersonelUcretKaydi | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [fetchResolved, setFetchResolved] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitErrorMessage, setSubmitErrorMessage] = useState<string | null>(null);
  const [cancellingUcretId, setCancellingUcretId] = useState<number | null>(null);
  const [cancelErrorMessage, setCancelErrorMessage] = useState<string | null>(null);

  const canFetch = isActive && canViewUcret && Boolean(personel.id);
  const isUcretLoading = canFetch && (isLoading || !fetchResolved);

  useEffect(() => {
    let isCancelled = false;

    if (!canFetch) {
      setUcretler([]);
      setAktifUcret(null);
      setIsLoading(false);
      setErrorMessage(null);
      setFetchResolved(false);
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);
    setFetchResolved(false);

    Promise.all([fetchPersonelUcretList(personel.id), fetchPersonelAktifUcret(personel.id)])
      .then(([liste, aktif]) => {
        if (isCancelled) {
          return;
        }
        setUcretler(sortUcretKayitlari(liste));
        setAktifUcret(aktif);
      })
      .catch((err) => {
        if (isCancelled) {
          return;
        }
        setUcretler([]);
        setAktifUcret(null);
        setErrorMessage(getUcretApiErrorMessage(err, "Ücret geçmişi yüklenemedi."));
      })
      .finally(() => {
        if (!isCancelled) {
          setIsLoading(false);
          setFetchResolved(true);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [canFetch, personel.id, reloadKey]);

  function refetch() {
    setReloadKey((prev) => prev + 1);
  }

  /**
   * Salary mutations sync legacy personeller.maas_tutari on the server.
   * Re-read Personel SoT (no client-invented maas) and reconcile caches.
   */
  async function reconcilePersonelAfterSalaryMutation(): Promise<void> {
    const personelId = personel.id;
    const activeSube = getActiveSube();
    const detailKey = dataCacheKeys.personelDetail(activeSube, personelId);
    deleteCacheEntry(detailKey);
    const updated = await fetchWithCacheMerge(detailKey, () =>
      runDeduped(detailKey, () => fetchPersonelDetail(personelId))
    );
    commitPersonelUpdateToCaches(updated);
    onSalaryMutationSuccess?.(updated);
  }

  async function submitUcret(payload: CreatePersonelUcretPayload): Promise<boolean> {
    if (isSubmitting || cancellingUcretId !== null) {
      return false;
    }

    setIsSubmitting(true);
    onBusyChange?.(true);
    setSubmitErrorMessage(null);

    try {
      await createPersonelUcret(personel.id, payload);
      try {
        await reconcilePersonelAfterSalaryMutation();
      } catch {
        /* Salary write succeeded; personel SoT refresh is best-effort. */
      }
      refetch();
      return true;
    } catch (err) {
      setSubmitErrorMessage(getUcretApiErrorMessage(err, "Ücret kaydı oluşturulamadı."));
      return false;
    } finally {
      setIsSubmitting(false);
      onBusyChange?.(false);
    }
  }

  async function cancelUcret(ucretId: number): Promise<boolean> {
    if (cancellingUcretId !== null || isSubmitting) {
      return false;
    }

    setCancellingUcretId(ucretId);
    onBusyChange?.(true);
    setCancelErrorMessage(null);

    try {
      await cancelPersonelUcret(personel.id, ucretId);
      try {
        await reconcilePersonelAfterSalaryMutation();
      } catch {
        /* Cancel succeeded; personel SoT refresh is best-effort. */
      }
      refetch();
      return true;
    } catch (err) {
      setCancelErrorMessage(getUcretApiErrorMessage(err, "Ücret kaydı iptal edilemedi."));
      return false;
    } finally {
      setCancellingUcretId(null);
      onBusyChange?.(false);
    }
  }

  return {
    ucretler,
    aktifUcret,
    isLoading: isUcretLoading,
    errorMessage,
    fetchResolved,
    canFetch,
    refetch,
    isSubmitting,
    submitErrorMessage,
    clearSubmitError: () => setSubmitErrorMessage(null),
    submitUcret,
    cancellingUcretId,
    cancelErrorMessage,
    cancelUcret
  };
}
