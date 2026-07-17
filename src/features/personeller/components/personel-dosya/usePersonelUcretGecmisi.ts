import { useEffect, useState } from "react";
import {
  cancelPersonelUcret,
  createPersonelUcret,
  fetchPersonelAktifUcret,
  fetchPersonelUcretList,
  getUcretApiErrorMessage
} from "../../../../api/ucretler.api";
import { dataCacheKeys, deleteCacheEntry, getActiveSube } from "../../../../data/data-manager";
import type { CreatePersonelUcretPayload, PersonelUcretKaydi } from "../../../../types/ucret";
import type { Personel } from "../../../../types/personel";
import { sortUcretKayitlari } from "./personel-ucret-utils";

export function usePersonelUcretGecmisi({
  personel,
  canViewUcret,
  isActive
}: {
  personel: Personel;
  canViewUcret: boolean;
  isActive: boolean;
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

  /** Ücret mutasyonu legacy personeller.maas_tutari alanını da etkiler; detay cache'i tazelenmeli. */
  function invalidatePersonelDetailCache() {
    deleteCacheEntry(dataCacheKeys.personelDetail(getActiveSube(), personel.id));
  }

  async function submitUcret(payload: CreatePersonelUcretPayload): Promise<boolean> {
    if (isSubmitting) {
      return false;
    }

    setIsSubmitting(true);
    setSubmitErrorMessage(null);

    try {
      await createPersonelUcret(personel.id, payload);
      invalidatePersonelDetailCache();
      refetch();
      return true;
    } catch (err) {
      setSubmitErrorMessage(getUcretApiErrorMessage(err, "Ücret kaydı oluşturulamadı."));
      return false;
    } finally {
      setIsSubmitting(false);
    }
  }

  async function cancelUcret(ucretId: number): Promise<boolean> {
    if (cancellingUcretId !== null) {
      return false;
    }

    setCancellingUcretId(ucretId);
    setCancelErrorMessage(null);

    try {
      await cancelPersonelUcret(personel.id, ucretId);
      invalidatePersonelDetailCache();
      refetch();
      return true;
    } catch (err) {
      setCancelErrorMessage(getUcretApiErrorMessage(err, "Ücret kaydı iptal edilemedi."));
      return false;
    } finally {
      setCancellingUcretId(null);
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
