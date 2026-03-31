import { useSyncExternalStore } from "react";
import { getActiveSubeId } from "../auth/auth-manager";
import { ApiRequestError } from "../api/api-client";
import {
  cancelBildirim,
  createBildirim,
  fetchBildirimlerList,
  updateBildirim
} from "../api/bildirimler.api";
import {
  cancelFinansKalem,
  createFinansKalem,
  updateFinansKalem
} from "../api/finans.api";
import {
  createPersonel,
  fetchPersonellerList,
  updatePersonel,
  type CreatePersonelPayload
} from "../api/personeller.api";
import {
  fetchBagliAmirOptions,
  fetchBildirimTuruOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchSurecTuruOptions
} from "../api/referans.api";
import { upsertGunlukPuantaj } from "../api/puantaj.api";
import { cancelSurec, createSurec, updateSurec } from "../api/surecler.api";
import type { PaginatedResult } from "../types/api";
import type { Bildirim } from "../types/bildirim";
import type { FinansKalem } from "../types/finans";
import type { Personel } from "../types/personel";
import type { Surec } from "../types/surec";
import type { GunlukPuantaj } from "../types/puantaj";
import type { RealtimeEnvelope } from "../realtime/realtime-manager";
import {
  APP_DATA_SCHEMA_VERSION,
  APP_DATA_STORAGE_KEY,
  APP_SYNC_QUEUE_KEY,
  type AppData,
  type CacheEnvelope,
  type PersonelReferenceBundle,
  type SyncQueueItem,
  emptyPaginated
} from "./app-data.types";

const listeners = new Set<() => void>();

export function subscribeAppData(onStoreChange: () => void): () => void {
  listeners.add(onStoreChange);
  return () => {
    listeners.delete(onStoreChange);
  };
}

function notifyAppData(): void {
  listeners.forEach((listener) => {
    listener();
  });
}

export function getAppDataRevision(): number {
  return ensureAppData().revision;
}

export function useAppDataRevision(): number {
  return useSyncExternalStore(subscribeAppData, getAppDataRevision, getAppDataRevision);
}

function createEmptyAppData(): AppData {
  return {
    schemaVersion: APP_DATA_SCHEMA_VERSION,
    revision: 0,
    updatedAt: null,
    cache: {}
  };
}

export function getFallbackData(): AppData {
  return createEmptyAppData();
}

export function getSafeAppDataFallback(): AppData {
  return createEmptyAppData();
}

export function ensureAppData(): AppData {
  if (typeof window === "undefined") {
    return createEmptyAppData();
  }

  if (!window.appData) {
    window.appData = createEmptyAppData();
    return window.appData;
  }

  if (window.appData.schemaVersion !== APP_DATA_SCHEMA_VERSION) {
    window.appData = createEmptyAppData();
    return window.appData;
  }

  return window.appData;
}

export function getAppData(): AppData {
  return ensureAppData();
}

export function hasUsableData(): boolean {
  const data = ensureAppData();
  if (data.schemaVersion !== APP_DATA_SCHEMA_VERSION) {
    return false;
  }

  return typeof data.cache === "object" && data.cache !== null;
}

function bumpRevision(data: AppData): void {
  data.revision += 1;
  data.updatedAt = new Date().toISOString();
}

export function persistAppData(): void {
  if (typeof window === "undefined") {
    return;
  }

  try {
    const payload = JSON.stringify(getAppData());
    window.localStorage.setItem(APP_DATA_STORAGE_KEY, payload);
  } catch {
    /* quota / private mode */
  }
}

export function clearAllAppPersistence(): void {
  if (typeof window === "undefined") {
    return;
  }

  const empty = createEmptyAppData();
  window.appData = empty;

  try {
    window.localStorage.removeItem(APP_DATA_STORAGE_KEY);
    window.localStorage.removeItem(APP_SYNC_QUEUE_KEY);
  } catch {
    /* ignore */
  }

  notifyAppData();
}

