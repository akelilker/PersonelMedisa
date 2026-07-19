import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import type { MockUserRole } from "./helpers/mock-api";

const CLOSED_WEEK_START = "2024-01-01";
const CLOSED_WEEK_END = "2024-01-07";

async function openCreateFromKaynakRow(page: Page, personelId: string) {
  await page.goto(
    `/haftalik-kapanis?personel_id=${encodeURIComponent(personelId)}&hafta_baslangic=${CLOSED_WEEK_START}`
  );
  await expect(page.getByTestId("haftalik-kapanis-page")).toBeVisible();
  await page.getByTestId("hk-prefill-personel").selectOption(personelId);
  await page.getByTestId("hk-prefill-hafta").fill(CLOSED_WEEK_START);
  await expect(page.getByTestId("hk-kaynak-tablosu")).toBeVisible({ timeout: 15_000 });
  await page.getByTestId("hk-satir-revizyon-ac-9002").click();
  await expect(page.getByTestId("revizyon-talep-create")).toBeVisible();
  await expect(page.locator("#revizyon-personel")).toHaveValue(personelId);
  await expect(page.locator('input[name="hafta_baslangic"]')).toHaveValue(CLOSED_WEEK_START);
  await expect(page.locator("#revizyon-kaynak")).not.toHaveValue("");
  await expect(page.getByTestId("revizyon-onceki-deger-readonly")).toContainText("Giriş");
  await expect(page.getByTestId("revizyon-onceki-deger-readonly")).not.toContainText("[object Object]");
}

async function fillCreateAndSave(
  page: Page,
  opts: { gerekce: string; yeniDeger: string; submit?: boolean }
) {
  await page.locator("#talep-edilen-deger").fill(opts.yeniDeger);
  await page.locator("#revizyon-gerekce").fill(opts.gerekce);
  if (opts.submit) {
    await page.getByTestId("revizyon-kaydet-gonder").click();
  } else {
    await page.getByTestId("revizyon-taslak-kaydet").click();
  }
  await expect(page.getByTestId("revizyon-talep-detay")).toBeVisible({ timeout: 15_000 });
}

