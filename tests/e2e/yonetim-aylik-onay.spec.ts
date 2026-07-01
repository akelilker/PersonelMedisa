import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "genel_yonetici", password: "demo123" },
  BOLUM_YONETICISI: { username: "bolum_yonetici", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

const FIXTURE_AY = `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, "0")}`;

type ApiFetchResult = {
  status: number;
  body: {
    data?: {
      ay?: string;
      state?: string;
      items?: Array<{ bolum_onay_durumu?: string; kapanis_durumu?: string; sube?: string }>;
    };
    errors?: Array<{ code?: string; message?: string; field?: string }>;
  };
};

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

      const json = (await response.json()) as ApiFetchResult["body"];
      return { status: response.status, body: json };
    },
    { fetchPath: path, method: options?.method, body: options?.body }
  );
}

async function loginAs(page: Page, role: MockUserRole) {
  await mockApi(page, role);
  await login(page, ROLE_LOGIN[role]);
}

function bolumOnayBody(overrides?: Record<string, unknown>) {
  return {
    ay: FIXTURE_AY,
    sube_id: 2,
    ...overrides
  };
}

function ayKapatBody(overrides?: Record<string, unknown>) {
  return {
    ay: FIXTURE_AY,
    sube_id: 2,
    ...overrides
  };
}

test.describe("S45A-2 aylik ozet onay POST (mock-api contract)", () => {
  test("BOLUM_YONETICISI can approve department summary for scoped sube", async ({ page }) => {
    await loginAs(page, "BOLUM_YONETICISI");

    const response = await apiFetch(page, "/api/yonetim/aylik-ozet/bolum-onay", {
      method: "POST",
      body: bolumOnayBody()
    });

    expect(response.status).toBe(200);
    expect(response.body.data?.items?.every((item) => item.bolum_onay_durumu === "BOLUM_ONAYLANDI")).toBe(true);
    expect(response.body.data?.items?.some((item) => item.sube === "Depolama")).toBe(true);
  });

  test("GENEL_YONETICI can approve department summary", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const response = await apiFetch(page, "/api/yonetim/aylik-ozet/bolum-onay", {
      method: "POST",
      body: bolumOnayBody({ sube_id: 1 })
    });

    expect(response.status).toBe(200);
    expect(response.body.data?.items?.length).toBeGreaterThan(0);
  });

  test("MUHASEBE and BIRIM_AMIRI are denied bolum-onay write", async ({ page }) => {
    for (const role of ["MUHASEBE", "BIRIM_AMIRI"] as const) {
      await loginAs(page, role);
      const response = await apiFetch(page, "/api/yonetim/aylik-ozet/bolum-onay", {
        method: "POST",
        body: bolumOnayBody()
      });
      expect(response.status).toBe(403);
    }
  });

  test("BOLUM_YONETICISI gets 403 for out-of-scope sube on bolum-onay", async ({ page }) => {
    await loginAs(page, "BOLUM_YONETICISI");

    const response = await apiFetch(page, "/api/yonetim/aylik-ozet/bolum-onay", {
      method: "POST",
      body: bolumOnayBody({ sube_id: 1 })
    });

    expect(response.status).toBe(403);
  });

  test("invalid bolum-onay body returns 400", async ({ page }) => {
    await loginAs(page, "BOLUM_YONETICISI");

    const response = await apiFetch(page, "/api/yonetim/aylik-ozet/bolum-onay", {
      method: "POST",
      body: { sube_id: 2 }
    });

    expect(response.status).toBe(400);
    expect(response.body.errors?.[0]?.field).toBe("ay");
  });

  test("GENEL_YONETICI can close month via ay-kapat", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const response = await apiFetch(page, "/api/yonetim/aylik-ozet/ay-kapat", {
      method: "POST",
      body: ayKapatBody()
    });

    expect(response.status).toBe(200);
    expect(response.body.data?.state).toBe("KAPANDI");
    expect(response.body.data?.items?.every((item) => item.kapanis_durumu === "KAPANDI")).toBe(true);
  });

  test("non-executive roles are denied ay-kapat write", async ({ page }) => {
    for (const role of ["BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"] as const) {
      await loginAs(page, role);
      const response = await apiFetch(page, "/api/yonetim/aylik-ozet/ay-kapat", {
        method: "POST",
        body: ayKapatBody()
      });
      expect(response.status).toBe(403);
    }
  });

  test("invalid ay-kapat body returns 400", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const response = await apiFetch(page, "/api/yonetim/aylik-ozet/ay-kapat", {
      method: "POST",
      body: { sube_id: 2 }
    });

    expect(response.status).toBe(400);
    expect(response.body.errors?.[0]?.field).toBe("ay");
  });

  test("GET state reflects bolum-onay update", async ({ page }) => {
    await loginAs(page, "BOLUM_YONETICISI");

    await apiFetch(page, "/api/yonetim/aylik-ozet/bolum-onay", {
      method: "POST",
      body: bolumOnayBody()
    });

    const getResponse = await apiFetch(page, `/api/yonetim/aylik-ozet?ay=${FIXTURE_AY}&sube_id=2`);
    expect(getResponse.status).toBe(200);
    expect(getResponse.body.data?.items?.every((item) => item.bolum_onay_durumu === "BOLUM_ONAYLANDI")).toBe(true);
  });
});
