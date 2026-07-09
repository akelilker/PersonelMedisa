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

type ApiFetchJsonResult = {
  status: number;
  data: unknown;
};

async function apiFetch(
  page: Page,
  path: string,
  options?: { method?: string; body?: Record<string, unknown> }
): Promise<ApiFetchResult> {
  const result = await apiFetchJson(page, path, options);
  return { status: result.status };
}

async function apiFetchJson(
  page: Page,
  path: string,
  options?: { method?: string; body?: Record<string, unknown> }
): Promise<ApiFetchJsonResult> {
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

      const payload = (await response.json().catch(() => null)) as { data?: unknown } | null;

      return {
        status: response.status,
        data: payload?.data ?? null
      };
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
    await expect(apiFetch(page, "/api/yonetim/kullanicilar")).resolves.toMatchObject({ status: 403 });
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
    await expect(apiFetch(page, "/api/yonetim/kullanicilar")).resolves.toMatchObject({ status: 200 });
  });
});

const PUANTAJ_DETAIL = "/api/gunluk-puantaj/1/2026-04-09";
const AMIR_KONTROL_BODY = { kontrol_durumu: "AMIR_KONTROL_ETTI" } as const;
const FULL_UPDATE_BODY = { giris_saati: "09:00", cikis_saati: "18:00" } as const;
const MUHURLE_BODY = { yil: 2026, ay: 4 } as const;

test.describe("S70B-2C puantaj API role guards (mock-api)", () => {
  test("authorized roles can read gunluk puantaj detail", async ({ page }) => {
    for (const role of ["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"] as const) {
      await loginAs(page, role);
      await expect(apiFetch(page, PUANTAJ_DETAIL)).resolves.toMatchObject({ status: 200 });
    }
  });

  test("BIRIM_AMIRI can mark amir kontrol with kontrol-only payload", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");
    await expect(
      apiFetch(page, PUANTAJ_DETAIL, {
        method: "PUT",
        body: AMIR_KONTROL_BODY
      })
    ).resolves.toMatchObject({ status: 200 });
  });

  test("BIRIM_AMIRI is denied full puantaj update", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");
    await expect(
      apiFetch(page, PUANTAJ_DETAIL, {
        method: "PUT",
        body: FULL_UPDATE_BODY
      })
    ).resolves.toMatchObject({ status: 403 });
  });

  test("BIRIM_AMIRI is denied mixed amir kontrol payload with extra fields", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");
    await expect(
      apiFetch(page, PUANTAJ_DETAIL, {
        method: "PUT",
        body: {
          kontrol_durumu: "AMIR_KONTROL_ETTI",
          giris_saati: "09:00"
        }
      })
    ).resolves.toMatchObject({ status: 403 });
  });

  test("management roles can perform full puantaj update", async ({ page }) => {
    for (const role of ["GENEL_YONETICI", "MUHASEBE"] as const) {
      await loginAs(page, role);
      await expect(
        apiFetch(page, PUANTAJ_DETAIL, {
          method: "PUT",
          body: FULL_UPDATE_BODY
        })
      ).resolves.toMatchObject({ status: 200 });
    }
  });

  test("GENEL_YONETICI can mark amir kontrol with kontrol-only payload", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");
    await expect(
      apiFetch(page, PUANTAJ_DETAIL, {
        method: "PUT",
        body: AMIR_KONTROL_BODY
      })
    ).resolves.toMatchObject({ status: 200 });
  });

  test("only muhurle-authorized roles can seal monthly puantaj", async ({ page }) => {
    for (const role of ["GENEL_YONETICI", "BOLUM_YONETICISI"] as const) {
      await loginAs(page, role);
      await expect(
        apiFetch(page, "/api/puantaj/muhurle", {
          method: "POST",
          body: MUHURLE_BODY
        })
      ).resolves.toMatchObject({ status: 200 });
    }

    for (const role of ["MUHASEBE", "BIRIM_AMIRI"] as const) {
      await loginAs(page, role);
      await expect(
        apiFetch(page, "/api/puantaj/muhurle", {
          method: "POST",
          body: MUHURLE_BODY
        })
      ).resolves.toMatchObject({ status: 403 });
    }
  });
});

const BILDIRIM_CREATE_BODY = {
  personel_id: 1,
  tarih: "2026-04-15",
  departman_id: 3,
  bildirim_turu: "GEC_GELDI",
  aciklama: "E2E test bildirimi"
} as const;

function bildirimRecord(data: unknown): { id: number; state: string } {
  const record = data as { id?: number; state?: string };
  return {
    id: record.id ?? 0,
    state: record.state ?? ""
  };
}

