/** @vitest-environment jsdom */
import { act, renderHook, waitFor } from "@testing-library/react";
import type { FormEvent } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { APP_DATA_SCHEMA_VERSION } from "../../src/data/app-data.types";
import { dataCacheKeys, setCacheEntry } from "../../src/data/data-manager";
import {
  buildGunlukPuantajEksikGunSiniflandirmaGirdisi,
  buildHastalikRaporSurecList,
  mapSurecToHastalikRaporSureci
} from "../../src/services/puantaj-hastalik-rapor-cozumu";
import { siniflandirGunlukPuantajEksikGunEtkisi, usePuantaj } from "../../src/hooks/usePuantaj";
import type { HastalikRaporSureci } from "../../src/services/hastalik-rapor-politikasi";
import type { PaginatedResult } from "../../src/types/api";
import type { Surec } from "../../src/types/surec";
import type { GunlukPuantaj } from "../../src/types/puantaj";

const fetchGunlukPuantajMock = vi.hoisted(() => vi.fn());
const fetchPersonelDetailMock = vi.hoisted(() => vi.fn());
let activeSubeId: number | null = 2;

vi.mock("../../src/api/puantaj.api", () => ({
  fetchGunlukPuantaj: fetchGunlukPuantajMock
}));

vi.mock("../../src/api/personeller.api", () => ({
  fetchPersonelDetail: fetchPersonelDetailMock
}));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({
    session: {
      active_sube_id: activeSubeId
    }
  })
}));

function resetAppDataCache(): void {
  window.appData = {
    schemaVersion: APP_DATA_SCHEMA_VERSION,
    revision: 0,
    updatedAt: null,
    cache: {}
  };
}

function makeHastalikSurec(
  overrides: Partial<HastalikRaporSureci> & { id?: number | string }
): HastalikRaporSureci {
  return {
    id: 1,
    personel_id: 1,
    surec_turu: "RAPOR",
    alt_tur: "Raporlu_Hastalik",
    baslangic_tarihi: "2026-04-10",
    bitis_tarihi: "2026-04-14",
    ilk_iki_gun_firma_oder_mi: false,
    state: "AKTIF",
    ...overrides
  };
}

function makeSurec(overrides: Partial<Surec> = {}): Surec {
  return {
    id: 10,
    personel_id: 1,
    surec_turu: "RAPOR",
    alt_tur: "Raporlu_Hastalik",
    baslangic_tarihi: "2026-04-10",
    bitis_tarihi: "2026-04-14",
    ilk_iki_gun_firma_oder_mi: false,
    state: "AKTIF",
    ...overrides
  };
}

function makePuantaj(overrides: Partial<GunlukPuantaj> = {}): GunlukPuantaj {
  return {
    personel_id: 1,
    tarih: "2026-04-10",
    hareket_durumu: "Gelmedi",
    dayanak: "Raporlu_Hastalik",
    compliance_uyarilari: [],
    ...overrides
  };
}

function seedSurecCache(personelId: number, items: Surec[]): void {
  const key = dataCacheKeys.sureclerList(activeSubeId, String(personelId), "", "", "", "", 1);
  setCacheEntry<PaginatedResult<Surec>>(key, {
    items,
    pagination: {
      page: 1,
      limit: 20,
      total: items.length,
      total_pages: 1
    }
  });
}

beforeEach(() => {
  activeSubeId = 2;
  fetchGunlukPuantajMock.mockReset();
  fetchPersonelDetailMock.mockReset();
  fetchPersonelDetailMock.mockResolvedValue({ id: 1, maas_tutari: 30000, dogum_tarihi: "1990-01-01" });
  resetAppDataCache();
});

describe("mapSurecToHastalikRaporSureci", () => {
  it("baslangic_tarihi olmayan sureci eler", () => {
    expect(mapSurecToHastalikRaporSureci(makeSurec({ baslangic_tarihi: undefined }))).toBeNull();
  });

  it("hastalik rapor resolver girdisine map eder", () => {
    expect(mapSurecToHastalikRaporSureci(makeSurec({ id: 42 }))).toMatchObject({
      id: 42,
      personel_id: 1,
      surec_turu: "RAPOR",
      alt_tur: "Raporlu_Hastalik",
      baslangic_tarihi: "2026-04-10"
    });
  });
});