export function safeParseStoredAppData(raw: string | null): AppData | null {
  if (raw === null || raw === undefined || raw.trim() === "") {
    return null;
  }

  const trimmed = raw.trim();
  if (trimmed.startsWith("<") || trimmed.startsWith("<!")) {
    return null;
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(trimmed);
  } catch {
    return null;
  }

  if (typeof parsed !== "object" || parsed === null) {
    return null;
  }

  const record = parsed as Record<string, unknown>;
  if (record.schemaVersion === 2) {
    record.schemaVersion = APP_DATA_SCHEMA_VERSION;
    delete record.activeSubeId;
  }
  if (record.schemaVersion !== APP_DATA_SCHEMA_VERSION) {
    return null;
  }

  if (typeof record.revision !== "number" || typeof record.cache !== "object" || record.cache === null) {
    return null;
  }

  return parsed as AppData;
}

export function initAppDataFromStorage(): AppData {
  if (typeof window === "undefined") {
    return createEmptyAppData();
  }

  const raw = window.localStorage.getItem(APP_DATA_STORAGE_KEY);
  const parsed = safeParseStoredAppData(raw);
  if (parsed) {
    window.appData = parsed;
  } else {
    window.appData = getSafeAppDataFallback();
    persistAppData();
  }

  notifyAppData();
  return window.appData;
}

export function setAppData(partial: Partial<Pick<AppData, "cache">> & Partial<Pick<AppData, "updatedAt">>): void {
  const data = ensureAppData();
  if (partial.cache) {
    data.cache = { ...data.cache, ...partial.cache };
  }
  if (partial.updatedAt !== undefined) {
    data.updatedAt = partial.updatedAt;
  }
  bumpRevision(data);
  persistAppData();
  notifyAppData();
}

/** Aktif sube tek kaynak: auth oturumu (session). */
export function getActiveSube(): number | null {
  if (typeof window === "undefined") {
    return null;
  }
  return getActiveSubeId();
}

/** Sube degisimi sonrasi onbellek aboneliklerini uyandirir. */
export function bumpAppDataRevision(): void {
  const data = ensureAppData();
  bumpRevision(data);
  persistAppData();
  notifyAppData();
}

export function getSubeIdForApiRequest(): number | undefined {
  const id = getActiveSube();
  return id === null ? undefined : id;
}

function subeSeg(subeId: number | null): string {
  return subeId === null ? "all" : String(subeId);
}

export const dataCacheKeys = {
  personellerList: (subeId: number | null, search: string, aktiflik: string, page: number) =>
    `personeller:list:s${subeSeg(subeId)}:${search}|${aktiflik}|${page}`,
  personelDetail: (id: number) => `personeller:detail:${id}`,
  referansPersonel: () => `referans:personel-bundle`,
  sureclerList: (
    subeId: number | null,
    personelId: string,
    surecTuru: string,
    state: string,
    bas: string,
    bit: string,
    page: number
  ) => `surecler:list:s${subeSeg(subeId)}:${personelId}|${surecTuru}|${state}|${bas}|${bit}|${page}`,
  surecDetail: (id: number) => `surecler:detail:${id}`,
  surecTuruRef: () => `referans:surec-turu`,
  bildirimlerList: (subeId: number | null, personelId: string, tur: string, tarih: string, page: number) =>
    `bildirimler:list:s${subeSeg(subeId)}:${personelId}|${tur}|${tarih}|${page}`,
  bildirimlerHeader: (subeId: number | null) => `bildirimler:header:8:s${subeSeg(subeId)}`,
  bildirimDetail: (id: number) => `bildirimler:detail:${id}`,
  bildirimRef: () => `referans:bildirim-meta`,
  finansList: (subeId: number | null, personelId: string, donem: string, kalem: string, state: string, page: number) =>
    `finans:list:s${subeSeg(subeId)}:${personelId}|${donem}|${kalem}|${state}|${page}`,
  puantajDetail: (subeId: number | null, personelId: number, tarih: string) =>
    `puantaj:s${subeSeg(subeId)}:${personelId}|${tarih}`
};

function wrapEnvelope<T>(data: T): CacheEnvelope<T> {
  return { data, fetchedAt: new Date().toISOString() };
}

