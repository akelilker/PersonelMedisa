import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yoneticisi", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

type PersonelListResult = {
  status: number;
  ids: number[];
  total: number | null;
};

async function fetchPersoneller(
  page: Page,
  options?: { activeSubeId?: number | null; clearActiveSube?: boolean; limit?: number }
): Promise<PersonelListResult> {
  return page.evaluate(
    async ({ activeSubeId, clearActiveSube, limit }) => {
      const key = "medisa_auth_session";
      const fromSession = sessionStorage.getItem(key);
      const storage = fromSession ? sessionStorage : localStorage;
      const raw = fromSession ?? localStorage.getItem(key);
      const session = raw ? (JSON.parse(raw) as { token?: string; active_sube_id?: number | null }) : null;

      if (session && clearActiveSube) {
        session.active_sube_id = null;
        storage.setItem(key, JSON.stringify(session));
      }

      const token = session?.token ?? "mock-token";
      const headers: Record<string, string> = {
        Authorization: `Bearer ${token}`
      };

      if (typeof activeSubeId === "number") {
        headers["X-Active-Sube-Id"] = String(activeSubeId);
      }

      const response = await fetch(`/api/personeller?page=1&limit=${limit ?? 50}`, { headers });
      const body = (await response.json()) as {
        data?: { items?: Array<{ id: number }> };
        meta?: { total?: number };
      };

      return {
        status: response.status,
        ids: (body.data?.items ?? []).map((item) => item.id),
        total: body.meta?.total ?? null
      };
    },
    options ?? {}
  );
}

async function loginAs(page: Page, role: MockUserRole) {
  await mockApi(page, role);
  await login(page, ROLE_LOGIN[role]);
}

test.describe("personeller sube scope (S43C)", () => {
  test("GENEL_YONETICI sees all subeler without active scope", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const result = await fetchPersoneller(page, { clearActiveSube: true });
    expect(result.status).toBe(200);
    expect(result.ids).toContain(1);
    expect(result.ids).toContain(2);
    expect(result.ids).toContain(5);
    expect(result.total).toBeGreaterThanOrEqual(5);
  });

  test("MUHASEBE without active scope returns only allowed sube 1 and 2", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await fetchPersoneller(page, { clearActiveSube: true });
    expect(result.status).toBe(200);
    expect(result.ids).toEqual(expect.arrayContaining([1, 2, 3, 4]));
    expect(result.ids).not.toContain(5);
    expect(result.total).toBe(4);
  });

  test("MUHASEBE with active sube 1 narrows list to sube 1", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await fetchPersoneller(page, { activeSubeId: 1 });
    expect(result.status).toBe(200);
    expect(result.ids).toEqual(expect.arrayContaining([1, 3, 4]));
    expect(result.ids).not.toContain(2);
    expect(result.ids).not.toContain(5);
    expect(result.total).toBe(3);
  });

  test("MUHASEBE with active sube 2 narrows list to sube 2", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await fetchPersoneller(page, { activeSubeId: 2 });
    expect(result.status).toBe(200);
    expect(result.ids).toEqual([2]);
    expect(result.total).toBe(1);
  });

  test("MUHASEBE with unauthorized active sube returns 403", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await fetchPersoneller(page, { activeSubeId: 99 });
    expect(result.status).toBe(403);
  });

  test("BOLUM_YONETICISI without active scope returns only sube 2", async ({ page }) => {
    await loginAs(page, "BOLUM_YONETICISI");

    const result = await fetchPersoneller(page, { clearActiveSube: true });
    expect(result.status).toBe(200);
    expect(result.ids).toEqual([2]);
    expect(result.ids).not.toContain(5);
    expect(result.total).toBe(1);
  });

  test("BIRIM_AMIRI without active scope returns only sube 1", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    const result = await fetchPersoneller(page, { clearActiveSube: true });
    expect(result.status).toBe(200);
    expect(result.ids).toEqual(expect.arrayContaining([1, 3, 4]));
    expect(result.ids).not.toContain(2);
    expect(result.ids).not.toContain(5);
    expect(result.total).toBe(3);
  });
});
