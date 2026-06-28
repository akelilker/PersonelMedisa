import { expect, test, type Page } from "@playwright/test";
import { SUBE_DETAIL_REDIRECT_MESSAGE } from "../../src/lib/detail-sube-context";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const users = {
  genelYonetici: { username: "genel_yonetici", password: "demo123" },
  muhasebe: { username: "muhasebe", password: "demo123" },
  birimAmiri: { username: "birim_amiri", password: "demo123" }
};

function trackPageErrors(page: Page) {
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => {
    pageErrors.push(error.message);
  });
  return pageErrors;
}

type PersonelListRequestCapture = {
  headers: Record<string, string>;
  url: string;
};

function trackPersonelListRequests(page: Page): PersonelListRequestCapture[] {
  const captures: PersonelListRequestCapture[] = [];
  page.on("request", (request) => {
    if (request.method() !== "GET" || !request.url().includes("/api/personeller")) {
      return;
    }

    const pathname = new URL(request.url()).pathname;
    if (pathname !== "/api/personeller") {
      return;
    }

    captures.push({
      headers: request.headers(),
      url: request.url()
    });
  });
  return captures;
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

test.describe("sube scope", () => {
  test("birim amiri sube disi personel kartini direct url ile acamaz", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, users.birimAmiri);

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Ayşe Yılmaz/i);

    await page.goto("/personeller/2");
    await expect(page).toHaveURL(/\/(personeller|yetkisiz)$/);
    await expect(page.locator(".personel-dosya-hero")).toHaveCount(0);
    await expect(page.getByRole("link", { name: /Mehmet Kaya.*kişisinin kartını aç/i })).toHaveCount(0);

    if (page.url().endsWith("/personeller")) {
      await expect(page.getByText(SUBE_DETAIL_REDIRECT_MESSAGE)).toBeVisible();
    }

    expect(pageErrors).toEqual([]);
  });

  test("muhasebe active sube degisince personel listesini ve header scopeunu daraltir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    const personelRequests = trackPersonelListRequests(page);

    await mockApi(page, "MUHASEBE");
    await login(page, users.muhasebe);

    await page.goto("/personeller");
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();
    await expect(page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i })).toBeVisible();
    await expect(page.getByRole("link", { name: /Mehmet Kaya.*kişisinin kartını aç/i })).toHaveCount(0);

    const initialScopedRequest = personelRequests.find((item) => {
      const scopedUrl = new URL(item.url);
      const headerScope = item.headers["x-active-sube-id"];
      return headerScope === "1" || scopedUrl.searchParams.get("sube_id") === "1";
    });
    expect(initialScopedRequest).toBeTruthy();

    await switchActiveSubeViaSession(page, 2);
    await page.goto("/personeller");

    await expect(page.getByRole("link", { name: /Mehmet Kaya.*kişisinin kartını aç/i })).toBeVisible({
      timeout: 15_000
    });
    await expect(page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i })).toHaveCount(0);

    const switchedScopedRequest = personelRequests.find((item) => {
      const scopedUrl = new URL(item.url);
      const headerScope = item.headers["x-active-sube-id"];
      return headerScope === "2" || scopedUrl.searchParams.get("sube_id") === "2";
    });
    expect(switchedScopedRequest).toBeTruthy();

    expect(pageErrors).toEqual([]);
  });

  test("genel yonetici sube scope olmadan tum personel listesini gorur", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    await page.goto("/personeller");
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();
    await expect(page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i })).toBeVisible();
    await expect(page.getByRole("link", { name: /Mehmet Kaya.*kişisinin kartını aç/i })).toBeVisible();

    expect(pageErrors).toEqual([]);
  });
});
