/** @vitest-environment jsdom */
import { renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { APP_DATA_SCHEMA_VERSION } from "../../src/data/app-data.types";
import { dataCacheKeys, setCacheEntry } from "../../src/data/data-manager";
import { hesaplaAylikPuantajEksikGunOzeti } from "../../src/services/puantaj-hesap-motoru";
import {
  PUANTAJ_EKSIK_GUN_VERI_KAPSAMI_EKSIK_ACIKLAMA,
  mapAylikPuantajEksikGunOzetiToView,
  usePuantajEksikGunOzeti
} from "../../src/hooks/usePuantajEksikGunOzeti";
import type { Personel } from "../../src/types/personel";
import type { GunlukPuantaj } from "../../src/types/puantaj";

const NISAN_2026_GUN_SAYISI = 30;
let activeSubeId: number | null = 2;

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

beforeEach(() => {
  activeSubeId = 2;
  resetAppDataCache();
});

describe("mapAylikPuantajEksikGunOzetiToView", () => {
  it("eksik cache kapsaminda kesin SGK hesaplanabilir sonuc uretmez", () => {
    const sonuc = hesaplaAylikPuantajEksikGunOzeti({ kayitlar: [] });

    const view = mapAylikPuantajEksikGunOzetiToView(
      sonuc,
      "2026-04",
      0,
      NISAN_2026_GUN_SAYISI
    );

    expect(view.durum).toBe("veri_kapsami_eksik");
    expect(view.durumLabel).toBe("Veri Kapsamı Eksik");
    expect(view.aciklama).toBe(PUANTAJ_EKSIK_GUN_VERI_KAPSAMI_EKSIK_ACIKLAMA);
    expect(view.kesinSgkPrimGunuHesaplanabilirMi).toBe(false);
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
      NISAN_2026_GUN_SAYISI
    );

    expect(view.durum).toBe("hazir");
    expect(view.durumLabel).toBe("Hesaplanabilir");
    expect(view.kesinSgkPrimGunuHesaplanabilirMi).toBe(true);
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
    expect(result.current?.kayitKapsamiNotu).toContain("0/30");
  });
});
