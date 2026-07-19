import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import { resolveDemoApiResponse, seedDemoHaftalikMutabakatForClose } from "../../src/api/mock-demo";
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


function closeDemoHaftalikKapanis(haftaBaslangic: string, haftaBitis: string, departmanId = 3) {
  seedDemoHaftalikMutabakatForClose({ haftaBaslangic, haftaBitis });
  return resolveDemoApiResponse("/haftalik-kapanis", {
    method: "POST",
    body: JSON.stringify({
      hafta_baslangic: haftaBaslangic,
      hafta_bitis: haftaBitis,
      departman_id: departmanId
    })
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
  function demoHeaders(userId?: number, role?: string): HeadersInit {
    const headers = new Headers();
    if (userId !== undefined) {
      headers.set("X-Demo-User-Id", String(userId));
    }
    if (role) {
      headers.set("X-Demo-Role", role);
    }
    return headers;
  }

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

  it("duplicate acik talep ALREADY_EXISTS doner", () => {
    closeDemoHaftalikKapanis("2026-05-01", "2026-05-07", 3);

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

    expect(duplicate?.errors?.[0]?.code).toBe("ALREADY_EXISTS");
  });

  it("REDDEDILDI durumundan ONAYLANDI gecisi STATE_CONFLICT doner", () => {
    closeDemoHaftalikKapanis("2026-05-08", "2026-05-14", 3);

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

    expect(invalid?.errors?.[0]?.code).toBe("STATE_CONFLICT");
  });

  it("BOLUM_YONETICISI list yalniz kendi bolumundeki talepleri doner", () => {
    closeDemoHaftalikKapanis("2026-06-01", "2026-06-07", 3);
    closeDemoHaftalikKapanis("2026-06-08", "2026-06-14", 6);

    resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({
        personel_id: 1,
        hafta_baslangic: "2026-06-01",
        hafta_bitis: "2026-06-07",
        etkilenen_tarih: "2026-06-02",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9301,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        onceki_deger: "08:00",
        talep_edilen_deger: "08:15",
        gerekce: "Dept 3"
      })
    });

    resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({
        personel_id: 2,
        hafta_baslangic: "2026-06-08",
        hafta_bitis: "2026-06-14",
        etkilenen_tarih: "2026-06-10",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9302,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        onceki_deger: "08:00",
        talep_edilen_deger: "08:15",
        gerekce: "Dept 6"
      })
    });

    const list = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      headers: demoHeaders(2)
    });
    const items = (list?.data as { items?: Array<{ personel_id: number }> })?.items ?? [];

    expect(items).toHaveLength(1);
    expect(items[0]?.personel_id).toBe(2);
  });

  it("BOLUM_YONETICISI scope disi detail REVISION_SCOPE_DENIED doner", () => {
    closeDemoHaftalikKapanis("2026-06-15", "2026-06-21", 3);

    const created = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({
        personel_id: 1,
        hafta_baslangic: "2026-06-15",
        hafta_bitis: "2026-06-21",
        etkilenen_tarih: "2026-06-16",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9401,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        onceki_deger: "08:00",
        talep_edilen_deger: "08:15",
        gerekce: "Scope test"
      })
    });
    const talepId = getCreatedTalepId(created);

    const detail = resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}`, {
      headers: demoHeaders(2)
    });

    expect(detail?.errors?.[0]?.code).toBe("REVISION_SCOPE_DENIED");
  });

  it("MUHASEBE approve UNAUTHORIZED_REVISION_APPROVAL doner", () => {
    closeDemoHaftalikKapanis("2026-06-22", "2026-06-28", 3);

    const created = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({
        personel_id: 1,
        hafta_baslangic: "2026-06-22",
        hafta_bitis: "2026-06-28",
        etkilenen_tarih: "2026-06-23",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9501,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        onceki_deger: "08:00",
        talep_edilen_deger: "08:15",
        gerekce: "Approve test",
        bordro_etki_var_mi: true
      })
    });
    const talepId = getCreatedTalepId(created);

    resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/gonder`, {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({})
    });

    const approve = resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/onay`, {
      method: "POST",
      headers: demoHeaders(undefined, "MUHASEBE"),
      body: JSON.stringify({ karar_notu: "Yetkisiz" })
    });

    expect(approve?.errors?.[0]?.code).toBe("UNAUTHORIZED_REVISION_APPROVAL");
  });

  it("BIRIM_AMIRI detail finance alanini maskeler", () => {
    closeDemoHaftalikKapanis("2026-07-01", "2026-07-07", 3);

    const created = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({
        personel_id: 1,
        hafta_baslangic: "2026-07-01",
        hafta_bitis: "2026-07-07",
        etkilenen_tarih: "2026-07-02",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9601,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        onceki_deger: "08:00",
        talep_edilen_deger: "08:15",
        gerekce: "Finance mask",
        bordro_etki_var_mi: true,
        bordro_etki_notu: "Gizli not"
      })
    });
    const talepId = getCreatedTalepId(created);

    const detail = resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}`, {
      headers: demoHeaders(3)
    });
    const talep = detail?.data as { bordro_etki_notu?: string | null; bordro_etki_var_mi?: boolean };

    expect(talep.bordro_etki_var_mi).toBe(true);
    expect(talep.bordro_etki_notu).toBeNull();
  });

  it("create client talep_eden_kullanici_id gonderse ignore eder", () => {
    closeDemoHaftalikKapanis("2026-07-08", "2026-07-14", 3);

    const created = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      headers: demoHeaders(3),
      body: JSON.stringify({
        personel_id: 1,
        hafta_baslangic: "2026-07-08",
        hafta_bitis: "2026-07-14",
        etkilenen_tarih: "2026-07-09",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9701,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        onceki_deger: "08:00",
        talep_edilen_deger: "08:15",
        gerekce: "Owner test",
        talep_eden_kullanici_id: 999
      })
    });
    const talep = created?.data as { talep_eden_kullanici_id?: number };

    expect(talep.talep_eden_kullanici_id).toBe(3);
  });

  it("GENEL_YONETICI ONAY_BEKLIYOR talebi onaylayabilir", () => {
    closeDemoHaftalikKapanis("2026-07-15", "2026-07-21", 3);

    const created = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({
        personel_id: 1,
        hafta_baslangic: "2026-07-15",
        hafta_bitis: "2026-07-21",
        etkilenen_tarih: "2026-07-16",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9801,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        onceki_deger: "08:00",
        talep_edilen_deger: "08:15",
        gerekce: "Onay test"
      })
    });
    const talepId = getCreatedTalepId(created);

    resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/gonder`, {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({})
    });

    const approved = resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/onay`, {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({ karar_notu: "Onaylandi" })
    });
    const talep = approved?.data as { durum?: string; karar_veren_kullanici_id?: number };

    expect(talep.durum).toBe("ONAYLANDI");
    expect(talep.karar_veren_kullanici_id).toBe(1);
  });

  it("ONAYLANDI talep cancel STATE_CONFLICT doner", () => {
    closeDemoHaftalikKapanis("2026-07-22", "2026-07-28", 3);

    const created = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({
        personel_id: 1,
        hafta_baslangic: "2026-07-22",
        hafta_bitis: "2026-07-28",
        etkilenen_tarih: "2026-07-23",
        kaynak_tipi: "PUANTAJ",
        kaynak_id: 9901,
        revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
        onceki_deger: "08:00",
        talep_edilen_deger: "08:15",
        gerekce: "Cancel test"
      })
    });
    const talepId = getCreatedTalepId(created);

    resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/gonder`, {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({})
    });
    resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/onay`, {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({ karar_notu: "Onay" })
    });

    const cancel = resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/iptal`, {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({})
    });

    expect(cancel?.errors?.[0]?.code).toBe("STATE_CONFLICT");
  });
});
