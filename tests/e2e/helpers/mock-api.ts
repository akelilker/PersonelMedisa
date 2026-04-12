import type { Page, Route } from "@playwright/test";

export type MockUserRole = "GENEL_YONETICI" | "BOLUM_YONETICISI" | "MUHASEBE" | "BIRIM_AMIRI";

function okBody(data: unknown) {
  return JSON.stringify({
    data,
    meta: {},
    errors: []
  });
}

function errorBody(code: string, message: string) {
  return JSON.stringify({
    data: null,
    meta: {},
    errors: [{ code, message }]
  });
}

async function fulfillJson(route: Route, status: number, body: string) {
  await route.fulfill({
    status,
    contentType: "application/json",
    body
  });
}

export async function mockApi(page: Page, role: MockUserRole) {
  const personeller: Array<{
    id: number;
    tc_kimlik_no: string;
    ad: string;
    soyad: string;
    aktif_durum: "AKTIF" | "PASIF";
    sube_id?: number;
    telefon?: string;
    dogum_tarihi?: string;
    dogum_yeri?: string;
    kan_grubu?: string;
    sicil_no?: string;
    ise_giris_tarihi?: string;
    acil_durum_kisi?: string;
    acil_durum_telefon?: string;
    departman_id?: number;
    gorev_id?: number;
    personel_tipi_id?: number;
    bagli_amir_id?: number;
    sube_adi?: string;
    departman_adi?: string;
    gorev_adi?: string;
    personel_tipi_adi?: string;
    bagli_amir_adi?: string;
  }> = [
    {
      id: 1,
      tc_kimlik_no: "12345678901",
      ad: "Ayse",
      soyad: "Yilmaz",
      aktif_durum: "AKTIF",
      sube_id: 1,
      telefon: "05550000000",
      dogum_tarihi: "1992-03-14",
      dogum_yeri: "Istanbul",
      kan_grubu: "A Rh+",
      sicil_no: "P-001",
      ise_giris_tarihi: "2023-02-01",
      acil_durum_kisi: "Fatma Yilmaz",
      acil_durum_telefon: "05553334455",
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 1,
      bagli_amir_id: 9,
      sube_adi: "Merkez",
      departman_adi: "Atölye",
      gorev_adi: "Uzman",
      personel_tipi_adi: "Tam Zamanli",
      bagli_amir_adi: "Demo Amir"
    },
    {
      id: 2,
      tc_kimlik_no: "23456789012",
      ad: "Mehmet",
      soyad: "Kaya",
      aktif_durum: "AKTIF",
      sube_id: 2,
      telefon: "05551111111",
      dogum_tarihi: "1989-11-02",
      dogum_yeri: "Ankara",
      kan_grubu: "0 Rh+",
      sicil_no: "P-002",
      ise_giris_tarihi: "2024-07-15",
      acil_durum_kisi: "Zeynep Kaya",
      acil_durum_telefon: "05556667788",
      departman_id: 1,
      gorev_id: 2,
      personel_tipi_id: 2,
      bagli_amir_id: 9,
      sube_adi: "Depolama",
      departman_adi: "Depo",
      gorev_adi: "Sef",
      personel_tipi_adi: "Yari Zamanli",
      bagli_amir_adi: "Demo Amir"
    }
  ];

  const surecler: Array<{
    id: number;
    personel_id: number;
    surec_turu: string;
    alt_tur?: string;
    baslangic_tarihi: string;
    bitis_tarihi: string;
    ucretli_mi?: boolean;
    aciklama?: string;
    state: string;
  }> = [
    {
      id: 501,
      personel_id: 1,
      surec_turu: "IZIN",
      alt_tur: "YILLIK_IZIN",
      baslangic_tarihi: "2026-04-10",
      bitis_tarihi: "2026-04-11",
      ucretli_mi: true,
      aciklama: "Mevcut surec",
      state: "AKTIF"
    }
  ];

  const bildirimler: Array<{
    id: number;
    tarih: string;
    departman_id: number;
    personel_id: number;
    bildirim_turu: string;
    aciklama?: string;
    state: string;
    okundu_mi?: boolean;
  }> = [
    {
      id: 701,
      tarih: "2026-04-09",
      departman_id: 3,
      personel_id: 1,
      bildirim_turu: "GEC_GELDI",
      aciklama: "Mevcut bildirim",
      state: "AKTIF",
      okundu_mi: false
    }
  ];

  const finansKalemleri: Array<{
    id: number;
    personel_id: number;
    donem: string;
    kalem_turu: string;
    tutar: number;
    aciklama?: string;
    state: string;
  }> = [
    {
      id: 901,
      personel_id: 1,
      donem: "2026-04",
      kalem_turu: "AVANS",
      tutar: 2500,
      aciklama: "Mevcut finans kalemi",
      state: "AKTIF"
    }
  ];

  const departmanOptions: Array<{ id: number; ad: string }> = [
    { id: 1, ad: "Depo" },
    { id: 2, ad: "Döşeme" },
    { id: 3, ad: "Atölye" },
    { id: 4, ad: "Finans" }
  ];

  const subeler: Array<{
    id: number;
    kod: string;
    ad: string;
    departman_ids: number[];
    departman_adlari: string[];
    durum: "AKTIF" | "PASIF";
  }> = [
    {
      id: 1,
      kod: "MRK",
      ad: "Merkez",
      departman_ids: [3, 4],
      departman_adlari: ["Atölye", "Finans"],
      durum: "AKTIF"
    },
    {
      id: 2,
      kod: "DPL",
      ad: "Depolama",
      departman_ids: [1],
      departman_adlari: ["Depo"],
      durum: "AKTIF"
    }
  ];

  const makineler: Array<{
    id: number;
    ad: string;
    tip: string;
    konum?: string | null;
    durum: "aktif" | "arizali" | "pasif";
    sube_id: number;
    son_bakim?: string | null;
    bakim_periyot_gun?: number | null;
  }> = [
    {
      id: 1101,
      ad: "Kesim Robotu",
      tip: "Kesim",
      konum: "Atolye A",
      durum: "aktif",
      sube_id: 1,
      son_bakim: "2026-04-01",
      bakim_periyot_gun: 30
    },
    {
      id: 1102,
      ad: "Forklift 02",
      tip: "Tasima",
      konum: "Depo Giris",
      durum: "aktif",
      sube_id: 2,
      son_bakim: "2026-02-10",
      bakim_periyot_gun: 30
    },
    {
      id: 1103,
      ad: "Pres Hatti",
      tip: "Pres",
      konum: "Atolye B",
      durum: "aktif",
      sube_id: 1,
      son_bakim: null,
      bakim_periyot_gun: 45
    }
  ];

  const yonetimKullanicilari: Array<{
    id: number;
    ad_soyad: string;
    telefon?: string;
    kullanici_tipi: "IC_PERSONEL" | "HARICI";
    rol: MockUserRole;
    personel_id: number | null;
    sube_ids: number[];
    varsayilan_sube_id: number | null;
    durum: "AKTIF" | "PASIF";
    notlar?: string;
  }> = [
    {
      id: 1,
      ad_soyad: "Ilker Akel",
      telefon: "05550000001",
      kullanici_tipi: "HARICI",
      rol: "GENEL_YONETICI",
      personel_id: null,
      sube_ids: [],
      varsayilan_sube_id: null,
      durum: "AKTIF",
      notlar: "Tum yapiyi yonetir"
    },
    {
      id: 2,
      ad_soyad: "Adnan Bulut",
      telefon: "05550000002",
      kullanici_tipi: "HARICI",
      rol: "BOLUM_YONETICISI",
      personel_id: null,
      sube_ids: [2],
      varsayilan_sube_id: 2,
      durum: "AKTIF",
      notlar: "Depolama kapsaminda bolum onayi verir"
    },
    {
      id: 3,
      ad_soyad: "Serhan Kose",
      telefon: "05550000003",
      kullanici_tipi: "IC_PERSONEL",
      rol: "BIRIM_AMIRI",
      personel_id: 1,
      sube_ids: [1],
      varsayilan_sube_id: 1,
      durum: "AKTIF",
      notlar: "Gunluk bildirimleri girer"
    }
  ];

  const aylikOzetRows: Array<{
    ay: string;
    personel_id: number;
    ad_soyad: string;
    sicil_no?: string;
    sube_id: number;
    sube: string;
    departman_id: number;
    bolum: string;
    birim_amiri: string;
    devamsizlik_gun: number;
    gec_kalma_adet: number;
    izinli_gelmedi: number;
    izinsiz_gelmedi: number;
    raporlu: number;
    tesvik_tutari: number;
    ceza_kesinti_tutari: number;
    bolum_onay_durumu: "BOLUM_ONAYINDA" | "BOLUM_ONAYLANDI" | "REVIZE_ISTENDI" | "KAPANDI";
    revize_var_mi: boolean;
    son_islem: string;
    kapanis_durumu: "ACIK" | "KAPANDI";
  }> = [
    {
      ay: "2026-04",
      personel_id: 1,
      ad_soyad: "Ayse Yilmaz",
      sicil_no: "P-001",
      sube_id: 1,
      sube: "Merkez",
      departman_id: 3,
      bolum: "Atölye",
      birim_amiri: "Serhan Kose",
      devamsizlik_gun: 0,
      gec_kalma_adet: 1,
      izinli_gelmedi: 0,
      izinsiz_gelmedi: 0,
      raporlu: 0,
      tesvik_tutari: 1200,
      ceza_kesinti_tutari: 0,
      bolum_onay_durumu: "BOLUM_ONAYINDA",
      revize_var_mi: false,
      son_islem: "Birim amiri gunluk kayitlari hazirladi",
      kapanis_durumu: "ACIK"
    },
    {
      ay: "2026-04",
      personel_id: 2,
      ad_soyad: "Mehmet Kaya",
      sicil_no: "P-002",
      sube_id: 2,
      sube: "Depolama",
      departman_id: 1,
      bolum: "Depo",
      birim_amiri: "Serhan Kose",
      devamsizlik_gun: 1,
      gec_kalma_adet: 0,
      izinli_gelmedi: 1,
      izinsiz_gelmedi: 1,
      raporlu: 0,
      tesvik_tutari: 0,
      ceza_kesinti_tutari: 450,
      bolum_onay_durumu: "REVIZE_ISTENDI",
      revize_var_mi: true,
      son_islem: "Bolum yoneticisi revize istedi",
      kapanis_durumu: "ACIK"
    }
  ];

  let surecIdCounter = 600;
  let bildirimIdCounter = 800;
  let finansIdCounter = 950;
  let kullaniciIdCounter = 3;
  let subeIdCounter = 2;
  let departmanIdCounter = 4;

  function getDepartmanLabel(id: number) {
    return departmanOptions.find((item) => item.id === id)?.ad ?? `Departman ${id}`;
  }

  function normalizeSubePayload(payload: { kod: string; ad: string; departman_ids?: number[]; durum: "AKTIF" | "PASIF" }) {
    const departmanIds = payload.departman_ids ?? [];
    return {
      kod: payload.kod,
      ad: payload.ad,
      departman_ids: departmanIds,
      departman_adlari: departmanIds.map((id) => getDepartmanLabel(id)),
      durum: payload.durum
    };
  }

  function buildAylikOzetResponse(searchUrl: URL) {
    const ay = urlValue(searchUrl.searchParams.get("ay")) ?? "2026-04";
    const subeId = numberValue(searchUrl.searchParams.get("sube_id"));
    const departmanId = numberValue(searchUrl.searchParams.get("departman_id"));
    const sadeceRevizeli = searchUrl.searchParams.get("sadece_revizeli") === "true";

    const items = aylikOzetRows.filter((item) => {
      if (item.ay !== ay) {
        return false;
      }
      if (Number.isFinite(subeId) && item.sube_id !== subeId) {
        return false;
      }
      if (Number.isFinite(departmanId) && item.departman_id !== departmanId) {
        return false;
      }
      if (sadeceRevizeli && !item.revize_var_mi) {
        return false;
      }
      return true;
    });

    const pendingBolumOnayi = items.filter(
      (item) => item.kapanis_durumu !== "KAPANDI" && item.bolum_onay_durumu !== "BOLUM_ONAYLANDI"
    ).length;

    const state =
      items.length > 0 && items.every((item) => item.kapanis_durumu === "KAPANDI")
        ? "KAPANDI"
        : items.some((item) => item.bolum_onay_durumu === "REVIZE_ISTENDI")
          ? "REVIZE_ISTENDI"
          : pendingBolumOnayi === 0
            ? "BOLUM_ONAYLANDI"
            : "BOLUM_ONAYINDA";

    return {
      ay,
      state,
      summary: {
        toplam_personel: items.length,
        toplam_devamsizlik_gun: items.reduce((acc, item) => acc + item.devamsizlik_gun, 0),
        toplam_gec_kalma: items.reduce((acc, item) => acc + item.gec_kalma_adet, 0),
        toplam_izinli_gelmedi: items.reduce((acc, item) => acc + item.izinli_gelmedi, 0),
        toplam_izinsiz_gelmedi: items.reduce((acc, item) => acc + item.izinsiz_gelmedi, 0),
        toplam_raporlu: items.reduce((acc, item) => acc + item.raporlu, 0),
        toplam_tesvik_tutari: items.reduce((acc, item) => acc + item.tesvik_tutari, 0),
        toplam_ceza_kesinti_tutari: items.reduce((acc, item) => acc + item.ceza_kesinti_tutari, 0)
      },
      items,
      pending_bolum_onayi: pendingBolumOnayi
    };
  }

  function urlValue(value: string | null) {
    return value && value.trim() ? value : null;
  }

  function numberValue(value: string | null) {
    const parsed = Number.parseInt(value ?? "", 10);
    return Number.isFinite(parsed) ? parsed : null;
  }

  await page.route(
    (testUrl) => {
      try {
        return new URL(testUrl).pathname.startsWith("/api/");
      } catch {
        return false;
      }
    },
    async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    const method = request.method();

    if (path === "/api/auth/login" && method === "POST") {
      const subeIds =
        role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : role === "BOLUM_YONETICISI" ? [2] : [];
      await fulfillJson(
        route,
        200,
        okBody({
          token: "mock-token",
          ui_profile: role === "BIRIM_AMIRI" ? "birim_amiri" : "yonetim",
          sube_list: subeIds.map((id) => ({ id, ad: subeler.find((item) => item.id === id)?.ad ?? `Sube ${id}` })),
          user: {
            id: 1,
            ad_soyad: "Mock Kullanici",
            rol: role,
            sube_ids: subeIds
          }
        })
      );
      return;
    }

    if (path === "/api/personeller" && method === "GET") {
      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const search = (url.searchParams.get("search") ?? "").toLowerCase();
      const departmanId = Number.parseInt(url.searchParams.get("departman_id") ?? "", 10);
      const personelTipiId = Number.parseInt(url.searchParams.get("personel_tipi_id") ?? "", 10);
      const aktiflik = url.searchParams.get("aktiflik") ?? "tum";

      const filtered = personeller.filter((item) => {
        if (aktiflik === "aktif" && item.aktif_durum !== "AKTIF") {
          return false;
        }
        if (aktiflik === "pasif" && item.aktif_durum !== "PASIF") {
          return false;
        }
        if (Number.isFinite(departmanId) && item.departman_id !== departmanId) {
          return false;
        }
        if (Number.isFinite(personelTipiId) && item.personel_tipi_id !== personelTipiId) {
          return false;
        }
        if (!search) {
          return true;
        }

        const fullText = `${item.ad} ${item.soyad} ${item.tc_kimlik_no}`.toLowerCase();
        return fullText.includes(search);
      });

      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit);

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path === "/api/isg/makineler" && method === "GET") {
      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const search = (url.searchParams.get("search") ?? "").toLowerCase();
      const durum = (url.searchParams.get("durum") ?? "tum").toLowerCase();
      const tip = (url.searchParams.get("tip") ?? "").toLowerCase();
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);

      const filtered = makineler.filter((item) => {
        if (Number.isFinite(subeId) && item.sube_id !== subeId) {
          return false;
        }
        if (durum !== "tum" && item.durum !== durum) {
          return false;
        }
        if (tip && !item.tip.toLowerCase().includes(tip)) {
          return false;
        }
        if (!search) {
          return true;
        }

        const fullText = `${item.ad} ${item.tip} ${item.konum ?? ""}`.toLowerCase();
        return fullText.includes(search);
      });

      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit).map((item) => ({
        ...item,
        referans_adlari: {
          sube: subeler.find((sube) => sube.id === item.sube_id)?.ad ?? "-"
        }
      }));

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path === "/api/personeller/1" && method === "GET") {
      await fulfillJson(
        route,
        200,
        okBody({
          ana_kart: {
            id: 1,
            tc_kimlik_no: "12345678901",
            ad: "Ayse",
            soyad: "Yilmaz",
            aktif_durum: "AKTIF",
            sube_id: 1,
            telefon: "05550000000",
            dogum_tarihi: "1992-03-14",
            dogum_yeri: "Istanbul",
            kan_grubu: "A Rh+",
            sicil_no: "P-001",
            ise_giris_tarihi: "2023-02-01",
            acil_durum_kisi: "Fatma Yilmaz",
            acil_durum_telefon: "05553334455",
            departman_id: 3,
            gorev_id: 1,
            personel_tipi_id: 1,
            bagli_amir_id: 9
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
            sube: "Merkez",
            departman: "Atölye",
            gorev: "Uzman",
            personel_tipi: "Tam Zamanli",
            bagli_amir: "Demo Amir"
          }
        })
      );
      return;
    }

    if (path === "/api/surecler" && method === "GET") {
      await fulfillJson(route, 200, okBody({ items: surecler }));
      return;
    }

    if (path === "/api/surecler" && method === "POST") {
      const payload = request.postDataJSON() as {
        personel_id: number;
        surec_turu: string;
        alt_tur?: string;
        baslangic_tarihi: string;
        bitis_tarihi: string;
        ucretli_mi?: boolean;
        aciklama?: string;
      };

      const created = {
        id: ++surecIdCounter,
        personel_id: payload.personel_id,
        surec_turu: payload.surec_turu,
        alt_tur: payload.alt_tur,
        baslangic_tarihi: payload.baslangic_tarihi,
        bitis_tarihi: payload.bitis_tarihi,
        ucretli_mi: payload.ucretli_mi,
        aciklama: payload.aciklama,
        state: "AKTIF"
      };
      surecler.unshift(created);

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/surecler\/\d+$/) && method === "GET") {
      const surecId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const surec = surecler.find((item) => item.id === surecId);
      if (!surec) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Surec bulunamadi."));
        return;
      }

      await fulfillJson(route, 200, okBody(surec));
      return;
    }

    if (path.match(/^\/api\/surecler\/\d+$/) && method === "PUT") {
      const surecId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const surec = surecler.find((item) => item.id === surecId);
      if (!surec) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Surec bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<typeof surec>;
      Object.assign(surec, payload);

      await fulfillJson(route, 200, okBody(surec));
      return;
    }

    if (path.match(/^\/api\/surecler\/\d+\/iptal$/) && method === "POST") {
      const surecId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const surec = surecler.find((item) => item.id === surecId);
      if (!surec) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Surec bulunamadi."));
        return;
      }

      surec.state = "IPTAL";
      await fulfillJson(route, 200, okBody({ id: surec.id, state: surec.state }));
      return;
    }

    if (path === "/api/bildirimler" && method === "GET") {
      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const tarih = url.searchParams.get("tarih");
      const personelId = Number.parseInt(url.searchParams.get("personel_id") ?? "", 10);
      const bildirimTuru = url.searchParams.get("bildirim_turu");

      const filtered = bildirimler.filter((item) => {
        if (tarih && item.tarih !== tarih) {
          return false;
        }
        if (Number.isFinite(personelId) && item.personel_id !== personelId) {
          return false;
        }
        if (bildirimTuru && item.bildirim_turu !== bildirimTuru) {
          return false;
        }
        return true;
      });

      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit);

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path === "/api/bildirimler" && method === "POST") {
      const payload = request.postDataJSON() as {
        tarih: string;
        departman_id: number;
        personel_id: number;
        bildirim_turu: string;
        aciklama?: string;
      };

      const created = {
        id: ++bildirimIdCounter,
        tarih: payload.tarih,
        departman_id: payload.departman_id,
        personel_id: payload.personel_id,
        bildirim_turu: payload.bildirim_turu,
        aciklama: payload.aciklama,
        state: "AKTIF"
      };
      bildirimler.unshift(created);

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+$/) && method === "GET") {
      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      await fulfillJson(route, 200, okBody(bildirim));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+$/) && method === "PUT") {
      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<typeof bildirim>;
      Object.assign(bildirim, payload);

      await fulfillJson(route, 200, okBody(bildirim));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+\/iptal$/) && method === "POST") {
      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      bildirim.state = "IPTAL";
      await fulfillJson(route, 200, okBody({ id: bildirim.id, state: bildirim.state }));
      return;
    }

    if (path === "/api/referans/departmanlar" && method === "POST") {
      const payload = request.postDataJSON() as { ad?: string };
      const ad = (payload.ad ?? "").trim();
      if (!ad) {
        await fulfillJson(route, 400, errorBody("DEPARTMAN_NAME_REQUIRED", "Departman adı zorunludur."));
        return;
      }

      const existing = departmanOptions.find((item) => item.ad.toLocaleLowerCase("tr-TR") === ad.toLocaleLowerCase("tr-TR"));
      if (existing) {
        await fulfillJson(route, 200, okBody(existing));
        return;
      }

      const created = {
        id: ++departmanIdCounter,
        ad
      };
      departmanOptions.push(created);
      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.startsWith("/api/referans/") && method === "GET") {
      if (path === "/api/referans/departmanlar") {
        await fulfillJson(route, 200, okBody(departmanOptions));
        return;
      }

      if (path === "/api/referans/gorevler") {
        await fulfillJson(
          route,
          200,
          okBody([
            { id: 1, ad: "Uzman" },
            { id: 2, ad: "Sef" },
            { id: 3, ad: "Mudur" }
          ])
        );
        return;
      }

      if (path === "/api/referans/personel-tipleri") {
        await fulfillJson(
          route,
          200,
          okBody([
            { id: 1, ad: "Tam Zamanli" },
            { id: 2, ad: "Yari Zamanli" }
          ])
        );
        return;
      }

      if (path === "/api/referans/bagli-amirler") {
        await fulfillJson(route, 200, okBody([{ id: 9, ad: "Demo Amir" }]));
        return;
      }

      if (path === "/api/referans/bildirim-turleri") {
        await fulfillJson(
          route,
          200,
          okBody([
            { key: "GEC_GELDI", label: "Gec Geldi" },
            { key: "GELMEDI", label: "Gelmedi" },
            { key: "IZINLI_GELMEDI", label: "Izinli Gelmedi" },
            { key: "IZINSIZ_GELMEDI", label: "Izinsiz Gelmedi" },
            { key: "DEVAMSIZLIK", label: "Devamsizlik" },
            { key: "RAPORLU", label: "Raporlu" }
          ])
        );
        return;
      }

      await fulfillJson(route, 200, okBody([]));
      return;
    }

    if (path.startsWith("/api/gunluk-puantaj/") && method === "GET") {
      const segments = path.split("/");
      const personelId = Number.parseInt(segments[3] ?? "0", 10);
      const tarih = decodeURIComponent(segments[4] ?? "");

      await fulfillJson(
        route,
        200,
        okBody({
          personel_id: personelId,
          tarih,
          giris_saati: "08:30",
          cikis_saati: "18:00",
          gercek_mola_dakika: 60,
          hesaplanan_mola_dakika: 60,
          net_calisma_suresi_dakika: 510,
          gunluk_brut_sure_dakika: 570,
          state: "HESAPLANDI",
          compliance_uyarilari: []
        })
      );
      return;
    }

    if (path.startsWith("/api/gunluk-puantaj/") && method === "PUT") {
      const segments = path.split("/");
      const personelId = Number.parseInt(segments[3] ?? "0", 10);
      const tarih = decodeURIComponent(segments[4] ?? "");
      const payload = request.postDataJSON() as {
        giris_saati?: string;
        cikis_saati?: string;
        gercek_mola_dakika?: number;
      };

      await fulfillJson(
        route,
        200,
        okBody({
          personel_id: personelId,
          tarih,
          giris_saati: payload.giris_saati ?? "08:30",
          cikis_saati: payload.cikis_saati ?? "18:00",
          gercek_mola_dakika: payload.gercek_mola_dakika ?? 60,
          hesaplanan_mola_dakika: payload.gercek_mola_dakika ?? 60,
          net_calisma_suresi_dakika: 510,
          gunluk_brut_sure_dakika: 570,
          state: "HESAPLANDI",
          compliance_uyarilari: []
        })
      );
      return;
    }

    if (path === "/api/haftalik-kapanis" && method === "POST") {
      const payload = request.postDataJSON() as {
        hafta_baslangic?: string;
        hafta_bitis?: string;
        departman_id?: number;
      };

      await fulfillJson(
        route,
        200,
        okBody({
          id: 99,
          hafta_baslangic: payload.hafta_baslangic ?? "2026-04-06",
          hafta_bitis: payload.hafta_bitis ?? "2026-04-12",
          departman_id: payload.departman_id ?? 3,
          state: "KAPANDI",
          personel_sayisi: 24
        })
      );
      return;
    }

    if (path === "/api/yonetim/kullanicilar" && method === "GET") {
      await fulfillJson(
        route,
        200,
        okBody({
          items: yonetimKullanicilari.map((item) => ({
            ...item,
            personel_ad_soyad:
              item.personel_id != null
                ? personeller.find((personel) => personel.id === item.personel_id)
                  ? `${personeller.find((personel) => personel.id === item.personel_id)?.ad} ${
                      personeller.find((personel) => personel.id === item.personel_id)?.soyad
                    }`
                  : null
                : null
          }))
        })
      );
      return;
    }

    if (path === "/api/yonetim/kullanicilar" && method === "POST") {
      const payload = request.postDataJSON() as {
        ad_soyad: string;
        telefon?: string;
        kullanici_tipi: "IC_PERSONEL" | "HARICI";
        rol: MockUserRole;
        personel_id?: number | null;
        sube_ids?: number[];
        varsayilan_sube_id?: number | null;
        durum: "AKTIF" | "PASIF";
        notlar?: string;
      };

      const linkedPersonel =
        payload.personel_id != null ? personeller.find((item) => item.id === payload.personel_id) ?? null : null;

      const created = {
        id: ++kullaniciIdCounter,
        ad_soyad: payload.ad_soyad,
        telefon: payload.telefon,
        kullanici_tipi: payload.kullanici_tipi,
        rol: payload.rol,
        personel_id: payload.personel_id ?? null,
        sube_ids: payload.sube_ids ?? [],
        varsayilan_sube_id: payload.varsayilan_sube_id ?? null,
        durum: payload.durum,
        notlar: payload.notlar
      };

      yonetimKullanicilari.unshift(created);

      await fulfillJson(
        route,
        200,
        okBody({
          ...created,
          personel_ad_soyad: linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : null
        })
      );
      return;
    }

    if (path.match(/^\/api\/yonetim\/kullanicilar\/\d+$/) && method === "PUT") {
      const kullaniciId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const target = yonetimKullanicilari.find((item) => item.id === kullaniciId);
      if (!target) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Kullanici bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as {
        ad_soyad?: string;
        telefon?: string;
        kullanici_tipi?: "IC_PERSONEL" | "HARICI";
        rol?: MockUserRole;
        personel_id?: number | null;
        sube_ids?: number[];
        varsayilan_sube_id?: number | null;
        durum?: "AKTIF" | "PASIF";
        notlar?: string;
      };
      const linkedPersonel =
        payload.personel_id != null ? personeller.find((item) => item.id === payload.personel_id) ?? null : null;

      Object.assign(target, {
        ad_soyad: payload.ad_soyad ?? target.ad_soyad,
        telefon: payload.telefon ?? target.telefon,
        kullanici_tipi: payload.kullanici_tipi ?? target.kullanici_tipi,
        rol: payload.rol ?? target.rol,
        personel_id: payload.personel_id ?? null,
        sube_ids: payload.sube_ids ?? target.sube_ids,
        varsayilan_sube_id: payload.varsayilan_sube_id ?? target.varsayilan_sube_id,
        durum: payload.durum ?? target.durum,
        notlar: payload.notlar ?? target.notlar
      });

      await fulfillJson(
        route,
        200,
        okBody({
          ...target,
          personel_ad_soyad: linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : null
        })
      );
      return;
    }

    if (path === "/api/yonetim/subeler" && method === "GET") {
      await fulfillJson(route, 200, okBody({ items: subeler }));
      return;
    }

    if (path === "/api/yonetim/subeler" && method === "POST") {
      const payload = request.postDataJSON() as {
        kod: string;
        ad: string;
        departman_ids?: number[];
        durum: "AKTIF" | "PASIF";
      };

      const created = {
        id: ++subeIdCounter,
        ...normalizeSubePayload(payload)
      };

      subeler.unshift(created);
      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/yonetim\/subeler\/\d+$/) && method === "PUT") {
      const subeId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const target = subeler.find((item) => item.id === subeId);
      if (!target) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Sube bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<(typeof subeler)[number]>;
      Object.assign(target, {
        ...(payload.kod ? { kod: payload.kod } : {}),
        ...(payload.ad ? { ad: payload.ad } : {}),
        ...(payload.departman_ids ? {
          departman_ids: payload.departman_ids,
          departman_adlari: payload.departman_ids.map((id) => getDepartmanLabel(id))
        } : {}),
        ...(payload.durum ? { durum: payload.durum } : {})
      });
      await fulfillJson(route, 200, okBody(target));
      return;
    }

    if (path === "/api/yonetim/aylik-ozet" && method === "GET") {
      await fulfillJson(route, 200, okBody(buildAylikOzetResponse(url)));
      return;
    }

    if (path === "/api/yonetim/aylik-ozet/bolum-onay" && method === "POST") {
      const payload = request.postDataJSON() as {
        ay?: string;
        sube_id?: number;
        departman_id?: number;
      };

      aylikOzetRows.forEach((item) => {
        if (item.ay !== (payload.ay ?? "2026-04")) {
          return;
        }
        if (payload.sube_id != null && item.sube_id !== payload.sube_id) {
          return;
        }
        if (payload.departman_id != null && item.departman_id !== payload.departman_id) {
          return;
        }
        if (item.kapanis_durumu === "KAPANDI") {
          return;
        }

        item.bolum_onay_durumu = "BOLUM_ONAYLANDI";
        item.revize_var_mi = false;
        item.son_islem = "Bolum yoneticisi toplu onay verdi";
      });

      const responseUrl = new URL(url.toString());
      if (payload.ay) {
        responseUrl.searchParams.set("ay", payload.ay);
      }
      if (payload.sube_id != null) {
        responseUrl.searchParams.set("sube_id", String(payload.sube_id));
      }
      if (payload.departman_id != null) {
        responseUrl.searchParams.set("departman_id", String(payload.departman_id));
      }
      await fulfillJson(route, 200, okBody(buildAylikOzetResponse(responseUrl)));
      return;
    }

    if (path === "/api/yonetim/aylik-ozet/ay-kapat" && method === "POST") {
      const payload = request.postDataJSON() as {
        ay?: string;
        sube_id?: number;
        departman_id?: number;
      };

      const responseUrl = new URL(url.toString());
      if (payload.ay) {
        responseUrl.searchParams.set("ay", payload.ay);
      }
      if (payload.sube_id != null) {
        responseUrl.searchParams.set("sube_id", String(payload.sube_id));
      }
      if (payload.departman_id != null) {
        responseUrl.searchParams.set("departman_id", String(payload.departman_id));
      }

      const current = buildAylikOzetResponse(responseUrl);
      if (current.pending_bolum_onayi > 0) {
        await fulfillJson(
          route,
          400,
          errorBody("PENDING_BOLUM_APPROVAL", "Bolum onayi bekleyen kayitlar var. Ay kapatilamadi.")
        );
        return;
      }

      aylikOzetRows.forEach((item) => {
        if (item.ay !== (payload.ay ?? "2026-04")) {
          return;
        }
        if (payload.sube_id != null && item.sube_id !== payload.sube_id) {
          return;
        }
        if (payload.departman_id != null && item.departman_id !== payload.departman_id) {
          return;
        }

        item.kapanis_durumu = "KAPANDI";
        item.bolum_onay_durumu = "KAPANDI";
        item.son_islem = "Genel yonetici ayi kapatti";
      });

      await fulfillJson(route, 200, okBody(buildAylikOzetResponse(responseUrl)));
      return;
    }

    if (path.startsWith("/api/raporlar/") && method === "GET") {
      if (path === "/api/raporlar/personel-ozet") {
        await fulfillJson(
          route,
          200,
          okBody({
            items: [
              {
                personel_id: 1,
                ad_soyad: "Ayse Yilmaz",
                net_calisma_dakika: 510
              }
            ]
          })
        );
        return;
      }

      await fulfillJson(route, 200, okBody({ items: [] }));
      return;
    }

    if (path === "/api/ek-odeme-kesinti" && method === "GET") {
      await fulfillJson(route, 200, okBody({ items: finansKalemleri }));
      return;
    }

    if (path === "/api/ek-odeme-kesinti" && method === "POST") {
      const payload = request.postDataJSON() as {
        personel_id: number;
        donem: string;
        kalem_turu: string;
        tutar: number;
        aciklama?: string;
      };

      const created = {
        id: ++finansIdCounter,
        personel_id: payload.personel_id,
        donem: payload.donem,
        kalem_turu: payload.kalem_turu,
        tutar: payload.tutar,
        aciklama: payload.aciklama,
        state: "AKTIF"
      };
      finansKalemleri.unshift(created);

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/ek-odeme-kesinti\/\d+$/) && method === "PUT") {
      const kalemId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const kalem = finansKalemleri.find((item) => item.id === kalemId);
      if (!kalem) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Finans kalemi bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<typeof kalem>;
      Object.assign(kalem, payload);

      await fulfillJson(route, 200, okBody(kalem));
      return;
    }

    if (path.match(/^\/api\/ek-odeme-kesinti\/\d+\/iptal$/) && method === "POST") {
      const kalemId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const kalem = finansKalemleri.find((item) => item.id === kalemId);
      if (!kalem) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Finans kalemi bulunamadi."));
        return;
      }

      kalem.state = "IPTAL";
      await fulfillJson(route, 200, okBody({ id: kalem.id, state: kalem.state }));
      return;
    }

    await fulfillJson(route, 404, errorBody("NOT_MOCKED", `${method} ${path}`));
    }
  );
}
