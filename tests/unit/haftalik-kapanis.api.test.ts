import { afterEach, describe, expect, it, vi } from "vitest";
import {
  createHaftalikKapanis,
  fetchHaftalikKapanisDetail
} from "../../src/api/haftalik-kapanis.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("haftalik-kapanis.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("posts weekly close payload and normalizes response fields", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: "99",
            week_start: "2026-04-06",
            week_end: "2026-04-12",
            departman_id: 3,
            durum: "KAPANDI",
            personel_sayisi: 24
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await createHaftalikKapanis({
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12",
      departman_id: 3
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/haftalik-kapanis");
    expect(init.method).toBe("POST");
    expect(result.id).toBe(99);
    expect(result.kapanis_id).toBe(99);
    expect(result.hafta_baslangic).toBe("2026-04-06");
    expect(result.hafta_bitis).toBe("2026-04-12");
    expect(result.state).toBe("KAPANDI");
    expect(result.personel_sayisi).toBe(24);
    expect(result.snapshot_satirlari).toEqual([]);
    expect(result.snapshot_satir_sayisi).toBe(0);
  });

  it("normalizes snapshot_satirlari and snapshot_satir_sayisi from response", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: 10,
            hafta_baslangic: "2026-04-06",
            hafta_bitis: "2026-04-12",
            departman_id: 3,
            state: "KAPANDI",
            personel_sayisi: 2,
            snapshot_satir_sayisi: 2,
            snapshot_satirlari: [
              {
                snapshot_id: 1001,
                personel_id: 1,
                hafta_baslangic: "2026-04-06",
                hafta_bitis: "2026-04-12",
                state: "KAPANDI",
                kaynak_versiyon: "A1_CONTRACT_STUB"
              },
              {
                snapshot_id: 1002,
                personel_id: 2,
                departman_id: 3,
                hafta_baslangic: "2026-04-06",
                hafta_bitis: "2026-04-12"
              }
            ]
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await createHaftalikKapanis({
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12",
      departman_id: 3
    });

    expect(result.kapanis_id).toBe(10);
    expect(result.snapshot_satirlari).toHaveLength(2);
    expect(result.snapshot_satir_sayisi).toBe(2);
    expect(result.snapshot_satirlari[0].personel_id).toBe(1);
    expect(result.snapshot_satirlari[0].fazla_surelerle_calisma_dakika).toBe(0);
    expect(result.snapshot_satirlari[0].toplam_net_dakika).toBe(0);
    expect(result.snapshot_satirlari[0].compliance_uyarilari).toEqual([]);
    expect(result.snapshot_satirlari[0].compliance_uyari_sayisi).toBe(0);
    expect(result.snapshot_satirlari[0].tam_hafta_verisi).toBe(false);
    expect(result.snapshot_satirlari[0].state).toBe("KAPANDI");
    expect(result.snapshot_satirlari[0].kaynak_versiyon).toBe("A1_CONTRACT_STUB");
    expect(result.snapshot_satirlari[1].kapanis_id).toBe(10);
  });

  it("derives kapanis_id from id when kapanis_id is absent", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: 77,
            hafta_baslangic: "2026-04-06",
            hafta_bitis: "2026-04-12",
            snapshot_satirlari: [
              {
                personel_id: 5,
                hafta_baslangic: "2026-04-06",
                hafta_bitis: "2026-04-12"
              }
            ]
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await createHaftalikKapanis({
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12"
    });

    expect(result.kapanis_id).toBe(77);
    expect(result.snapshot_satirlari[0].kapanis_id).toBe(77);
  });

  it("defaults snapshot row fields when omitted in payload", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: 1,
            hafta_baslangic: "2026-04-06",
            hafta_bitis: "2026-04-12",
            snapshot_satirlari: [
              {
                personel_id: 3,
                hafta_baslangic: "2026-04-06",
                hafta_bitis: "2026-04-12",
                compliance_uyarilari: [
                  { code: "MAX_DAILY_LIMIT", message: "Uyarı", level: "KRITIK" }
                ]
              }
            ]
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await createHaftalikKapanis({
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12"
    });

    const row = result.snapshot_satirlari[0];
    expect(row.fazla_surelerle_calisma_dakika).toBe(0);
    expect(row.normal_calisma_dakika).toBe(0);
    expect(row.fazla_calisma_dakika).toBe(0);
    expect(row.tam_hafta_verisi).toBe(false);
    expect(row.state).toBe("KAPANDI");
    expect(row.compliance_uyarilari).toHaveLength(1);
    expect(row.compliance_uyari_sayisi).toBe(1);
    expect(row.kritik_uyari_var_mi).toBe(true);
  });

  it("uses compliance_uyari_sayisi from payload when provided", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: 1,
            snapshot_satirlari: [
              {
                personel_id: 1,
                hafta_baslangic: "2026-04-06",
                hafta_bitis: "2026-04-12",
                compliance_uyarilari: [],
                compliance_uyari_sayisi: 5
              }
            ]
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await createHaftalikKapanis({
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12"
    });

    expect(result.snapshot_satirlari[0].compliance_uyari_sayisi).toBe(5);
    expect(result.snapshot_satirlari[0].kritik_uyari_var_mi).toBe(false);
  });

  it("fetchHaftalikKapanisDetail sends GET to detail endpoint", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: 42,
            kapanis_id: 42,
            hafta_baslangic: "2026-04-06",
            hafta_bitis: "2026-04-12",
            state: "KAPANDI",
            snapshot_satirlari: []
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    await fetchHaftalikKapanisDetail(42);

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/haftalik-kapanis/42");
    expect(init.method).toBeUndefined();
  });

  it("fetchHaftalikKapanisDetail normalizes response and preserves snapshot_satirlari", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            kapanis_id: 55,
            hafta_baslangic: "2026-04-06",
            hafta_bitis: "2026-04-12",
            departman_id: 3,
            state: "KAPANDI",
            personel_sayisi: 1,
            snapshot_satir_sayisi: 1,
            snapshot_satirlari: [
              {
                snapshot_id: 55001,
                kapanis_id: 55,
                personel_id: 1,
                hafta_baslangic: "2026-04-06",
                hafta_bitis: "2026-04-12",
                state: "KAPANDI",
                kaynak_versiyon: "A2_MOTOR_V1",
                toplam_net_dakika: 3570,
                normal_calisma_dakika: 2700,
                fazla_calisma_dakika: 870,
                fazla_surelerle_calisma_dakika: 0,
                tam_hafta_verisi: true,
                compliance_uyarilari: [],
                compliance_uyari_sayisi: 0,
                kritik_uyari_var_mi: false
              }
            ]
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchHaftalikKapanisDetail(55);

    expect(result.kapanis_id).toBe(55);
    expect(result.snapshot_satirlari).toHaveLength(1);
    expect(result.snapshot_satirlari[0].personel_id).toBe(1);
    expect(result.snapshot_satirlari[0].toplam_net_dakika).toBe(3570);
    expect(result.snapshot_satirlari[0].fazla_calisma_dakika).toBe(870);
    expect(result.snapshot_satirlari[0].kaynak_versiyon).toBe("A2_MOTOR_V1");
    expect(result.snapshot_satir_sayisi).toBe(1);
  });

  it("fetchHaftalikKapanisDetail derives kapanis_id from id when kapanis_id is absent", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: 88,
            hafta_baslangic: "2026-04-06",
            hafta_bitis: "2026-04-12",
            snapshot_satirlari: [
              {
                personel_id: 2,
                hafta_baslangic: "2026-04-06",
                hafta_bitis: "2026-04-12"
              }
            ]
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchHaftalikKapanisDetail(88);

    expect(result.kapanis_id).toBe(88);
    expect(result.snapshot_satirlari[0].kapanis_id).toBe(88);
  });

  it("fetchHaftalikKapanisDetail normalizes non-array snapshot_satirlari to empty list", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: 12,
            kapanis_id: 12,
            hafta_baslangic: "2026-04-06",
            hafta_bitis: "2026-04-12",
            snapshot_satirlari: "invalid"
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchHaftalikKapanisDetail(12);

    expect(result.snapshot_satirlari).toEqual([]);
    expect(result.snapshot_satir_sayisi).toBe(0);
  });

  it("fetchHaftalikKapanisDetail rejects with ApiRequestError on 404 response", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: null,
          meta: {},
          errors: [{ code: "NOT_FOUND", message: "Haftalik kapanis bulunamadi." }]
        },
        404
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    await expect(fetchHaftalikKapanisDetail(9999)).rejects.toMatchObject({
      status: 404,
      message: "Haftalik kapanis bulunamadi.",
      code: "NOT_FOUND"
    });
  });
});