export function getCacheEntry<T>(key: string): T | undefined {
  const env = ensureAppData().cache[key] as CacheEnvelope<T> | undefined;
  return env?.data;
}

export function setCacheEntry<T>(key: string, data: T): void {
  const app = ensureAppData();
  app.cache[key] = wrapEnvelope(data) as CacheEnvelope<unknown>;
  bumpRevision(app);
  persistAppData();
  notifyAppData();
}

export function deleteCacheEntry(key: string): void {
  const app = ensureAppData();
  if (!(key in app.cache)) {
    return;
  }
  delete app.cache[key];
  bumpRevision(app);
  persistAppData();
  notifyAppData();
}

export function mergeCacheEntry<T>(key: string, updater: (prev: T | undefined) => T): void {
  const prev = getCacheEntry<T>(key);
  setCacheEntry(key, updater(prev));
}

export async function fetchWithCacheMerge<T>(key: string, fetcher: () => Promise<T>): Promise<T> {
  try {
    const data = await fetcher();
    setCacheEntry(key, data);
    return data;
  } catch {
    const cached = getCacheEntry<T>(key);
    if (cached !== undefined) {
      return cached;
    }

    const fallback = resolveFallbackForKey(key) as T | undefined;
    if (fallback !== undefined) {
      setCacheEntry(key, fallback);
      return fallback;
    }

    throw new Error("Offline ve onbellekte veri yok.");
  }
}

function resolveFallbackForKey(key: string): unknown {
  if (key.startsWith("personeller:list:")) {
    return emptyPaginated<Personel>();
  }
  if (key.startsWith("surecler:list:")) {
    return emptyPaginated();
  }
  if (key.startsWith("bildirimler:list:") || key.startsWith("bildirimler:header:")) {
    return emptyPaginated();
  }
  if (key.startsWith("finans:list:")) {
    return emptyPaginated();
  }
  if (key.startsWith("referans:personel-bundle")) {
    return {
      departmanOptions: [],
      gorevOptions: [],
      personelTipiOptions: [],
      bagliAmirOptions: []
    } satisfies PersonelReferenceBundle;
  }
  if (key.startsWith("referans:surec-turu")) {
    return [];
  }
  if (key.startsWith("referans:bildirim-meta")) {
    return { departman: [], bildirimTuru: [] };
  }
  if (key.startsWith("puantaj:s") || key.startsWith("puantaj:")) {
    return null;
  }
  return undefined;
}

function readQueue(): SyncQueueItem[] {
  if (typeof window === "undefined") {
    return [];
  }

  try {
    const raw = window.localStorage.getItem(APP_SYNC_QUEUE_KEY);
    if (!raw) {
      return [];
    }

    const parsed = JSON.parse(raw) as unknown;
    if (!Array.isArray(parsed)) {
      return [];
    }

    return parsed as SyncQueueItem[];
  } catch {
    return [];
  }
}

function writeQueue(items: SyncQueueItem[]): void {
  if (typeof window === "undefined") {
    return;
  }

  try {
    window.localStorage.setItem(APP_SYNC_QUEUE_KEY, JSON.stringify(items));
  } catch {
    /* ignore */
  }
}

export function enqueueSyncOperation(item: Omit<SyncQueueItem, "id" | "createdAt"> & { id?: string }): void {
  const queue = readQueue();
  const id = item.id ?? `q_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`;
  const next: SyncQueueItem = {
    ...(item as SyncQueueItem),
    id,
    createdAt: new Date().toISOString()
  };
  queue.push(next);
  writeQueue(queue);
}

function dequeueSyncOperation(id: string): void {
  writeQueue(readQueue().filter((entry) => entry.id !== id));
}

export async function processSyncQueue(): Promise<void> {
  if (typeof navigator !== "undefined" && navigator.onLine === false) {
    return;
  }

  const queue = readQueue();
  if (queue.length === 0) {
    return;
  }

  for (const item of queue) {
    try {
      await dispatchSyncItem(item);
      dequeueSyncOperation(item.id);
    } catch (error) {
      if (error instanceof ApiRequestError && (error.status === 401 || error.status === 403)) {
        dequeueSyncOperation(item.id);
        continue;
      }
      break;
    }
  }

  notifyAppData();
}

