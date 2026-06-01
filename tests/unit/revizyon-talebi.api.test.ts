import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import { resolveDemoApiResponse } from "../../src/api/mock-demo";
import {
  approveRevizyonTalebi,
  cancelRevizyonTalebi,
  createRevizyonTalebi,
  fetchRevizyonTalebiDetail,
  fetchRevizyonTalepleri,
  normalizeRevizyonTalebi,
  rejectRevizyonTalebi,
  submitRevizyonTalebi
} from "../../src/api/revizyon-talebi.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

const validRevizyonPayload = {
  personel_id: 1,
  hafta_baslangic: "2026-04-06",
  hafta_bitis: "2026-04-12",
  etkilenen_tarih: "2026-04-08",
  kaynak_tipi: "PUANTAJ",
  kaynak_id: 501,
  revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME" as const,
  onceki_deger: "08:00",
  talep_edilen_deger: "08:15",
  gerekce: "Gec giris duzeltmesi"
};

const validRevizyonEntity = {
  id: 1,
  personel_id: 1,
  hafta_baslangic: "2026-04-06",
  hafta_bitis: "2026-04-12",
  etkilenen_tarih: "2026-04-08",
  kaynak_tipi: "PUANTAJ",
  kaynak_id: 501,
  revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
  onceki_deger: "08:00",
  talep_edilen_deger: "08:15",
  gerekce: "Gec giris duzeltmesi",
  talep_eden_kullanici_id: 1,
  talep_zamani: "2026-06-01T10:00:00.000Z",
  durum: "TASLAK",
  bordro_etki_var_mi: false,
  correction_event_id: null
};

describe("revizyon-talebi.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("normalizeRevizyonTalebi valid entity dondurur", () => {
    const result = normalizeRevizyonTalebi(validRevizyonEntity);

    expect(result.id).toBe(1);
    expect(result.durum).toBe("TASLAK");
    expect(result.revizyon_tipi).toBe("PUANTAJ_GIRIS_CIKIS_DUZELTME");
    expect(result.correction_event_id).toBeNull();
  });

  it("fetchRevizyonTalepleri query string uretir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: { items: [validRevizyonEntity] },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchRevizyonTalepleri({
      personel_id: 1,
      durum: "TASLAK",
      hafta_baslangic: "2026-04-06"
    });

    const [url] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe(
      "/api/haftalik-kapanis/revizyon-talepleri?personel_id=1&durum=TASLAK&hafta_baslangic=2026-04-06"
    );
    expect(result).toHaveLength(1);
    expect(result[0]?.id).toBe(1);
  });

  it("fetchRevizyonTalebiDetail dogru endpoint cagirir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: validRevizyonEntity,
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchRevizyonTalebiDetail(7);

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/haftalik-kapanis/revizyon-talepleri/7");
    expect(init.method).toBeUndefined();
    expect(result.id).toBe(1);
  });

  it("createRevizyonTalebi POST body dogru", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: validRevizyonEntity,
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await createRevizyonTalebi(validRevizyonPayload);

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/haftalik-kapanis/revizyon-talepleri");
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toMatchObject({
      personel_id: 1,
      kaynak_id: 501,
      revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
      gerekce: "Gec giris duzeltmesi"
    });
  });

  it("submit/approve/reject/cancel dogru endpoint ve method kullanir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: { ...validRevizyonEntity, durum: "ONAY_BEKLIYOR" },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await submitRevizyonTalebi(3);
    let [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/haftalik-kapanis/revizyon-talepleri/3/gonder");
    expect(init.method).toBe("POST");

    fetchMock.mockClear();
    await approveRevizyonTalebi(3, { karar_notu: "Onay" });
    [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/haftalik-kapanis/revizyon-talepleri/3/onay");
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({ karar_notu: "Onay" });

    fetchMock.mockClear();
    await rejectRevizyonTalebi(3, { karar_notu: "Red" });
    [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/haftalik-kapanis/revizyon-talepleri/3/red");
    expect(init.method).toBe("POST");

    fetchMock.mockClear();
    await cancelRevizyonTalebi(3);
    [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/haftalik-kapanis/revizyon-talepleri/3/iptal");
    expect(init.method).toBe("POST");
  });

  it("PERIOD_NOT_CLOSED hatasini 409 olarak firlatir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: null,
        meta: {},
        errors: [{ code: "PERIOD_NOT_CLOSED", message: "Kapali donem yok" }]
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await expect(createRevizyonTalebi(validRevizyonPayload)).rejects.toMatchObject({
      status: 409,
      code: "PERIOD_NOT_CLOSED"
    } satisfies Partial<ApiRequestError>);
  });

  it("TARGET_NOT_FOUND hatasini 404 olarak firlatir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: null,
        meta: {},
        errors: [{ code: "TARGET_NOT_FOUND", message: "Kaynak bulunamadi" }]
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await expect(fetchRevizyonTalebiDetail(99)).rejects.toMatchObject({
      status: 404,
      code: "TARGET_NOT_FOUND"
    } satisfies Partial<ApiRequestError>);
  });

  it("UNAUTHORIZED_REVISION_APPROVAL hatasini 403 olarak firlatir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: null,
        meta: {},
        errors: [{ code: "UNAUTHORIZED_REVISION_APPROVAL", message: "Yetkisiz onay" }]
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await expect(approveRevizyonTalebi(1, {})).rejects.toMatchObject({
      status: 403,
      code: "UNAUTHORIZED_REVISION_APPROVAL"
    } satisfies Partial<ApiRequestError>);
  });

  it("INVALID_STATE_TRANSITION hatasini 409 olarak firlatir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: null,
        meta: {},
        errors: [{ code: "INVALID_STATE_TRANSITION", message: "Gecersiz gecis" }]
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await expect(submitRevizyonTalebi(1)).rejects.toMatchObject({
      status: 409,
      code: "INVALID_STATE_TRANSITION"
    } satisfies Partial<ApiRequestError>);
  });
});

