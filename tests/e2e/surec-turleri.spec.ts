import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const users = {
  genelYonetici: { username: "genel_yonetici", password: "demo123" },
  muhasebe: { username: "muhasebe", password: "demo123" }
};

type SurecScenario = {
  surecTuru: string;
  altTur: string;
  tarih: string;
  aciklama: string;
  title: RegExp;
};

const scenarios: SurecScenario[] = [
  {
    surecTuru: "IZIN",
    altTur: "YILLIK_IZIN",
    tarih: "2026-06-10",
    aciklama: "S28 izin sureci",
    title: /İzin|Izin/i
  },
  {
    surecTuru: "DEVAMSIZLIK",
    altTur: "IZINSIZ_GELMEDI",
    tarih: "2026-06-11",
    aciklama: "S28 devamsizlik sureci",
    title: /Devamsızlık|Devamsizlik/i
  },
  {
    surecTuru: "TESVIK",
    altTur: "SGK_TESVIK",
    tarih: "2026-06-12",
    aciklama: "S28 tesvik sureci",
    title: /Teşvik|Tesvik/i
  },
  {
    surecTuru: "IS_KAZASI",
    altTur: "IS_KAZASI_BILDIRIMI",
    tarih: "2026-06-13",
    aciklama: "S28 is kazasi sureci",
    title: /İş Kazası|Is Kazasi/i
  },
  {
    surecTuru: "BELGE",
    altTur: "SERTIFIKA",
    tarih: "2026-06-14",
    aciklama: "S28 belge sureci",
    title: /Belge|Sertifika/i
  }
];

function kayitSurecModal(page: Page) {
  return page.locator(".modal-container--kayit-surec, .modal-container").filter({
    has: page.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })
  });
}

async function fetchSurecler(page: Page, params: Record<string, string>) {
  return page.evaluate(async (inputParams) => {
    const searchParams = new URLSearchParams(inputParams);
    const response = await fetch(`/api/surecler?${searchParams.toString()}`);
    const body = await response.json();
    return {
      status: response.status,
      body,
      pathname: window.location.pathname
    };
  }, params);
}

async function openSurecCreateFromPersonelKart(page: Page) {
  await page.goto("/personeller/1");
  await expect(page).toHaveURL(/\/personeller\/1$/);
  await expect(page.locator(".personel-dosya-hero")).toContainText(/Ayşe Yılmaz/i);

  await page.getByRole("button", { name: "Islemler" }).click();
  await page.getByRole("button", { name: "Süreç Ekle" }).click();

  const modal = kayitSurecModal(page);
  await expect(modal).toBeVisible();
  await expect(modal.getByTestId("kayit-tab-surec")).toHaveAttribute("aria-selected", "true");
  await expect(modal.locator("[name='surec-create-personel']")).toHaveValue("1");
  return modal;
}

async function createSurecAndAssertTimeline(page: Page, scenario: SurecScenario) {
  const modal = await openSurecCreateFromPersonelKart(page);

  await modal.locator("[name='surec-create-turu']").selectOption(scenario.surecTuru);
  await modal.locator("[name='surec-create-alt']").fill(scenario.altTur);
  await modal.locator("[name='surec-create-bas']").fill(scenario.tarih);
  await modal.locator("[name='surec-create-bitis']").fill(scenario.tarih);
  await modal.locator("[name='surec-create-aciklama']").fill(scenario.aciklama);

  const postResponse = page.waitForResponse(
    (response) =>
      response.url().includes("/api/surecler") &&
      response.request().method() === "POST" &&
      response.request().postDataJSON()?.surec_turu === scenario.surecTuru
  );
  await modal.getByRole("button", { name: "Süreci Kaydet" }).click();
  expect((await postResponse).status()).toBe(201);
  await expect(modal.locator(".workspace-success")).toContainText(/eklendi/i, { timeout: 15_000 });
  await modal.locator(".universal-btn-cancel").click();

  const listResponse = await fetchSurecler(page, {
    personel_id: "1",
    surec_turu: scenario.surecTuru,
    page: "1",
    limit: "10"
  });
  expect(listResponse.status).toBe(200);
  expect(Array.isArray(listResponse.body.data?.items)).toBe(true);
  expect(listResponse.body.meta?.page).toBe(1);
  expect(listResponse.body.meta?.limit).toBe(10);
  expect(listResponse.body.meta?.total).toBeGreaterThanOrEqual(1);
  expect(listResponse.pathname).not.toBe("/yetkisiz");

  await page.goto("/personeller/1");
  await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
  const timeline = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
  await expect(timeline).toBeVisible();
  await expect(timeline).toContainText(scenario.title);
  await expect(timeline).toContainText(scenario.tarih);
  await expect(timeline).toContainText(scenario.aciklama);
  await expect(page).not.toHaveURL(/\/yetkisiz$/);
}

test.describe("surec turleri create/list/timeline", () => {
  for (const scenario of scenarios) {
    test(`${scenario.surecTuru} create list ve timeline zinciri`, async ({ page }) => {
      await mockApi(page, "GENEL_YONETICI");
      await login(page, users.genelYonetici);

      await createSurecAndAssertTimeline(page, scenario);
    });
  }

  test("unknown surec_turu 422 doner ve yetkisiz redirect tetiklemez", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    const response = await page.evaluate(async () => {
      const result = await fetch("/api/surecler", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          personel_id: 1,
          surec_turu: "BILINMEYEN_TUR",
          baslangic_tarihi: "2026-06-15",
          bitis_tarihi: "2026-06-15"
        })
      });
      const body = await result.json();
      return {
        status: result.status,
        body,
        pathname: window.location.pathname
      };
    });

    expect(response.status).toBe(422);
    expect(response.body.errors?.[0]?.code).toBe("VALIDATION_ERROR");
    expect(response.body.errors?.[0]?.field).toBe("surec_turu");
    expect(response.pathname).not.toBe("/yetkisiz");
  });

  test("scope disi personel surec create 403 doner ve yetkisiz redirect tetiklemez", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, users.muhasebe);

    const response = await page.evaluate(async () => {
      const result = await fetch("/api/surecler", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "x-active-sube-id": "1"
        },
        body: JSON.stringify({
          personel_id: 2,
          surec_turu: "BELGE",
          alt_tur: "SERTIFIKA",
          baslangic_tarihi: "2026-06-16",
          bitis_tarihi: "2026-06-16",
          aciklama: "S28 scope negatif"
        })
      });
      const body = await result.json();
      return {
        status: result.status,
        body,
        pathname: window.location.pathname
      };
    });

    expect(response.status).toBe(403);
    expect(response.body.errors?.[0]?.code).toBe("FORBIDDEN");
    expect(response.pathname).not.toBe("/yetkisiz");
  });
});
