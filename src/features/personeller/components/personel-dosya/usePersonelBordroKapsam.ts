import { useEffect, useState } from "react";
import {
  approvePersonelBordroKapsam,
  cancelPersonelBordroKapsam,
  createPersonelBordroKapsam,
  dryRunPersonelBordroKapsam,
  fetchPersonelBordroKapsamlari,
  getBordroKapsamApiErrorMessage,
  submitPersonelBordroKapsam
} from "../../../../api/personel-bordro-kapsam.api";
import type {
  CreatePersonelBordroKapsamPayload,
  PersonelBordroKapsamDryRunResult,
  PersonelBordroKapsamKaydi
} from "../../../../types/personel-bordro-kapsam";
import type { Personel } from "../../../../types/personel";

export function usePersonelBordroKapsam({
  personel,
  canView,
  isActive
}: {
  personel: Personel;
  canView: boolean;
  isActive: boolean;
}) {
  const [kayitlar, setKayitlar] = useState<PersonelBordroKapsamKaydi[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [fetchResolved, setFetchResolved] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitErrorMessage, setSubmitErrorMessage] = useState<string | null>(null);
  const [dryRunResult, setDryRunResult] = useState<PersonelBordroKapsamDryRunResult | null>(null);
  const [actionErrorMessage, setActionErrorMessage] = useState<string | null>(null);

  const canFetch = isActive && canView && Boolean(personel.id);

  useEffect(() => {
    let cancelled = false;
    if (!canFetch) {
      setKayitlar([]);
      setIsLoading(false);
      setErrorMessage(null);
      setFetchResolved(false);
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);
    setFetchResolved(false);

    fetchPersonelBordroKapsamlari(personel.id)
      .then((items) => {
        if (!cancelled) {
          setKayitlar(items);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setKayitlar([]);
          setErrorMessage(getBordroKapsamApiErrorMessage(err, "Bordro kapsam listesi yüklenemedi."));
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoading(false);
          setFetchResolved(true);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [canFetch, personel.id, reloadKey]);

  function refetch() {
    setReloadKey((prev) => prev + 1);
  }

  function clearSubmitError() {
    setSubmitErrorMessage(null);
  }

  async function runDryRun(
    payload: Omit<CreatePersonelBordroKapsamPayload, "dry_run_hash">
  ): Promise<PersonelBordroKapsamDryRunResult | null> {
    setSubmitErrorMessage(null);
    try {
      const result = await dryRunPersonelBordroKapsam(personel.id, payload);
      setDryRunResult(result);
      return result;
    } catch (err) {
      setDryRunResult(null);
      setSubmitErrorMessage(getBordroKapsamApiErrorMessage(err, "Dry-run başarısız."));
      return null;
    }
  }

  async function submitCreate(payload: CreatePersonelBordroKapsamPayload): Promise<boolean> {
    if (isSubmitting) {
      return false;
    }
    setIsSubmitting(true);
    setSubmitErrorMessage(null);
    try {
      await createPersonelBordroKapsam(personel.id, payload);
      setDryRunResult(null);
      refetch();
      return true;
    } catch (err) {
      setSubmitErrorMessage(getBordroKapsamApiErrorMessage(err, "Kapsam kaydı oluşturulamadı."));
      return false;
    } finally {
      setIsSubmitting(false);
    }
  }

  async function submitForApproval(kapsamId: number): Promise<void> {
    setActionErrorMessage(null);
    try {
      await submitPersonelBordroKapsam(personel.id, kapsamId);
      refetch();
    } catch (err) {
      setActionErrorMessage(getBordroKapsamApiErrorMessage(err, "Onaya gönderilemedi."));
    }
  }

  async function approve(kapsamId: number): Promise<void> {
    setActionErrorMessage(null);
    try {
      await approvePersonelBordroKapsam(personel.id, kapsamId);
      refetch();
    } catch (err) {
      setActionErrorMessage(getBordroKapsamApiErrorMessage(err, "Onaylanamadı."));
    }
  }

  async function cancel(kapsamId: number, neden: string): Promise<void> {
    setActionErrorMessage(null);
    try {
      await cancelPersonelBordroKapsam(personel.id, kapsamId, neden);
      refetch();
    } catch (err) {
      setActionErrorMessage(getBordroKapsamApiErrorMessage(err, "İptal edilemedi."));
    }
  }

  return {
    kayitlar,
    isLoading,
    errorMessage,
    fetchResolved,
    canFetch,
    isSubmitting,
    submitErrorMessage,
    clearSubmitError,
    dryRunResult,
    setDryRunResult,
    runDryRun,
    submitCreate,
    submitForApproval,
    approve,
    cancel,
    actionErrorMessage
  };
}
