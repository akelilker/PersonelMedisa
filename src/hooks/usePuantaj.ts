import { useCallback, useState, type FormEvent } from "react";
import { fetchGunlukPuantaj, upsertGunlukPuantaj } from "../api/puantaj.api";
import {
  dataCacheKeys,
  enqueueSyncOperation,
  fetchWithCacheMerge,
  getCacheEntry,
  mergePuantajCache,
  processSyncQueue
} from "../data/data-manager";
import { runDeduped } from "../lib/in-flight-dedupe";
import type { GunlukPuantaj } from "../types/puantaj";

type ActiveQuery = {
  personelId: number;
  tarih: string;
};

function toDateInputValue(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export type GunlukPuantajFormState = {
  queryPersonelId: string;
  queryTarih: string;
  entryGirisSaati: string;
  entryCikisSaati: string;
  entryGercekMolaDakika: string;
};

function toPuantajFormState(puantaj: GunlukPuantaj | null): Pick<
  GunlukPuantajFormState,
  "entryGirisSaati" | "entryCikisSaati" | "entryGercekMolaDakika"
> {
  return {
    entryGirisSaati: puantaj?.giris_saati ?? "",
    entryCikisSaati: puantaj?.cikis_saati ?? "",
    entryGercekMolaDakika:
      puantaj?.gercek_mola_dakika !== undefined ? String(puantaj.gercek_mola_dakika) : ""
  };
}

function parseRequiredPositiveInt(value: string, label: string) {
  const number = Number.parseInt(value, 10);
  if (Number.isNaN(number) || number <= 0) {
    throw new Error(`${label} pozitif sayi olmalidir.`);
  }
  return number;
}

function parseOptionalNonNegativeInt(value: string) {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }

  const number = Number.parseInt(trimmed, 10);
  if (Number.isNaN(number) || number < 0) {
    throw new Error("Gercek mola dakika sifirdan kucuk olamaz.");
  }

  return number;
}

const INITIAL_FORM: GunlukPuantajFormState = {
  queryPersonelId: "",
  queryTarih: toDateInputValue(new Date()),
  entryGirisSaati: "",
  entryCikisSaati: "",
  entryGercekMolaDakika: ""
};

export function usePuantaj() {
  const [formState, setFormState] = useState<GunlukPuantajFormState>({ ...INITIAL_FORM });
  const [activeQuery, setActiveQuery] = useState<ActiveQuery | null>(null);
  const [puantaj, setPuantaj] = useState<GunlukPuantaj | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [submitErrorMessage, setSubmitErrorMessage] = useState<string | null>(null);

  const patchFormState = useCallback((partial: Partial<GunlukPuantajFormState>) => {
    setFormState((prev) => ({ ...prev, ...partial }));
  }, []);

  const detailKeyFor = useCallback((query: ActiveQuery) => {
    return dataCacheKeys.puantajDetail(query.personelId, query.tarih);
  }, []);

  const loadPuantaj = useCallback(
    async (query: ActiveQuery) => {
      setIsLoading(true);
      setErrorMessage(null);
      const key = detailKeyFor(query);

      try {
        const data = await fetchWithCacheMerge(key, () =>
          runDeduped(key, () => fetchGunlukPuantaj(query.personelId, query.tarih))
        );
        setPuantaj(data);
        patchFormState(toPuantajFormState(data));
      } catch {
        setErrorMessage("Gunluk puantaj kaydi su an guncellenemiyor.");
        const cached = getCacheEntry<GunlukPuantaj | null>(key);
        if (cached !== undefined) {
          setPuantaj(cached);
          patchFormState(toPuantajFormState(cached));
        } else {
          setPuantaj(null);
          patchFormState(toPuantajFormState(null));
        }
      } finally {
        setIsLoading(false);
      }
    },
    [detailKeyFor, patchFormState]
  );

  const submitQuery = useCallback(
    async (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();

      try {
        const personelId = parseRequiredPositiveInt(formState.queryPersonelId, "Personel ID");
        if (!formState.queryTarih) {
          throw new Error("Tarih zorunludur.");
        }

        const nextQuery: ActiveQuery = {
          personelId,
          tarih: formState.queryTarih
        };

        setActiveQuery(nextQuery);
        setSubmitErrorMessage(null);
        await loadPuantaj(nextQuery);
      } catch (error) {
        setErrorMessage(error instanceof Error ? error.message : "Puantaj sorgusu gecersiz.");
      }
    },
    [formState.queryPersonelId, formState.queryTarih, loadPuantaj]
  );

  const clearQuery = useCallback(() => {
    setFormState({ ...INITIAL_FORM });
    setActiveQuery(null);
    setPuantaj(null);
    setErrorMessage(null);
    setSubmitErrorMessage(null);
  }, []);

  const refetchActive = useCallback(async () => {
    if (!activeQuery) {
      return;
    }
    await loadPuantaj(activeQuery);
  }, [activeQuery, loadPuantaj]);

  const submitPuantaj = useCallback(
    async (event: FormEvent<HTMLFormElement>, canUpdate: boolean) => {
      event.preventDefault();
      if (isSubmitting) {
        return;
      }

      if (!activeQuery) {
        setSubmitErrorMessage("Kayit guncellemek icin once personel ve tarih sec.");
        return;
      }

      if (!canUpdate) {
        setSubmitErrorMessage("Bu islem icin yetkin bulunmuyor.");
        return;
      }

      setSubmitErrorMessage(null);
      setIsSubmitting(true);

      try {
        const girisSaati = formState.entryGirisSaati.trim();
        const cikisSaati = formState.entryCikisSaati.trim();

        if (!girisSaati || !cikisSaati) {
          throw new Error("Giris ve cikis saati zorunludur.");
        }

        const body = {
          giris_saati: girisSaati,
          cikis_saati: cikisSaati,
          gercek_mola_dakika: parseOptionalNonNegativeInt(formState.entryGercekMolaDakika)
        };

        const optimistic: GunlukPuantaj = {
          personel_id: activeQuery.personelId,
          tarih: activeQuery.tarih,
          giris_saati: body.giris_saati,
          cikis_saati: body.cikis_saati,
          gercek_mola_dakika: body.gercek_mola_dakika,
          compliance_uyarilari: []
        };
        mergePuantajCache(activeQuery.personelId, activeQuery.tarih, optimistic);
        setPuantaj(optimistic);
        patchFormState(toPuantajFormState(optimistic));

        try {
          const updated = await upsertGunlukPuantaj(activeQuery.personelId, activeQuery.tarih, body);
          mergePuantajCache(activeQuery.personelId, activeQuery.tarih, updated);
          setPuantaj(updated);
          patchFormState(toPuantajFormState(updated));
        } catch {
          enqueueSyncOperation({
            op: "puantaj.upsert",
            payload: {
              personelId: activeQuery.personelId,
              tarih: activeQuery.tarih,
              body
            }
          });
          void processSyncQueue();
        }
      } catch (error) {
        setSubmitErrorMessage(error instanceof Error ? error.message : "Puantaj kaydi guncellenemedi.");
      } finally {
        setIsSubmitting(false);
      }
    },
    [
      activeQuery,
      formState.entryCikisSaati,
      formState.entryGercekMolaDakika,
      formState.entryGirisSaati,
      isSubmitting,
      patchFormState
    ]
  );

  return {
    formState,
    patchFormState,
    activeQuery,
    puantaj,
    isLoading,
    isSubmitting,
    errorMessage,
    submitErrorMessage,
    submitQuery,
    clearQuery,
    refetchActive,
    submitPuantaj
  };
}
