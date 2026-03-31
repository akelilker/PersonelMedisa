import { expect, test, type Page, type Route } from "@playwright/test";

type UserRole = "GENEL_YONETICI" | "BOLUM_YONETICISI" | "MUHASEBE" | "BIRIM_AMIRI";

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

async function mockApi(page: Page, role: UserRole) {
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
  }> = [
    {
      id: 701,
      tarih: "2026-04-09",
      departman_id: 3,
      personel_id: 1,
      bildirim_turu: "GEC_GELDI",
      aciklama: "Mevcut bildirim",
      state: "AKTIF"
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

  let surecIdCounter = 600;
  let bildirimIdCounter = 800;
  let finansIdCounter = 950;

  await page.route("**://127.0.0.1:4173/api/**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    const method = request.method();

    if (path === "/api/auth/login" && method === "POST") {
      await fulfillJson(
        route,
        200,
        okBody({
          token: "mock-token",
          ui_profile: role === "BIRIM_AMIRI" ? "birim" : "yonetim",
          user: {
            id: 1,
            ad_soyad: "Mock Kullanici",
            rol: role
          }
        })
      );
      return;
    }

    if (path === "/api/personeller" && method === "GET") {
      await fulfillJson(
        route,
        200,
        okBody({
          items: [
            {
              id: 1,
              tc_kimlik_no: "12345678901",
              ad: "Ayse",
              soyad: "Yilmaz",
              aktif_durum: "AKTIF",
              telefon: "05550000000"
            }
          ]
        })
      );
      return;
    }

    if (path === "/api/personeller/1" && method === "GET") {
      await fulfillJson(
        route,
        200,
        okBody({
          id: 1,
          tc_kimlik_no: "12345678901",
          ad: "Ayse",
          soyad: "Yilmaz",
          aktif_durum: "AKTIF",
          telefon: "05550000000"
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
      await fulfillJson(route, 200, okBody({ items: bildirimler }));
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
      bildirim.bildirim_turu = "IPTAL_EDILDI";
      await fulfillJson(route, 200, okBody({ id: bildirim.id, state: bildirim.state }));
      return;
    }

    if (path.startsWith("/api/referans/") && method === "GET") {
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
  });
}

test.describe("e2e smoke", () => {
  test("management user completes login to kapanis flow", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await page.goto("/login");

    await page.getByLabel("Kullanici Adi").fill("yonetici");
    await page.getByLabel("Sifre").fill("secret");
    await page.getByRole("button", { name: "Giris Yap" }).click();

    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();
    await expect(page.getByText("Ayse Yilmaz")).toBeVisible();

    await page.getByRole("link", { name: "Detay" }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.getByRole("heading", { name: "Personel Detay" })).toBeVisible();

    await page.getByRole("link", { name: "Puantaj", exact: true }).click();
    await expect(page).toHaveURL(/\/puantaj$/);

    await page.getByLabel("Personel ID").fill("1");
    await page.getByLabel("Tarih").fill("2026-04-12");
    await page.getByRole("button", { name: "Kaydi Getir" }).click();

    await expect(page.getByText("HESAPLANDI")).toBeVisible();
    await expect(page.getByText("Net Calisma (dk): 510")).toBeVisible();

    await page.getByLabel("Giris Saati").fill("08:30");
    await page.getByLabel("Cikis Saati").fill("18:00");
    await page.getByLabel("Gercek Mola (dk)").fill("60");
    await page.getByRole("button", { name: "Kaydet" }).click();
    await expect(page.getByText("Gunluk Brut Sure (dk): 570")).toBeVisible();

    await page.getByRole("link", { name: "Haftalik kapanisa git" }).click();
    await expect(page).toHaveURL(/\/haftalik-kapanis$/);

    await page.getByLabel("Hafta Baslangic").fill("2026-04-06");
    await page.getByLabel("Hafta Bitis").fill("2026-04-12");
    await page.getByLabel("Departman ID (Opsiyonel)").fill("3");
    await page.getByRole("button", { name: "Haftayi Kapat" }).click();

    await expect(page.getByText("Durum: KAPANDI")).toBeVisible();
    await expect(page.getByText("Kapanis ID: 99")).toBeVisible();

    await page.getByRole("link", { name: "Raporlar", exact: true }).click();
    await expect(page).toHaveURL(/\/raporlar$/);
    await page.getByRole("button", { name: "Raporu Calistir" }).click();
    await expect(page.getByText("Toplam Kayit: 1")).toBeVisible();
  });

  test("birim amiri remains read-only and cannot access kapanis route", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");

    await page.goto("/login");

    await page.getByLabel("Kullanici Adi").fill("birim");
    await page.getByLabel("Sifre").fill("secret");
    await page.getByRole("button", { name: "Giris Yap" }).click();

    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("button", { name: "Yeni Personel" })).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Haftalik Kapanis" })).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Raporlar", exact: true })).toHaveCount(1);
    await expect(page.getByRole("link", { name: "Finans", exact: true })).toHaveCount(0);

    await page.getByRole("link", { name: "Puantaj", exact: true }).click();
    await expect(page).toHaveURL(/\/puantaj$/);
    await expect(page.getByText("Bu modulu sadece goruntuleme yetkin var.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Kaydet" })).toBeDisabled();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: "Yetkisiz Erisim" })).toBeVisible();

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(page.getByRole("heading", { name: "Raporlar" })).toBeVisible();

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: "Yetkisiz Erisim" })).toBeVisible();
  });

  test("management user can create update and cancel surec bildirim and finans", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await page.goto("/login");
    await page.getByLabel("Kullanici Adi").fill("yonetici");
    await page.getByLabel("Sifre").fill("secret");
    await page.getByRole("button", { name: "Giris Yap" }).click();

    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: "Surecler", exact: true }).click();
    await expect(page).toHaveURL(/\/surecler$/);
    await expect(page.getByRole("heading", { name: "Surec Takibi" })).toBeVisible();

    await page.getByRole("button", { name: "Yeni Surec" }).click();
    const surecCreateModal = page.locator(".modal-container").last();
    await expect(surecCreateModal).toBeVisible();
    await surecCreateModal.getByLabel("Personel ID").fill("1");
    await surecCreateModal.getByLabel("Surec Turu").fill("RAPOR");
    await surecCreateModal.getByLabel("Baslangic Tarihi").fill("2026-04-12");
    await surecCreateModal.getByLabel("Bitis Tarihi").fill("2026-04-12");
    await surecCreateModal.getByLabel("Aciklama").fill("Yeni surec kaydi");
    await surecCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText("RAPOR");

    await page.getByRole("button", { name: "Duzenle" }).first().click();
    const surecEditModal = page.locator(".modal-container").last();
    await expect(surecEditModal).toBeVisible();
    await surecEditModal.getByLabel("Surec Turu").fill("RAPOR_GUNCEL");
    await surecEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText("RAPOR_GUNCEL");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: "Iptal" }).first().click();
    await expect(page.locator(".surecler-list")).toContainText("Durum: IPTAL");

    await page.getByRole("link", { name: "Bildirimler", exact: true }).click();
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.getByRole("heading", { name: "Bildirimler" })).toBeVisible();

    await page.getByRole("button", { name: "Yeni Bildirim" }).click();
    const bildirimCreateModal = page.locator(".modal-container").last();
    await expect(bildirimCreateModal).toBeVisible();
    await bildirimCreateModal.getByLabel("Tarih").fill("2026-04-11");
    await bildirimCreateModal.getByLabel("Departman ID").fill("3");
    await bildirimCreateModal.getByLabel("Personel ID").fill("1");
    await bildirimCreateModal.getByLabel("Bildirim Turu").fill("DEVAMSIZLIK");
    await bildirimCreateModal.getByLabel("Aciklama").fill("Yeni bildirim kaydi");
    await bildirimCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText("DEVAMSIZLIK");

    await page.getByRole("button", { name: "Duzenle" }).first().click();
    const bildirimEditModal = page.locator(".modal-container").last();
    await expect(bildirimEditModal).toBeVisible();
    await bildirimEditModal.getByLabel("Bildirim Turu").fill("RAPORLU");
    await bildirimEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText("RAPORLU");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: "Iptal" }).first().click();
    await expect(page.locator(".bildirimler-list")).toContainText("IPTAL_EDILDI");

    await page.getByRole("link", { name: "Finans", exact: true }).click();
    await expect(page).toHaveURL(/\/finans$/);
    await expect(page.getByRole("heading", { name: "Finans" })).toBeVisible();

    await page.getByRole("button", { name: "Yeni Finans Kalemi" }).click();
    const finansCreateModal = page.locator(".modal-container").last();
    await expect(finansCreateModal).toBeVisible();
    await finansCreateModal.getByLabel("Personel ID").fill("1");
    await finansCreateModal.getByLabel("Donem").fill("2026-04");
    await finansCreateModal.getByLabel("Kalem Turu").fill("PRIM");
    await finansCreateModal.getByLabel("Tutar").fill("1500");
    await finansCreateModal.getByLabel("Aciklama").fill("Yeni finans kalemi");
    await finansCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText("PRIM");

    await page.getByRole("button", { name: "Duzenle" }).first().click();
    const finansEditModal = page.locator(".modal-container").last();
    await expect(finansEditModal).toBeVisible();
    await finansEditModal.getByLabel("Kalem Turu").fill("CEZA");
    await finansEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText("CEZA");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: "Iptal" }).first().click();
    await expect(page.locator(".finans-list")).toContainText("Durum: IPTAL");
  });
});
