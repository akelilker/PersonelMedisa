import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yonetici", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

const OPERATIONAL_RAPORLAR = [
  "personel-ozet",
  "devamsizlik",
  "izin",
  "is-kazasi",
  "bildirim"
] as const;

const FINANS_RAPORLAR = ["tesvik", "ceza", "ekstra-prim"] as const;

const ALL_RAPORLAR = [...OPERATIONAL_RAPORLAR, ...FINANS_RAPORLAR] as const;

const RAPOR_QUERY = "baslangic_tarihi=2026-04-01&bitis_tarihi=2026-04-30";

type ApiFetchResult = { status: number };

async function apiFetch(
  page: Page,
  path: string,
  options?: { method?: string; body?: Record<string, unknown> }
): Promise<ApiFetchResult> {
  return page.evaluate(
    async ({ fetchPath, method, body }) => {
      const storageKey = "medisa_auth_session";
      const raw = sessionStorage.getItem(storageKey) ?? localStorage.getItem(storageKey);
      const session = raw ? (JSON.parse(raw) as { token?: string }) : null;
      const token = session?.token ?? "mock-token";

      const response = await fetch(fetchPath, {
        method: method ?? "GET",
        headers: {
          Authorization: `Bearer ${token}`,
          ...(body ? { "Content-Type": "application/json" } : {})
        },
        ...(body ? { body: JSON.stringify(body) } : {})
      });

      return { status: response.status };
    },
    { fetchPath: path, method: options?.method, body: options?.body }
  );
}

async function loginAs(page: Page, role: MockUserRole) {
  await mockApi(page, role);
  await login(page, ROLE_LOGIN[role]);
}

test.describe("S43B API role guards (mock-api)", () => {
  test("BIRIM_AMIRI is denied finans endpoints", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    await expect(apiFetch(page, "/api/ek-odeme-kesinti")).resolves.toMatchObject({ status: 403 });
    await expect(
      apiFetch(page, "/api/ek-odeme-kesinti", {
        method: "POST",
        body: {
          personel_id: 1,
          donem: "2026-04",
          kalem_turu: "AVANS",
          tutar: 100
        }
      })
    ).resolves.toMatchObject({ status: 403 });
    await expect(
      apiFetch(page, "/api/ek-odeme-kesinti/901", {
        method: "PUT",
        body: { tutar: 200 }
      })
    ).resolves.toMatchObject({ status: 403 });
    await expect(apiFetch(page, "/api/ek-odeme-kesinti/901/iptal", { method: "POST" })).resolves.toMatchObject({
      status: 403
    });
  });

  test("GENEL_YONETICI can access finans list", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");
    await expect(apiFetch(page, "/api/ek-odeme-kesinti")).resolves.toMatchObject({ status: 200 });
  });

  test("BIRIM_AMIRI can access operational raporlar but not finans raporlari", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    for (const tip of OPERATIONAL_RAPORLAR) {
      await expect(apiFetch(page, `/api/raporlar/${tip}?${RAPOR_QUERY}`)).resolves.toMatchObject({ status: 200 });
    }

    for (const tip of FINANS_RAPORLAR) {
      await expect(apiFetch(page, `/api/raporlar/${tip}?${RAPOR_QUERY}`)).resolves.toMatchObject({ status: 403 });
    }
  });

  test("GENEL_YONETICI can access all 8 rapor endpoints", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    for (const tip of ALL_RAPORLAR) {
      await expect(apiFetch(page, `/api/raporlar/${tip}?${RAPOR_QUERY}`)).resolves.toMatchObject({ status: 200 });
    }
  });

  test("yonetim read guards block BIRIM_AMIRI", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    await expect(apiFetch(page, "/api/yonetim/subeler")).resolves.toMatchObject({ status: 403 });
    await expect(apiFetch(page, "/api/yonetim/aylik-ozet?ay=2026-04")).resolves.toMatchObject({ status: 403 });
  });

  test("BOLUM_YONETICISI can read subeler for aylik ozet filters", async ({ page }) => {
    await loginAs(page, "BOLUM_YONETICISI");

    await expect(apiFetch(page, "/api/yonetim/subeler")).resolves.toMatchObject({ status: 200 });
    await expect(apiFetch(page, "/api/yonetim/aylik-ozet?ay=2026-04")).resolves.toMatchObject({ status: 200 });
  });

  test("MUHASEBE can read subeler for personel create but not aylik ozet", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    await expect(apiFetch(page, "/api/yonetim/subeler")).resolves.toMatchObject({ status: 200 });
    await expect(apiFetch(page, "/api/yonetim/aylik-ozet?ay=2026-04")).resolves.toMatchObject({ status: 403 });
  });

  test("GENEL_YONETICI can access yonetim read endpoints", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    await expect(apiFetch(page, "/api/yonetim/subeler")).resolves.toMatchObject({ status: 200 });
    await expect(apiFetch(page, "/api/yonetim/aylik-ozet?ay=2026-04")).resolves.toMatchObject({ status: 200 });
  });
});
