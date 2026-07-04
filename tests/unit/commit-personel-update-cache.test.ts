/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import * as authManager from "../../src/auth/auth-manager";
import { APP_DATA_SCHEMA_VERSION, emptyPaginated } from "../../src/data/app-data.types";
import {
  commitPersonelUpdateToCaches,
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
    telefon: "05551112233",
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
  items: Personel[],
  pagination?: PaginatedResult<Personel>["pagination"]
): string {
  const key = dataCacheKeys.personellerList(subeId, search, aktiflik, departmanId, personelTipiId, page);
  setCacheEntry<PaginatedResult<Personel>>(key, {
    ...emptyPaginated<Personel>(),
    ...(pagination ? { pagination } : {}),
    items
  });
  return key;
}

describe("commitPersonelUpdateToCaches", () => {
  beforeEach(() => {
    resetAppDataCache();
    vi.spyOn(authManager, "getActiveSubeId").mockReturnValue(1);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("detail cache ve mevcut list satirini gunceller", () => {
    const existing = makePersonel({ ad: "Eski", soyad: "Ad", telefon: "05550000000", departman_id: 2 });
    const listKey = seedListCache(1, "", "tum", "", "", 1, [existing]);
    setCacheEntry(dataCacheKeys.personelDetail(1, 99), existing);

    const updated = makePersonel({
      ad: "Yeni",
      soyad: "Soyad",
      telefon: "05559998877",
      departman_id: 3
    });

    commitPersonelUpdateToCaches(updated);

    expect(getCacheEntry<Personel>(dataCacheKeys.personelDetail(1, 99))).toEqual(updated);

    const list = getCacheEntry<PaginatedResult<Personel>>(listKey);
    expect(list?.items).toHaveLength(1);
    expect(list?.items[0]?.id).toBe(99);
    expect(list?.items[0]).toMatchObject({
      ad: "Yeni",
      soyad: "Soyad",
      telefon: "05559998877",
      departman_id: 3
    });
  });

  it("filtreye uymayan guncellenmis personeli filtreli listeden cikarir", () => {
    const existing = makePersonel({ departman_id: 3 });
    const pagination = {
      page: 1,
      limit: 10,
      total: 1,
      totalPages: 1,
      hasNextPage: false,
      hasPreviousPage: false
    };
    const listKey = seedListCache(1, "", "tum", "3", "", 1, [existing], pagination);
    setCacheEntry(dataCacheKeys.personelDetail(1, 99), existing);

    const updated = makePersonel({ departman_id: 4 });

    commitPersonelUpdateToCaches(updated);

    expect(getCacheEntry<Personel>(dataCacheKeys.personelDetail(1, 99))).toEqual(updated);

    const list = getCacheEntry<PaginatedResult<Personel>>(listKey);
    expect(list?.items).toHaveLength(0);
    expect(list?.pagination).toEqual(pagination);
  });

  it("listedeki absent row icin insert yapmaz", () => {
    const other = makePersonel({ id: 1, ad: "Mevcut" });
    const listKey = seedListCache(1, "", "tum", "", "", 1, [other]);
    setCacheEntry(dataCacheKeys.personelDetail(1, 99), makePersonel());

    const updated = makePersonel({ ad: "Yeni" });

    commitPersonelUpdateToCaches(updated);

    expect(getCacheEntry<Personel>(dataCacheKeys.personelDetail(1, 99))).toEqual(updated);

    const list = getCacheEntry<PaginatedResult<Personel>>(listKey);
    expect(list?.items.map((item) => item.id)).toEqual([1]);
  });

  it("active sube prefix izolasyonunu korur", () => {
    const existing = makePersonel({ ad: "Eski" });
    const activeListKey = seedListCache(1, "", "tum", "", "", 1, [existing]);
    const otherSubeKey = seedListCache(2, "", "tum", "", "", 1, [existing]);
    setCacheEntry(dataCacheKeys.personelDetail(1, 99), existing);

    const updated = makePersonel({ ad: "Guncel" });

    commitPersonelUpdateToCaches(updated);

    expect(getCacheEntry<Personel>(dataCacheKeys.personelDetail(1, 99))?.ad).toBe("Guncel");
    expect(getCacheEntry<Personel>(dataCacheKeys.personelDetail(2, 99))).toBeUndefined();

    const activeList = getCacheEntry<PaginatedResult<Personel>>(activeListKey);
    expect(activeList?.items[0]?.ad).toBe("Guncel");

    const otherSubeList = getCacheEntry<PaginatedResult<Personel>>(otherSubeKey);
    expect(otherSubeList?.items[0]?.ad).toBe("Eski");
  });
});
