import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yoneticisi", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

const PANEL_AY = "2026-06";

async function setActiveSube(page: Page, subeId: number) {
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

async function openPuantaj(page: Page, role: MockUserRole) {
  await mockApi(page, role);
  await login(page, ROLE_LOGIN[role]);
  await page.goto("/puantaj");
  await expect(page).toHaveURL(/\/puantaj$/);
}

async function prepareMuhasebePanel(page: Page) {
  await openPuantaj(page, "MUHASEBE");
  await page.getByLabel("Ay", { exact: true }).last().fill(PANEL_AY);
  await expect(page.getByTestId("puantaj-etki-aday-panel")).toBeVisible();
  await expect(page.getByTestId("puantaj-etki-aday-table")).toBeVisible();
}

async function prepareReadOnlyPanel(
  page: Page,
  role: "GENEL_YONETICI" | "BOLUM_YONETICISI",
  subeId: number,
  amirUserId: string
) {
  await openPuantaj(page, role);
  await setActiveSube(page, subeId);
  await page.getByLabel("Ay", { exact: true }).last().fill(PANEL_AY);
  const amirSelect = page.getByLabel("Birim Amiri", { exact: true });
  await expect(amirSelect).toBeEnabled();
  await amirSelect.selectOption(amirUserId);
  await expect(page.getByTestId("puantaj-etki-aday-panel")).toBeVisible();
  await expect(
    page.getByTestId("puantaj-etki-aday-table").or(page.getByTestId("puantaj-etki-aday-empty"))
  ).toBeVisible();
}

function tableAction(page: Page, testId: string) {
  return page.getByTestId("puantaj-etki-aday-table").getByTestId(testId);
}

test.describe("S74-C2B puantaj etki adaylari paneli", () => {
  test("MUHASEBE paneli gorur ve Yok Say akisini tamamlar", async ({ page }) => {
    await prepareMuhasebePanel(page);
    await expect(tableAction(page, "puantaj-etki-aday-dismiss-3")).toBeVisible();
    await expect(page.getByTestId("puantaj-etki-aday-table").getByTestId("puantaj-etki-inceleme-uyari-3")).toBeVisible();
    await expect(page.getByRole("button", { name: "Uygula", exact: true })).toHaveCount(0);

    await tableAction(page, "puantaj-etki-aday-dismiss-3").click();
    await expect(page.getByTestId("puantaj-etki-aday-dismiss-modal")).toBeVisible();
    const submit = page.getByTestId("puantaj-etki-aday-dismiss-submit");
    await expect(submit).toBeDisabled();

    await page.getByLabel("Yok Sayma Gerekçesi").fill("abc");
    await expect(submit).toBeDisabled();

    await page.getByLabel("Yok Sayma Gerekçesi").fill("Mevcut puantaj kaydıyla çakıştığı için yok sayıldı.");
    await expect(submit).toBeEnabled();
    await submit.click();

    await expect(page.getByTestId("puantaj-etki-aday-success")).toContainText("Puantaj etki adayı yok sayıldı.");
    await expect(tableAction(page, "puantaj-etki-aday-dismiss-3")).toHaveCount(0);
    await expect(tableAction(page, "puantaj-etki-aday-state-3")).toContainText("Yok Sayıldı");
  });

  test("BIRIM_AMIRI paneli gormez", async ({ page }) => {
    await openPuantaj(page, "BIRIM_AMIRI");
    await expect(page.getByTestId("puantaj-etki-aday-panel")).toHaveCount(0);
  });

  test("GENEL_YONETICI paneli read-only gorur", async ({ page }) => {
    await openPuantaj(page, "GENEL_YONETICI");
    await expect(page.getByTestId("puantaj-etki-aday-panel")).toBeVisible();
    await expect(page.getByRole("button", { name: "Yok Say", exact: true })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Uygula", exact: true })).toHaveCount(0);
  });

  test("BOLUM_YONETICISI paneli read-only gorur", async ({ page }) => {
    await prepareReadOnlyPanel(page, "BOLUM_YONETICISI", 2, "4");
    await expect(page.getByTestId("puantaj-etki-aday-dismiss-2")).toHaveCount(0);
  });

  test("409 stale davranisi listeyi yeniler", async ({ page }) => {
    await prepareMuhasebePanel(page);
    await tableAction(page, "puantaj-etki-aday-dismiss-3").click();
    await page.getByLabel("Yok Sayma Gerekçesi").fill("E2E state stale tetikleyici");
    await page.getByTestId("puantaj-etki-aday-dismiss-submit").click();
    await expect(page.getByTestId("puantaj-etki-aday-info")).toContainText("Liste yenilendi");
    await expect(page.getByTestId("puantaj-etki-aday-dismiss-modal")).toHaveCount(0);
  });
});
