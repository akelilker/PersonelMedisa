import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yoneticisi", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

const SEED_PERSONEL_SUBE_1 = "1";
const SEED_PERSONEL_SUBE_2 = "2";
const SEED_TARIH = "2026-04-09";
const SEED_DONEM = "2026-04";

function trackPageErrors(page: Page) {
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => {
    pageErrors.push(error.message);
  });
  return pageErrors;
}

async function switchActiveSubeViaSession(page: Page, subeId: number) {
  await page.evaluate((nextSubeId) => {
    const key = "medisa_auth_session";
    const fromSession = sessionStorage.getItem(key);
    const storage = fromSession ? sessionStorage : localStorage;
    const raw = fromSession ?? localStorage.getItem(key);
    if (!raw) {
      throw new Error("auth session missing");
    }

    const session = JSON.parse(raw) as { active_sube_id?: number | null };
    session.active_sube_id = nextSubeId;
    storage.setItem(key, JSON.stringify(session));
  }, subeId);
  await page.reload({ waitUntil: "domcontentloaded" });
}

async function openPuantajRecord(page: Page, personelId: string, tarih: string = SEED_TARIH) {
  await page.getByLabel("Personel ID").fill(personelId);
  await page.getByLabel("Tarih").fill(tarih);
  await page.getByRole("button", { name: /Kayd.*Getir/i }).click();
}

type RequestCapture = {
  headers: Record<string, string>;
  url: string;
};

function trackMuhurleRequests(page: Page): RequestCapture[] {
  const captures: RequestCapture[] = [];
  page.on("request", (request) => {
    if (request.method() !== "POST" || !request.url().includes("/api/puantaj/muhurle")) {
      return;
    }

    captures.push({
      headers: request.headers(),
      url: request.url()
    });
  });
  return captures;
}

function trackRaporDetailRequests(page: Page): RequestCapture[] {
  const captures: RequestCapture[] = [];
  page.on("request", (request) => {
    if (request.method() !== "GET") {
      return;
    }

    const pathname = new URL(request.url()).pathname;
    if (!pathname.startsWith("/api/raporlar/") || pathname === "/api/raporlar/aylik-ozet") {
      return;
    }

    captures.push({
      headers: request.headers(),
      url: request.url()
    });
  });
  return captures;
}

async function runIzinReport(page: Page) {
  await page.locator('[name="rapor-turu"]').selectOption("izin");
  await page.locator('[name="rapor-bas"]').fill("2026-04-01");
  await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
  await page.getByTestId("raporlar-submit-run").click();
  const resultCard = page.getByTestId("raporlar-resmi-sonuc");
  await expect(resultCard).toBeVisible();
  return resultCard;
}

async function runBildirimReport(page: Page) {
  await page.locator('[name="rapor-turu"]').selectOption("bildirim");
  await page.locator('[name="rapor-bas"]').fill("2026-04-01");
  await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
  await page.getByTestId("raporlar-submit-run").click();
  const resultCard = page.getByTestId("raporlar-resmi-sonuc");
  await expect(resultCard).toBeVisible();
  return resultCard;
}

async function runIsKazasiReport(page: Page) {
  await page.locator('[name="rapor-turu"]').selectOption("is-kazasi");
  await page.locator('[name="rapor-bas"]').fill("2026-04-01");
  await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
  await page.getByTestId("raporlar-submit-run").click();
  const resultCard = page.getByTestId("raporlar-resmi-sonuc");
  await expect(resultCard).toBeVisible();
  return resultCard;
}

async function runTesvikReport(page: Page) {
  await page.locator('[name="rapor-turu"]').selectOption("tesvik");
  await page.locator('[name="rapor-bas"]').fill("2026-04-01");
  await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
  await page.getByTestId("raporlar-submit-run").click();
  const resultCard = page.getByTestId("raporlar-resmi-sonuc");
  await expect(resultCard).toBeVisible();
  return resultCard;
}

async function runCezaReport(page: Page) {
  await page.locator('[name="rapor-turu"]').selectOption("ceza");
  await page.locator('[name="rapor-bas"]').fill("2026-04-01");
  await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
  await page.getByTestId("raporlar-submit-run").click();
  const resultCard = page.getByTestId("raporlar-resmi-sonuc");
  await expect(resultCard).toBeVisible();
  return resultCard;
}

async function runEkstraPrimReport(page: Page) {
  await page.locator('[name="rapor-turu"]').selectOption("ekstra-prim");
  await page.locator('[name="rapor-bas"]').fill("2026-04-01");
  await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
  await page.getByTestId("raporlar-submit-run").click();
  const resultCard = page.getByTestId("raporlar-resmi-sonuc");
  await expect(resultCard).toBeVisible();
  return resultCard;
}

