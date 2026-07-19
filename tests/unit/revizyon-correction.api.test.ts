import { afterEach, describe, expect, it, vi } from "vitest";
import { resolveDemoApiResponse, seedDemoHaftalikMutabakatForClose } from "../../src/api/mock-demo";
import {
  cancelRevizyonCorrection,
  fetchRevizyonCorrectionDetail,
  fetchRevizyonCorrections,
  normalizeRevizyonCorrection,
  produceRevizyonCorrection
} from "../../src/api/revizyon-correction.api";
import { filterActiveCorrections } from "../../src/lib/revizyon-talebi/revizyon-correction-overlay";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

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

function closeWeekAndCreateTalep(params: {
  haftaBaslangic: string;
  haftaBitis: string;
  etkilenenTarih: string;
  kaynakId: number;
  oncekiDeger?: number;
  talepEdilenDeger?: number;
  bordroEtkiVarMi?: boolean;
}) {
  seedDemoHaftalikMutabakatForClose({
    haftaBaslangic: params.haftaBaslangic,
    haftaBitis: params.haftaBitis
  });
  resolveDemoApiResponse("/haftalik-kapanis", {
    method: "POST",
    body: JSON.stringify({
      hafta_baslangic: params.haftaBaslangic,
      hafta_bitis: params.haftaBitis,
      departman_id: 3
    })
  });

  const created = resolveDemoApiResponse("/haftalik-kapanis/revizyon-talepleri", {
    method: "POST",
    headers: demoHeaders(1),
    body: JSON.stringify({
      personel_id: 1,
      hafta_baslangic: params.haftaBaslangic,
      hafta_bitis: params.haftaBitis,
      etkilenen_tarih: params.etkilenenTarih,
      kaynak_tipi: "PUANTAJ",
      kaynak_id: params.kaynakId,
      revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
      onceki_deger: params.oncekiDeger ?? 100,
      talep_edilen_deger: params.talepEdilenDeger ?? 115,
      gerekce: "Correction test",
      bordro_etki_var_mi: params.bordroEtkiVarMi ?? false
    })
  });

  return getCreatedTalepId(created);
}

