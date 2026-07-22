/** @vitest-environment jsdom */
import { renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  mapCanonicalSgkSonucuToView,
  usePuantajEksikGunOzeti
} from "../../src/hooks/usePuantajEksikGunOzeti";
import type { SgkPrimGunuSonucu } from "../../src/api/maas-hesaplama.api";
import type { Personel } from "../../src/types/personel";

const fetchSgkMock = vi.hoisted(() => vi.fn());
let activeSubeId: number | null = 2;

vi.mock("../../src/api/maas-hesaplama.api", () => ({
  fetchSgkPrimGunuSonuclari: fetchSgkMock
}));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({ session: { active_sube_id: activeSubeId } })
}));

function personel(overrides: Partial<Personel> = {}): Personel {
  return {
    id: 1,
    tc_kimlik_no: "12345678901",
    ad: "Ayşe",
    soyad: "Yılmaz",
    aktif_durum: "AKTIF",
    sgk_donem: "2026-04",
    ...overrides
  };
}

function canonical(overrides: Partial<SgkPrimGunuSonucu> = {}): SgkPrimGunuSonucu {
  return {
    id: 91,
    snapshot_id: 10,
    snapshot_revision_no: 2,
    personel_snapshot_id: 20,
    personel_id: 1,
    yil: 2026,
    ay: 4,
    donem: "2026-04",
    hesaplanan_prim_gunu: 27,
    eksik_gun_sayisi: 3,
    eksik_gun_kodu: "01",
    eksik_gun_aciklamasi: "Test katalog açıklaması",
    kaynak_surec_idleri: [7],
    kaynak_puantaj_idleri: [11, 12],
    kaynak_belge_idleri: [13],
    katalog_surumu: "TEST-V1",
    kaynak_manifest_hash: "a".repeat(64),
    sgk_hesap_hash: "b".repeat(64),
    gunluk_karar_dokumu_hash: "c".repeat(64),
    manuel_inceleme_gerekli_mi: false,
    blocker_kodlari: [],
    blocker_detaylari: [],
    ucret_modeli: "MAKTU_AYLIK",
    ilk_iki_gun_politika_ozeti: { deger: true },
    sirket_politika_surum_id: 4,
    sirket_politika_hash: "d".repeat(64),
    sgk_odenek_durumu: "KESINLESMEMIS",
    is_goremezlik_finans_ozeti: [],
    gunluk_alt_sinir: "100",
    gunluk_ust_sinir: "750",
    donem_alt_sinir: "2700",
    donem_ust_sinir: "20250",
    sinir_mevzuat_surumu: "e".repeat(64),
    source_hash: "f".repeat(64),
    ...overrides
  };
}

beforeEach(() => {
  activeSubeId = 2;
  fetchSgkMock.mockReset();
});

describe("mapCanonicalSgkSonucuToView", () => {
  it("immutable backend sonucunu değiştirmeden gösterim kontratına taşır", () => {
    const view = mapCanonicalSgkSonucuToView("2026-04", canonical());
    expect(view.hesaplananPrimGunu).toBe(27);
    expect(view.eksikGunSayisi).toBe(3);
    expect(view.eksikGunKodu).toBe("01");
    expect(view.snapshotId).toBe(10);
    expect(view.snapshotRevisionNo).toBe(2);
    expect(view.sgkHesapHash).toBe("b".repeat(64));
    expect(view.durum).toBe("hazir");
  });

  it("snapshot yoksa frontend tahmini üretmez", () => {
    const view = mapCanonicalSgkSonucuToView("2026-04", null);
    expect(view.hesaplananPrimGunu).toBeNull();
    expect(view.eksikGunSayisi).toBeNull();
    expect(view.durum).toBe("bulunamadi");
    expect(view.errorMessage).toContain("frontend tahmin üretmedi");
  });

  it("backend blocker listesini manuel inceleme olarak korur", () => {
    const view = mapCanonicalSgkSonucuToView(
      "2026-04",
      canonical({ blocker_kodlari: ["HASTALIK_ILK_IKI_GUN_POLITIKASI_EKSIK"] })
    );
    expect(view.durum).toBe("manuel_inceleme");
    expect(view.blockerKodlari).toEqual(["HASTALIK_ILK_IKI_GUN_POLITIKASI_EKSIK"]);
    expect(view.blockerEtiketleri).toEqual(["Hastalık ilk iki gün politikası eksik"]);
    expect(view.ucretModeliLabel).toBe("Maktu aylık");
    expect(view.sgkOdenekDurumuLabel).toBe("Kesinleşmemiş");
  });
});

describe("usePuantajEksikGunOzeti", () => {
  it("yalnız authoritative SGK API sonucunu okur", async () => {
    fetchSgkMock.mockResolvedValue([canonical()]);
    const { result } = renderHook(() => usePuantajEksikGunOzeti(personel()));

    await waitFor(() => expect(result.current?.isLoading).toBe(false));
    expect(fetchSgkMock).toHaveBeenCalledWith({
      sube_id: 2,
      yil: 2026,
      ay: 4,
      personel_id: 1
    });
    expect(result.current?.hesaplananPrimGunu).toBe(27);
  });

  it("dönem veya şube yoksa çağrı ve hesap yapmaz", () => {
    activeSubeId = null;
    const { result } = renderHook(() => usePuantajEksikGunOzeti(personel({ sgk_donem: undefined })));
    expect(result.current).toBeNull();
    expect(fetchSgkMock).not.toHaveBeenCalled();
  });
});
