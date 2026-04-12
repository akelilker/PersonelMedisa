import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { getApiErrorMessage, shouldQueueOfflineMutation } from "../api/api-client";
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
import { hesapla, hesapSonucuToGunlukPuantaj } from "../services/puantaj-hesap-motoru";
import { useAuth } from "../state/auth.store";
import type {
  GunlukPuantaj,
  PuantajDayanak,
  PuantajGunTipi,
  PuantajHareketDurumu
} from "../types/puantaj";

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

function deriveGunTipiFromDateInput(value: string): PuantajGunTipi {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
  if (!match) {
    return "Normal_Is_Gunu";
  }

  const date = new Date(
    Number.parseInt(match[1], 10),
    Number.parseInt(match[2], 10) - 1,
    Number.parseInt(match[3], 10)
  );

  return date.getDay() === 0 ? "Hafta_Tatili_Pazar" : "Normal_Is_Gunu";
}

function hareketDurumuSaatGerekliMi(
  hareketDurumu: PuantajHareketDurumu | "" | undefined
): boolean {
  return hareketDurumu === "Geldi" || hareketDurumu === "Gec_Geldi" || hareketDurumu === "Erken_Cikti";
}

export type GunlukPuantajFormState = {
  queryPersonelId: string;
  queryTarih: string;
  entryGunTipi: PuantajGunTipi | "";
  entryHareketDurumu: PuantajHareketDurumu | "";
  entryDayanak: PuantajDayanak | "";
  entryGirisSaati: string;
  entryCikisSaati: string;
  entryGercekMolaDakika: string;
};

function toPuantajFormState(
  puantaj: GunlukPuantaj | null,
  fallbackTarih: string
): Pick<
  GunlukPuantajFormState,
  | "entryGunTipi"
  | "entryHareketDurumu"
  | "entryDayanak"
  | "entryGirisSaati"
  | "entryCikisSaati"
  | "entryGercekMolaDakika"
