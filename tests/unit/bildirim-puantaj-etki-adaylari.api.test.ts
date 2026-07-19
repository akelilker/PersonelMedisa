import { afterEach, describe, expect, it, vi } from "vitest";
import {
  applyBildirimPuantajEtkiAday,
  dismissBildirimPuantajEtkiAday,
  fetchBildirimPuantajEtkiAdayDetail,
  fetchBildirimPuantajEtkiAdayList,
  fetchBildirimPuantajEtkiAdayOzet,
  generateBildirimPuantajEtkiAdaylari
} from "../../src/api/bildirim-puantaj-etki-adaylari.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" }
  });
}

const listItem = {
  id: 1,
  genel_yonetici_bildirim_onayi_id: 10,
  gunluk_bildirim_id: 101,
  personel_id: 1,
  sube_id: 1,
  birim_amiri_user_id: 1,
  ay: "2026-06",
  tarih: "2026-06-03",
  bildirim_turu: "GEC_KALMA",
  etki_turu: "GEC_KALMA_DK",
  etki_miktari: 15,
  etki_birimi: "DK",
  state: "HAZIR",
  conflict_code: null,
  source_priority: "BILDIRIM",
  created_at: "2026-06-10 10:00:00",
  karar_veren_user_id: null,
  karar_zamani: null,
  uygulanan_puantaj_id: null
};

describe("bildirim-puantaj-etki-adaylari.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("fetches list with query params", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: { items: [listItem] },
        meta: { page: 1, limit: 20, total: 1, total_pages: 1 },
        errors: []
      })
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchBildirimPuantajEtkiAdayList({
      ay: "2026-06",
      birim_amiri_user_id: 1,
      page: 1,
      limit: 20
    });

    const [url] = fetchMock.mock.calls[0] as [string];
    expect(url).toContain("/api/puantaj/bildirim-etki-adaylari");
    expect(url).toContain("ay=2026-06");
    expect(url).toContain("birim_amiri_user_id=1");
    expect(result.items[0]?.state).toBe("HAZIR");
  });

  it("fetches ozet and detail", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        createJsonResponse({
          data: {
            context: {
              genel_yonetici_bildirim_onayi_id: 10,
              ay: "2026-06",
              ay_baslangic: "2026-06-01",
              ay_bitis: "2026-06-30",
              sube_id: 1,
              birim_amiri_user_id: 1,
              aylik_bildirim_onayi_id: 2,
              onaylandi_at: null
            },
            genel_yonetici_bildirim_onayi: { id: 10, state: "TAMAMLANDI", onaylandi_at: null },
            kaynak_bildirim_sayisi: 1,
            aday_sayilari: { toplam: 1, hazir: 1, inceleme_gerekli: 0, uygulandi: 0, yok_sayildi: 0 },
            muhur_durumu: "ACIK",
            hazirlanabilir_mi: false,
            blok_nedeni: null
          },
          meta: {},
          errors: []
        })
      )
      .mockResolvedValueOnce(
        createJsonResponse({
          data: {
            ...listItem,
            aylik_bildirim_onayi_id: 2,
            bildirim_alt_tur: null,
            bildirim_dakika: 15,
            bildirim_aciklama: "Test",
            bildirim_created_at: "2026-06-03 08:45:00",
            bildirim_updated_at: "2026-06-03 08:45:00",
            conflict_detail: null,
            resmi_surec_id: null,
            resmi_surec_turu: null,
            resmi_surec_alt_tur: null,
            ucretli_mi_snapshot: null,
            mevcut_puantaj_id: null,
            source_snapshot: null,
            source_hash: "hash",
            projection_version: "v1",
            updated_at: "2026-06-10 10:00:00",
            karar_gerekcesi: null,
            onceki_puantaj_snapshot: null,
            sonraki_puantaj_snapshot: null,
            uygulama_hash: null
          },
          meta: {},
          errors: []
        })
      );
    vi.stubGlobal("fetch", fetchMock);

    const ozet = await fetchBildirimPuantajEtkiAdayOzet(10);
    const detail = await fetchBildirimPuantajEtkiAdayDetail(1);
    expect(ozet.aday_sayilari.hazir).toBe(1);
    expect(detail.bildirim_aciklama).toBe("Test");
  });

  it("posts dismiss with expected_state", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          id: 1,
          state: "YOK_SAYILDI",
          karar_veren_user_id: 5,
          karar_zamani: "2026-07-12 15:30:00",
          karar_gerekcesi: "Mevcut puantaj kaydıyla çakıştı.",
          uygulanan_puantaj_id: null,
          idempotent: false
        },
        meta: {},
        errors: []
      })
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await dismissBildirimPuantajEtkiAday(1, {
      expected_state: "HAZIR",
      gerekce: "  Mevcut puantaj kaydıyla çakıştı.  "
    });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({
      expected_state: "HAZIR",
      gerekce: "Mevcut puantaj kaydıyla çakıştı."
    });
    expect(result.state).toBe("YOK_SAYILDI");
  });

  it("applies HAZIR aday with expected_state only", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          id: 1,
          state: "UYGULANDI",
          karar_veren_user_id: 9,
          karar_zamani: "2026-07-14 00:10:00",
          uygulanan_puantaj_id: 9001,
          onceki_puantaj_snapshot: { schema_version: "S74_APPLY_V1", aday_id: 1, puantaj: null },
          sonraki_puantaj_snapshot: {
            schema_version: "S74_APPLY_V1",
            aday_id: 1,
            puantaj: { id: 9001 }
          },
          uygulama_hash: "abc",
          idempotent: false
        },
        meta: {},
        errors: []
      })
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await applyBildirimPuantajEtkiAday(1, { expected_state: "HAZIR" });
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toContain("/api/puantaj/bildirim-etki-adaylari/1/uygula");
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({ expected_state: "HAZIR" });
    expect(result.state).toBe("UYGULANDI");
    expect(result.uygulanan_puantaj_id).toBe(9001);
    expect(result.idempotent).toBe(false);
  });

  it("posts hazirla with genel_yonetici_bildirim_onayi_id", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: { genel_yonetici_bildirim_onayi_id: 10, created_count: 2 },
        meta: {},
        errors: []
      })
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await generateBildirimPuantajEtkiAdaylari({
      genel_yonetici_bildirim_onayi_id: 10
    });
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toContain("/api/puantaj/bildirim-etki-adaylari/hazirla");
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({ genel_yonetici_bildirim_onayi_id: 10 });
    expect(result).toMatchObject({ genel_yonetici_bildirim_onayi_id: 10, created_count: 2 });
  });
});