test.describe("S80 Revizyon Merkezi final UI kabul", () => {
  test("Kayıt ve Süreç gateway → Revizyon Merkezi (GY)", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/");
    await page.getByTestId("menu-kayit-surec").click();
    await expect(page.getByTestId("kayit-surec-ops-links")).toBeVisible();
    await expect(page.getByTestId("kayit-surec-revizyon-merkezi-link")).toBeVisible();
    await page.getByTestId("kayit-surec-revizyon-merkezi-link").click();
    await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
  });

  test("BIRIM_AMIRI: create/submit + finans/onay yok + prefill", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await openCreateFromKaynakRow(page, "1");
    await expect(page.getByTestId("revizyon-bordro-etki-alani")).toHaveCount(0);
    await fillCreateAndSave(page, {
      gerekce: "S80 BA taslak",
      yeniDeger: "09:00-18:00",
      submit: false
    });
    await expect(page.getByTestId("revizyon-onayla")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-reddet")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-correction-uret")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-detail-bordro-alani")).toHaveCount(0);
    await page.getByTestId("revizyon-onaya-gonder").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("gönderildi");
  });

  test("BIRIM_AMIRI: iptal akışı", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await openCreateFromKaynakRow(page, "1");
    await fillCreateAndSave(page, {
      gerekce: "S80 BA iptal",
      yeniDeger: "10:00-19:00",
      submit: true
    });
    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByTestId("revizyon-talep-iptal").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("iptal");
    await expect(page.getByTestId("revizyon-onaya-gonder")).toHaveCount(0);
  });

  test("BOLUM_YONETICISI: kendi scope create/submit/iptal, onay yok", async ({ page }) => {
    await loginAsMockRole(page, "BOLUM_YONETICISI");
    await page.goto("/haftalik-kapanis/revizyonlar");
    await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
    await expect(page.getByTestId("revizyon-tab-onay")).toHaveCount(0);
    await openCreateFromKaynakRow(page, "2");
    await fillCreateAndSave(page, {
      gerekce: "S80 Bölüm talep",
      yeniDeger: "08:30-17:30",
      submit: true
    });
    await expect(page.getByTestId("revizyon-onayla")).toHaveCount(0);
    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByTestId("revizyon-talep-iptal").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("iptal");
  });

  test("MUHASEBE: finans görünür, onay/correction yok", async ({ page }) => {
    await loginAsMockRole(page, "MUHASEBE");
    await openCreateFromKaynakRow(page, "1");
    await expect(page.getByTestId("revizyon-bordro-etki-alani")).toBeVisible();
    await fillCreateAndSave(page, {
      gerekce: "S80 Muhasebe talep",
      yeniDeger: "08:00-16:00",
      submit: false
    });
    await expect(page.getByTestId("revizyon-detail-bordro-alani")).toBeVisible();
    await expect(page.getByTestId("revizyon-onayla")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-correction-uret")).toHaveCount(0);
    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByTestId("revizyon-talep-iptal").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("iptal");
  });

  test("GENEL_YONETICI: onay → correction → değer ayrımı → iptal + duplicate 409", async ({
    page
  }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/");
    await expect(page.getByTestId("kayit-surec-revizyon-merkezi-link")).toHaveCount(0);
    await page.getByTestId("menu-kayit-surec").click();
    await page.getByTestId("kayit-surec-revizyon-merkezi-link").click();
    await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
    await expect(page.getByTestId("revizyon-tab-onay")).toBeVisible();

    await openCreateFromKaynakRow(page, "1");
    await fillCreateAndSave(page, {
      gerekce: "S80 GY onay akışı",
      yeniDeger: '{"giris_saati":"09:00","cikis_saati":"18:00"}',
      submit: true
    });
    await page.locator('input[name="karar_notu"]').fill("S80 onay notu");
    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByTestId("revizyon-onayla").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("onay");

    await expect(page.getByTestId("revizyon-deger-ayrimi")).toBeVisible();
    await expect(page.getByTestId("revizyon-ham-deger")).toBeVisible();
    await expect(page.getByTestId("revizyon-talep-deger")).toBeVisible();
    await expect(page.getByTestId("revizyon-corrected-deger")).toContainText("Aktif correction yok");
    await expect(page.getByTestId("revizyon-overlay-uyari")).toContainText("rapor/bordro");
    await expect(page.getByTestId("revizyon-audit-gecmisi")).toBeVisible();

    await page.getByTestId("revizyon-correction-uret").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("Correction");
    await expect(page.getByTestId("revizyon-corrected-deger")).not.toContainText("Aktif correction yok");
    await expect(page.getByTestId("revizyon-correction-uret")).toHaveCount(0);
    await page.getByTestId("revizyon-correction-detay-git").click();
    await expect(page.getByTestId("revizyon-correction-detay")).toBeVisible();
    await page.goBack();
    await expect(page.getByTestId("revizyon-talep-detay")).toBeVisible();

    const talepId = page.url().match(/revizyonlar\/(\d+)/)?.[1];
    expect(talepId).toBeTruthy();
    const dup = await page.evaluate(async (id) => {
      const keys = ["medisa.auth.session.v1", "medisa_auth_session"];
      let raw: string | null = null;
      for (const key of keys) {
        raw = window.sessionStorage.getItem(key) ?? window.localStorage.getItem(key);
        if (raw) break;
      }
      if (!raw) return { status: 0, code: "NO_SESSION" };
      const session = JSON.parse(raw) as { token?: string };
      const res = await fetch(`/api/haftalik-kapanis/revizyon-talepleri/${id}/correction-uret`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          Authorization: `Bearer ${session.token ?? ""}`
        },
        body: "{}"
      });
      const body = (await res.json()) as { errors?: Array<{ code?: string }> };
      return { status: res.status, code: body.errors?.[0]?.code ?? null };
    }, talepId);
    expect(dup.status).toBe(409);
    expect(dup.code).toBe("CORRECTION_ALREADY_EXISTS");

    page.once("dialog", (dialog) => {
      if (dialog.type() === "prompt") {
        void dialog.accept("S80 iptal");
      } else {
        void dialog.accept();
      }
    });
    await page.getByTestId("revizyon-correction-iptal").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("iptal");
  });

  test("GENEL_YONETICI: red + terminal state", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await openCreateFromKaynakRow(page, "1");
    await fillCreateAndSave(page, {
      gerekce: "S80 GY red akışı",
      yeniDeger: "07:00-16:00",
      submit: true
    });
    await page.locator('input[name="karar_notu"]').fill("S80 red gerekçesi");
    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByTestId("revizyon-reddet").click();
    await expect(page.getByTestId("revizyon-action-success")).toContainText("reddedildi");
    await expect(page.getByText("S80 red gerekçesi", { exact: true })).toBeVisible();
    await expect(page.getByTestId("revizyon-onayla")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-reddet")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-correction-uret")).toHaveCount(0);
  });

  test("PATRON: gateway yok + doğrudan route yetkisiz", async ({ page }) => {
    await loginAsMockRole(page, "PATRON");
    await page.goto("/");
    await expect(page.getByTestId("menu-kayit-surec")).toBeDisabled();
    await expect(page.getByTestId("menu-personel-karti")).toBeDisabled();
    await page.goto("/haftalik-kapanis/revizyonlar");
    await expect(page.getByTestId("yetkisiz-page")).toBeVisible();
    await expect(page.getByTestId("revizyon-merkezi-page")).toHaveCount(0);
    await expect(page.getByTestId("kayit-surec-revizyon-merkezi-link")).toHaveCount(0);
  });

  test("server-owned onceki_deger: UI canonical + sahte payload reddedilir", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await openCreateFromKaynakRow(page, "1");
    await expect(page.getByTestId("revizyon-onceki-deger-readonly")).toContainText("server_owned");

    const forged = await page.evaluate(async () => {
      const keys = ["medisa.auth.session.v1", "medisa_auth_session"];
      let raw: string | null = null;
      for (const key of keys) {
        raw = window.sessionStorage.getItem(key) ?? window.localStorage.getItem(key);
        if (raw) break;
      }
      if (!raw) return { status: 0, code: "NO_SESSION" };
      const session = JSON.parse(raw) as { token?: string };
      const res = await fetch("/api/haftalik-kapanis/revizyon-talepleri", {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          Authorization: `Bearer ${session.token ?? ""}`
        },
        body: JSON.stringify({
          personel_id: 1,
          hafta_baslangic: "2024-01-01",
          hafta_bitis: "2024-01-07",
          etkilenen_tarih: "2024-01-01",
          kaynak_tipi: "PUANTAJ",
          kaynak_id: 9002,
          revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
          talep_edilen_deger: "fake",
          gerekce: "S80 forged onceki",
          onceki_deger: { forged: true }
        })
      });
      const body = (await res.json()) as { errors?: Array<{ code?: string; message?: string }> };
      return { status: res.status, code: body.errors?.[0]?.code ?? null, message: body.errors?.[0]?.message ?? null };
    });
    expect(forged.status).toBe(422);
    expect(forged.code).toBe("VALIDATION_ERROR");
    expect(String(forged.message)).toContain("onceki_deger");
  });

  for (const role of [
    "BIRIM_AMIRI",
    "BOLUM_YONETICISI",
    "MUHASEBE",
    "GENEL_YONETICI"
  ] as MockUserRole[]) {
    test(`${role} Revizyon Merkezi erişir`, async ({ page }) => {
      await loginAsMockRole(page, role);
      await page.goto("/haftalik-kapanis/revizyonlar");
      await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
      if (role === "GENEL_YONETICI") {
        await expect(page.getByTestId("revizyon-tab-onay")).toBeVisible();
      } else {
        await expect(page.getByTestId("revizyon-tab-onay")).toHaveCount(0);
      }
    });
  }
});
