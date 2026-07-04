import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yonetici", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

const RAPOR_QUERY = "baslangic_tarihi=2026-04-01&bitis_tarihi=2026-04-30";
const FINANS_RAPOR_TIPS = ["tesvik", "ceza", "ekstra-prim"] as const;

type ApiFetchResult = {
  status: number;
  personelIds: number[];
  total: number | null;
};

async function fetchRaporApi(
  page: Page,
  tip: string,
  options?: { activeSubeId?: number | null; clearActiveSube?: boolean; extraQuery?: string }
): Promise<ApiFetchResult> {
  return page.evaluate(
    async ({ reportTip, reportQuery, activeSubeId, clearActiveSube, extraQuery }) => {
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

      const queryParts = [reportQuery, extraQuery].filter(Boolean);
      const response = await fetch(`/api/raporlar/${reportTip}?${queryParts.join("&")}`, { headers });
      const body = (await response.json()) as {
        data?: { items?: Array<{ personel_id?: number }> };
        meta?: { total?: number };
      };

      return {
        status: response.status,
        personelIds: (body.data?.items ?? [])
          .map((item) => item.personel_id)
          .filter((id): id is number => typeof id === "number"),
        total: body.meta?.total ?? null
      };
    },
    {
      reportTip: tip,
      reportQuery: RAPOR_QUERY,
      activeSubeId: options?.activeSubeId,
      clearActiveSube: options?.clearActiveSube ?? false,
      extraQuery: options?.extraQuery
    }
  );
}

async function fetchFinansList(
  page: Page,
  options?: { activeSubeId?: number | null; clearActiveSube?: boolean }
): Promise<{ status: number; personelIds: number[] }> {
  return page.evaluate(
    async ({ activeSubeId, clearActiveSube }) => {
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

      const response = await fetch("/api/ek-odeme-kesinti?page=1&limit=50", { headers });
      const body = (await response.json()) as {
        data?: { items?: Array<{ personel_id?: number }> };
      };

      return {
        status: response.status,
        personelIds: (body.data?.items ?? [])
          .map((item) => item.personel_id)
          .filter((id): id is number => typeof id === "number")
      };
    },
    options ?? {}
  );
}

async function loginAs(page: Page, role: MockUserRole) {
  await mockApi(page, role);
  await login(page, ROLE_LOGIN[role]);
}

test.describe("rapor finans sube scope fallback (S43D)", () => {
  test("MUHASEBE without active scope returns only allowed sube report rows", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const personelOzet = await fetchRaporApi(page, "personel-ozet", { clearActiveSube: true });
    expect(personelOzet.status).toBe(200);
    expect(personelOzet.personelIds).toEqual(expect.arrayContaining([1, 2]));
    expect(personelOzet.total).toBe(2);
  });

  test("MUHASEBE without active scope returns only allowed sube rows for all finans reports", async ({
    page
  }) => {
    await loginAs(page, "MUHASEBE");

    for (const tip of FINANS_RAPOR_TIPS) {
      const result = await fetchRaporApi(page, tip, { clearActiveSube: true });
      expect(result.status, `${tip} status`).toBe(200);
      expect(result.personelIds, `${tip} personelIds`).toEqual(expect.arrayContaining([1, 2]));
      expect(result.total, `${tip} total`).toBe(2);
    }
  });

  test("MUHASEBE sube_id query takes precedence over X-Active-Sube-Id header", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const unauthorizedQuery = await fetchRaporApi(page, "personel-ozet", {
      activeSubeId: 1,
      extraQuery: "sube_id=99"
    });
    expect(unauthorizedQuery.status).toBe(403);

    const authorizedQuery = await fetchRaporApi(page, "personel-ozet", {
      activeSubeId: 99,
      extraQuery: "sube_id=1"
    });
    expect(authorizedQuery.status).toBe(200);
    expect(authorizedQuery.personelIds).toEqual([1]);
    expect(authorizedQuery.total).toBe(1);
  });

  test("MUHASEBE finans list without active scope returns only allowed sube records", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await fetchFinansList(page, { clearActiveSube: true });
    expect(result.status).toBe(200);
    expect(result.personelIds.sort()).toEqual([1, 2]);
  });

  test("MUHASEBE with unauthorized active sube returns 403 for rapor and finans", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const rapor = await fetchRaporApi(page, "personel-ozet", { activeSubeId: 99 });
    expect(rapor.status).toBe(403);

    const finans = await fetchFinansList(page, { activeSubeId: 99 });
    expect(finans.status).toBe(403);
  });

  test("BIRIM_AMIRI cannot read unauthorized muhur_id without scope", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    const result = await fetchRaporApi(page, "personel-ozet", {
      clearActiveSube: true,
      extraQuery: "muhur_id=2"
    });
    expect(result.status).toBe(403);
  });

  test("GENEL_YONETICI without active scope keeps all-sub report pagination", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const result = await fetchRaporApi(page, "personel-ozet", { clearActiveSube: true });
    expect(result.status).toBe(200);
    expect(result.total).toBe(2);
    expect(result.personelIds).toContain(1);
  });
});