function submitAndApprove(talepId: number) {
  resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/gonder`, {
    method: "POST",
    headers: demoHeaders(1),
    body: JSON.stringify({})
  });

  return resolveDemoApiResponse(`/haftalik-kapanis/revizyon-talepleri/${talepId}/onay`, {
    method: "POST",
    headers: demoHeaders(1),
    body: JSON.stringify({ karar_notu: "Onaylandi" })
  });
}

describe("revizyon-correction.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("normalizeRevizyonCorrection valid entity dondurur", () => {
    const result = normalizeRevizyonCorrection({
      id: 1,
      revizyon_talebi_id: 10,
      personel_id: 1,
      hafta_baslangic: "2026-08-01",
      hafta_bitis: "2026-08-07",
      etkilenen_tarih: "2026-08-02",
      kaynak_tipi: "PUANTAJ",
      kaynak_id: 8801,
      correction_tipi: "GIRIS_CIKIS_DUZELTME",
      onceki_deger: 100,
      yeni_deger: 115,
      delta_dakika: 15,
      delta_gun: 0,
      bordro_etki_var_mi: false,
      bordro_etki_tipi: null,
      aciklama: "Test",
      olusturan_kullanici_id: 1,
      olusturma_zamani: "2026-06-01T12:00:00.000Z",
      iptal_edildi_mi: false,
      iptal_zamani: null,
      iptal_eden_kullanici_id: null,
      audit_ref: "REV-CORR-10-1",
      snapshot_ref: "snapshot:1001"
    });

    expect(result.id).toBe(1);
    expect(result.correction_tipi).toBe("GIRIS_CIKIS_DUZELTME");
    expect(result.audit_ref).toBe("REV-CORR-10-1");
  });

  it("fetchRevizyonCorrections query string uretir", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: { items: [] },
        meta: {},
        errors: []
      })
    );

    vi.stubGlobal("fetch", fetchMock);

    await fetchRevizyonCorrections({
      revizyon_talebi_id: 5,
      personel_id: 1,
      hafta_baslangic: "2026-08-01"
    });

    const calledUrl = String(fetchMock.mock.calls[0]?.[0] ?? "");
    expect(calledUrl).toContain("revizyon_talebi_id=5");
    expect(calledUrl).toContain("personel_id=1");
    expect(calledUrl).toContain("hafta_baslangic=2026-08-01");
  });

  it("approve ONAY_BEKLIYOR talep icin correction_event_id set eder", () => {
    const talepId = closeWeekAndCreateTalep({
      haftaBaslangic: "2026-08-04",
      haftaBitis: "2026-08-10",
      etkilenenTarih: "2026-08-05",
      kaynakId: 8802
    });

    const approved = submitAndApprove(talepId);
    const talep = approved?.data as { correction_event_id?: number | null; durum?: string };

    expect(talep.durum).toBe("ONAYLANDI");
    expect(typeof talep.correction_event_id).toBe("number");
  });

  it("produceRevizyonCorrection ikinci kez CORRECTION_ALREADY_EXISTS doner", async () => {
    const talepId = closeWeekAndCreateTalep({
      haftaBaslangic: "2026-08-11",
      haftaBitis: "2026-08-17",
      etkilenenTarih: "2026-08-12",
      kaynakId: 8803
    });

    submitAndApprove(talepId);

    const duplicate = resolveDemoApiResponse(
      `/haftalik-kapanis/revizyon-talepleri/${talepId}/correction-uret`,
      {
        method: "POST",
        headers: demoHeaders(1),
        body: JSON.stringify({})
      }
    );

    expect(duplicate?.errors?.[0]?.code).toBe("CORRECTION_ALREADY_EXISTS");

    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse({
          data: null,
          meta: {},
          errors: [
            {
              code: "CORRECTION_ALREADY_EXISTS",
              message: "Bu revizyon talebi icin correction zaten mevcut."
            }
          ]
        }, 409)
      )
    );

    await expect(produceRevizyonCorrection(talepId)).rejects.toMatchObject({
      status: 409
    });
  });

  it("ONAYLANDI olmayan talepte correction-uret CORRECTION_NOT_ALLOWED_FOR_STATE doner", () => {
    const talepId = closeWeekAndCreateTalep({
      haftaBaslangic: "2026-08-18",
      haftaBitis: "2026-08-24",
      etkilenenTarih: "2026-08-19",
      kaynakId: 8804
    });

    const response = resolveDemoApiResponse(
      `/haftalik-kapanis/revizyon-talepleri/${talepId}/correction-uret`,
      {
        method: "POST",
        headers: demoHeaders(1),
        body: JSON.stringify({})
      }
    );

    expect(response?.errors?.[0]?.code).toBe("CORRECTION_NOT_ALLOWED_FOR_STATE");
  });

  it("cancelRevizyonCorrection iptal_edildi_mi true yapar", async () => {
    const talepId = closeWeekAndCreateTalep({
      haftaBaslangic: "2026-08-25",
      haftaBitis: "2026-08-31",
      etkilenenTarih: "2026-08-26",
      kaynakId: 8805
    });

    const approved = submitAndApprove(talepId);
    const correctionEventId = (approved?.data as { correction_event_id?: number }).correction_event_id;

    const cancelled = resolveDemoApiResponse(
      `/haftalik-kapanis/revizyon-corrections/${correctionEventId}/iptal`,
      {
        method: "POST",
        headers: demoHeaders(1),
        body: JSON.stringify({ aciklama: "Iptal" })
      }
    );
    const correction = cancelled?.data as { iptal_edildi_mi?: boolean };

    expect(correction.iptal_edildi_mi).toBe(true);

    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse({
          data: {
            id: correctionEventId,
            revizyon_talebi_id: talepId,
            personel_id: 1,
            hafta_baslangic: "2026-08-25",
            hafta_bitis: "2026-08-31",
            etkilenen_tarih: "2026-08-26",
            kaynak_tipi: "PUANTAJ",
            kaynak_id: 8805,
            correction_tipi: "GIRIS_CIKIS_DUZELTME",
            onceki_deger: 100,
            yeni_deger: 115,
            delta_dakika: 15,
            delta_gun: 0,
            bordro_etki_var_mi: false,
            bordro_etki_tipi: null,
            aciklama: "Iptal",
            olusturan_kullanici_id: 1,
            olusturma_zamani: "2026-06-01T12:00:00.000Z",
            iptal_edildi_mi: true,
            iptal_zamani: "2026-06-02T12:00:00.000Z",
            iptal_eden_kullanici_id: 1,
            audit_ref: "REV-CORR-1-1",
            snapshot_ref: null
          },
          meta: {},
          errors: []
        })
      )
    );

    const clientResult = await cancelRevizyonCorrection(correctionEventId!);
    expect(clientResult.iptal_edildi_mi).toBe(true);
  });

  it("iptal edilmis correction active filter disina duser", () => {
    const talepId = closeWeekAndCreateTalep({
      haftaBaslangic: "2026-09-01",
      haftaBitis: "2026-09-07",
      etkilenenTarih: "2026-09-02",
      kaynakId: 8806
    });

    const approved = submitAndApprove(talepId);
    const correctionEventId = (approved?.data as { correction_event_id?: number }).correction_event_id;

    resolveDemoApiResponse(`/haftalik-kapanis/revizyon-corrections/${correctionEventId}/iptal`, {
      method: "POST",
      headers: demoHeaders(1),
      body: JSON.stringify({})
    });

    const detail = resolveDemoApiResponse(
      `/haftalik-kapanis/revizyon-corrections/${correctionEventId}`,
      {
        headers: demoHeaders(1)
      }
    );
    const correction = detail?.data as {
      iptal_edildi_mi?: boolean;
    };

    expect(correction.iptal_edildi_mi).toBe(true);
    expect(filterActiveCorrections([normalizeRevizyonCorrection(detail?.data)])).toHaveLength(0);
  });

  it("BIRIM_AMIRI correction detail finance alanlarini maskeler", () => {
    const talepId = closeWeekAndCreateTalep({
      haftaBaslangic: "2026-09-08",
      haftaBitis: "2026-09-14",
      etkilenenTarih: "2026-09-09",
      kaynakId: 8807,
      bordroEtkiVarMi: true
    });

    const approved = submitAndApprove(talepId);
    const correctionEventId = (approved?.data as { correction_event_id?: number }).correction_event_id;

    const detail = resolveDemoApiResponse(
      `/haftalik-kapanis/revizyon-corrections/${correctionEventId}`,
      {
        headers: demoHeaders(3)
      }
    );
    const correction = detail?.data as {
      bordro_etki_var_mi?: boolean;
      bordro_etki_tipi?: string | null;
      aciklama?: string | null;
    };

    expect(correction.bordro_etki_var_mi).toBe(true);
    expect(correction.bordro_etki_tipi).toBeNull();
    expect(correction.aciklama).toBeNull();
  });

  it("scope disi correction detail CORRECTION_SCOPE_DENIED doner", () => {
    const talepId = closeWeekAndCreateTalep({
      haftaBaslangic: "2026-09-15",
      haftaBitis: "2026-09-21",
      etkilenenTarih: "2026-09-16",
      kaynakId: 8808
    });

    const approved = submitAndApprove(talepId);
    const correctionEventId = (approved?.data as { correction_event_id?: number }).correction_event_id;

    const detail = resolveDemoApiResponse(
      `/haftalik-kapanis/revizyon-corrections/${correctionEventId}`,
      {
        headers: demoHeaders(2)
      }
    );

    expect(detail?.errors?.[0]?.code).toBe("CORRECTION_SCOPE_DENIED");

    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse({
          data: null,
          meta: {},
          errors: [
            {
              code: "CORRECTION_SCOPE_DENIED",
              message: "Revizyon correction kapsam disi."
            }
          ]
        }, 403)
      )
    );

    return expect(fetchRevizyonCorrectionDetail(correctionEventId!)).rejects.toMatchObject({
      status: 403
    });
  });

  it("snapshot objesi approve+correction sonrasi degismez", () => {
    const haftaBaslangic = "2026-09-22";
    const haftaBitis = "2026-09-28";

    seedDemoHaftalikMutabakatForClose({ haftaBaslangic, haftaBitis });
    const closed = resolveDemoApiResponse("/haftalik-kapanis", {
      method: "POST",
      body: JSON.stringify({
        hafta_baslangic: haftaBaslangic,
        hafta_bitis: haftaBitis,
        departman_id: 3
      })
    });
    const kapanisId = (closed?.data as { kapanis_id?: number; id?: number })?.kapanis_id;
    const snapshotBefore = JSON.stringify(
      (closed?.data as { snapshot_satirlari?: unknown[] })?.snapshot_satirlari ?? []
    );

    const talepId = closeWeekAndCreateTalep({
      haftaBaslangic,
      haftaBitis,
      etkilenenTarih: "2026-09-23",
      kaynakId: 8809
    });

    submitAndApprove(talepId);

    const detail = resolveDemoApiResponse(`/haftalik-kapanis/${kapanisId}`, {
      method: "GET"
    });
    const snapshotAfter = JSON.stringify(
      (detail?.data as { snapshot_satirlari?: unknown[] })?.snapshot_satirlari ?? []
    );

    expect(snapshotAfter).toBe(snapshotBefore);
  });
});
