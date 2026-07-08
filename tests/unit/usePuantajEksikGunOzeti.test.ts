/** @vitest-environment jsdom */
import { act, renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { APP_DATA_SCHEMA_VERSION } from "../../src/data/app-data.types";
import { dataCacheKeys, getCacheEntry, setCacheEntry } from "../../src/data/data-manager";
import { hesaplaAylikPuantajEksikGunOzeti } from "../../src/services/puantaj-hesap-motoru";
import {
  PUANTAJ_EKSIK_GUN_VERI_KAPSAMI_EKSIK_ACIKLAMA,
  mapAylikPuantajEksikGunOzetiToView,
  usePuantajEksikGunOzeti
} from "../../src/hooks/usePuantajEksikGunOzeti";
import type { Personel } from "../../src/types/personel";
import type { GunlukPuantaj } from "../../src/types/puantaj";
import type { PaginatedResult } from "../../src/types/api";
import type { Surec } from "../../src/types/surec";

const NISAN_2026_GUN_SAYISI = 30;
let activeSubeId: number | null = 2;
const fetchGunlukPuantajMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/api/puantaj.api", () => ({
  fetchGunlukPuantaj: fetchGunlukPuantajMock
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

function makePersonel(overrides: Partial<Personel> = {}): Personel {
  return {
    id: 1,
    tc_kimlik_no: "12345678901",
    ad: "Ayse",
    soyad: "Yilmaz",
    aktif_durum: "AKTIF",
    sgk_donem: "2026-04",
    ...overrides
  };
}

function makePuantaj(overrides: Partial<GunlukPuantaj> & Pick<GunlukPuantaj, "tarih">): GunlukPuantaj {
  return {
    personel_id: 1,
    compliance_uyarilari: [],
    ...overrides
  };
}

function makeHastalikSurec(overrides: Partial<Surec> = {}): Surec {
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
  resetAppDataCache();
});

describe("mapAylikPuantajEksikGunOzetiToView", () => {
  it("eksik cache kapsaminda kesin SGK hesaplanabilir sonuc uretmez", () => {
    const sonuc = hesaplaAylikPuantajEksikGunOzeti({ kayitlar: [] });

    const view = mapAylikPuantajEksikGunOzetiToView(
      sonuc,
      "2026-04",
      0,
      NISAN_2026_GUN_SAYISI,
      Array.from({ length: NISAN_2026_GUN_SAYISI }, (_, index) =>
        `2026-04-${String(index + 1).padStart(2, "0")}`
      )
    );

    expect(view.durum).toBe("veri_kapsami_eksik");
    expect(view.durumLabel).toBe("Veri Kapsamı Eksik");
    expect(view.aciklama).toBe(PUANTAJ_EKSIK_GUN_VERI_KAPSAMI_EKSIK_ACIKLAMA);
    expect(view.kesinSgkPrimGunuHesaplanabilirMi).toBe(false);
    expect(view.veriKapsamiTamMi).toBe(false);
    expect(view.eksikTarihSayisi).toBe(NISAN_2026_GUN_SAYISI);
    expect(view.eksikTarihListesi[0]).toBe("2026-04-01");
    expect(view.eksikTarihListesi.at(-1)).toBe("2026-04-30");
    expect(view.kayitKapsamiNotu).toContain("0/30");
  });

  it("tam kapsam ve manuel inceleme yoksa hesaplanabilir durum dondurur", () => {
    const kayitlar = Array.from({ length: NISAN_2026_GUN_SAYISI }, (_, index) =>
      makePuantaj({
        tarih: `2026-04-${String(index + 1).padStart(2, "0")}`,
        hareket_durumu: "Geldi"
      })
    );
    const sonuc = hesaplaAylikPuantajEksikGunOzeti({ kayitlar });

    const view = mapAylikPuantajEksikGunOzetiToView(
      sonuc,
      "2026-04",
      NISAN_2026_GUN_SAYISI,
      NISAN_2026_GUN_SAYISI,
      []
    );

    expect(view.durum).toBe("hazir");
    expect(view.durumLabel).toBe("Hesaplanabilir");
    expect(view.kesinSgkPrimGunuHesaplanabilirMi).toBe(true);
    expect(view.veriKapsamiTamMi).toBe(true);
    expect(view.eksikTarihSayisi).toBe(0);
    expect(view.eksikTarihListesi).toEqual([]);
    expect(view.kayitKapsamiNotu).toBeNull();
  });
});

