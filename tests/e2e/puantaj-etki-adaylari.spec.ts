import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yoneticisi", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

/** Canli kontrollu aday donemi; 2026-07 bos-state beklenen sonuctur. */
const PANEL_AY_WITH_DATA = "2026-06";
const PANEL_AY_EMPTY = "2026-07";

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

async function prepareMuhasebePanel(page: Page, ay = PANEL_AY_WITH_DATA) {
  await openPuantaj(page, "MUHASEBE");
  await page.getByLabel("Ay", { exact: true }).last().fill(ay);
  await expect(page.getByTestId("puantaj-etki-aday-panel")).toBeVisible();
  if (ay === PANEL_AY_WITH_DATA) {
    await expect(page.getByTestId("puantaj-etki-aday-table")).toBeVisible();
  }
}

function tableAction(page: Page, testId: string) {
  return page.getByTestId("puantaj-etki-aday-table").getByTestId(testId);
}

function countApiRequests(requests: string[], fragment: string) {
  return requests.filter((url) => url.includes(fragment)).length;
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

  test("MUHASEBE detaydan Uygula akisini tamamlar", async ({ page }) => {
    await prepareMuhasebePanel(page);
    await expect(tableAction(page, "puantaj-etki-aday-detail-1")).toBeVisible();
    await expect(page.getByRole("button", { name: "Uygula", exact: true })).toHaveCount(0);

    await tableAction(page, "puantaj-etki-aday-detail-1").click();
    await expect(page.getByTestId("puantaj-etki-aday-detail-modal")).toBeVisible();
    await expect(page.getByTestId("puantaj-etki-aday-detail-apply")).toBeVisible();

    await page.getByTestId("puantaj-etki-aday-detail-apply").click();
    const applyModal = page.getByTestId("puantaj-etki-aday-apply-modal");
    await expect(applyModal).toBeVisible();
    await expect(applyModal).toContainText("Ali Demir");
    await expect(applyModal).toContainText("GEC_KALMA");
    await expect(applyModal).toContainText("üzerine yazılmaz");

    await page.getByTestId("puantaj-etki-aday-apply-submit").click();
    await expect(page.getByTestId("puantaj-etki-aday-success")).toContainText(
      "Puantaj etki adayı günlük puantaja uygulandı."
    );
    await expect(tableAction(page, "puantaj-etki-aday-state-1")).toContainText("Uygulandı");
    await expect(page.getByTestId("puantaj-etki-aday-detail-apply")).toHaveCount(0);
  });

  test("BIRIM_AMIRI paneli gormez ve birim-amiri-secenekleri requesti olusmaz", async ({ page }) => {
    const requests: string[] = [];
    page.on("request", (request) => {
      if (request.url().includes("/api/bildirimler/birim-amiri-secenekleri")) {
        requests.push(request.url());
      }
    });

    await openPuantaj(page, "BIRIM_AMIRI");
    await expect(page.getByTestId("puantaj-etki-aday-panel")).toHaveCount(0);
    await page.waitForTimeout(500);
    expect(countApiRequests(requests, "birim-amiri-secenekleri")).toBe(0);
  });

  test("GENEL_YONETICI tum sube oturumunda yerel sube secip read-only liste yukler", async ({ page }) => {
    const amirRequests: string[] = [];
    const adayRequests: string[] = [];
    page.on("request", (request) => {
      const url = request.url();
      if (url.includes("/api/bildirimler/birim-amiri-secenekleri")) {
        amirRequests.push(url);
      }
      if (url.includes("/api/puantaj/bildirim-etki-adaylari")) {
        adayRequests.push(url);
      }
    });

    await openPuantaj(page, "GENEL_YONETICI");
    await expect(page.getByLabel("Şube", { exact: true })).toBeVisible();
    await expect(page.getByTestId("puantaj-etki-aday-context")).toContainText("şube seçin");
    expect(countApiRequests(amirRequests, "birim-amiri-secenekleri")).toBe(0);
    expect(countApiRequests(adayRequests, "bildirim-etki-adaylari")).toBe(0);

    await page.getByLabel("Şube", { exact: true }).selectOption("1");
    await page.getByLabel("Ay", { exact: true }).last().fill(PANEL_AY_WITH_DATA);
    const amirSelect = page.getByLabel("Birim Amiri", { exact: true });
    await expect(amirSelect).toBeEnabled();
    await amirSelect.selectOption("1");

    await expect(page.getByTestId("puantaj-etki-aday-panel")).toBeVisible();
    await expect(
      page.getByTestId("puantaj-etki-aday-table").or(page.getByTestId("puantaj-etki-aday-empty"))
    ).toBeVisible();
    expect(countApiRequests(amirRequests, "sube_id=1")).toBeGreaterThan(0);
    await expect(page.getByRole("button", { name: "Yok Say", exact: true })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Uygula", exact: true })).toHaveCount(0);
  });

  test("BOLUM_YONETICISI read-only panel calisir", async ({ page }) => {
    await openPuantaj(page, "BOLUM_YONETICISI");
    await setActiveSube(page, 2);
    await page.getByLabel("Ay", { exact: true }).last().fill(PANEL_AY_WITH_DATA);
    await page.getByLabel("Birim Amiri", { exact: true }).selectOption("4");
    await expect(page.getByTestId("puantaj-etki-aday-panel")).toBeVisible();
    await expect(page.getByTestId("puantaj-etki-aday-dismiss-2")).toHaveCount(0);
  });

  test("MUHASEBE tum sube oturumunda yerel sube secimi sonrasi 2026-06 adayini listeler", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, ROLE_LOGIN.MUHASEBE);
    await page.evaluate(() => {
      const key = "medisa_auth_session";
      const fromSession = sessionStorage.getItem(key);
      const storage = fromSession ? sessionStorage : localStorage;
      const raw = fromSession ?? localStorage.getItem(key);
      if (!raw) {
        throw new Error("auth session missing");
      }
      const session = JSON.parse(raw) as {
        active_sube_id?: number | null;
        user?: { sube_ids?: number[] };
      };
      session.active_sube_id = null;
      if (session.user) {
        session.user.sube_ids = [];
      }
      storage.setItem(key, JSON.stringify(session));
    });
    await page.goto("/puantaj");
    await page.getByLabel("Şube", { exact: true }).selectOption("1");
    await page.getByLabel("Ay", { exact: true }).last().fill(PANEL_AY_WITH_DATA);
    await page.getByLabel("Birim Amiri", { exact: true }).selectOption("1");
    await expect(page.getByTestId("puantaj-etki-aday-table")).toBeVisible();
    await expect(tableAction(page, "puantaj-etki-aday-dismiss-3")).toBeVisible();
  });

  test("2026-07 doneminde bos-state gosterir ve hata sayilmaz", async ({ page }) => {
    await prepareMuhasebePanel(page, PANEL_AY_EMPTY);
    await expect(page.getByTestId("puantaj-etki-aday-empty")).toBeVisible();
    await expect(page.getByTestId("puantaj-etki-aday-table")).toHaveCount(0);
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
