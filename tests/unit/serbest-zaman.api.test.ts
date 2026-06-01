import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import {
  fetchSerbestZamanBakiye,
  fetchSerbestZamanEvents,
  normalizeSerbestZamanEvent,
  postSerbestZamanDuzeltme,
  postSerbestZamanIptal,
  postSerbestZamanKullanim,
  postSerbestZamanOlusum
} from "../../src/api/serbest-zaman.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("serbest-zaman.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("normalizeSerbestZamanEvent OLUSUM alanlarini normalize eder", () => {
    const result = normalizeSerbestZamanEvent({
      id: 1,
      personel_id: 2,
      kaynak_snapshot_id: 1001,
      kaynak_odeme_tercihi_id: 5,
      event_tipi: "SERBEST_ZAMAN_OLUSUM",
      dakika: 90,
      event_tarihi: "2026-05-31",
      son_kullanim_tarihi: "2026-11-30",
      aciklama: "Test"
    });

    expect(result.event_tipi).toBe("SERBEST_ZAMAN_OLUSUM");
    expect(result.dakika).toBe(90);
    expect(result.kaynak_odeme_tercihi_id).toBe(5);
  });

  it("GET events personel_id ile listeler", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          items: [
            {
              id: 1,
              personel_id: 1,
              kaynak_snapshot_id: 1001,
              kaynak_odeme_tercihi_id: 1,
              event_tipi: "SERBEST_ZAMAN_OLUSUM",
              dakika: 90,
              event_tarihi: "2026-05-31",
              son_kullanim_tarihi: "2026-11-30"
            }
          ]
        },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchSerbestZamanEvents(1);

    const [url] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/serbest-zaman/events?personel_id=1");
    expect(result).toHaveLength(1);
    expect(result[0]?.dakika).toBe(90);
  });

  it("GET bakiye read model dondurur", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          personel_id: 1,
          toplam_hak_dakika: 90,
          kullanilan_dakika: 0,
          kalan_dakika: 90,
          suresi_dolan_dakika: 0,
          event_sayisi: 1
        },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchSerbestZamanBakiye(1);

    const [url] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/serbest-zaman/bakiye?personel_id=1");
    expect(result.kullanilan_dakika).toBe(0);
    expect(result.kalan_dakika).toBe(90);
  });

  it("POST olusum snapshot_id ile event olusturur", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          id: 1,
          personel_id: 1,
          kaynak_snapshot_id: 1001,
          kaynak_odeme_tercihi_id: 1,
          event_tipi: "SERBEST_ZAMAN_OLUSUM",
          dakika: 90,
          event_tarihi: "2026-05-31",
          son_kullanim_tarihi: "2026-11-30"
        },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await postSerbestZamanOlusum({ snapshot_id: 1001 });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toMatchObject({ snapshot_id: 1001 });
    expect(result.event_tipi).toBe("SERBEST_ZAMAN_OLUSUM");
  });

  it("ALREADY_EXISTS hatasini 409 olarak firlatir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: null,
        meta: {},
        errors: [{ code: "ALREADY_EXISTS", message: "Duplicate olusum" }]
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await expect(postSerbestZamanOlusum({ odeme_tercihi_id: 1 })).rejects.toMatchObject({
      status: 409
    } satisfies Partial<ApiRequestError>);
  });

  it("gecersiz personel_id parametresini reddeder", async () => {
    await expect(fetchSerbestZamanEvents(0)).rejects.toBeInstanceOf(ApiRequestError);
  });

  it("POST body bosken INVALID_BODY firlatir", async () => {
    await expect(postSerbestZamanOlusum({})).rejects.toBeInstanceOf(ApiRequestError);
  });

  it("normalizeSerbestZamanEvent KULLANIM alanlarini normalize eder", () => {
    const result = normalizeSerbestZamanEvent({
      id: 2,
      personel_id: 1,
      event_tipi: "SERBEST_ZAMAN_KULLANIM",
      dakika: 30,
      event_tarihi: "2026-06-15",
      aciklama: "Izin kullanimi"
    });

    expect(result.event_tipi).toBe("SERBEST_ZAMAN_KULLANIM");
    if (result.event_tipi === "SERBEST_ZAMAN_KULLANIM") {
      expect(result.dakika).toBe(30);
      expect(result.event_tarihi).toBe("2026-06-15");
    }
  });

  it("GET events hem OLUSUM hem KULLANIM normalize eder", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          items: [
            {
              id: 1,
              personel_id: 1,
              kaynak_snapshot_id: 1001,
              kaynak_odeme_tercihi_id: 1,
              event_tipi: "SERBEST_ZAMAN_OLUSUM",
              dakika: 90,
              event_tarihi: "2026-05-31",
              son_kullanim_tarihi: "2026-11-30"
            },
            {
              id: 2,
              personel_id: 1,
              event_tipi: "SERBEST_ZAMAN_KULLANIM",
              dakika: 30,
              event_tarihi: "2026-06-15"
            }
          ]
        },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchSerbestZamanEvents(1);

    expect(result).toHaveLength(2);
    expect(result[0]?.event_tipi).toBe("SERBEST_ZAMAN_OLUSUM");
    expect(result[1]?.event_tipi).toBe("SERBEST_ZAMAN_KULLANIM");
  });

  it("GET bakiye kullanilan_dakika > 0 senaryosunu dondurur", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          personel_id: 1,
          toplam_hak_dakika: 90,
          kullanilan_dakika: 30,
          kalan_dakika: 60,
          suresi_dolan_dakika: 0,
          event_sayisi: 1
        },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchSerbestZamanBakiye(1);

    expect(result.kullanilan_dakika).toBe(30);
    expect(result.kalan_dakika).toBe(60);
  });

  it("POST kullanim basarili response normalize eder", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          id: 3,
          personel_id: 1,
          event_tipi: "SERBEST_ZAMAN_KULLANIM",
          dakika: 30,
          event_tarihi: "2026-06-15"
        },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await postSerbestZamanKullanim({
      personel_id: 1,
      dakika: 30,
      event_tarihi: "2026-06-15"
    });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toMatchObject({
      personel_id: 1,
      dakika: 30,
      event_tarihi: "2026-06-15"
    });
    expect(result.event_tipi).toBe("SERBEST_ZAMAN_KULLANIM");
    expect(result.dakika).toBe(30);
  });

  it("INSUFFICIENT_BALANCE hatasini 409 olarak firlatir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: null,
        meta: {},
        errors: [{ code: "INSUFFICIENT_BALANCE", message: "Bakiye yetersiz" }]
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await expect(
      postSerbestZamanKullanim({
        personel_id: 1,
        dakika: 100,
        event_tarihi: "2026-06-15"
      })
    ).rejects.toMatchObject({
      status: 409
    } satisfies Partial<ApiRequestError>);
  });

  it("normalizeSerbestZamanEvent IPTAL alanlarini normalize eder", () => {
    const result = normalizeSerbestZamanEvent({
      id: 4,
      personel_id: 1,
      event_tipi: "SERBEST_ZAMAN_IPTAL",
      hedef_event_id: 2,
      hedef_event_tipi: "SERBEST_ZAMAN_KULLANIM",
      event_tarihi: "2026-06-20"
    });

    expect(result.event_tipi).toBe("SERBEST_ZAMAN_IPTAL");
    if (result.event_tipi === "SERBEST_ZAMAN_IPTAL") {
      expect(result.hedef_event_id).toBe(2);
    }
  });

  it("normalizeSerbestZamanEvent DUZELTME alanlarini normalize eder", () => {
    const result = normalizeSerbestZamanEvent({
      id: 5,
      personel_id: 1,
      event_tipi: "SERBEST_ZAMAN_DUZELTME",
      hedef_event_id: 1,
      hedef_event_tipi: "SERBEST_ZAMAN_OLUSUM",
      yeni_dakika: 60,
      event_tarihi: "2026-06-20"
    });

    expect(result.event_tipi).toBe("SERBEST_ZAMAN_DUZELTME");
    if (result.event_tipi === "SERBEST_ZAMAN_DUZELTME") {
      expect(result.yeni_dakika).toBe(60);
    }
  });

  it("POST iptal basarili response normalize eder", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          id: 4,
          personel_id: 1,
          event_tipi: "SERBEST_ZAMAN_IPTAL",
          hedef_event_id: 2,
          hedef_event_tipi: "SERBEST_ZAMAN_KULLANIM",
          event_tarihi: "2026-06-20"
        },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await postSerbestZamanIptal({
      personel_id: 1,
      hedef_event_id: 2,
      hedef_event_tipi: "SERBEST_ZAMAN_KULLANIM",
      event_tarihi: "2026-06-20"
    });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toMatchObject({
      hedef_event_id: 2,
      hedef_event_tipi: "SERBEST_ZAMAN_KULLANIM"
    });
    expect(result.event_tipi).toBe("SERBEST_ZAMAN_IPTAL");
  });

  it("POST duzeltme basarili response normalize eder", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          id: 5,
          personel_id: 1,
          event_tipi: "SERBEST_ZAMAN_DUZELTME",
          hedef_event_id: 1,
          hedef_event_tipi: "SERBEST_ZAMAN_OLUSUM",
          yeni_dakika: 60,
          event_tarihi: "2026-06-20"
        },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await postSerbestZamanDuzeltme({
      personel_id: 1,
      hedef_event_id: 1,
      hedef_event_tipi: "SERBEST_ZAMAN_OLUSUM",
      yeni_dakika: 60,
      event_tarihi: "2026-06-20"
    });

    const [url] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/serbest-zaman/duzeltme");
    expect(result.event_tipi).toBe("SERBEST_ZAMAN_DUZELTME");
    if (result.event_tipi === "SERBEST_ZAMAN_DUZELTME") {
      expect(result.yeni_dakika).toBe(60);
    }
  });

  it("TARGET_NOT_FOUND hatasini 404 olarak firlatir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: null,
        meta: {},
        errors: [{ code: "TARGET_NOT_FOUND", message: "Hedef yok" }]
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await expect(
      postSerbestZamanIptal({
        personel_id: 1,
        hedef_event_id: 999,
        hedef_event_tipi: "SERBEST_ZAMAN_KULLANIM",
        event_tarihi: "2026-06-20"
      })
    ).rejects.toMatchObject({
      status: 404
    } satisfies Partial<ApiRequestError>);
  });

  it("ALREADY_CANCELLED hatasini 409 olarak firlatir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: null,
        meta: {},
        errors: [{ code: "ALREADY_CANCELLED", message: "Zaten iptal" }]
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await expect(
      postSerbestZamanIptal({
        personel_id: 1,
        hedef_event_id: 1,
        hedef_event_tipi: "SERBEST_ZAMAN_KULLANIM",
        event_tarihi: "2026-06-20"
      })
    ).rejects.toMatchObject({
      status: 409
    } satisfies Partial<ApiRequestError>);
  });
});
