import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yonetici", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

const MUHUR_ROLE_CASES: MockUserRole[] = [
  "GENEL_YONETICI",
  "BOLUM_YONETICISI",
  "MUHASEBE",
  "BIRIM_AMIRI"
];

const ROLE_MUHUR_VISIBLE: Record<MockUserRole, boolean> = {
  GENEL_YONETICI: true,
  BOLUM_YONETICISI: true,
  MUHASEBE: false,
  BIRIM_AMIRI: false
};

const SEED_PERSONEL_ID = "1";
const SEED_TARIH = "2026-04-09";
const SEED_DONEM = "2026-04";

async function openPuantajRecord(page: Parameters<typeof test>[0]["page"]) {
  await page.getByLabel("Personel ID").fill(SEED_PERSONEL_ID);
  await page.getByLabel("Tarih").fill(SEED_TARIH);
  await page.getByRole("button", { name: /Kayd.*Getir/i }).click();
  await expect(page.getByTestId("puantaj-ana-detay")).toBeVisible();
}

function readonlyFieldInCardByLabel(
  container: ReturnType<Parameters<typeof test>[0]["page"]["getByTestId"]>,
  label: string
) {
  return container.locator(".form-section").filter({ hasText: label });
}

test.describe("puantaj muhurleme", () => {
  for (const role of MUHUR_ROLE_CASES) {
    test(`puantaj muhur aksiyonunu role gore gosterir - ${role}`, async ({ page }) => {
      const runtimeErrors: string[] = [];
      page.on("pageerror", (error) => {
        runtimeErrors.push(error.message);
      });

      await mockApi(page, role);
      await login(page, ROLE_LOGIN[role]);
      await page.goto("/puantaj");
      await expect(page).toHaveURL(/\/puantaj$/);
      await expect(page.locator(".modal-header h2").first()).toContainText("Günlük Puantaj");
      await expect(page.getByRole("heading", { name: "Günlük Kayıt ve Puantaj" })).toBeVisible();

      const muhurButton = page.getByTestId("muhur-ay-kapat-btn");
      if (ROLE_MUHUR_VISIBLE[role]) {
        await expect(muhurButton).toBeVisible();
      } else {
        await expect(muhurButton).toHaveCount(0);
      }

      expect(runtimeErrors).toEqual([]);
    });
  }

  test("genel yonetici puantaj ayini muhurlendikten sonra kaydi kilitli gorur", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await mockApi(page, "GENEL_YONETICI");
    await login(page, ROLE_LOGIN.GENEL_YONETICI);
    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);

    await openPuantajRecord(page);

    const kaydetButton = page.getByTestId("puantaj-kaydet");
    await expect(kaydetButton).toBeEnabled();
    await expect(page.getByTestId("muhur-uyari")).toHaveCount(0);

    await page.getByTestId("muhur-ay-kapat-btn").click();
    const muhurModal = page.getByTestId("muhur-modal");
    await expect(muhurModal).toBeVisible();

    await page.locator("[name='muhur-donem']").fill(SEED_DONEM);
    await page.getByTestId("muhur-onayla-btn").click();

    const muhurSonuc = page.getByTestId("muhur-sonuc");
    await expect(muhurSonuc).toBeVisible();
    await expect(muhurSonuc).toContainText(SEED_DONEM);
    await expect(muhurSonuc).toContainText("kayıt mühürlendi");
    await expect(muhurModal).toBeVisible();

    await page.getByRole("button", { name: "Vazgeç" }).click();
    await expect(muhurModal).toHaveCount(0);

    await expect(page.getByTestId("muhur-uyari")).toBeVisible();
    await expect(kaydetButton).toBeDisabled();

    expect(runtimeErrors).toEqual([]);
  });
});

test.describe("puantaj birim amiri", () => {
  test("birim amiri kayit yuklendikten sonra puantaji read only gorur", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await mockApi(page, "BIRIM_AMIRI");
    await login(page, ROLE_LOGIN.BIRIM_AMIRI);
    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);

    await openPuantajRecord(page);

    await expect(page.getByTestId("puantaj-kaydet")).toBeDisabled();
    await expect(page.getByTestId("muhur-ay-kapat-btn")).toHaveCount(0);
    await expect(page.getByText("Bu modülü sadece görüntüleme yetkin var.")).toBeVisible();
    await expect(page.locator("[name='puantaj-giris']")).toBeEnabled();

    expect(runtimeErrors).toEqual([]);
  });

  test("birim amiri amir kontrol aksiyonunu tamamlar", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await mockApi(page, "BIRIM_AMIRI");
    await login(page, ROLE_LOGIN.BIRIM_AMIRI);
    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);

    await openPuantajRecord(page);

    const gunlukDetayKarti = page.getByTestId("puantaj-ana-detay");
    await expect(readonlyFieldInCardByLabel(gunlukDetayKarti, "Kontrol Durumu")).toContainText("Bekliyor");

    const amirKontrolButton = page.getByRole("button", { name: "Amir Kontrol Etti" });
    await expect(amirKontrolButton).toBeVisible();
    await amirKontrolButton.click();

    await expect(amirKontrolButton).toHaveCount(0);
    await expect(readonlyFieldInCardByLabel(gunlukDetayKarti, "Kontrol Durumu")).toContainText("Amir kontrol etti");
    await expect(page.getByTestId("puantaj-kaydet")).toBeDisabled();
    await expect(page.getByTestId("muhur-ay-kapat-btn")).toHaveCount(0);
    await expect(page.getByText("Bu modülü sadece görüntüleme yetkin var.")).toBeVisible();

    expect(runtimeErrors).toEqual([]);
  });
});