test.describe("S70C-2 gunluk bildirim API role guards (mock-api)", () => {
  test("BIRIM_AMIRI can create bildirim as TASLAK", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    const created = await apiFetchJson(page, "/api/bildirimler", {
      method: "POST",
      body: { ...BILDIRIM_CREATE_BODY }
    });

    expect(created.status).toBe(201);
    expect(bildirimRecord(created.data).state).toBe("TASLAK");
  });

  test("BIRIM_AMIRI can fetch bildirim detail by id", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    const created = await apiFetchJson(page, "/api/bildirimler", {
      method: "POST",
      body: { ...BILDIRIM_CREATE_BODY, tarih: "2026-04-18" }
    });
    expect(created.status).toBe(201);
    const bildirimId = bildirimRecord(created.data).id;
    expect(bildirimId).toBeGreaterThan(0);

    const detail = await apiFetchJson(page, `/api/bildirimler/${bildirimId}`, {
      method: "GET"
    });
    expect(detail.status).toBe(200);
    expect(bildirimRecord(detail.data).id).toBe(bildirimId);
    expect(bildirimRecord(detail.data).state).toBe("TASLAK");
  });

  test("MUHASEBE and BOLUM_YONETICISI cannot create bildirim", async ({ page }) => {
    for (const role of ["MUHASEBE", "BOLUM_YONETICISI"] as const) {
      await loginAs(page, role);
      await expect(
        apiFetch(page, "/api/bildirimler", {
          method: "POST",
          body: { ...BILDIRIM_CREATE_BODY }
        })
      ).resolves.toMatchObject({ status: 403 });
    }
  });

  test("BIRIM_AMIRI workflow: update, submit, block update, correction, reopen update, cancel", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    const created = await apiFetchJson(page, "/api/bildirimler", {
      method: "POST",
      body: { ...BILDIRIM_CREATE_BODY, tarih: "2026-04-16" }
    });
    expect(created.status).toBe(201);
    const bildirimId = bildirimRecord(created.data).id;
    expect(bildirimId).toBeGreaterThan(0);

    const updated = await apiFetchJson(page, `/api/bildirimler/${bildirimId}`, {
      method: "PUT",
      body: { aciklama: "Guncellenmis aciklama" }
    });
    expect(updated.status).toBe(200);

    const submitted = await apiFetchJson(page, `/api/bildirimler/${bildirimId}/submit`, {
      method: "POST"
    });
    expect(submitted.status).toBe(200);
    expect(bildirimRecord(submitted.data).state).toBe("GONDERILDI");

    await expect(
      apiFetch(page, `/api/bildirimler/${bildirimId}`, {
        method: "PUT",
        body: { aciklama: "Gonderildikten sonra" }
      })
    ).resolves.toMatchObject({ status: 409 });

    await loginAs(page, "BOLUM_YONETICISI");
    const correction = await apiFetchJson(page, `/api/bildirimler/${bildirimId}/request-correction`, {
      method: "POST",
      body: { correction_reason: "Saat bilgisi hatali" }
    });
    expect(correction.status).toBe(200);
    expect(bildirimRecord(correction.data).state).toBe("DUZELTME_ISTENDI");

    await loginAs(page, "BIRIM_AMIRI");
    const reopened = await apiFetchJson(page, `/api/bildirimler/${bildirimId}`, {
      method: "PUT",
      body: { aciklama: "Duzeltme sonrasi" }
    });
    expect(reopened.status).toBe(200);

    const cancelDraft = await apiFetchJson(page, "/api/bildirimler", {
      method: "POST",
      body: { ...BILDIRIM_CREATE_BODY, tarih: "2026-04-17" }
    });
    const cancelId = bildirimRecord(cancelDraft.data).id;

    const cancelled = await apiFetchJson(page, `/api/bildirimler/${cancelId}/iptal`, {
      method: "POST"
    });
    expect(cancelled.status).toBe(200);
    expect(bildirimRecord(cancelled.data).state).toBe("IPTAL");
  });

  test("MUHASEBE cannot cancel bildirim", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");
    const created = await apiFetchJson(page, "/api/bildirimler", {
      method: "POST",
      body: { ...BILDIRIM_CREATE_BODY, tarih: "2026-04-18" }
    });
    const bildirimId = bildirimRecord(created.data).id;

    await loginAs(page, "MUHASEBE");
    await expect(apiFetch(page, `/api/bildirimler/${bildirimId}/iptal`, { method: "POST" })).resolves.toMatchObject({
      status: 403
    });
  });
});