async function dispatchSyncItem(item: SyncQueueItem): Promise<void> {
  switch (item.op) {
    case "personeller.create": {
      const created = await createPersonel(item.payload);
      finalizePersonelCreateInCache(item, created);
      return;
    }
    case "personeller.update": {
      await updatePersonel(item.payload.personelId, item.payload.body);
      return;
    }
    case "surecler.create": {
      const createdSurec = await createSurec(item.payload);
      finalizeSurecCreateInCache(item, createdSurec);
      return;
    }
    case "surecler.update": {
      await updateSurec(item.payload.surecId, item.payload.body);
      return;
    }
    case "surecler.cancel": {
      await cancelSurec(item.payload.surecId);
      return;
    }
    case "bildirimler.create": {
      const createdBildirim = await createBildirim(item.payload);
      finalizeBildirimCreateInCache(item, createdBildirim);
      return;
    }
    case "bildirimler.update": {
      await updateBildirim(item.payload.bildirimId, item.payload.body);
      return;
    }
    case "bildirimler.cancel": {
      await cancelBildirim(item.payload.bildirimId);
      return;
    }
    case "finans.create": {
      const createdFinans = await createFinansKalem(item.payload);
      finalizeFinansCreateInCache(item, createdFinans);
      return;
    }
    case "finans.update": {
      const updatedFinans = await updateFinansKalem(item.payload.kalemId, item.payload.body);
      finalizeFinansUpdateInCache(item, updatedFinans);
      return;
    }
    case "finans.cancel": {
      await cancelFinansKalem(item.payload.kalemId);
      finalizeFinansCancelInCache(item);
      return;
    }
    case "puantaj.upsert": {
      const updatedPuantaj = await upsertGunlukPuantaj(
        item.payload.personelId,
        item.payload.tarih,
        item.payload.body
      );
      mergePuantajCache(item.payload.personelId, item.payload.tarih, updatedPuantaj);
      return;
    }
  }
}

function finalizeFinansCreateInCache(item: SyncQueueItem & { op: "finans.create" }, created: FinansKalem): void {
  const listKey = item.meta?.listKey;
  const tempId = item.meta?.tempId;
  if (!listKey || tempId === undefined) {
    return;
  }

  mergeCacheEntry<PaginatedResult<FinansKalem>>(listKey, (prev) => {
    const base = prev ?? emptyPaginated<FinansKalem>();
    return {
      ...base,
      items: base.items.map((row) => (row.id === tempId ? created : row))
    };
  });
}

function finalizeFinansUpdateInCache(item: SyncQueueItem & { op: "finans.update" }, updated: FinansKalem): void {
  const listKey = item.meta?.listKey;
  if (!listKey) {
    return;
  }

  mergeCacheEntry<PaginatedResult<FinansKalem>>(listKey, (prev) => {
    const base = prev ?? emptyPaginated<FinansKalem>();
    return {
      ...base,
      items: base.items.map((row) => (row.id === updated.id ? updated : row))
    };
  });
}

function finalizeFinansCancelInCache(item: SyncQueueItem & { op: "finans.cancel" }): void {
  const listKey = item.meta?.listKey;
  const kalemId = item.payload.kalemId;
  if (!listKey) {
    return;
  }

  mergeCacheEntry<PaginatedResult<FinansKalem>>(listKey, (prev) => {
    const base = prev ?? emptyPaginated<FinansKalem>();
    return {
      ...base,
      items: base.items.map((row) =>
        row.id === kalemId ? { ...row, state: "IPTAL" as const } : row
      )
    };
  });
}

function finalizeBildirimCreateInCache(item: SyncQueueItem & { op: "bildirimler.create" }, created: Bildirim): void {
  const listKey = item.meta?.listKey;
  const tempId = item.meta?.tempId;
  if (!listKey || tempId === undefined) {
    return;
  }

  mergeCacheEntry<PaginatedResult<Bildirim>>(listKey, (prev) => {
    const base = prev ?? emptyPaginated<Bildirim>();
    return {
      ...base,
      items: base.items.map((row) => (row.id === tempId ? created : row))
    };
  });
}

