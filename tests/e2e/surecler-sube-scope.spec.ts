import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const users = {
  genelYonetici: { username: "genel_yonetici", password: "demo123" },
  muhasebe: { username: "muhasebe", password: "demo123" }
};

type SurecItem = {
  id: number;
  personel_id: number;
  surec_turu: string;
};

type ApiBody = {
  data?: {
    items?: SurecItem[];
  };
  errors?: Array<{ code?: string; message?: string; field?: string }>;
};

async function fetchSurecler(
  page: Page,
  options: { activeSubeId?: number | null; personelId?: number } = {}
) {
  const searchParams = new URLSearchParams();
  if (options.personelId !== undefined) {
    searchParams.set("personel_id", String(options.personelId));
  }
  const query = searchParams.toString();

  return page.evaluate(
    async ({ activeSubeId, queryString }) => {
      const headers: Record<string, string> = {};
      if (activeSubeId !== null) {
        headers["x-active-sube-id"] = String(activeSubeId);
      }

      const response = await fetch(`/api/surecler${queryString ? `?${queryString}` : ""}`, { headers });
      const body = (await response.json()) as ApiBody;
      return {
        status: response.status,
        body,
        pathname: window.location.pathname
      };
    },
    {
      activeSubeId: options.activeSubeId ?? null,
      queryString: query
    }
  );
}

function itemsOf(body: ApiBody) {
  return Array.isArray(body.data?.items) ? body.data.items : [];
}

test.describe("surecler sube scope", () => {
  test("active sube 1 surec listesi sube 2 personel surecini dondurmez", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, users.muhasebe);

    const response = await fetchSurecler(page, { activeSubeId: 1 });
    const items = itemsOf(response.body);

    expect(response.status).toBe(200);
    expect(items.some((item) => item.personel_id === 1)).toBe(true);
    expect(items.some((item) => item.personel_id === 2)).toBe(false);
  });

  test("active sube 1 sube 2 personel_id surec sorgusuna 403 verir", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, users.muhasebe);

    const response = await fetchSurecler(page, { activeSubeId: 1, personelId: 2 });

    expect(response.status).toBe(403);
    expect(response.body.errors?.[0]?.code).toBe("FORBIDDEN");
    expect(response.pathname).not.toBe("/yetkisiz");
  });

  test("active sube 1 kendi sube personelinin sureclerini dondurur", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, users.muhasebe);

    const response = await fetchSurecler(page, { activeSubeId: 1, personelId: 1 });
    const items = itemsOf(response.body);

    expect(response.status).toBe(200);
    expect(items.length).toBeGreaterThan(0);
    expect(items.every((item) => item.personel_id === 1)).toBe(true);
  });

  test("genel yonetici active sube olmadan tum sube sureclerini gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    const response = await fetchSurecler(page);
    const items = itemsOf(response.body);

    expect(response.status).toBe(200);
    expect(items.some((item) => item.personel_id === 1)).toBe(true);
    expect(items.some((item) => item.personel_id === 2)).toBe(true);
  });
});
