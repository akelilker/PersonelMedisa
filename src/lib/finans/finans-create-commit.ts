import { getApiErrorMessage, shouldQueueOfflineMutation } from "../../api/api-client";
import { createFinansKalem, fetchFinansKalemList } from "../../api/finans.api";
import { makeTempId } from "../../data/app-data.types";
import {
  dataCacheKeys,
  enqueueSyncOperation,
  fetchWithCacheMerge,
  getSubeIdForApiRequest,
  optimisticPrependToList,
  processSyncQueue
} from "../../data/data-manager";
import { runDeduped } from "../in-flight-dedupe";
import type { CreateFinansKalemPayload, FinansKalem } from "../../types/finans";

const FINANS_PAGE_SIZE = 10;

function toMonthInputValue(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

function parsePositiveInt(value: string): number | undefined {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }
  const parsed = Number.parseInt(trimmed, 10);
  if (Number.isNaN(parsed) || parsed <= 0) {
    return undefined;
  }
  return parsed;
}

export function parseRequiredPositiveInt(value: string, label: string): number {
  const parsed = parsePositiveInt(value);
  if (!parsed) {
    throw new Error(`${label} pozitif sayi olmalidir.`);
  }
  return parsed;
}

export function parseRequiredPositiveNumber(value: string, label: string): number {
  const trimmed = value.trim();
  const parsed = Number.parseFloat(trimmed);
  if (!trimmed || Number.isNaN(parsed) || parsed <= 0) {
    throw new Error(`${label} sifirdan buyuk olmali.`);
  }
  return parsed;
}

export function validateDonem(donem: string): string {
  const value = donem.trim();
  if (!/^\d{4}-\d{2}$/.test(value)) {
    throw new Error("Dönem YYYY-MM formatında olmalı.");
  }
  return value;
}

export type FinansCreateFormInput = {
  personelId: string;
  donem: string;
  kalemTuru: string;
  tutar: string;
  aciklama: string;
};

export type FinansListAppliedFilters = {
  personelId: string;
  donem: string;
  kalemTuru: string;
  state: string;
};

export type FinansMaliFieldsState = Omit<FinansCreateFormInput, "personelId">;

export function createEmptyFinansCreateForm(personelId = ""): FinansCreateFormInput {
  return {
    personelId,
    donem: toMonthInputValue(new Date()),
    kalemTuru: "AVANS",
    tutar: "",
    aciklama: ""
  };
}

export function createEmptyFinansMaliFields(defaultKalemTuru = "AVANS"): FinansMaliFieldsState {
  const base = createEmptyFinansCreateForm("");
  return {
    donem: base.donem,
    kalemTuru: defaultKalemTuru,
    tutar: base.tutar,
    aciklama: base.aciklama
  };
}

function draftFinansFromPayload(payload: CreateFinansKalemPayload, tempId: number): FinansKalem {
  return {
    id: tempId,
    personel_id: payload.personel_id,
    donem: payload.donem,
    kalem_turu: payload.kalem_turu,
    tutar: payload.tutar,
    aciklama: payload.aciklama,
    state: "AKTIF"
  };
}

export function buildCreateFinansKalemPayload(form: FinansCreateFormInput): CreateFinansKalemPayload {
  return {
    personel_id: parseRequiredPositiveInt(form.personelId, "Personel ID"),
    donem: validateDonem(form.donem),
    kalem_turu: form.kalemTuru.trim(),
    tutar: parseRequiredPositiveNumber(form.tutar, "Tutar"),
    aciklama: form.aciklama.trim() || undefined
  };
}

function finansListPageOneKey(activeSube: number | null, applied: FinansListAppliedFilters): string {
  return dataCacheKeys.finansList(
    activeSube,
    applied.personelId,
    applied.donem,
    applied.kalemTuru,
    applied.state,
    1
  );
}

async function refreshFinansListPageOne(
  activeSube: number | null,
  applied: FinansListAppliedFilters
): Promise<void> {
  const pageOneKey = finansListPageOneKey(activeSube, applied);
  await fetchWithCacheMerge(pageOneKey, () =>
    runDeduped(pageOneKey, () =>
      fetchFinansKalemList({
        personel_id: parsePositiveInt(applied.personelId),
        donem: applied.donem || undefined,
        kalem_turu: applied.kalemTuru || undefined,
        state: applied.state || undefined,
        sube_id: getSubeIdForApiRequest(),
        page: 1,
        limit: FINANS_PAGE_SIZE
      })
    )
  );
}

export type FinansCreateCommitResult =
  | { outcome: "created" }
  | { outcome: "queued-offline" }
  | { outcome: "error"; message: string };

/**
 * Tek finans oluşturma yolu: API, sayfa-1 liste önbelleği, çevrimdışı kuyruk.
 * React state'ine dokunmaz; çağıran modal / form sıfırlamayı yapar.
 */
export async function commitFinansKalemCreate(options: {
  payload: CreateFinansKalemPayload;
  activeSube: number | null;
  applied: FinansListAppliedFilters;
}): Promise<FinansCreateCommitResult> {
  const { payload, activeSube, applied } = options;
  const pageOneKey = finansListPageOneKey(activeSube, applied);

  try {
    try {
      await createFinansKalem(payload);
      await refreshFinansListPageOne(activeSube, applied);
      return { outcome: "created" };
    } catch (error) {
      if (!shouldQueueOfflineMutation(error)) {
        throw error;
      }

      const tempId = makeTempId();
      optimisticPrependToList(pageOneKey, draftFinansFromPayload(payload, tempId));
      enqueueSyncOperation({
        op: "finans.create",
        payload,
        meta: { listKey: pageOneKey, tempId }
      });
      void processSyncQueue();
      return { outcome: "queued-offline" };
    }
  } catch (error) {
    return {
      outcome: "error",
      message: getApiErrorMessage(error, "Finans kaydi olusturulamadi.")
    };
  }
}