function finalizeSurecCreateInCache(item: SyncQueueItem & { op: "surecler.create" }, created: Surec): void {
  const listKey = item.meta?.listKey;
  const tempId = item.meta?.tempId;
  if (!listKey || tempId === undefined) {
    return;
  }

  mergeCacheEntry<PaginatedResult<Surec>>(listKey, (prev) => {
    const base = prev ?? emptyPaginated<Surec>();
    return {
      ...base,
      items: base.items.map((row) => (row.id === tempId ? created : row))
    };
  });
}

function finalizePersonelCreateInCache(item: SyncQueueItem & { op: "personeller.create" }, created: Personel): void {
  const listKey = item.meta?.listKey;
  const tempId = item.meta?.tempId;
  if (!listKey || tempId === undefined) {
    return;
  }

  mergeCacheEntry<PaginatedResult<Personel>>(listKey, (prev) => {
    const base = prev ?? emptyPaginated<Personel>();
    return {
      ...base,
      items: base.items.map((row) => (row.id === tempId ? created : row))
    };
  });
}

export function optimisticPrependToList<T extends { id: number }>(listKey: string, row: T): number {
  const tempId = row.id;
  mergeCacheEntry<PaginatedResult<T>>(listKey, (prev) => {
    const base = prev ?? emptyPaginated<T>();
    if (base.items.some((item) => item.id === tempId)) {
      return base;
    }
    return {
      ...base,
      items: [row, ...base.items]
    };
  });
  return tempId;
}

export function optimisticPrependPersonel(listKey: string, row: Personel): number {
  return optimisticPrependToList(listKey, row);
}

export function replacePersonelInListCache(listKey: string, tempId: number, created: Personel): void {
  mergeCacheEntry<PaginatedResult<Personel>>(listKey, (prev) => {
    if (!prev) {
      return { ...emptyPaginated<Personel>(), items: [created] };
    }
    return {
      ...prev,
      items: prev.items.map((item) => (item.id === tempId ? created : item))
    };
  });
}

export function removeQueuedTempPersonel(listKey: string, tempId: number): void {
  mergeCacheEntry<PaginatedResult<Personel>>(listKey, (prev) => {
    if (!prev) {
      return emptyPaginated<Personel>();
    }
    return {
      ...prev,
      items: prev.items.filter((item) => item.id !== tempId)
    };
  });
}

export function draftPersonelFromPayload(payload: CreatePersonelPayload, tempId: number): Personel {
  return {
    id: tempId,
    tc_kimlik_no: payload.tc_kimlik_no,
    ad: payload.ad,
    soyad: payload.soyad,
    dogum_tarihi: payload.dogum_tarihi,
    telefon: payload.telefon,
    sicil_no: payload.sicil_no,
    aktif_durum: payload.aktif_durum
  };
}

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === "object" && v !== null;
}

function parsePersonelRealtimePayload(p: unknown): Personel | null {
  if (!isRecord(p) || typeof p.id !== "number") {
    return null;
  }
  if (typeof p.ad !== "string" || typeof p.soyad !== "string" || typeof p.tc_kimlik_no !== "string") {
    return null;
  }
  const ad = p.ad;
  const soyad = p.soyad;
  const durum = p.aktif_durum;
  if (durum !== "AKTIF" && durum !== "PASIF") {
    return null;
  }
  return {
    id: p.id,
    tc_kimlik_no: p.tc_kimlik_no,
    ad,
    soyad,
    aktif_durum: durum,
    telefon: typeof p.telefon === "string" ? p.telefon : undefined,
    dogum_tarihi: typeof p.dogum_tarihi === "string" ? p.dogum_tarihi : undefined,
    sicil_no: typeof p.sicil_no === "string" ? p.sicil_no : undefined
  };
}

