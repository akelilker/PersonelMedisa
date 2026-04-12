import { afterEach, describe, expect, it, vi } from "vitest";
import { fetchGunlukPuantaj, upsertGunlukPuantaj } from "../../src/api/puantaj.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("puantaj.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("returns null when backend has no gunluk puantaj data", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => createJsonResponse({ data: null, meta: {}, errors: [] }, 200))
    );

    const result = await fetchGunlukPuantaj(42, "2026-04-12");
    expect(result).toBeNull();
  });

  it("normalizes alternate response fields into GunlukPuantaj shape", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              personelId: 7,
              date: "2026-04-15",
              giris: "08:10",
              cikis: "18:05",
              gunluk_mola_dusumu: 60,
              gunluk_net_calisma_suresi: 475,
              state: "HESAPLANDI",
              uyarilar: [
                {
                  kod: "MAX_DAILY_LIMIT",
                  mesaj: "Gunluk calisma suresi kritik esikte."
                }
              ]
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchGunlukPuantaj(7, "2026-04-15");

    expect(result).toEqual({
      personel_id: 7,
      tarih: "2026-04-15",
      gun_tipi: "Normal_Is_Gunu",
      hareket_durumu: "Geldi",
      dayanak: undefined,
      hesap_etkisi: "Tam_Yevmiye_Ver",
      giris_saati: "08:10",
      cikis_saati: "18:05",
      gercek_mola_dakika: undefined,
      hesaplanan_mola_dakika: 60,
      net_calisma_suresi_dakika: 475,
      gunluk_brut_sure_dakika: undefined,
      hafta_tatili_hak_kazandi_mi: true,
      state: "HESAPLANDI",
      compliance_uyarilari: [
        {
          code: "MAX_DAILY_LIMIT",
          message: "Gunluk calisma suresi kritik esikte.",
          level: undefined
        }
      ]
    });
  });

  it("sends PUT request to upsert endpoint and returns normalized payload", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            personel_id: 12,
            tarih: "2026-04-20",
            giris_saati: "09:00",
            cikis_saati: "18:00",
            gercek_mola_dakika: 45,
            compliance_uyarilari: []
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await upsertGunlukPuantaj(12, "2026-04-20", {
      giris_saati: "09:00",
      cikis_saati: "18:00",
      gercek_mola_dakika: 45
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/gunluk-puantaj/12/2026-04-20");
    expect(init.method).toBe("PUT");
    expect(init.body).toBe(
      JSON.stringify({
        giris_saati: "09:00",
        cikis_saati: "18:00",
        gercek_mola_dakika: 45
      })
    );
    expect(result.personel_id).toBe(12);
    expect(result.tarih).toBe("2026-04-20");
  });
});