async function runDevamsizlikReport(page: Page) {
  await page.locator('[name="rapor-turu"]').selectOption("devamsizlik");
  await page.locator('[name="rapor-bas"]').fill("2026-04-01");
  await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
  await page.getByTestId("raporlar-submit-run").click();
  const resultCard = page.getByTestId("raporlar-resmi-sonuc");
  await expect(resultCard).toBeVisible();
  return resultCard;
}

async function runPersonelOzetReport(page: Page) {
  await page.locator('[name="rapor-turu"]').selectOption("personel-ozet");
  await page.getByTestId("raporlar-submit-run").click();
  const resultCard = page.getByTestId("raporlar-resmi-sonuc");
  await expect(resultCard).toBeVisible();
  return resultCard;
}

test.describe("puantaj rapor sube scope", () => {
  test("puantaj muhurleme active sube scope ile yalniz ilgili subeyi etkiler", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    const muhurRequests = trackMuhurleRequests(page);

    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, ROLE_LOGIN.BOLUM_YONETICISI);
    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);

    await openPuantajRecord(page, SEED_PERSONEL_SUBE_2);
    await expect(page.getByTestId("puantaj-ana-detay")).toBeVisible();
    await expect(page.getByTestId("puantaj-kaydet")).toBeEnabled();
    await expect(page.getByTestId("muhur-uyari")).toHaveCount(0);

    await page.getByTestId("muhur-ay-kapat-btn").click();
    await expect(page.getByTestId("muhur-modal")).toBeVisible();
    await page.locator("[name='muhur-donem']").fill(SEED_DONEM);
    await page.getByTestId("muhur-onayla-btn").click();

    await expect(page.getByTestId("muhur-sonuc")).toBeVisible();
    await expect(page.getByTestId("muhur-sonuc")).toContainText(SEED_DONEM);
    await expect(page.getByTestId("muhur-sonuc")).toContainText("1 kayıt mühürlendi");

    const scopedRequest = muhurRequests.find((item) => {
      const scopedUrl = new URL(item.url);
      const headerScope = item.headers["x-active-sube-id"];
      return headerScope === "2" || scopedUrl.searchParams.get("sube_id") === "2";
    });
    expect(scopedRequest).toBeTruthy();

    await page.getByRole("button", { name: "Vazgeç" }).click();
    await expect(page.getByTestId("muhur-uyari")).toBeVisible();
    await expect(page.getByTestId("puantaj-kaydet")).toBeDisabled();

    expect(pageErrors).toEqual([]);
  });

  test("birim amiri sube disi personel puantajini acamaz", async ({ page }) => {
    const pageErrors = trackPageErrors(page);

    await mockApi(page, "BIRIM_AMIRI");
    await login(page, ROLE_LOGIN.BIRIM_AMIRI);
    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);

    await openPuantajRecord(page, SEED_PERSONEL_SUBE_1);
    await expect(page.getByTestId("puantaj-ana-detay")).toBeVisible();

    await openPuantajRecord(page, SEED_PERSONEL_SUBE_2);
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: "Yetkisiz Erişim" })).toBeVisible();
    await expect(page.getByTestId("puantaj-ana-detay")).toHaveCount(0);

    expect(pageErrors).toEqual([]);
  });

  test("detayli rapor active sube scope ile satirlari daraltir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    const raporRequests = trackRaporDetailRequests(page);

    await mockApi(page, "MUHASEBE");
    await login(page, ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const initialResult = await runPersonelOzetReport(page);
    await expect(initialResult.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(initialResult.locator("tbody")).not.toContainText("Mehmet Kaya");

    await switchActiveSubeViaSession(page, 2);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const switchedResult = await runPersonelOzetReport(page);
    await expect(switchedResult.locator("tbody")).toContainText("Mehmet Kaya");
    await expect(switchedResult.locator("tbody")).not.toContainText("Ayşe Yılmaz");

    const switchedScopedRequest = raporRequests.find((item) => {
      const scopedUrl = new URL(item.url);
      const headerScope = item.headers["x-active-sube-id"];
      return headerScope === "2" || scopedUrl.searchParams.get("sube_id") === "2";
    });
    expect(switchedScopedRequest).toBeTruthy();

    expect(pageErrors).toEqual([]);
  });

  test("devamsizlik raporu active sube scope ile satirlari daraltir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);

    await mockApi(page, "MUHASEBE");
    await login(page, ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const initialResult = await runDevamsizlikReport(page);
    await expect(initialResult.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(initialResult.locator("tbody")).not.toContainText("Mehmet Kaya");

    await switchActiveSubeViaSession(page, 2);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const switchedResult = await runDevamsizlikReport(page);
    await expect(switchedResult.locator("tbody")).toContainText("Mehmet Kaya");
    await expect(switchedResult.locator("tbody")).not.toContainText("Ayşe Yılmaz");

    expect(pageErrors).toEqual([]);
  });

  test("izin raporu active sube scope ile satirlari daraltir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);

    await mockApi(page, "MUHASEBE");
    await login(page, ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const initialResult = await runIzinReport(page);
    await expect(initialResult.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(initialResult.locator("tbody")).not.toContainText("Mehmet Kaya");

    await switchActiveSubeViaSession(page, 2);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const switchedResult = await runIzinReport(page);
    await expect(switchedResult.locator("tbody")).toContainText("Mehmet Kaya");
    await expect(switchedResult.locator("tbody")).not.toContainText("Ayşe Yılmaz");

    expect(pageErrors).toEqual([]);
  });

  test("bildirim raporu active sube scope ile satirlari daraltir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);

    await mockApi(page, "MUHASEBE");
    await login(page, ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const initialResult = await runBildirimReport(page);
    await expect(initialResult.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(initialResult.locator("tbody")).not.toContainText("Mehmet Kaya");

    await switchActiveSubeViaSession(page, 2);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const switchedResult = await runBildirimReport(page);
    await expect(switchedResult.locator("tbody")).toContainText("Mehmet Kaya");
    await expect(switchedResult.locator("tbody")).not.toContainText("Ayşe Yılmaz");

    expect(pageErrors).toEqual([]);
  });

  test("is-kazasi raporu active sube scope ile satirlari daraltir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);

    await mockApi(page, "MUHASEBE");
    await login(page, ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const initialResult = await runIsKazasiReport(page);
    await expect(initialResult.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(initialResult.locator("tbody")).not.toContainText("Mehmet Kaya");

    await switchActiveSubeViaSession(page, 2);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const switchedResult = await runIsKazasiReport(page);
    await expect(switchedResult.locator("tbody")).toContainText("Mehmet Kaya");
    await expect(switchedResult.locator("tbody")).not.toContainText("Ayşe Yılmaz");

    expect(pageErrors).toEqual([]);
  });

  test("tesvik raporu active sube scope ile satirlari daraltir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);

    await mockApi(page, "MUHASEBE");
    await login(page, ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const initialResult = await runTesvikReport(page);
    await expect(initialResult.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(initialResult.locator("tbody")).not.toContainText("Mehmet Kaya");

    await switchActiveSubeViaSession(page, 2);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const switchedResult = await runTesvikReport(page);
    await expect(switchedResult.locator("tbody")).toContainText("Mehmet Kaya");
    await expect(switchedResult.locator("tbody")).not.toContainText("Ayşe Yılmaz");

    expect(pageErrors).toEqual([]);
  });

  test("ceza raporu active sube scope ile satirlari daraltir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);

    await mockApi(page, "MUHASEBE");
    await login(page, ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const initialResult = await runCezaReport(page);
    await expect(initialResult.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(initialResult.locator("tbody")).not.toContainText("Mehmet Kaya");

    await switchActiveSubeViaSession(page, 2);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const switchedResult = await runCezaReport(page);
    await expect(switchedResult.locator("tbody")).toContainText("Mehmet Kaya");
    await expect(switchedResult.locator("tbody")).not.toContainText("Ayşe Yılmaz");

    expect(pageErrors).toEqual([]);
  });

  test("ekstra-prim raporu active sube scope ile satirlari daraltir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);

    await mockApi(page, "MUHASEBE");
    await login(page, ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const initialResult = await runEkstraPrimReport(page);
    await expect(initialResult.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(initialResult.locator("tbody")).not.toContainText("Mehmet Kaya");

    await switchActiveSubeViaSession(page, 2);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const switchedResult = await runEkstraPrimReport(page);
    await expect(switchedResult.locator("tbody")).toContainText("Mehmet Kaya");
    await expect(switchedResult.locator("tbody")).not.toContainText("Ayşe Yılmaz");

    expect(pageErrors).toEqual([]);
  });

  test("genel yonetici detayli raporda sube scope olmadan tum veriyi gorur", async ({ page }) => {
    const pageErrors = trackPageErrors(page);

    await mockApi(page, "GENEL_YONETICI");
    await login(page, ROLE_LOGIN.GENEL_YONETICI);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const firstPageResult = await runPersonelOzetReport(page);
    await expect(firstPageResult.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(firstPageResult.locator("tbody")).not.toContainText("Mehmet Kaya");

    await page.getByRole("button", { name: "Sonraki" }).click();

    const secondPageResult = page.getByTestId("raporlar-resmi-sonuc");
    await expect(secondPageResult.locator("tbody")).toContainText("Mehmet Kaya");
    await expect(secondPageResult.locator("tbody")).not.toContainText("Ayşe Yılmaz");

    expect(pageErrors).toEqual([]);
  });
});
