/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import * as authManager from "../../src/auth/auth-manager";
import { APP_DATA_SCHEMA_VERSION } from "../../src/data/app-data.types";
import {
  dataCacheKeys,
  getRealtimeCacheImpact,
  handleRealtimeEnvelope,
  setCacheEntry,
  shouldApplyRealtimeUpdate,
  getCacheEntry
} from "../../src/data/data-manager";
import type { Personel } from "../../src/types/personel";
import type { Surec } from "../../src/types/surec";
import { getReportCacheMeta, markReportCacheStale } from "../../src/reports/report-cache-meta";
import { generateReport } from "../../src/reports/report-engine";

function resetAppDataCache(): void {
  window.appData = {
    schemaVersion: APP_DATA_SCHEMA_VERSION,
    revision: 0,
    updatedAt: null,
    cache: {}
  };
}

describe("shouldApplyRealtimeUpdate", () => {
  const basePersonel = {
    id: 1,
    tc_kimlik_no: "1",
    ad: "A",
    soyad: "B",
    aktif_durum: "AKTIF" as const
  };

  it("applies when no current", () => {
    expect(shouldApplyRealtimeUpdate(undefined, basePersonel)).toBe(true);
  });

  it("rejects older updated_at", () => {
    const cur = {
      ...basePersonel,
      ad: "NEW",
      updated_at: "2030-01-01T00:00:00.000Z"
    } as unknown as Personel;
    const inc = { ...basePersonel, ad: "OLD" };
    expect(
      shouldApplyRealtimeUpdate(
        cur,
        inc as Personel,
        { updated_at: "2020-01-01T00:00:00.000Z" }
      )
    ).toBe(false);
  });

  it("accepts newer updated_at", () => {
    const cur = {
      ...basePersonel,
      ad: "OLD",
      updated_at: "2020-01-01T00:00:00.000Z"
    } as unknown as Personel;
    const inc = { ...basePersonel, ad: "NEW" };
    expect(
      shouldApplyRealtimeUpdate(
        cur,
        inc as Personel,
        { updated_at: "2030-01-01T00:00:00.000Z" }
      )
    ).toBe(true);
  });

  it("rejects incoming when version is lower than current metadata", () => {
    const cur = { ...basePersonel, version: 2 } as unknown as Personel;
    const inc = { ...basePersonel, ad: "Z" };
    expect(shouldApplyRealtimeUpdate(cur, inc as Personel, { version: 1 })).toBe(false);
    expect(shouldApplyRealtimeUpdate(cur, inc as Personel, { version: 3 })).toBe(true);
  });

  it("fallback LWW when no version or time", () => {
    const cur = { ...basePersonel, ad: "OLD" };
    const inc = { ...basePersonel, ad: "NEW" };
    expect(shouldApplyRealtimeUpdate(cur as Personel, inc as Personel, {})).toBe(true);
  });
});

describe("getRealtimeCacheImpact", () => {
  it("scopes personel lists to sube prefix", () => {
    const i = getRealtimeCacheImpact("PERSONEL_GUNCELLENDI", 5, 9);
    expect(i.detailCacheKey).toBe(dataCacheKeys.personelDetail(5, 9));
    expect(i.listKeyPrefixes).toEqual(["personeller:list:s5:"]);
  });

  it("uses all segment when sube null", () => {
    const i = getRealtimeCacheImpact("SUREC_GUNCELLENDI", null, 1);
    expect(i.detailCacheKey).toBe(dataCacheKeys.surecDetail(null, 1));
    expect(i.listKeyPrefixes).toEqual(["surecler:list:sall:"]);
  });
});

describe("detail cache keys per sube", () => {
  it("does not collide for same id different sube", () => {
    expect(dataCacheKeys.personelDetail(1, 42)).not.toBe(dataCacheKeys.personelDetail(2, 42));
  });
});

describe("handleRealtimeEnvelope + matrix + report stale", () => {
  beforeEach(() => {
    vi.spyOn(authManager, "getActiveSubeId").mockReturnValue(10);
    resetAppDataCache();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("marks report cache stale after event", () => {
    expect(getReportCacheMeta().isStale).toBe(false);
    handleRealtimeEnvelope({
      type: "PERSONEL_GUNCELLENDI",
      sube_id: 10,
      payload: {
        id: 1,
        tc_kimlik_no: "x",
        ad: "A",
        soyad: "B",
        aktif_durum: "AKTIF",
        updated_at: "2030-01-01T00:00:00.000Z"
      }
    });
    expect(getReportCacheMeta().isStale).toBe(true);
  });

  it("updates only personel list prefix for PERSONEL_GUNCELLENDI", () => {
    const pKey = dataCacheKeys.personellerList(10, "", "tum", 1);
    const sKey = dataCacheKeys.sureclerList(10, "", "", "", "", "", 1);
    const personelRow: Personel = {
      id: 1,
      tc_kimlik_no: "1",
      ad: "OldP",
      soyad: "X",
      aktif_durum: "AKTIF"
    };
    const surecRow: Surec = {
      id: 1,
      personel_id: 1,
      surec_turu: "IZIN",
      state: "OPEN"
    };
    setCacheEntry(pKey, { items: [personelRow], total: 1, page: 1, limit: 10 });
    setCacheEntry(sKey, { items: [surecRow], total: 1, page: 1, limit: 10 });
    handleRealtimeEnvelope({
      type: "PERSONEL_GUNCELLENDI",
      sube_id: 10,
      payload: {
        id: 1,
        tc_kimlik_no: "1",
        ad: "NewP",
        soyad: "X",
        aktif_durum: "AKTIF",
        updated_at: "2030-01-01T00:00:00.000Z"
      }
    });
    expect(getCacheEntry<{ items: Personel[] }>(pKey)?.items[0]?.ad).toBe("NewP");
    expect(getCacheEntry<{ items: Surec[] }>(sKey)?.items[0]?.surec_turu).toBe("IZIN");
  });

  it("does not write personel detail for other sube key", () => {
    const otherKey = dataCacheKeys.personelDetail(99, 7);
    const activeKey = dataCacheKeys.personelDetail(10, 7);
    setCacheEntry(otherKey, {
      id: 7,
      tc_kimlik_no: "9",
      ad: "Other",
      soyad: "Sube",
      aktif_durum: "AKTIF"
    });
    handleRealtimeEnvelope({
      type: "PERSONEL_GUNCELLENDI",
      sube_id: 10,
      payload: {
        id: 7,
        tc_kimlik_no: "1",
        ad: "Ten",
        soyad: "Z",
        aktif_durum: "AKTIF",
        updated_at: "2030-01-01T00:00:00.000Z"
      }
    });
    expect(getCacheEntry<Personel>(otherKey)?.ad).toBe("Other");
    expect(getCacheEntry<Personel>(activeKey)?.ad).toBe("Ten");
  });

  it("generateReport clears offline stale flag", () => {
    markReportCacheStale();
    expect(getReportCacheMeta().isStale).toBe(true);
    generateReport("personel-ozet", {});
    expect(getReportCacheMeta().isStale).toBe(false);
  });
});
