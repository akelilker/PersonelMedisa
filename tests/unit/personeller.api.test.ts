import { afterEach, describe, expect, it, vi } from "vitest";
import {
  fetchPersonelDetail,
  fetchPersonellerList,
  updatePersonel
} from "../../src/api/personeller.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("personeller.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("sends the raw search text as a single query param and forwards the abort signal", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        { data: { items: [] }, meta: { page: 1, limit: 10, total: 0, total_pages: 1 }, errors: [] },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const controller = new AbortController();
    await fetchPersonellerList({
      search: "İlker Akel",
      calisan_kapsami: "DIS_KAYNAK",
      page: 1,
      limit: 10,
      signal: controller.signal
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    // One unified search param — the backend owns tokenization.
    expect(url).toContain("search=%C4%B0lker+Akel");
    expect(url).toContain("calisan_kapsami=DIS_KAYNAK");
    expect(url).not.toContain("search_ad");
    expect(init?.signal).toBeInstanceOf(AbortSignal);
  });

  it("normalizes nested personel detail payload into flat Personel shape", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            ana_kart: {
              id: 12,
              tc_kimlik_no: "12345678901",
              ad: "Ayse",
              soyad: "Yilmaz",
              aktif_durum: "AKTIF",
              telefon: "05550000000",
              dogum_tarihi: "1992-03-14",
              sicil_no: "P-001",
              dogum_yeri: "Istanbul",
              kan_grubu: "A Rh+",
              ise_giris_tarihi: "2023-02-01",
              acil_durum_kisi: "Fatma Yilmaz",
              acil_durum_telefon: "05553334455",
              departman_id: 3,
              gorev_id: 1,
              personel_tipi_id: 2,
              bagli_amir_id: 44
            },
            sistem_ozeti: {
              hizmet_suresi: "3 yil 2 ay",
              toplam_izin_hakki: 14,
              kullanilan_izin: 4,
              kalan_izin: 10
            },
            pasiflik_durumu: {
              aktif_durum: "AKTIF",
              etiket: null
            },
            referans_adlari: {
              departman: "Döşeme",
              gorev: "Genel Müdür",
              personel_tipi: "Tam Zamanli",
              bagli_amir: "Demo Amir"
            }
          },
          meta: {},
          errors: []
        },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchPersonelDetail(12);

    const [url] = fetchMock.mock.calls[0] as [string];
    expect(url).toBe("/api/personeller/12");
    expect(result).toMatchObject({
      id: 12,
      tc_kimlik_no: "12345678901",
      ad: "Ayse",
      soyad: "Yilmaz",
      aktif_durum: "AKTIF",
      telefon: "05550000000",
      dogum_tarihi: "1992-03-14",
      sicil_no: "P-001",
      dogum_yeri: "Istanbul",
      kan_grubu: "A Rh+",
      ise_giris_tarihi: "2023-02-01",
      acil_durum_kisi: "Fatma Yilmaz",
      acil_durum_telefon: "05553334455",
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 2,
      bagli_amir_id: 44,
      departman_adi: "Döşeme",
      gorev_adi: "Genel Müdür",
      personel_tipi_adi: "Tam Zamanli",
      bagli_amir_adi: "Demo Amir",
      hizmet_suresi: "3 yil 2 ay",
      toplam_izin_hakki: 14,
      kullanilan_izin: 4,
      kalan_izin: 10,
      pasiflik_durumu_etiketi: null
    });
  });

  it("keeps flat personel detail payload support for legacy responses", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              id: 7,
              tc_kimlik_no: "98765432109",
              ad: "Mehmet",
              soyad: "Kaya",
              aktif_durum: "PASIF",
              telefon: "05551111111"
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchPersonelDetail(7);

    expect(result).toMatchObject({
      id: 7,
      tc_kimlik_no: "98765432109",
      ad: "Mehmet",
      soyad: "Kaya",
      aktif_durum: "PASIF",
      telefon: "05551111111"
    });
  });

  it("updates personel with PUT and normalizes response", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            ana_kart: {
              id: 12,
              tc_kimlik_no: "12345678901",
              ad: "Yeni",
              soyad: "Personel",
              aktif_durum: "AKTIF",
              telefon: "05550001122"
            },
            sistem_ozeti: {
              hizmet_suresi: "3 yil 2 ay",
              toplam_izin_hakki: 14,
              kullanilan_izin: 4,
              kalan_izin: 10
            },
            pasiflik_durumu: {
              aktif_durum: "AKTIF",
              etiket: null
            },
            referans_adlari: {
              departman: "Döşeme",
              gorev: "Genel Müdür"
            }
          },
          meta: {},
          errors: []
        },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await updatePersonel(12, {
      ad: "Yeni",
      soyad: "Personel",
      telefon: "05550001122"
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/personeller/12");
    expect(init.method).toBe("PUT");
    expect(JSON.parse(String(init.body))).toMatchObject({
      ad: "Yeni",
      soyad: "Personel",
      telefon: "05550001122"
    });
    expect(result).toMatchObject({
      id: 12,
      ad: "Yeni",
      soyad: "Personel",
      telefon: "05550001122",
      aktif_durum: "AKTIF",
      tc_kimlik_no: "12345678901"
    });
  });

  it("keeps empty PUT payload as an explicit empty object", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            ana_kart: {
              id: 12,
              tc_kimlik_no: "12345678901",
              ad: "Ayse",
              soyad: "Yilmaz",
              aktif_durum: "AKTIF"
            }
          },
          meta: {},
          errors: []
        },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await updatePersonel(12, {});

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/personeller/12");
    expect(init.method).toBe("PUT");
    expect(init.body).toBe("{}");
    expect(result).toMatchObject({
      id: 12,
      ad: "Ayse",
      soyad: "Yilmaz",
      aktif_durum: "AKTIF",
      tc_kimlik_no: "12345678901"
    });
  });

  it("dual-reads legacy maas_tutari into net_maas_tutari", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              id: 3,
              tc_kimlik_no: "12345678901",
              ad: "Test",
              soyad: "User",
              aktif_durum: "AKTIF",
              maas_tutari: 35000
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchPersonelDetail(3);

    expect(result.net_maas_tutari).toBe(35000);
    expect(result.maas_tutari).toBe(35000);
    expect(result.brut_maas_tutari).toBeUndefined();
  });

  it("dual-reads net_maas_tutari into legacy maas_tutari", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              id: 5,
              tc_kimlik_no: "12345678901",
              ad: "Test",
              soyad: "User",
              aktif_durum: "AKTIF",
              net_maas_tutari: 28000
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchPersonelDetail(5);

    expect(result.net_maas_tutari).toBe(28000);
    expect(result.maas_tutari).toBe(28000);
  });

  it("keeps distinct values when both maas fields are present", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              id: 6,
              tc_kimlik_no: "12345678901",
              ad: "Test",
              soyad: "User",
              aktif_durum: "AKTIF",
              net_maas_tutari: 30000,
              maas_tutari: 32000
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchPersonelDetail(6);

    expect(result.net_maas_tutari).toBe(30000);
    expect(result.maas_tutari).toBe(32000);
  });

  it("passes through optional brut fields when present in response", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: {
              id: 8,
              tc_kimlik_no: "12345678901",
              ad: "Test",
              soyad: "User",
              aktif_durum: "AKTIF",
              net_maas_tutari: 40000,
              brut_maas_tutari: null,
              brut_hesaplama_modeli: null
            },
            meta: {},
            errors: []
          },
          200
        )
      )
    );

    const result = await fetchPersonelDetail(8);

    expect(result.net_maas_tutari).toBe(40000);
    expect(result.maas_tutari).toBe(40000);
    expect(result.brut_maas_tutari).toBeNull();
    expect(result.brut_hesaplama_modeli).toBeNull();
  });
});