> {
  const effectiveTarih = puantaj?.tarih ?? fallbackTarih;

  return {
    entryGunTipi: puantaj?.gun_tipi ?? deriveGunTipiFromDateInput(effectiveTarih),
    entryHareketDurumu: puantaj?.hareket_durumu ?? "",
    entryDayanak: puantaj?.dayanak ?? "",
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

const TODAY_INPUT = toDateInputValue(new Date());

const INITIAL_FORM: GunlukPuantajFormState = {
  queryPersonelId: "",
  queryTarih: TODAY_INPUT,
  entryGunTipi: deriveGunTipiFromDateInput(TODAY_INPUT),
  entryHareketDurumu: "",
  entryDayanak: "",
  entryGirisSaati: "",
  entryCikisSaati: "",
  entryGercekMolaDakika: ""
};

export function usePuantaj() {
  const { session } = useAuth();
  const activeSube = session?.active_sube_id ?? null;

  const [formState, setFormState] = useState<GunlukPuantajFormState>({ ...INITIAL_FORM });
  const [activeQuery, setActiveQuery] = useState<ActiveQuery | null>(null);
  const [puantaj, setPuantaj] = useState<GunlukPuantaj | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [submitErrorMessage, setSubmitErrorMessage] = useState<string | null>(null);

  const patchFormState = useCallback((partial: Partial<GunlukPuantajFormState>) => {
    setFormState((prev) => {
      const next = { ...prev, ...partial };
      if (partial.queryTarih !== undefined && partial.entryGunTipi === undefined && !activeQuery) {
        next.entryGunTipi = deriveGunTipiFromDateInput(partial.queryTarih);
      }
      return next;
    });
  }, [activeQuery]);

  const detailKeyFor = useCallback(
    (query: ActiveQuery) => dataCacheKeys.puantajDetail(activeSube, query.personelId, query.tarih),
    [activeSube]
  );

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
        patchFormState(toPuantajFormState(data, query.tarih));
      } catch {
        setErrorMessage("Gunluk puantaj kaydi su an guncellenemiyor.");
        const cached = getCacheEntry<GunlukPuantaj | null>(key);
        if (cached !== undefined) {
          setPuantaj(cached);
          patchFormState(toPuantajFormState(cached, query.tarih));
        } else {
          setPuantaj(null);
          patchFormState(toPuantajFormState(null, query.tarih));
        }
      } finally {
        setIsLoading(false);
      }
    },
    [detailKeyFor, patchFormState]
  );

  useEffect(() => {
    if (!activeQuery) {
      return;
    }
    void loadPuantaj(activeQuery);
  }, [activeSube, activeQuery, loadPuantaj]);

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

  const entryRequiresSaatBilgisi = useMemo(
    () => hareketDurumuSaatGerekliMi(formState.entryHareketDurumu),
    [formState.entryHareketDurumu]
  );

  const submitPuantaj = useCallback(
    async (event: FormEvent<HTMLFormElement>, canUpdate: boolean) => {
      event.preventDefault();
      if (isSubmitting) {
        return;
      }

      if (!activeQuery) {
        setSubmitErrorMessage("Kaydi guncellemek icin once personel ve tarih sec.");
        return;
      }

      if (!canUpdate) {
        setSubmitErrorMessage("Bu islem icin yetkin bulunmuyor.");
        return;
      }

      setSubmitErrorMessage(null);
      setIsSubmitting(true);

      try {
        const gunTipi = formState.entryGunTipi || deriveGunTipiFromDateInput(activeQuery.tarih);
        const hareketDurumu = formState.entryHareketDurumu;
        const dayanak = formState.entryDayanak || undefined;

        if (!hareketDurumu) {
          throw new Error("Hareket durumu zorunludur.");
        }

        const girisSaati = formState.entryGirisSaati.trim();
        const cikisSaati = formState.entryCikisSaati.trim();

        if (hareketDurumuSaatGerekliMi(hareketDurumu) && (!girisSaati || !cikisSaati)) {
          throw new Error("Bu hareket durumu icin giris ve cikis saati zorunludur.");
        }

        const body = {
          gun_tipi: gunTipi,
          hareket_durumu: hareketDurumu,
          dayanak,
          giris_saati: hareketDurumuSaatGerekliMi(hareketDurumu) ? girisSaati : undefined,
          cikis_saati: hareketDurumuSaatGerekliMi(hareketDurumu) ? cikisSaati : undefined,
          gercek_mola_dakika: hareketDurumuSaatGerekliMi(hareketDurumu)
            ? parseOptionalNonNegativeInt(formState.entryGercekMolaDakika)
            : undefined
        };

        const hesapSonucu = hesapla({
          personel_id: activeQuery.personelId,
          tarih: activeQuery.tarih,
          gun_tipi: body.gun_tipi,
          hareket_durumu: body.hareket_durumu,
          dayanak: body.dayanak,
          giris_saati: body.giris_saati,
          cikis_saati: body.cikis_saati,
          gercek_mola_dakika: body.gercek_mola_dakika
        });
        const optimistic = hesapSonucuToGunlukPuantaj(hesapSonucu, puantaj?.state ?? "ACIK");

        const previousPuantaj = puantaj;
        mergePuantajCache(activeQuery.personelId, activeQuery.tarih, optimistic);
        setPuantaj(optimistic);
        patchFormState(toPuantajFormState(optimistic, activeQuery.tarih));

        try {
          const updated = await upsertGunlukPuantaj(activeQuery.personelId, activeQuery.tarih, body);
          mergePuantajCache(activeQuery.personelId, activeQuery.tarih, updated);
          setPuantaj(updated);
          patchFormState(toPuantajFormState(updated, activeQuery.tarih));
        } catch (error) {
          if (shouldQueueOfflineMutation(error)) {
            enqueueSyncOperation({
              op: "puantaj.upsert",
              payload: {
                personelId: activeQuery.personelId,
                tarih: activeQuery.tarih,
                body
              }
            });
            void processSyncQueue();
            return;
          }

          mergePuantajCache(activeQuery.personelId, activeQuery.tarih, previousPuantaj ?? null);
          setPuantaj(previousPuantaj ?? null);
          patchFormState(toPuantajFormState(previousPuantaj ?? null, activeQuery.tarih));
          setSubmitErrorMessage(getApiErrorMessage(error, "Puantaj kaydi guncellenemedi."));
        }
      } catch (error) {
        setSubmitErrorMessage(getApiErrorMessage(error, "Puantaj kaydi guncellenemedi."));
      } finally {
        setIsSubmitting(false);
      }
    },
    [
      activeQuery,
      entryRequiresSaatBilgisi,
      formState.entryCikisSaati,
      formState.entryDayanak,
      formState.entryGercekMolaDakika,
      formState.entryGirisSaati,
      formState.entryGunTipi,
      formState.entryHareketDurumu,
      isSubmitting,
      patchFormState,
      puantaj
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
    submitPuantaj,
    entryRequiresSaatBilgisi
  };
}
