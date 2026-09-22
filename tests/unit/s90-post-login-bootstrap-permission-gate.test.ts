// @vitest-environment jsdom

/**
 * Login sonrasi bootstrap yonlendirme regresyon kapisi.
 *
 * Kok neden: basarili girişten sonra ortak `loadDataFromServer()` tum kullanicilar
 * icin yonetim verisini cekiyordu. PERSONEL/self-service kullanicisinin yetkisi
 * olmayan personel listesi, bildirim ozeti ve referans uclari 403 donuyor, global
 * 403 dinleyicisi bunu "oturum yetkisiz" sanip kullaniciyi /yetkisiz'e atiyordu.
 *
 * Bu test owner'i dogrular:
 *   `loadDataFromServer` bootstrap cagrilarini oturumun efektif izinlerine gore
 *   kosullar — kullanicinin erisemeyecegi veri hic istenmez. Beklenen bootstrap
 *   403'unun global /yetkisiz yonlendirmesini tetiklemedigi ise
 *   `tests/unit/api-client.test.ts` (shouldEmitGlobalAuthForbidden + apiRequest)
 *   tarafinda dogrulanir.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const {
  fetchPersonellerList,
  fetchGunlukTamamlamalariHeader,
  fetchDepartmanOptions,
  fetchBolumOptions,
  fetchBirimOptions,
  fetchGorevOptions,
  fetchPozisyonOptions,
  fetchPersonelTipiOptions,
  fetchSgkIsverenOptions,
  fetchCalismaLokasyonuOptions,
  fetchBagliAmirOptions,
  fetchUcretTipiOptions,
  fetchPrimKuraliOptions,
  fetchSurecTuruOptions,
  fetchBildirimTuruOptions,
  getTokenMock,
  getActiveSubeIdMock,
  getSessionMock
} = vi.hoisted(() => {
  const okList = vi.fn(async () => ({ items: [], meta: { page: 1, limit: 10, total: 0 } }));
  return {
    fetchPersonellerList: okList,
    fetchGunlukTamamlamalariHeader: vi.fn(async () => ({
      items: [],
      meta: { page: 1, limit: 8, total: 0 }
    })),
    fetchDepartmanOptions: vi.fn(async () => []),
    fetchBolumOptions: vi.fn(async () => []),
    fetchBirimOptions: vi.fn(async () => []),
    fetchGorevOptions: vi.fn(async () => []),
    fetchPozisyonOptions: vi.fn(async () => []),
    fetchPersonelTipiOptions: vi.fn(async () => []),
    fetchSgkIsverenOptions: vi.fn(async () => []),
    fetchCalismaLokasyonuOptions: vi.fn(async () => []),
    fetchBagliAmirOptions: vi.fn(async () => []),
    fetchUcretTipiOptions: vi.fn(async () => []),
    fetchPrimKuraliOptions: vi.fn(async () => []),
    fetchSurecTuruOptions: vi.fn(async () => []),
    fetchBildirimTuruOptions: vi.fn(async () => []),
    getTokenMock: vi.fn<() => string | null>(() => null),
    getActiveSubeIdMock: vi.fn<() => number | null>(() => null),
    getSessionMock: vi.fn<() => unknown>(() => null)
  };
});

vi.mock("../../src/api/personeller.api", () => ({
  fetchPersonellerList,
  createPersonel: vi.fn(),
  updatePersonel: vi.fn()
}));

vi.mock("../../src/api/bildirimler.api", () => ({
  fetchGunlukTamamlamalariHeader,
  fetchBildirimlerList: vi.fn(),
  createBildirim: vi.fn(),
  updateBildirim: vi.fn(),
  cancelBildirim: vi.fn()
}));

vi.mock("../../src/api/referans.api", () => ({
  fetchDepartmanOptions,
  fetchBolumOptions,
  fetchBirimOptions,
  fetchGorevOptions,
  fetchPozisyonOptions,
  fetchPersonelTipiOptions,
  fetchSgkIsverenOptions,
  fetchCalismaLokasyonuOptions,
  fetchBagliAmirOptions,
  fetchUcretTipiOptions,
  fetchPrimKuraliOptions,
  fetchSurecTuruOptions,
  fetchBildirimTuruOptions
}));

vi.mock("../../src/api/finans.api", () => ({
  createFinansKalem: vi.fn(),
  updateFinansKalem: vi.fn(),
  cancelFinansKalem: vi.fn()
}));

vi.mock("../../src/api/puantaj.api", () => ({
  upsertGunlukPuantaj: vi.fn()
}));

vi.mock("../../src/api/surecler.api", () => ({
  createSurec: vi.fn(),
  updateSurec: vi.fn(),
  cancelSurec: vi.fn()
}));

vi.mock("../../src/auth/auth-manager", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../src/auth/auth-manager")>();
  return {
    ...actual,
    getToken: getTokenMock,
    getActiveSubeId: getActiveSubeIdMock,
    getSession: getSessionMock
  };
});

import { loadDataFromServer, resetProtectedDataLoadGate } from "../../src/data/data-manager";
import type { AuthSession, UserRole } from "../../src/types/auth";

function buildSession(
  rol: UserRole,
  options: { personelId?: number | null; personelTipiAd?: string | null; subeIds?: number[] } = {}
): AuthSession {
  return {
    token: "tok-bootstrap",
    ui_profile: "yonetim",
    active_sube_id: null,
    user: {
      id: 42,
      ad_soyad: "Test Kullanici",
      rol,
      sube_ids: options.subeIds ?? [],
      personel_id: options.personelId ?? null,
      personel_tipi_ad: options.personelTipiAd ?? null
    }
  } as AuthSession;
}

const managementBootstrapCalls = [
  fetchPersonellerList,
  fetchGunlukTamamlamalariHeader,
  fetchDepartmanOptions,
  fetchBolumOptions,
  fetchBirimOptions,
  fetchGorevOptions,
  fetchPozisyonOptions,
  fetchPersonelTipiOptions,
  fetchSgkIsverenOptions,
  fetchCalismaLokasyonuOptions,
  fetchBagliAmirOptions,
  fetchUcretTipiOptions,
  fetchPrimKuraliOptions,
  fetchSurecTuruOptions,
  fetchBildirimTuruOptions
] as const;

function clearAllBootstrapMocks() {
  for (const fn of managementBootstrapCalls) {
    fn.mockClear();
  }
}

describe("S90 login sonrasi bootstrap izin kapisi", () => {
  beforeEach(() => {
    resetProtectedDataLoadGate();
    getTokenMock.mockReset();
    getActiveSubeIdMock.mockReset();
    getSessionMock.mockReset();
    getTokenMock.mockReturnValue("tok-bootstrap");
    getActiveSubeIdMock.mockReturnValue(null);
    getSessionMock.mockReturnValue(buildSession("GENEL_YONETICI"));
    clearAllBootstrapMocks();
  });

  afterEach(() => {
    resetProtectedDataLoadGate();
    vi.restoreAllMocks();
  });

  it("PERSONEL/self-service: hicbir yonetim bootstrap cagrisi yapilmaz", async () => {
    getSessionMock.mockReturnValue(
      buildSession("PERSONEL", { personelId: 158, personelTipiAd: "Mavi Yaka", subeIds: [1] })
    );

    await loadDataFromServer({ force: true });

    for (const fn of managementBootstrapCalls) {
      expect(fn, `${fn.getMockName()} bootstrap icin cagrilmamali`).not.toHaveBeenCalled();
    }
  });

  it("bagli mavi yaka BOLUM_YONETICISI: yonetim bootstrap'i rol dusurulmeden calisir", async () => {
    getSessionMock.mockReturnValue(
      buildSession("BOLUM_YONETICISI", { personelId: 158, personelTipiAd: "Mavi Yaka", subeIds: [1] })
    );

    await loadDataFromServer({ force: true });

    expect(fetchPersonellerList).toHaveBeenCalled();
    expect(fetchGunlukTamamlamalariHeader).toHaveBeenCalled();
    expect(fetchDepartmanOptions).toHaveBeenCalled();
    expect(fetchSurecTuruOptions).toHaveBeenCalled();
    expect(fetchBildirimTuruOptions).toHaveBeenCalled();
  });

  it("bildirimler.view olmayan yonetim rolu bildirim bootstrap'ini istemez", async () => {
    // AUTH_SMOKE_READONLY: yalniz ops.auth_smoke.read — ne personel ne bildirim okuma.
    getSessionMock.mockReturnValue(buildSession("AUTH_SMOKE_READONLY"));

    await loadDataFromServer({ force: true });

    expect(fetchGunlukTamamlamalariHeader).not.toHaveBeenCalled();
    expect(fetchBildirimTuruOptions).not.toHaveBeenCalled();
    expect(fetchPersonellerList).not.toHaveBeenCalled();
    expect(fetchDepartmanOptions).not.toHaveBeenCalled();
  });
});
