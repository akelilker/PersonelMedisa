import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { getApiErrorMessage } from "../api/api-client";
import { createZimmet } from "../api/zimmetler.api";
import { emptyPaginated } from "../data/app-data.types";
import { dataCacheKeys, mergeCacheEntry } from "../data/data-manager";
import type { PaginatedResult } from "../types/api";
import { useAuth } from "../state/auth.store";
import type { CreateZimmetPayload, Zimmet } from "../types/zimmet";

export type PersonelZimmetFormState = {
  urunTuru: string;
  teslimTarihi: string;
  teslimEden: string;
  aciklama: string;
  teslimDurumu: string;
};

export const INITIAL_PERSONEL_ZIMMET_FORM: PersonelZimmetFormState = {
  urunTuru: "",
  teslimTarihi: "",
  teslimEden: "",
  aciklama: "",
  teslimDurumu: "YENI"
};

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

function mergeZimmetHistoryRow(items: Zimmet[], next: Zimmet) {
  return sortZimmetHistory([next, ...items.filter((item) => item.id !== next.id)]);
}

function buildPersonelZimmetPayload(
  personelId: number,
  form: PersonelZimmetFormState
): CreateZimmetPayload {
  const urunTuru = form.urunTuru.trim();
  const teslimTarihi = form.teslimTarihi.trim();
  const teslimEden = form.teslimEden.trim();
  const teslimDurumu = form.teslimDurumu.trim();

  if (!urunTuru) {
    throw new Error("Urun turu zorunludur.");
  }

  if (!teslimTarihi) {
    throw new Error("Teslim tarihi zorunludur.");
  }

  if (!teslimEden) {
    throw new Error("Teslim eden bilgisi zorunludur.");
  }

  if (!teslimDurumu) {
    throw new Error("Teslim durumu zorunludur.");
  }

  return {
    personel_id: personelId,
    urun_turu: urunTuru,
    teslim_tarihi: teslimTarihi,
    teslim_eden: teslimEden,
    aciklama: form.aciklama.trim() || undefined,
    teslim_durumu: teslimDurumu
  };
}

type UsePersonelZimmetCreateOptions = {
  canSubmit: boolean;
  onCreateSuccess?: () => void;
};

export function usePersonelZimmetCreate(
  parsedPersonelId: number,
  hasValidId: boolean,
  canCreateZimmet: boolean,
  options: UsePersonelZimmetCreateOptions
) {
  const { session } = useAuth();
  const activeSubeId = session?.active_sube_id ?? null;
  const { canSubmit, onCreateSuccess } = options;

  const zimmetHistoryKey = useMemo(
    () => dataCacheKeys.zimmetlerList(activeSubeId, String(parsedPersonelId), 1),
    [activeSubeId, parsedPersonelId]
  );

  const [zimmetForm, setZimmetForm] = useState<PersonelZimmetFormState>(INITIAL_PERSONEL_ZIMMET_FORM);
  const [isZimmetSubmitting, setIsZimmetSubmitting] = useState(false);
  const [zimmetCreateErrorMessage, setZimmetCreateErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    setZimmetForm(INITIAL_PERSONEL_ZIMMET_FORM);
    setZimmetCreateErrorMessage(null);
    setIsZimmetSubmitting(false);
  }, [parsedPersonelId]);

  const resetZimmetForm = useCallback(() => {
    setZimmetForm(INITIAL_PERSONEL_ZIMMET_FORM);
    setZimmetCreateErrorMessage(null);
  }, []);

  const createZimmetHandler = useCallback(
    async (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      if (!hasValidId || !canSubmit || isZimmetSubmitting) {
        return;
      }

      if (!canCreateZimmet) {
        setZimmetCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
        return;
      }

      setZimmetCreateErrorMessage(null);
      setIsZimmetSubmitting(true);

      try {
        const payload = buildPersonelZimmetPayload(parsedPersonelId, zimmetForm);
        const created = await createZimmet(payload);
        mergeCacheEntry<PaginatedResult<Zimmet>>(zimmetHistoryKey, (prev) => {
          const base = prev ?? emptyPaginated<Zimmet>();
          return {
            ...base,
            items: mergeZimmetHistoryRow(base.items, created)
          };
        });
        setZimmetForm(INITIAL_PERSONEL_ZIMMET_FORM);
        onCreateSuccess?.();
      } catch (error) {
        setZimmetCreateErrorMessage(getApiErrorMessage(error, "Zimmet kaydi yapilamadi."));
      } finally {
        setIsZimmetSubmitting(false);
      }
    },
    [
      canCreateZimmet,
      canSubmit,
      hasValidId,
      isZimmetSubmitting,
      onCreateSuccess,
      parsedPersonelId,
      zimmetForm,
      zimmetHistoryKey
    ]
  );

  return {
    zimmetForm,
    setZimmetForm,
    createZimmetHandler,
    isZimmetSubmitting,
    zimmetCreateErrorMessage,
    setZimmetCreateErrorMessage,
    resetZimmetForm
  };
}