function parseSurecRealtimePayload(p: unknown): Surec | null {
  if (!isRecord(p) || typeof p.id !== "number" || typeof p.personel_id !== "number") {
    return null;
  }
  if (typeof p.surec_turu !== "string") {
    return null;
  }
  return {
    id: p.id,
    personel_id: p.personel_id,
    surec_turu: p.surec_turu,
    alt_tur: typeof p.alt_tur === "string" ? p.alt_tur : undefined,
    baslangic_tarihi: typeof p.baslangic_tarihi === "string" ? p.baslangic_tarihi : undefined,
    bitis_tarihi: typeof p.bitis_tarihi === "string" ? p.bitis_tarihi : undefined,
    ucretli_mi: typeof p.ucretli_mi === "boolean" ? p.ucretli_mi : undefined,
    aciklama: typeof p.aciklama === "string" ? p.aciklama : undefined,
    state: typeof p.state === "string" ? p.state : undefined
  };
}

function parseBildirimRealtimePayload(p: unknown): Bildirim | null {
  if (!isRecord(p) || typeof p.id !== "number" || typeof p.bildirim_turu !== "string") {
    return null;
  }
  return {
    id: p.id,
    bildirim_turu: p.bildirim_turu,
    tarih: typeof p.tarih === "string" ? p.tarih : undefined,
    departman_id: typeof p.departman_id === "number" ? p.departman_id : undefined,
    personel_id: typeof p.personel_id === "number" ? p.personel_id : undefined,
    aciklama: typeof p.aciklama === "string" ? p.aciklama : undefined,
    state: typeof p.state === "string" ? p.state : undefined,
    okundu_mi: typeof p.okundu_mi === "boolean" ? p.okundu_mi : undefined
  };
}

function realtimeSubeMatchesActive(eventSube: number | undefined): boolean {
  const active = getActiveSube();
  if (active === null) {
    return true;
  }
  if (eventSube === undefined) {
    return true;
  }
  return eventSube === active;
}

function mergePersonelIntoListCaches(row: Personel): void {
  for (const key of Object.keys(ensureAppData().cache)) {
    if (!key.startsWith("personeller:list:")) {
      continue;
    }
    const prev = getCacheEntry<PaginatedResult<Personel>>(key);
    if (!prev) {
      continue;
    }
    const idx = prev.items.findIndex((i) => i.id === row.id);
    if (idx === -1) {
      continue;
    }
    mergeCacheEntry<PaginatedResult<Personel>>(key, (base) => {
      const cur = base!;
      const items = [...cur.items];
      items[idx] = row;
      return { ...cur, items };
    });
  }
}

function mergeSurecIntoListCaches(row: Surec): void {
  for (const key of Object.keys(ensureAppData().cache)) {
    if (!key.startsWith("surecler:list:")) {
      continue;
    }
    const prev = getCacheEntry<PaginatedResult<Surec>>(key);
    if (!prev) {
      continue;
    }
    const idx = prev.items.findIndex((i) => i.id === row.id);
    if (idx === -1) {
      continue;
    }
    mergeCacheEntry<PaginatedResult<Surec>>(key, (base) => {
      const cur = base!;
      const items = [...cur.items];
      items[idx] = row;
      return { ...cur, items };
    });
  }
}

function mergeBildirimHeader(sube: number | null, b: Bildirim): void {
  const key = dataCacheKeys.bildirimlerHeader(sube);
  mergeCacheEntry<PaginatedResult<Bildirim>>(key, (prev) => {
    const base = prev ?? emptyPaginated<Bildirim>();
    const withoutDup = base.items.filter((i) => i.id !== b.id);
    return { ...base, items: [b, ...withoutDup].slice(0, 32) };
  });
}

function prependBildirimToFirstPageLists(sube: number | null, b: Bildirim): void {
  const seg = subeSeg(sube);
  const prefix = `bildirimler:list:s${seg}:`;
  for (const key of Object.keys(ensureAppData().cache)) {
    if (!key.startsWith(prefix) || !key.endsWith("|1")) {
      continue;
    }
    const prev = getCacheEntry<PaginatedResult<Bildirim>>(key);
    if (!prev) {
      continue;
    }
    mergeCacheEntry<PaginatedResult<Bildirim>>(key, (base) => {
      const cur = base!;
      const withoutDup = cur.items.filter((i) => i.id !== b.id);
      return { ...cur, items: [b, ...withoutDup] };
    });
  }
}