describe("buildGunlukPuantajEksikGunSiniflandirmaGirdisi (S63-3)", () => {
  it("Raporlu_Hastalik gununde hastalik_rapor_cozumu uretir", () => {
    const girdi = buildGunlukPuantajEksikGunSiniflandirmaGirdisi(makePuantaj(), [
      makeHastalikSurec({ id: 10, ilk_iki_gun_firma_oder_mi: false })
    ]);

    expect(girdi.hastalik_rapor_cozumu).toMatchObject({
      eslesme_var_mi: true,
      gun_sirasi: 1,
      ucret_policy: "KESINTI_ADAYI"
    });
  });

  it("Raporlu_Hastalik disinda hastalik_rapor_cozumu eklemez", () => {
    const girdi = buildGunlukPuantajEksikGunSiniflandirmaGirdisi(
      makePuantaj({ dayanak: "Yok_Izinsiz" }),
      [makeHastalikSurec()]
    );

    expect(girdi.hastalik_rapor_cozumu).toBeUndefined();
  });

  it("surec cache yoksa Raporlu_Hastalik icin hastalik_rapor_cozumu eklemez", () => {
    const girdi = buildGunlukPuantajEksikGunSiniflandirmaGirdisi(makePuantaj(), null);

    expect(girdi.hastalik_rapor_cozumu).toBeUndefined();
  });
});

describe("siniflandirGunlukPuantajEksikGunEtkisi (S63-3)", () => {
  it("ilk 2 gun firma odemez → gunluk kesinti adayi", () => {
    const sonuc = siniflandirGunlukPuantajEksikGunEtkisi(makePuantaj(), [
      makeHastalikSurec({ id: 20, ilk_iki_gun_firma_oder_mi: false })
    ]);

    expect(sonuc).toMatchObject({
      ucret_etkisi_turu: "GUNLUK_KESINTI_ADAYI",
      eksik_gun_adayi_mi: true,
      sgk_prim_gununu_dusurur_mu: false
    });
  });

  it("ilk 2 gun firma oder → ucret korunur", () => {
    const sonuc = siniflandirGunlukPuantajEksikGunEtkisi(makePuantaj(), [
      makeHastalikSurec({ id: 21, ilk_iki_gun_firma_oder_mi: true })
    ]);

    expect(sonuc).toMatchObject({
      ucret_etkisi_turu: "UCRET_KORUNUR",
      eksik_gun_adayi_mi: false
    });
  });

  it("surec cache yoksa eski Raporlu_Hastalik davranisini korur", () => {
    const sonuc = siniflandirGunlukPuantajEksikGunEtkisi(makePuantaj({ tarih: "2026-04-12" }), null);

    expect(sonuc).toMatchObject({
      ucret_etkisi_turu: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true
    });
  });
});

describe("buildHastalikRaporSurecList", () => {
  it("gecersiz surec kayitlarini filtreler", () => {
    expect(
      buildHastalikRaporSurecList([
        makeSurec({ id: 1 }),
        makeSurec({ id: 2, baslangic_tarihi: undefined })
      ])
    ).toHaveLength(1);
  });
});

describe("usePuantaj gunlukEksikGunSiniflandirmasi (S63-3)", () => {
  it("onbellekte surec varken Raporlu_Hastalik gunu icin policy motor girdisine ulasir", async () => {
    const puantaj = makePuantaj();
    fetchGunlukPuantajMock.mockResolvedValue(puantaj);
    setCacheEntry(dataCacheKeys.puantajDetail(activeSubeId, 1, "2026-04-10"), puantaj);
    seedSurecCache(1, [makeSurec({ id: 30, ilk_iki_gun_firma_oder_mi: false })]);

    const { result } = renderHook(() => usePuantaj());

    act(() => {
      result.current.patchFormState({
        queryPersonelId: "1",
        queryTarih: "2026-04-10"
      });
    });

    await act(async () => {
      await result.current.submitQuery({
        preventDefault: () => undefined
      } as FormEvent<HTMLFormElement>);
    });

    await waitFor(() => {
      expect(result.current.puantaj?.dayanak).toBe("Raporlu_Hastalik");
    });

    await waitFor(() => {
      expect(result.current.gunlukEksikGunSiniflandirmasi).toMatchObject({
        ucret_etkisi_turu: "GUNLUK_KESINTI_ADAYI"
      });
    });
  });

  it("onbellekte surec yoksa eski Raporlu_Hastalik siniflandirmasini korur", async () => {
    const puantaj = makePuantaj();
    fetchGunlukPuantajMock.mockResolvedValue(puantaj);
    setCacheEntry(dataCacheKeys.puantajDetail(activeSubeId, 1, "2026-04-10"), puantaj);

    const { result } = renderHook(() => usePuantaj());

    act(() => {
      result.current.patchFormState({
        queryPersonelId: "1",
        queryTarih: "2026-04-10"
      });
    });

    await act(async () => {
      await result.current.submitQuery({
        preventDefault: () => undefined
      } as FormEvent<HTMLFormElement>);
    });

    await waitFor(() => {
      expect(result.current.gunlukEksikGunSiniflandirmasi).toMatchObject({
        ucret_etkisi_turu: "POLITIKA_INCELEMESI",
        manuel_inceleme_gerekli_mi: true
      });
    });
  });
});