describe("usePuantajEksikGunOzeti", () => {
  it("personelin SGK donemi yoksa ozet uretmez", () => {
    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel({ sgk_donem: undefined })));

    expect(result.current).toBeNull();
  });

  it("aylik cache kayitlarini eksik gun ozeti olarak siniflandirir", () => {
    setCacheEntry(
      dataCacheKeys.puantajDetail(2, 1, "2026-04-03"),
      makePuantaj({
        tarih: "2026-04-03",
        hareket_durumu: "Gelmedi",
        dayanak: "Yok_Izinsiz",
        durumu_bildirdi_mi: false
      })
    );
    setCacheEntry(
      dataCacheKeys.puantajDetail(2, 1, "2026-04-04"),
      makePuantaj({
        tarih: "2026-04-04",
        hareket_durumu: "Gec_Geldi"
      })
    );
    setCacheEntry(
      dataCacheKeys.puantajDetail(2, 1, "2026-04-05"),
      makePuantaj({
        tarih: "2026-04-05",
        hareket_durumu: "Gelmedi",
        dayanak: "Raporlu_Hastalik",
        durumu_bildirdi_mi: true
      })
    );

    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel()));

    expect(result.current?.toplamKayitSayisi).toBe(3);
    expect(result.current?.sgkPrimGununuDusurenEksikGunSayisi).toBe(1);
    expect(result.current?.manuelIncelemeKayitSayisi).toBe(1);
    expect(result.current?.dakikaBazliUcretEtkisiAdayiSayisi).toBe(1);
    expect(result.current?.haberliYoklukSinyaliSayisi).toBe(1);
    expect(result.current?.habersizYoklukSinyaliSayisi).toBe(1);
    expect(result.current?.durum).toBe("veri_kapsami_eksik");
    expect(result.current?.kesinSgkPrimGunuHesaplanabilirMi).toBe(false);
    expect(result.current?.veriKapsamiTamMi).toBe(false);
    expect(result.current?.eksikTarihSayisi).toBe(27);
    expect(result.current?.eksikTarihListesi).not.toContain("2026-04-03");
    expect(result.current?.eksikTarihListesi).not.toContain("2026-04-04");
    expect(result.current?.eksikTarihListesi).not.toContain("2026-04-05");
    expect(result.current?.eksikTarihListesi.slice(0, 2)).toEqual(["2026-04-01", "2026-04-02"]);
  });

  it("surec cache varken Raporlu_Hastalik ilk 2 gun firma odemez → gunluk kesinti adayi sayar (S63-6)", () => {
    seedSurecCache(1, [makeHastalikSurec({ id: 30, ilk_iki_gun_firma_oder_mi: false })]);
    setCacheEntry(
      dataCacheKeys.puantajDetail(2, 1, "2026-04-10"),
      makePuantaj({
        tarih: "2026-04-10",
        hareket_durumu: "Gelmedi",
        dayanak: "Raporlu_Hastalik",
        durumu_bildirdi_mi: true
      })
    );

    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel()));

    expect(result.current?.gunlukKesintiAdayiSayisi).toBe(1);
    expect(result.current?.ucretKorunanKayitSayisi).toBe(0);
    expect(result.current?.manuelIncelemeKayitSayisi).toBe(0);
    expect(result.current?.eksikGunAdayiKayitSayisi).toBe(1);
  });

  it("surec cache varken Raporlu_Hastalik ilk 2 gun firma oder → ucret korunan kayit sayar (S63-6)", () => {
    seedSurecCache(1, [makeHastalikSurec({ id: 31, ilk_iki_gun_firma_oder_mi: true })]);
    setCacheEntry(
      dataCacheKeys.puantajDetail(2, 1, "2026-04-10"),
      makePuantaj({
        tarih: "2026-04-10",
        hareket_durumu: "Gelmedi",
        dayanak: "Raporlu_Hastalik",
        durumu_bildirdi_mi: true
      })
    );

    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel()));

    expect(result.current?.ucretKorunanKayitSayisi).toBe(1);
    expect(result.current?.gunlukKesintiAdayiSayisi).toBe(0);
    expect(result.current?.manuelIncelemeKayitSayisi).toBe(0);
    expect(result.current?.eksikGunAdayiKayitSayisi).toBe(0);
  });

  it("surec cache yokken Raporlu_Hastalik eski POLITIKA_INCELEMESI davranisini korur (S63-6)", () => {
    setCacheEntry(
      dataCacheKeys.puantajDetail(2, 1, "2026-04-10"),
      makePuantaj({
        tarih: "2026-04-10",
        hareket_durumu: "Gelmedi",
        dayanak: "Raporlu_Hastalik",
        durumu_bildirdi_mi: true
      })
    );

    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel()));

    expect(result.current?.manuelIncelemeKayitSayisi).toBe(1);
    expect(result.current?.gunlukKesintiAdayiSayisi).toBe(0);
    expect(result.current?.ucretKorunanKayitSayisi).toBe(0);
    expect(result.current?.eksikGunAdayiKayitSayisi).toBe(1);
  });

  it("farkli sube ve farkli personel cache kayitlarini karistirmaz", () => {
    setCacheEntry(
      dataCacheKeys.puantajDetail(1, 1, "2026-04-03"),
      makePuantaj({
        tarih: "2026-04-03",
        hareket_durumu: "Gelmedi",
        dayanak: "Yok_Izinsiz"
      })
    );
    setCacheEntry(
      dataCacheKeys.puantajDetail(2, 9, "2026-04-04"),
      makePuantaj({
        personel_id: 9,
        tarih: "2026-04-04",
        hareket_durumu: "Gelmedi",
        dayanak: "Yok_Izinsiz"
      })
    );

    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel()));

    expect(result.current?.toplamKayitSayisi).toBe(0);
    expect(result.current?.sgkPrimGununuDusurenEksikGunSayisi).toBe(0);
    expect(result.current?.veriKapsamiTamMi).toBe(false);
    expect(result.current?.eksikTarihSayisi).toBe(NISAN_2026_GUN_SAYISI);
    expect(result.current?.eksikTarihListesi).toContain("2026-04-03");
    expect(result.current?.eksikTarihListesi).toContain("2026-04-04");
    expect(result.current?.kayitKapsamiNotu).toContain("0/30");
  });

  it("cache'te null olan tarihi eksik saymaz ama hesap kayitlarina katmaz", () => {
    setCacheEntry(dataCacheKeys.puantajDetail(2, 1, "2026-04-01"), null);
    setCacheEntry(
      dataCacheKeys.puantajDetail(2, 1, "2026-04-02"),
      makePuantaj({
        tarih: "2026-04-02",
        hareket_durumu: "Gelmedi",
        dayanak: "Yok_Izinsiz"
      })
    );

    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel()));

    expect(result.current?.toplamKayitSayisi).toBe(1);
    expect(result.current?.eksikTarihSayisi).toBe(28);
    expect(result.current?.eksikTarihListesi).not.toContain("2026-04-01");
    expect(result.current?.eksikTarihListesi).not.toContain("2026-04-02");
    expect(result.current?.sgkPrimGununuDusurenEksikGunSayisi).toBe(1);
  });

  it("tam ay cache doluysa veri kapsamini tam gosterir", () => {
    for (let day = 1; day <= NISAN_2026_GUN_SAYISI; day++) {
      const tarih = `2026-04-${String(day).padStart(2, "0")}`;
      setCacheEntry(
        dataCacheKeys.puantajDetail(2, 1, tarih),
        makePuantaj({
          tarih,
          hareket_durumu: "Geldi"
        })
      );
    }

    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel()));

    expect(result.current?.toplamKayitSayisi).toBe(NISAN_2026_GUN_SAYISI);
    expect(result.current?.veriKapsamiTamMi).toBe(true);
    expect(result.current?.eksikTarihSayisi).toBe(0);
    expect(result.current?.eksikTarihListesi).toEqual([]);
    expect(result.current?.kesinSgkPrimGunuHesaplanabilirMi).toBe(true);
  });

  it("hydrateEksikPuantajTarihleri sadece ilk 7 eksik tarihi fetch eder", async () => {
    fetchGunlukPuantajMock.mockImplementation(async (personelId: number, tarih: string) =>
      makePuantaj({
        personel_id: personelId,
        tarih,
        hareket_durumu: "Geldi"
      })
    );

    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel()));

    await act(async () => {
      await result.current?.hydrateEksikPuantajTarihleri();
    });

    expect(fetchGunlukPuantajMock).toHaveBeenCalledTimes(7);
    expect(fetchGunlukPuantajMock.mock.calls.map((call) => call[1])).toEqual([
      "2026-04-01",
      "2026-04-02",
      "2026-04-03",
      "2026-04-04",
      "2026-04-05",
      "2026-04-06",
      "2026-04-07"
    ]);
    expect(result.current?.hydrateDurumu).toBe("success");
    expect(result.current?.hydrateEdilenTarihSayisi).toBe(7);
    expect(result.current?.hydrateHataMesaji).toBeNull();
    expect(getCacheEntry(dataCacheKeys.puantajDetail(2, 1, "2026-04-07"))).not.toBeUndefined();
    expect(getCacheEntry(dataCacheKeys.puantajDetail(2, 1, "2026-04-08"))).toBeUndefined();
  });

  it("hydrate basarili olunca null sonuclari cache'e yazar ve kapsamdan duser", async () => {
    fetchGunlukPuantajMock.mockResolvedValue(null);

    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel()));

    await act(async () => {
      await result.current?.hydrateEksikPuantajTarihleri();
    });

    expect(result.current?.hydrateDurumu).toBe("success");
    expect(result.current?.hydrateEdilenTarihSayisi).toBe(7);
    expect(getCacheEntry(dataCacheKeys.puantajDetail(2, 1, "2026-04-01"))).toBeNull();
    expect(result.current?.toplamKayitSayisi).toBe(0);
    expect(result.current?.eksikTarihListesi).not.toContain("2026-04-01");
  });

  it("hydrate hata verirse hata durumunu ve mesajini dondurur", async () => {
    fetchGunlukPuantajMock.mockRejectedValue(new Error("Puantaj getirilemedi"));

    const { result } = renderHook(() => usePuantajEksikGunOzeti(makePersonel()));

    await act(async () => {
      await result.current?.hydrateEksikPuantajTarihleri();
    });

    expect(result.current?.hydrateDurumu).toBe("error");
    expect(result.current?.hydrateEdilenTarihSayisi).toBe(0);
    expect(result.current?.hydrateHataMesaji).toBe("Puantaj getirilemedi");
  });
});
