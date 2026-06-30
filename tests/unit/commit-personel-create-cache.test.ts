/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import * as authManager from "../../src/auth/auth-manager";
import { APP_DATA_SCHEMA_VERSION, emptyPaginated } from "../../src/data/app-data.types";
import {
  commitPersonelCreateToCaches,
  dataCacheKeys,
  getCacheEntry,
  setCacheEntry
} from "../../src/data/data-manager";
import type { PaginatedResult } from "../../src/types/api";
import type { Personel } from "../../src/types/personel";

function resetAppDataCache(): void {
  window.appData = {
    schemaVersion: APP_DATA_SCHEMA_VERSION,
    revision: 0,
    updatedAt: null,
    cache: {}
  };
}

function makePersonel(overrides: Partial<Personel> = {}): Personel {
  return {
    id: 99,
    tc_kimlik_no: "11122233344",
    ad: "Cache",
    soyad: "Sync",
    aktif_durum: "AKTIF",
    departman_id: 2,
    personel_tipi_id: 1,
    ...overrides
  };
}

function seedListCache(
  subeId: number | null,
  search: string,
  aktiflik: string,
  departmanId: string,
  personelTipiId: string,
  page: number,
  items: Personel[]
): string {
  const key = dataCacheKeys.personellerList(subeId, search, aktiflik, departmanId, personelTipiId, page);
  setCacheEntry<PaginatedResult<Personel>>(key, {
    ...emptyPaginated<Personel>(),
    items
  });
  return key;
}

describe("commitPersonelCreateToCaches", () => {
  beforeEach(() => {
    resetAppDataCache();
    vi.spyOn(authManager, "getActiveSubeId").mockReturnValue(1);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("page=1 tum filtreye prepend eder ve detail cache set eder", () => {
    const existing = makePersonel({ id: 1, ad: "Mevcut" });
    const key = seedListCache(1, "", "tum", "", "", 1, [existing]);
    const created = makePersonel();

    commitPersonelCreateToCaches(created);

    const list = getCacheEntry<PaginatedResult<Personel>>(key);
    expect(list?.items.map((item) => item.id)).toEqual([99, 1]);
    expect(getCacheEntry<Personel>(dataCacheKeys.personelDetail(1, 99))).toEqual(created);
  });

  it("eslesmeyen search filtresine prepend etmez", () => {
    const key = seedListCache(1, "ayse", "tum", "", "", 1, [makePersonel({ id: 1, ad: "Ayse" })]);
    const created = makePersonel({ ad: "Cache", soyad: "Sync" });

    commitPersonelCreateToCaches(created);

    const list = getCacheEntry<PaginatedResult<Personel>>(key);
    expect(list?.items.map((item) => item.id)).toEqual([1]);
  });

  it("eslesen search filtresine prepend eder", () => {
    const key = seedListCache(1, "cache", "tum", "", "", 1, [makePersonel({ id: 1, ad: "Mevcut" })]);
    const created = makePersonel({ ad: "Cache", soyad: "Sync" });

    commitPersonelCreateToCaches(created);

    const list = getCacheEntry<PaginatedResult<Personel>>(key);
    expect(list?.items.map((item) => item.id)).toEqual([99, 1]);
  });

  it("duplicate id varsa ikinci kez eklemez", () => {
    const created = makePersonel();
    const key = seedListCache(1, "", "tum", "", "", 1, [created]);

    commitPersonelCreateToCaches(created);

    const list = getCacheEntry<PaginatedResult<Personel>>(key);
    expect(list?.items).toHaveLength(1);
    expect(list?.items[0]?.id).toBe(99);
  });

  it("page>1 cache'e dokunmaz", () => {
    const pageTwoKey = seedListCache(1, "", "tum", "", "", 2, [makePersonel({ id: 2, ad: "Sayfa2" })]);
    const created = makePersonel();

    commitPersonelCreateToCaches(created);

    const list = getCacheEntry<PaginatedResult<Personel>>(pageTwoKey);
    expect(list?.items.map((item) => item.id)).toEqual([2]);
  });

  it("farkli sube prefix'ine dokunmaz", () => {
    const otherSubeKey = seedListCache(2, "", "tum", "", "", 1, [makePersonel({ id: 3, ad: "Giresun" })]);
    const created = makePersonel();

    commitPersonelCreateToCaches(created);

    const list = getCacheEntry<PaginatedResult<Personel>>(otherSubeKey);
    expect(list?.items.map((item) => item.id)).toEqual([3]);
  });
});