describe("revizyon-talebi mock integration", () => {
  function getCreatedTalepId(response: ReturnType<typeof resolveDemoApiResponse>): number {
    const data = response?.data as { id?: number } | null | undefined;
    if (typeof data?.id !== "number") {
      throw new Error("Mock revizyon talebi id alinamadi.");
    }
    return data.id;
  }

  it("kapali donem yoksa PERIOD_NOT_CLOSED doner", () => {
    const response = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      body: JSON.stringify({
        personel_id: 1,
        hafta_baslangic: "2099-01-01",
        hafta_bitis: "2099-01-07",
        etkilenen_tarih: "2099-01-02",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9001,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        onceki_deger: "08:00",
        talep_edilen_deger: "08:15",
        gerekce: "Mock revizyon"
      })
    });

    expect(response?.errors?.[0]?.code).toBe("PERIOD_NOT_CLOSED");
  });

  it("duplicate acik talep REVISION_ALREADY_EXISTS doner", () => {
    resolveDemoApiResponse("/haftalik-kapanis", {
      method: "POST",
      body: JSON.stringify({
        hafta_baslangic: "2026-05-01",
        hafta_bitis: "2026-05-07",
        departman_id: 3
      })
    });

    const revizyonBody = {
      personel_id: 1,
      hafta_baslangic: "2026-05-01",
      hafta_bitis: "2026-05-07",
      etkilenen_tarih: "2026-05-03",
      kaynak_tipi: "PUANTAJ",
      kaynak_id: 9101,
      revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
      onceki_deger: "08:00",
      talep_edilen_deger: "08:15",
      gerekce: "Mock revizyon"
    };

    const created = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      body: JSON.stringify(revizyonBody)
    });
    const talepId = getCreatedTalepId(created);

    resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/gonder`, {
      method: "POST",
      body: JSON.stringify({})
    });

    const duplicate = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      body: JSON.stringify(revizyonBody)
    });

    expect(duplicate?.errors?.[0]?.code).toBe("REVISION_ALREADY_EXISTS");
  });

  it("REDDEDILDI durumundan ONAYLANDI gecisi INVALID_STATE_TRANSITION doner", () => {
    resolveDemoApiResponse("/haftalik-kapanis", {
      method: "POST",
      body: JSON.stringify({
        hafta_baslangic: "2026-05-08",
        hafta_bitis: "2026-05-14",
        departman_id: 3
      })
    });

    const revizyonBody = {
      personel_id: 1,
      hafta_baslangic: "2026-05-08",
      hafta_bitis: "2026-05-14",
      etkilenen_tarih: "2026-05-10",
      kaynak_tipi: "PUANTAJ",
      kaynak_id: 9201,
      revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
      onceki_deger: "08:00",
      talep_edilen_deger: "08:15",
      gerekce: "Mock revizyon red"
    };

    const created = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      body: JSON.stringify(revizyonBody)
    });
    const talepId = getCreatedTalepId(created);

    resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/gonder`, {
      method: "POST",
      body: JSON.stringify({})
    });

    resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/red`, {
      method: "POST",
      body: JSON.stringify({ karar_notu: "Red" })
    });

    const invalid = resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/onay`, {
      method: "POST",
      body: JSON.stringify({ karar_notu: "Tekrar onay" })
    });

    expect(invalid?.errors?.[0]?.code).toBe("INVALID_STATE_TRANSITION");
  });
});
