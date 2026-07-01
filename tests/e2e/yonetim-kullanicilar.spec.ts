import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

async function apiFetch(
  page: Page,
  path: string,
  options?: { method?: string; body?: Record<string, unknown> }
) {
  return page.evaluate(
    async ({ path: apiPath, method, body }) => {
      const key = "medisa_auth_session";
      const fromSession = sessionStorage.getItem(key);
      const storage = fromSession ? sessionStorage : localStorage;
      const raw = fromSession ?? localStorage.getItem(key);
      const session = raw ? (JSON.parse(raw) as { token?: string }) : null;
      const token = session?.token ?? "mock-token";

      const response = await fetch(apiPath, {
        method: method ?? "GET",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json"
        },
        body: body ? JSON.stringify(body) : undefined
      });

      const json = (await response.json()) as Record<string, unknown>;
      return { status: response.status, json };
    },
    { path, method: options?.method, body: options?.body }
  );
}

test.describe("yonetim kullanicilar API (S44)", () => {
  test("GENEL_YONETICI lists kullanicilar and response has no password_hash", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const result = await apiFetch(page, "/api/yonetim/kullanicilar");
    expect(result.status).toBe(200);
    const items = (result.json.data as { items?: Array<Record<string, unknown>> })?.items ?? [];
    expect(items.length).toBeGreaterThan(0);
    expect(JSON.stringify(result.json)).not.toContain("password_hash");
    expect(JSON.stringify(result.json)).not.toMatch(/"password"\s*:/);
  });

  test("GENEL_YONETICI creates kullanici with username/password fields in UI", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/yonetim-paneli?tab=kullanicilar");
    await page.getByTestId("yonetim-kullanici-yeni").click();
    await expect(page.getByLabel("Kullanıcı Adı")).toBeVisible();
    await expect(page.getByLabel("Geçici Şifre")).toBeVisible();

    await page.getByLabel("Kullanıcı Tipi").selectOption("HARICI");
    await page.getByLabel("Rol").selectOption("MUHASEBE");
    await page.getByLabel("Kullanıcı Adı").fill("e2e_muhasebe");
    await page.getByLabel("Geçici Şifre").fill("GeciciSifre2026");
    await page.getByLabel("Ad Soyad").fill("E2E Muhasebe");
    await page.locator(".yonetim-selection-pill").filter({ hasText: "Merkez" }).click();
    await page.locator(".yonetim-selection-pill").filter({ hasText: "Depolama" }).click();
    await page.getByTestId("yonetim-kullanici-kaydet").click();

    await expect(page.getByText("Kullanıcı kaydı oluşturuldu.")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--users")).toContainText(/E2e MUHASEBE/i);
  });

  test("duplicate username returns 409", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const result = await apiFetch(page, "/api/yonetim/kullanicilar", {
      method: "POST",
      body: {
        username: "genel_yonetici",
        password: "GeciciSifre2026",
        ad_soyad: "Duplicate User",
        kullanici_tipi: "HARICI",
        rol: "MUHASEBE",
        sube_ids: [1],
        durum: "AKTIF"
      }
    });

    expect(result.status).toBe(409);
    const errors = (result.json.errors as Array<{ code?: string }>) ?? [];
    expect(errors[0]?.code).toBe("DUPLICATE_USERNAME");
  });

  test("BIRIM_AMIRI is denied kullanicilar endpoints", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, { username: "birim_amiri", password: "demo123" });

    await expect(apiFetch(page, "/api/yonetim/kullanicilar")).resolves.toMatchObject({ status: 403 });
  });

  test("create user with varsayilan_sube_id returns consistent sube_ids order", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const result = await apiFetch(page, "/api/yonetim/kullanicilar", {
      method: "POST",
      body: {
        username: "sube_sira_test",
        password: "GeciciSifre2026",
        ad_soyad: "Sube Sira Test",
        kullanici_tipi: "HARICI",
        rol: "MUHASEBE",
        sube_ids: [1, 2],
        varsayilan_sube_id: 2,
        durum: "AKTIF"
      }
    });

    expect(result.status).toBe(200);
    const data = result.json.data as Record<string, unknown>;
    expect(data.varsayilan_sube_id).toBe(2);
    expect(data.sube_ids).toEqual([2, 1]);
    expect(JSON.stringify(result.json)).not.toContain("password_hash");
  });

  test("update user with varsayilan_sube_id returns consistent response", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const result = await apiFetch(page, "/api/yonetim/kullanicilar/3", {
      method: "PUT",
      body: {
        username: "birim_amiri",
        ad_soyad: "Ayşe Yılmaz",
        kullanici_tipi: "IC_PERSONEL",
        rol: "BIRIM_AMIRI",
        personel_id: 1,
        sube_ids: [1, 2],
        varsayilan_sube_id: 2,
        durum: "AKTIF"
      }
    });

    expect(result.status).toBe(200);
    const data = result.json.data as Record<string, unknown>;
    expect(data.varsayilan_sube_id).toBe(2);
    expect(data.sube_ids).toEqual([2, 1]);
  });

  test("invalid varsayilan_sube_id returns validation error", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const result = await apiFetch(page, "/api/yonetim/kullanicilar", {
      method: "POST",
      body: {
        username: "invalid_varsayilan",
        password: "GeciciSifre2026",
        ad_soyad: "Invalid Varsayilan",
        kullanici_tipi: "HARICI",
        rol: "MUHASEBE",
        sube_ids: [1],
        varsayilan_sube_id: 2,
        durum: "AKTIF"
      }
    });

    expect(result.status).toBe(400);
    const errors = (result.json.errors as Array<{ field?: string; code?: string }>) ?? [];
    expect(errors[0]?.field).toBe("varsayilan_sube_id");
    expect(errors[0]?.code).toBe("VALIDATION_ERROR");
  });

  test("unsupported fields are accepted in mock but real payload contract strips them in unit layer", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const result = await apiFetch(page, "/api/yonetim/kullanicilar", {
      method: "POST",
      body: {
        username: "extended_fields_mock",
        password: "GeciciSifre2026",
        ad_soyad: "Extended Fields Mock",
        telefon: "05551234567",
        notlar: "mock not",
        kullanici_tipi: "HARICI",
        rol: "MUHASEBE",
        sube_ids: [1],
        durum: "AKTIF"
      }
    });

    expect(result.status).toBe(200);
    const data = result.json.data as Record<string, unknown>;
    expect(data.telefon).toBe("05551234567");
    expect(data.notlar).toBe("mock not");
    expect(JSON.stringify(result.json)).not.toMatch(/"password"\s*:/);
  });
});
