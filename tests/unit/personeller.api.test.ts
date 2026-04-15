import { afterEach, describe, expect, it, vi } from "vitest";
import { fetchPersonelDetail } from "../../src/api/personeller.api";

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
});
