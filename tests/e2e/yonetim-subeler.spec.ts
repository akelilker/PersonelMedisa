import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";
import {
  SUBE_DELETE_BLOCKED_ERROR_CODE,
  SUBE_DELETE_BLOCKED_MESSAGE
} from "../../src/lib/yonetim/sube-delete";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "genel_yonetici", password: "demo123" },
  BOLUM_YONETICISI: { username: "bolum_yonetici", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

type ApiFetchResult = {
  status: number;
  body: {
    data?: unknown;
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

test.describe("S45A-1 yonetim sube CRUD (mock-api contract)", () => {
  test("GENEL_YONETICI lists subeler with departman_adlari aligned to departman_ids", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const response = await apiFetch(page, "/api/yonetim/subeler");
    expect(response.status).toBe(200);

    const items = (response.body.data as { items?: Array<Record<string, unknown>> } | undefined)?.items ?? [];
    expect(items.length).toBeGreaterThan(0);

    const merkez = items.find((item) => item.kod === "MRK");
    expect(merkez).toBeTruthy();
    expect(Array.isArray(merkez?.departman_ids)).toBe(true);
    expect(Array.isArray(merkez?.departman_adlari)).toBe(true);
    expect((merkez?.departman_ids as number[]).length).toBe((merkez?.departman_adlari as string[]).length);
    expect((merkez?.departman_adlari as string[]).length).toBeGreaterThan(0);
  });

  test("GENEL_YONETICI can create, update and delete personelsiz sube", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const createResponse = await apiFetch(page, "/api/yonetim/subeler", {
      method: "POST",
      body: {
        kod: "TST",
        ad: "Test Sube",
        durum: "AKTIF",
        departman_ids: [1]
      }
    });
    expect(createResponse.status).toBe(200);
    const created = createResponse.body.data as Record<string, unknown>;
    expect(created.kod).toBe("TST");
    expect(created.ad).toBe("Test Sube");
    expect(created.departman_ids).toEqual([1]);
    expect(created.departman_adlari).toEqual(["Muhasebe"]);

    const subeId = created.id as number;

    const updateResponse = await apiFetch(page, `/api/yonetim/subeler/${subeId}`, {
      method: "PUT",
      body: {
        kod: "TST",
        ad: "Test Sube Guncel",
        durum: "AKTIF",
        departman_ids: [1, 3]
      }
    });
    expect(updateResponse.status).toBe(200);
    const updated = updateResponse.body.data as Record<string, unknown>;
    expect(updated.ad).toBe("Test Sube Guncel");
    expect(updated.departman_ids).toEqual([1, 3]);
    expect(updated.departman_adlari).toEqual(["Muhasebe", "Döşeme"]);

    const deleteResponse = await apiFetch(page, `/api/yonetim/subeler/${subeId}`, {
      method: "DELETE"
    });
    expect(deleteResponse.status).toBe(200);
    expect(deleteResponse.body.data).toMatchObject({ id: subeId, deleted: true });
  });

  test("GENEL_YONETICI cannot delete sube with linked personel", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const response = await apiFetch(page, "/api/yonetim/subeler/1", { method: "DELETE" });
    expect(response.status).toBe(409);
    expect(response.body.errors?.[0]?.code).toBe(SUBE_DELETE_BLOCKED_ERROR_CODE);
    expect(response.body.errors?.[0]?.message).toBe(SUBE_DELETE_BLOCKED_MESSAGE);
  });

  test("GENEL_YONETICI gets 409 on duplicate sube kod and ad", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const duplicateKod = await apiFetch(page, "/api/yonetim/subeler", {
      method: "POST",
      body: {
        kod: "MRK",
        ad: "Yeni Sube",
        durum: "AKTIF",
        departman_ids: [1]
      }
    });
    expect(duplicateKod.status).toBe(409);
    expect(duplicateKod.body.errors?.[0]?.code).toBe("DUPLICATE_SUBE_KOD");

    const duplicateAd = await apiFetch(page, "/api/yonetim/subeler", {
      method: "POST",
      body: {
        kod: "YEN",
        ad: "Merkez",
        durum: "AKTIF",
        departman_ids: [1]
      }
    });
    expect(duplicateAd.status).toBe(409);
    expect(duplicateAd.body.errors?.[0]?.code).toBe("DUPLICATE_SUBE_AD");
  });

  test("non-GENEL roles are denied sube write endpoints", async ({ page }) => {
    for (const role of ["BIRIM_AMIRI", "MUHASEBE", "BOLUM_YONETICISI"] as const) {
      await loginAs(page, role);

      await expect(
        apiFetch(page, "/api/yonetim/subeler", {
          method: "POST",
          body: { kod: "X1", ad: "X Sube", durum: "AKTIF", departman_ids: [1] }
        })
      ).resolves.toMatchObject({ status: 403 });

      await expect(
        apiFetch(page, "/api/yonetim/subeler/2", {
          method: "PUT",
          body: { kod: "DPL", ad: "Depolama", durum: "AKTIF", departman_ids: [1] }
        })
      ).resolves.toMatchObject({ status: 403 });

      await expect(apiFetch(page, "/api/yonetim/subeler/2", { method: "DELETE" })).resolves.toMatchObject({
        status: 403
      });
    }
  });

  test("S43B read guards remain: BOLUM/MUHASEBE can GET subeler, BIRIM_AMIRI cannot", async ({ page }) => {
    await loginAs(page, "BOLUM_YONETICISI");
    await expect(apiFetch(page, "/api/yonetim/subeler")).resolves.toMatchObject({ status: 200 });

    await loginAs(page, "MUHASEBE");
    await expect(apiFetch(page, "/api/yonetim/subeler")).resolves.toMatchObject({ status: 200 });

    await loginAs(page, "BIRIM_AMIRI");
    await expect(apiFetch(page, "/api/yonetim/subeler")).resolves.toMatchObject({ status: 403 });
  });
});
