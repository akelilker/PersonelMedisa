import type {
  CreateBildirimPayload,
  UpdateBildirimPayload
} from "../api/bildirimler.api";
import type { CreatePersonelPayload, UpdatePersonelPayload } from "../api/personeller.api";
import type { CreateSurecPayload, UpdateSurecPayload } from "../api/surecler.api";
import type { PaginatedResult } from "../types/api";
import type { Bildirim } from "../types/bildirim";
import type {
  CreateFinansKalemPayload,
  FinansKalem,
  UpdateFinansKalemPayload
} from "../types/finans";
import type { Personel } from "../types/personel";
import type { UpsertGunlukPuantajPayload } from "../types/puantaj";
import type { IdOption } from "../types/referans";
import type { Surec } from "../types/surec";

export const APP_DATA_STORAGE_KEY = "medisa_app_data";
export const APP_SYNC_QUEUE_KEY = "medisa_sync_queue";
export const APP_DATA_SCHEMA_VERSION = 4;

export type CacheEnvelope<T> = {
  data: T;
  fetchedAt: string;
};

export type PersonelReferenceBundle = {
  departmanOptions: IdOption[];
  gorevOptions: IdOption[];
  personelTipiOptions: IdOption[];
  bagliAmirOptions: IdOption[];
  ucretTipiOptions: IdOption[];
  primKuraliOptions: IdOption[];
};

export type AppData = {
  schemaVersion: number;
  revision: number;
  updatedAt: string | null;
  cache: Record<string, CacheEnvelope<unknown>>;
};

export type SyncQueueItemBase = {
  id: string;
  createdAt: string;
  meta?: {
    listKey?: string;
    tempId?: number;
    detailKey?: string;
  };
};

export type SyncQueueItem =
  | (SyncQueueItemBase & { op: "personeller.create"; payload: CreatePersonelPayload })
  | (SyncQueueItemBase & {
      op: "personeller.update";
      payload: { personelId: number; body: UpdatePersonelPayload };
    })
  | (SyncQueueItemBase & { op: "surecler.create"; payload: CreateSurecPayload })
  | (SyncQueueItemBase & {
      op: "surecler.update";
      payload: { surecId: number; body: UpdateSurecPayload };
    })
  | (SyncQueueItemBase & { op: "surecler.cancel"; payload: { surecId: number } })
  | (SyncQueueItemBase & { op: "bildirimler.create"; payload: CreateBildirimPayload })
  | (SyncQueueItemBase & {
      op: "bildirimler.update";
      payload: { bildirimId: number; body: UpdateBildirimPayload };
    })
  | (SyncQueueItemBase & { op: "bildirimler.cancel"; payload: { bildirimId: number } })
  | (SyncQueueItemBase & { op: "finans.create"; payload: CreateFinansKalemPayload })
  | (SyncQueueItemBase & {
      op: "finans.update";
      payload: { kalemId: number; body: UpdateFinansKalemPayload };
    })
  | (SyncQueueItemBase & { op: "finans.cancel"; payload: { kalemId: number } })
  | (SyncQueueItemBase & {
      op: "puantaj.upsert";
      payload: {
        personelId: number;
        tarih: string;
        body: UpsertGunlukPuantajPayload;
      };
    });

export function emptyPaginated<T>(): PaginatedResult<T> {
  return {
    items: [],
    pagination: {
      page: 1,
      limit: 10,
      total: 0,
      totalPages: 0,
      hasNextPage: false,
      hasPreviousPage: false
    }
  };
}

export function makeTempId(): number {
  return -Math.abs(Date.now());
}

export type CachedPersonelList = PaginatedResult<Personel>;
export type CachedSurecList = PaginatedResult<Surec>;
export type CachedBildirimList = PaginatedResult<Bildirim>;
export type CachedFinansList = PaginatedResult<FinansKalem>;
