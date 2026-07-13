import { afterEach, describe, expect, it, vi } from "vitest";
import { fetchGunlukPuantaj, muhurleAylikPuantaj, upsertGunlukPuantaj } from "../../src/api/puantaj.api";

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
      durumu_bildirdi_mi: undefined,
      durum_bildirim_aciklamasi: undefined,
      hesap_etkisi: "Tam_Yevmiye_Ver",
      beklenen_giris_saati: undefined,
      beklenen_cikis_saati: undefined,
      giris_saati: "08:10",
      cikis_saati: "18:05",
      gercek_mola_dakika: undefined,
      hesaplanan_mola_dakika: 60,
      net_calisma_suresi_dakika: 475,
      gunluk_brut_sure_dakika: undefined,
      hafta_tatili_hak_kazandi_mi: true,
      state: "HESAPLANDI",
      kontrol_durumu: "BEKLIYOR",
      compliance_uyarilari: [
        {
          code: "MAX_DAILY_LIMIT",
          message: "Gunluk calisma suresi kritik esikte.",
          level: undefined
        }
      ]
    });
  });

  it("normalizes beklenen_giris_saati and beklenen_cikis_saati when present", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              personel_id: 8,
              tarih: "2026-04-16",
              beklenen_giris_saati: "08:00",
              beklenen_cikis_saati: "17:00",
              compliance_uyarilari: []
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchGunlukPuantaj(8, "2026-04-16");
    expect(result?.beklenen_giris_saati).toBe("08:00");
    expect(result?.beklenen_cikis_saati).toBe("17:00");
  });

  it("normalizes gec_kalma_dakika and erken_cikis_dakika from snake_case or camelCase", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              personel_id: 9,
              tarih: "2026-04-17",
              gecKalmaDakika: 18,
              early_leave_minutes: 7,
              compliance_uyarilari: []
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchGunlukPuantaj(9, "2026-04-17");
    expect(result?.gec_kalma_dakika).toBe(18);
    expect(result?.erken_cikis_dakika).toBe(7);
  });

  it("normalizes kontrol_durumu from snake_case or camelCase", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              personel_id: 3,
              tarih: "2026-05-01",
              kontrolDurumu: "AMIR_KONTROL_ETTI",
              compliance_uyarilari: []
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchGunlukPuantaj(3, "2026-05-01");
    expect(result?.kontrol_durumu).toBe("AMIR_KONTROL_ETTI");
  });

  it("sends PUT request to upsert endpoint and returns normalized payload", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
            data: {
              personel_id: 12,
              tarih: "2026-04-20",
              beklenen_giris_saati: "08:00",
              beklenen_cikis_saati: "17:30",
              durumu_bildirdi_mi: true,
              durum_bildirim_aciklamasi: "Telefonla haber verdi.",
              giris_saati: "09:00",
              cikis_saati: "18:00",
              gercek_mola_dakika: 45,
              kontrol_durumu: "AMIR_KONTROL_ETTI",
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
      beklenen_giris_saati: "08:00",
      beklenen_cikis_saati: "17:30",
      durumu_bildirdi_mi: true,
      durum_bildirim_aciklamasi: "Telefonla haber verdi.",
      giris_saati: "09:00",
      cikis_saati: "18:00",
      gercek_mola_dakika: 45,
      kontrol_durumu: "AMIR_KONTROL_ETTI"
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/gunluk-puantaj/12/2026-04-20");
    expect(init.method).toBe("PUT");
    expect(init.body).toBe(
      JSON.stringify({
        beklenen_giris_saati: "08:00",
        beklenen_cikis_saati: "17:30",
        durumu_bildirdi_mi: true,
        durum_bildirim_aciklamasi: "Telefonla haber verdi.",
        giris_saati: "09:00",
        cikis_saati: "18:00",
        gercek_mola_dakika: 45,
        kontrol_durumu: "AMIR_KONTROL_ETTI"
      })
    );
    expect(result.personel_id).toBe(12);
    expect(result.tarih).toBe("2026-04-20");
    expect(result.beklenen_giris_saati).toBe("08:00");
    expect(result.beklenen_cikis_saati).toBe("17:30");
    expect(result.durumu_bildirdi_mi).toBe(true);
    expect(result.durum_bildirim_aciklamasi).toBe("Telefonla haber verdi.");
    expect(result.kontrol_durumu).toBe("AMIR_KONTROL_ETTI");
  });

  it("normalizes Raporlu_Hastalik + Gelmedi without hesap_etkisi", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              personel_id: 21,
              tarih: "2026-04-22",
              hareket_durumu: "Gelmedi",
              dayanak: "Raporlu_Hastalik",
              compliance_uyarilari: []
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchGunlukPuantaj(21, "2026-04-22");
    expect(result?.dayanak).toBe("Raporlu_Hastalik");
    expect(result?.hesap_etkisi).toBeUndefined();
  });

  it("normalizes Raporlu_Is_Kazasi + Gelmedi without hesap_etkisi", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              personel_id: 22,
              tarih: "2026-04-23",
              hareket_durumu: "Gelmedi",
              dayanak: "Raporlu_Is_Kazasi",
              compliance_uyarilari: []
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchGunlukPuantaj(22, "2026-04-23");
    expect(result?.dayanak).toBe("Raporlu_Is_Kazasi");
    expect(result?.hesap_etkisi).toBeUndefined();
  });

  it("normalizes Pazar rapor + saat without Mesai_Yaz hesap_etkisi", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              personel_id: 23,
              tarih: "2026-04-12",
              gun_tipi: "Hafta_Tatili_Pazar",
              hareket_durumu: "Geldi",
              dayanak: "Raporlu_Hastalik",
              giris_saati: "08:00",
              cikis_saati: "17:00",
              compliance_uyarilari: []
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchGunlukPuantaj(23, "2026-04-12");
    expect(result?.hesap_etkisi).toBeUndefined();
    expect(result?.hesap_etkisi).not.toBe("Mesai_Yaz");
  });

  it("normalizes UBGT rapor + saat without Mesai_Yaz hesap_etkisi", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              personel_id: 24,
              tarih: "2026-04-23",
              gun_tipi: "UBGT_Resmi_Tatil",
              hareket_durumu: "Geldi",
              dayanak: "Raporlu_Hastalik",
              giris_saati: "08:00",
              compliance_uyarilari: []
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchGunlukPuantaj(24, "2026-04-23");
    expect(result?.hesap_etkisi).toBeUndefined();
    expect(result?.hesap_etkisi).not.toBe("Mesai_Yaz");
  });

  it("ignores explicit Tam_Yevmiye_Ver when rapor dayanak is present", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              personel_id: 25,
              tarih: "2026-04-24",
              hareket_durumu: "Gelmedi",
              dayanak: "Raporlu_Hastalik",
              hesap_etkisi: "Tam_Yevmiye_Ver",
              compliance_uyarilari: []
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchGunlukPuantaj(25, "2026-04-24");
    expect(result?.hesap_etkisi).toBeUndefined();
  });

  it("keeps existing behavior when beklenen saat fields are absent", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            personel_id: 13,
            tarih: "2026-04-21",
            giris_saati: "08:45",
            cikis_saati: "18:15",
            compliance_uyarilari: []
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await upsertGunlukPuantaj(13, "2026-04-21", {
      giris_saati: "08:45",
      cikis_saati: "18:15"
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/gunluk-puantaj/13/2026-04-21");
    expect(init.body).toBe(
      JSON.stringify({
        giris_saati: "08:45",
        cikis_saati: "18:15"
      })
    );
    expect(result.beklenen_giris_saati).toBeUndefined();
    expect(result.beklenen_cikis_saati).toBeUndefined();
  });

  it("sends dakika fields in upsert payload when provided", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            personel_id: 14,
            tarih: "2026-04-22",
            gec_kalma_dakika: 25,
            erken_cikis_dakika: 0,
            compliance_uyarilari: []
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await upsertGunlukPuantaj(14, "2026-04-22", {
      gec_kalma_dakika: 25,
      erken_cikis_dakika: 0
    });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.body).toBe(
      JSON.stringify({
        gec_kalma_dakika: 25,
        erken_cikis_dakika: 0
      })
    );
    expect(result.gec_kalma_dakika).toBe(25);
    expect(result.erken_cikis_dakika).toBe(0);
  });

  it("sends POST request to aylik puantaj muhurle endpoint", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            muhur_id: 4,
            sube_id: 2,
            yil: 2026,
            ay: 4,
            donem: "2026-04",
            durum: "MUHURLENDI",
            muhurlenen_kayit_sayisi: 18
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await muhurleAylikPuantaj({ yil: 2026, ay: 4 });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/puantaj/muhurle");
    expect(init.method).toBe("POST");
    expect(init.body).toBe(JSON.stringify({ yil: 2026, ay: 4 }));
    expect(result).toMatchObject({
      muhur_id: 4,
      donem: "2026-04",
      muhurlenen_kayit_sayisi: 18
    });
  });
});
