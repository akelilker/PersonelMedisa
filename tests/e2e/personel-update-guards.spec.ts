import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yonetici", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

type PutPersonelResult = {
  status: number;
  body: {
    data?: Record<string, unknown> | null;
    errors?: Array<{ code?: string; field?: string; message?: string }>;
  };
};

async function loginAs(page: Page, role: MockUserRole) {
  await mockApi(page, role);
  await login(page, ROLE_LOGIN[role]);
}

async function putPersonelApi(
  page: Page,
  personelId: number,
  body: Record<string, unknown>,
  options?: { activeSubeId?: number }
): Promise<PutPersonelResult> {
  return page.evaluate(
    async ({ id, payload, activeSubeId }) => {
      const storageKey = "medisa_auth_session";
      const fromSession = sessionStorage.getItem(storageKey);
      const storage = fromSession ? sessionStorage : localStorage;
      const raw = fromSession ?? localStorage.getItem(storageKey);
      const session = raw ? (JSON.parse(raw) as { token?: string }) : null;
      const token = session?.token ?? "mock-token";

      const headers: Record<string, string> = {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/json"
      };

      if (typeof activeSubeId === "number") {
        headers["X-Active-Sube-Id"] = String(activeSubeId);
      }

      const response = await fetch(`/api/personeller/${id}`, {
        method: "PUT",
        headers,
        body: JSON.stringify(payload)
      });

      const parsed = (await response.json()) as PutPersonelResult["body"];
      return { status: response.status, body: parsed };
    },
    { id: personelId, payload: body, activeSubeId: options?.activeSubeId }
  );
}

test.describe("personel update guards (S51-A)", () => {
  test("BIRIM_AMIRI cannot PUT personel update", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    const result = await putPersonelApi(page, 1, { ad: "Yetkisiz" });

    expect(result.status).toBe(403);
    expect(result.body.errors?.some((error) => error.code === "FORBIDDEN")).toBe(true);
  });

  test("MUHASEBE gets 403 when active sube is unauthorized for personel scope", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await putPersonelApi(page, 1, { ad: "Scope" }, { activeSubeId: 99 });

    expect(result.status).toBe(403);
  });

  test("MUHASEBE empty personel update is accepted as no-op", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await putPersonelApi(page, 1, {});

    expect(result.status).toBe(200);
    const anaKart = result.body.data?.ana_kart as Record<string, unknown> | undefined;
    expect(anaKart?.id).toBe(1);
    expect(anaKart?.ad).toBe("Ayşe");
    expect(anaKart?.soyad).toBe("Yılmaz");
    expect(anaKart?.tc_kimlik_no).toBe("12345678901");
    expect(anaKart?.aktif_durum).toBe("AKTIF");
  });

  test("MUHASEBE cannot change sube_id via PUT", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await putPersonelApi(page, 1, { sube_id: 2 });

    expect(result.status).toBe(403);
  });

  test("MUHASEBE cannot change aktif_durum via PUT", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await putPersonelApi(page, 1, { aktif_durum: "PASIF" });

    expect(result.status).toBe(422);
    const aktifDurumError = result.body.errors?.find(
      (error) => error.field === "aktif_durum" || /aktif.?durum/i.test(error.message ?? "")
    );
    expect(aktifDurumError).toBeDefined();
  });

  test("MUHASEBE requires effective_date for lifecycle field changes", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await putPersonelApi(page, 1, { departman_id: 4 });

    expect(result.status).toBe(400);
    expect(result.body.errors?.some((error) => /Gecerlilik tarihi zorunludur/i.test(error.message ?? ""))).toBe(
      true
    );
  });

  test("MUHASEBE cannot update tc_kimlik_no to an existing personel tc", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await putPersonelApi(page, 1, { tc_kimlik_no: "23456789012" });

    expect(result.status).toBe(409);
    const tcError = result.body.errors?.find((error) => error.code === "DUPLICATE_TC_KIMLIK_NO");
    expect(tcError).toBeDefined();
    expect(tcError?.field).toBe("tc_kimlik_no");
  });

  test("MUHASEBE can update simple contact fields", async ({ page }) => {
    await loginAs(page, "MUHASEBE");

    const result = await putPersonelApi(page, 1, {
      ad: "Guncel",
      soyad: "Personel",
      telefon: "05550001122"
    });

    expect(result.status).toBe(200);
    const anaKart = result.body.data?.ana_kart as Record<string, unknown> | undefined;
    expect(anaKart?.ad).toBe("Guncel");
    expect(anaKart?.soyad).toBe("Personel");
    expect(anaKart?.telefon).toBe("05550001122");
  });
});
