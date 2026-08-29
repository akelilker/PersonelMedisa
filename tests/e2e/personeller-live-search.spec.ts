import { expect, test, type Page, type Request } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

/** Search values of every personel list GET the page issued, in order. */
function trackListRequests(page: Page): { searches: (string | null)[]; all: string[] } {
  const searches: (string | null)[] = [];
  const all: string[] = [];
  page.on("request", (request: Request) => {
    const url = new URL(request.url(), "http://localhost");
    if (request.method() !== "GET" || !url.pathname.endsWith("/api/personeller")) {
      return;
    }
    all.push(url.search);
    searches.push(url.searchParams.get("search"));
  });
  return { searches, all };
}

function trackConsoleErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on("console", (message) => {
    if (message.type() === "error") {
      errors.push(message.text());
    }
  });
  page.on("pageerror", (error) => errors.push(String(error)));
  return errors;
}

async function openPersonellerSearch(page: Page) {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "yonetici", password: "secret" });
  await page.goto("/personeller");
  await page.getByRole("button", { name: "Arama aç" }).click();
  await expect(page.getByTestId("personeller-search-input")).toBeVisible();
}

test.describe("personeller unified live search", () => {
  test("typing searches without any submit button and only the final query is applied", async ({
    page
  }) => {
    const consoleErrors = trackConsoleErrors(page);
    await openPersonellerSearch(page);

    // The submit-only button is gone: search-as-you-type is the contract.
    await expect(page.getByRole("button", { name: "Filtrele" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Temizle" })).toBeVisible();

    const tracked = trackListRequests(page);
    const input = page.getByTestId("personeller-search-input");

    await input.pressSequentially("Ayse Yilmaz", { delay: 30 });

    await expect
      .poll(() => tracked.searches.filter((value) => value !== null))
      .toContain("Ayse Yilmaz");

    // Intermediate keystrokes must not each become a request.
    const issued = tracked.searches.filter((value): value is string => value !== null);
    expect(issued).toEqual(["Ayse Yilmaz"]);

    // Every issued query starts back at page 1.
    for (const search of tracked.all.filter((value) => value.includes("search="))) {
      expect(search).toContain("page=1");
    }

    await expect(page.getByText(/Ay[sş]e\s+Y[iı]lmaz/i).first()).toBeVisible({ timeout: 15_000 });
    expect(consoleErrors).toEqual([]);
  });

  test("reversed and partial tokens resolve the same person, and clearing restores the list", async ({
    page
  }) => {
    const tracked = trackListRequests(page);
    await openPersonellerSearch(page);
    const input = page.getByTestId("personeller-search-input");

    await input.fill("Yilmaz Ayse");
    await expect(page.getByText(/Ay[sş]e\s+Y[iı]lmaz/i).first()).toBeVisible({ timeout: 15_000 });
    await expect.poll(() => tracked.searches).toContain("Yilmaz Ayse");

    await input.fill("ay yil");
    await expect(page.getByText(/Ay[sş]e\s+Y[iı]lmaz/i).first()).toBeVisible({ timeout: 15_000 });
    await expect.poll(() => tracked.searches).toContain("ay yil");

    // Clearing goes back to the unfiltered list.
    await input.fill("");
    await expect.poll(() => tracked.searches.at(-1)).toBeNull();
  });

  test("Enter applies immediately and produces no extra request", async ({ page }) => {
    await openPersonellerSearch(page);
    const tracked = trackListRequests(page);
    const input = page.getByTestId("personeller-search-input");

    await input.fill("Ayse");
    await input.press("Enter");

    await expect.poll(() => tracked.searches.filter((v) => v !== null)).toContain("Ayse");
    await page.waitForTimeout(600);
    expect(tracked.searches.filter((value) => value === "Ayse")).toHaveLength(1);
  });

  test("Çalışan Kapsamı applies the moment it changes", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    await page.goto("/personeller");
    await page.getByRole("button", { name: "Detaylı filtre aç" }).click();

    const tracked = trackListRequests(page);
    await page.locator('select[name="personel-filter-calisan-kapsami"]').selectOption("DIS_KAYNAK");

    await expect
      .poll(() => tracked.all.some((search) => search.includes("calisan_kapsami=DIS_KAYNAK")))
      .toBe(true);
    const kapsamRequest = tracked.all.find((search) =>
      search.includes("calisan_kapsami=DIS_KAYNAK")
    );
    expect(kapsamRequest).toContain("page=1");
    await expect(page.getByRole("button", { name: "Filtrele" })).toHaveCount(0);
  });
});
