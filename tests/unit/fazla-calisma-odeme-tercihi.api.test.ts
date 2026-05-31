import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import {
  fetchFazlaCalismaOdemeTercihi,
  normalizeFazlaCalismaOdemeTercihi,
  putFazlaCalismaOdemeTercihi
} from "../../src/api/fazla-calisma-odeme-tercihi.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("fazla-calisma-odeme-tercihi.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("normalize varsayilan odeme_tipi KARAR_BEKLIYOR kullanir", () => {
    const result = normalizeFazlaCalismaOdemeTercihi({
      snapshot_id: 1001,
      kapanis_id: 1,
      personel_id: 2,
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12",
      fazla_calisma_dakika: 60
    });

    expect(result.odeme_tipi).toBe("KARAR_BEKLIYOR");
    expect(result.fazla_calisma_dakika).toBe(60);
  });

  it("GET store bosken default KARAR_BEKLIYOR dondurur", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          snapshot_id: 1001,
          kapanis_id: 1,
          personel_id: 1,
          hafta_baslangic: "2026-04-06",
          hafta_bitis: "2026-04-12",
          fazla_calisma_dakika: 60,
          odeme_tipi: "KARAR_BEKLIYOR"
        },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchFazlaCalismaOdemeTercihi(1001);

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/fazla-calisma-odeme-tercihi?snapshot_id=1001");
    expect(init.method).toBeUndefined();
    expect(result.odeme_tipi).toBe("KARAR_BEKLIYOR");
    expect(result.snapshot_id).toBe(1001);
    expect(result.id).toBeUndefined();
  });

  it("PUT tercih kaydeder ve audit alanlarini normalize eder", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          id: 1,
          snapshot_id: 1001,
          kapanis_id: 1,
          personel_id: 1,
          hafta_baslangic: "2026-04-06",
          hafta_bitis: "2026-04-12",
          fazla_calisma_dakika: 60,
          odeme_tipi: "SERBEST_ZAMAN",
          secim_zamani: "2026-05-31T10:00:00.000Z",
          secen_kullanici_id: 5,
          onceki_odeme_tipi: "KARAR_BEKLIYOR",
          gerekce: "Personel talebi"
        },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await putFazlaCalismaOdemeTercihi({
      snapshot_id: 1001,
      odeme_tipi: "SERBEST_ZAMAN",
      gerekce: "Personel talebi",
      secen_kullanici_id: 5
    });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.method).toBe("PUT");
    expect(JSON.parse(String(init.body))).toMatchObject({
      snapshot_id: 1001,
      odeme_tipi: "SERBEST_ZAMAN"
    });
    expect(result.odeme_tipi).toBe("SERBEST_ZAMAN");
    expect(result.onceki_odeme_tipi).toBe("KARAR_BEKLIYOR");
    expect(result.id).toBe(1);
  });

  it("NOT_FOUND hatasini 404 olarak firlatir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: null,
        meta: {},
        errors: [{ code: "NOT_FOUND", message: "snapshot bulunamadi" }]
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await expect(fetchFazlaCalismaOdemeTercihi(9999)).rejects.toMatchObject({
      status: 404
    } satisfies Partial<ApiRequestError>);
  });

  it("gecersiz snapshot_id parametresini reddeder", async () => {
    await expect(fetchFazlaCalismaOdemeTercihi(0)).rejects.toBeInstanceOf(ApiRequestError);
  });

  it("gecersiz odeme_tipi PUT payloadini reddeder", async () => {
    await expect(
      putFazlaCalismaOdemeTercihi({
        snapshot_id: 1001,
        odeme_tipi: "INVALID" as "UCRET"
      })
    ).rejects.toBeInstanceOf(ApiRequestError);
  });
});