/**
 * Sunucu / WebSocket pusundan gelen olaylari tek veri kaynagina yazar.
 * Optimistic gecici id'ler degistirilmez; eslesen kalici id'ler uzerine yazilir.
 */
export function handleRealtimeEnvelope(env: RealtimeEnvelope): void {
  if (!realtimeSubeMatchesActive(env.sube_id)) {
    return;
  }
  const keySube = getActiveSube() ?? (typeof env.sube_id === "number" ? env.sube_id : null);

  switch (env.type) {
    case "PERSONEL_GUNCELLENDI": {
      const row = parsePersonelRealtimePayload(env.payload);
      if (!row) {
        return;
      }
      setCacheEntry(dataCacheKeys.personelDetail(row.id), row);
      mergePersonelIntoListCaches(row);
      return;
    }
    case "SUREC_GUNCELLENDI": {
      const row = parseSurecRealtimePayload(env.payload);
      if (!row) {
        return;
      }
      setCacheEntry(dataCacheKeys.surecDetail(row.id), row);
      mergeSurecIntoListCaches(row);
      return;
    }
    case "BILDIRIM_YENI": {
      const b = parseBildirimRealtimePayload(env.payload);
      if (!b) {
        return;
      }
      setCacheEntry(dataCacheKeys.bildirimDetail(b.id), b);
      mergeBildirimHeader(keySube, b);
      prependBildirimToFirstPageLists(keySube, b);
      return;
    }
    default:
      return;
  }
}

export async function loadDataFromServer(): Promise<void> {
  const sube = getActiveSube();
  const subeQ = getSubeIdForApiRequest();
  const tasks: Array<Promise<void>> = [
    (async () => {
      const key = dataCacheKeys.personellerList(sube, "", "tum", 1);
      try {
        const data = await fetchPersonellerList({ aktiflik: "tum", page: 1, limit: 10, sube_id: subeQ });
        setCacheEntry(key, data);
      } catch {
        /* sessiz */
      }
    })(),
    (async () => {
      const key = dataCacheKeys.bildirimlerHeader(sube);
      try {
        const data = await fetchBildirimlerList({ page: 1, limit: 8, sube_id: subeQ });
        setCacheEntry(key, data);
      } catch {
        /* sessiz */
      }
    })(),
    (async () => {
      const key = dataCacheKeys.referansPersonel();
      try {
        const bundle: PersonelReferenceBundle = {
          departmanOptions: await fetchDepartmanOptions(),
          gorevOptions: await fetchGorevOptions(),
          personelTipiOptions: await fetchPersonelTipiOptions(),
          bagliAmirOptions: await fetchBagliAmirOptions()
        };
        setCacheEntry(key, bundle);
      } catch {
        /* sessiz */
      }
    })(),
    (async () => {
      const key = dataCacheKeys.surecTuruRef();
      try {
        const data = await fetchSurecTuruOptions();
        setCacheEntry(key, data);
      } catch {
        /* sessiz */
      }
    })(),
    (async () => {
      const key = dataCacheKeys.bildirimRef();
      try {
        const [departman, bildirimTuru] = await Promise.all([
          fetchDepartmanOptions(),
          fetchBildirimTuruOptions()
        ]);
        setCacheEntry(key, { departman, bildirimTuru });
      } catch {
        /* sessiz */
      }
    })()
  ];

  await Promise.allSettled(tasks);
  persistAppData();
  notifyAppData();

  void processSyncQueue();
}

export function attachConnectivityListeners(): () => void {
  if (typeof window === "undefined") {
    return () => undefined;
  }

  const handleOnline = () => {
    void loadDataFromServer();
  };

  window.addEventListener("online", handleOnline);
  return () => {
    window.removeEventListener("online", handleOnline);
  };
}

export function mergePuantajCache(personelId: number, tarih: string, row: GunlukPuantaj | null): void {
  setCacheEntry(dataCacheKeys.puantajDetail(getActiveSube(), personelId, tarih), row);
}
