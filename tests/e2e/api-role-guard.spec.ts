import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yoneticisi", password: "demo123" },
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

test.describe("S71-B haftalik bildirim mutabakati API guards (mock-api)", () => {
  const weekStart = "2026-04-06";

  test("BIRIM_AMIRI ozet gorur, kendi kaydini onaylar ve ikinci onay 409 olur", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    const summary = await apiFetchJson(page, `/api/haftalik-bildirim-mutabakatlari/ozet?hafta_baslangic=${weekStart}`);
    expect(summary.status).toBe(200);
    expect((summary.data as { onaylanabilir_mi?: boolean }).onaylanabilir_mi).toBe(true);

    const approved = await apiFetchJson(page, "/api/haftalik-bildirim-mutabakatlari", {
      method: "POST",
      body: { hafta_baslangic: weekStart }
    });
    expect(approved.status).toBe(201);
    const detail = approved.data as {
      mutabakat?: { id?: number };
      gunluk_bildirimler?: Array<{ state?: string; haftalik_mutabakat_id?: number }>;
    };
    expect(detail.gunluk_bildirimler).toHaveLength(1);
    expect(detail.gunluk_bildirimler?.[0]?.state).toBe("HAFTALIK_MUTABAKATA_ALINDI");
    expect(detail.gunluk_bildirimler?.[0]?.haftalik_mutabakat_id).toBe(detail.mutabakat?.id);

    await expect(apiFetch(page, "/api/haftalik-bildirim-mutabakatlari", {
      method: "POST", body: { hafta_baslangic: weekStart }
    })).resolves.toMatchObject({ status: 409 });
  });

  test("TASLAK ve DUZELTME_ISTENDI acik kayitlari onayi bloklar", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");
    const draft = await apiFetchJson(page, "/api/bildirimler", {
      method: "POST", body: { ...BILDIRIM_CREATE_BODY, tarih: "2026-04-13" }
    });
    await expect(apiFetch(page, "/api/haftalik-bildirim-mutabakatlari", {
      method: "POST", body: { hafta_baslangic: "2026-04-13" }
    })).resolves.toMatchObject({ status: 409 });

    const id = bildirimRecord(draft.data).id;
    await apiFetchJson(page, `/api/bildirimler/${id}/submit`, { method: "POST" });
    await loginAs(page, "BOLUM_YONETICISI");
    await apiFetchJson(page, `/api/bildirimler/${id}/request-correction`, {
      method: "POST", body: { correction_reason: "Eksik bilgi" }
    });
    await loginAs(page, "BIRIM_AMIRI");
    await expect(apiFetch(page, "/api/haftalik-bildirim-mutabakatlari", {
      method: "POST", body: { hafta_baslangic: "2026-04-13" }
    })).resolves.toMatchObject({ status: 409 });
  });

  test("yonetim rolleri approve yapamaz ve scope disi detayi goremez", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");
    const approved = await apiFetchJson(page, "/api/haftalik-bildirim-mutabakatlari", {
      method: "POST", body: { hafta_baslangic: weekStart }
    });
    const id = (approved.data as { mutabakat?: { id?: number } }).mutabakat?.id;

    for (const role of ["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE"] as const) {
      await loginAs(page, role);
      await expect(apiFetch(page, "/api/haftalik-bildirim-mutabakatlari", {
        method: "POST", body: { hafta_baslangic: "2026-04-20" }
      })).resolves.toMatchObject({ status: 403 });
    }

    await loginAs(page, "BOLUM_YONETICISI");
    await expect(apiFetch(page, `/api/haftalik-bildirim-mutabakatlari/${id}`)).resolves.toMatchObject({ status: 403 });
  });
});

test.describe("S72-B aylik bildirim onayi API guards (mock-api)", () => {
  const weekStart = "2026-04-06";
  const ay = "2026-04";

  async function approveWeek(page: Page) {
    await apiFetchJson(page, "/api/haftalik-bildirim-mutabakatlari", {
      method: "POST",
      body: { hafta_baslangic: weekStart }
    });
  }

  test("BIRIM_AMIRI ozet gorur, haftalik mutabakat sonrasi ayi onaylar ve ikinci onay 409 olur", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");
    await approveWeek(page);

    const summary = await apiFetchJson(page, `/api/aylik-bildirim-onaylari/ozet?ay=${ay}`);
    expect(summary.status).toBe(200);
    const summaryData = summary.data as {
      ay?: string;
      ay_baslangic?: string;
      ay_bitis?: string;
      onaylanabilir_mi?: boolean;
    };
    expect(summaryData.ay).toBe(ay);
    expect(summaryData.ay_baslangic).toBe(`${ay}-01`);
    expect(summaryData.ay_bitis).toBe("2026-04-30");
    expect(summaryData.onaylanabilir_mi).toBe(true);

    const approved = await apiFetchJson(page, "/api/aylik-bildirim-onaylari", {
      method: "POST",
      body: { ay }
    });
    expect(approved.status).toBe(201);
    const detail = approved.data as { onay?: { id?: number } };
    const onayId = detail.onay?.id;
    expect(onayId).toBeTruthy();

    const detailResponse = await apiFetchJson(page, `/api/aylik-bildirim-onaylari/${onayId}`);
    expect(detailResponse.status).toBe(200);

    await expect(apiFetch(page, "/api/aylik-bildirim-onaylari", {
      method: "POST",
      body: { ay }
    })).resolves.toMatchObject({ status: 409 });
  });

  test("TASLAK, DUZELTME_ISTENDI ve GONDERILDI acik kayitlari aylik onayi bloklar", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");

    const draft = await apiFetchJson(page, "/api/bildirimler", {
      method: "POST",
      body: { personel_id: 1, tarih: "2026-04-15", bildirim_turu: "GEC_GELDI", aciklama: "Taslak" }
    });
    await expect(apiFetch(page, "/api/aylik-bildirim-onaylari", {
      method: "POST",
      body: { ay }
    })).resolves.toMatchObject({ status: 409 });

    const draftId = (draft.data as { id?: number }).id;
    await apiFetchJson(page, `/api/bildirimler/${draftId}/submit`, { method: "POST" });
    await expect(apiFetch(page, "/api/aylik-bildirim-onaylari", {
      method: "POST",
      body: { ay }
    })).resolves.toMatchObject({ status: 409 });

    await loginAs(page, "BOLUM_YONETICISI");
    await apiFetchJson(page, `/api/bildirimler/${draftId}/request-correction`, {
      method: "POST",
      body: { correction_reason: "Eksik bilgi" }
    });
    await loginAs(page, "BIRIM_AMIRI");
    await expect(apiFetch(page, "/api/aylik-bildirim-onaylari", {
      method: "POST",
      body: { ay }
    })).resolves.toMatchObject({ status: 409 });
  });

  test("haftalik mutabakat olmadan aylik onay 409 olur", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");
    await expect(apiFetch(page, "/api/aylik-bildirim-onaylari", {
      method: "POST",
      body: { ay }
    })).resolves.toMatchObject({ status: 409 });
  });

  test("yonetim rolleri approve yapamaz ve scope disi detayi goremez", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");
    await approveWeek(page);
    const approved = await apiFetchJson(page, "/api/aylik-bildirim-onaylari", {
      method: "POST",
      body: { ay }
    });
    const onayId = (approved.data as { onay?: { id?: number } }).onay?.id;

    for (const role of ["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE"] as const) {
      await loginAs(page, role);
      await expect(apiFetch(page, "/api/aylik-bildirim-onaylari", {
        method: "POST",
        body: { ay: "2026-05" }
      })).resolves.toMatchObject({ status: 403 });
    }

    await loginAs(page, "BOLUM_YONETICISI");
    await expect(apiFetch(page, `/api/aylik-bildirim-onaylari/${onayId}`)).resolves.toMatchObject({ status: 403 });
  });
});

test.describe("S73-B genel yonetici bildirim onayi API guards (mock-api)", () => {
  const weekStart = "2026-04-06";
  const ay = "2026-04";

  async function approveWeek(page: Page) {
    await apiFetchJson(page, "/api/haftalik-bildirim-mutabakatlari", {
      method: "POST",
      body: { hafta_baslangic: weekStart }
    });
  }

  async function approveAylik(page: Page) {
    await approveWeek(page);
    return apiFetchJson(page, "/api/aylik-bildirim-onaylari", {
      method: "POST",
      body: { ay }
    });
  }

  test("GENEL_YONETICI seeded temmuz ozetinde S72 onayini gorur ve ust onay verir", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const summary = await apiFetchJson(
      page,
      "/api/genel-yonetici-bildirim-onaylari/ozet?ay=2026-07&sube_id=1&birim_amiri_user_id=1"
    );
    expect(summary.status).toBe(200);
    const summaryData = summary.data as {
      onay_verilebilir_mi?: boolean;
      aylik_bildirim_onayi?: { id?: number; state?: string } | null;
      genel_yonetici_bildirim_onayi?: unknown;
    };
    expect(summaryData.aylik_bildirim_onayi?.id).toBe(1);
    expect(summaryData.aylik_bildirim_onayi?.state).toBe("TAMAMLANDI");
    expect(summaryData.genel_yonetici_bildirim_onayi).toBeNull();
    expect(summaryData.onay_verilebilir_mi).toBe(true);

    const approved = await apiFetchJson(
      page,
      "/api/genel-yonetici-bildirim-onaylari?sube_id=1",
      {
        method: "POST",
        body: { ay: "2026-07", birim_amiri_user_id: 1, aciklama: "Ust onay" }
      }
    );
    expect(approved.status).toBe(201);
    const detail = approved.data as { id?: number; state?: string; aylik_bildirim_onayi_id?: number };
    expect(detail.state).toBe("TAMAMLANDI");
    expect(detail.aylik_bildirim_onayi_id).toBe(1);

    await expect(
      apiFetch(page, "/api/genel-yonetici-bildirim-onaylari?sube_id=1", {
        method: "POST",
        body: { ay: "2026-07", birim_amiri_user_id: 1 }
      })
    ).resolves.toMatchObject({ status: 409 });
  });

  test("S72 onayi yoksa summary 200 ve approve 422 doner", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");

    const summary = await apiFetchJson(
      page,
      "/api/genel-yonetici-bildirim-onaylari/ozet?ay=2099-01&sube_id=1&birim_amiri_user_id=1"
    );
    expect(summary.status).toBe(200);
    expect((summary.data as { onay_verilebilir_mi?: boolean }).onay_verilebilir_mi).toBe(false);
    expect((summary.data as { blok_nedeni?: string }).blok_nedeni).toBe("AYLIK_BILDIRIM_ONAYI_GEREKLI");

    await expect(
      apiFetch(page, "/api/genel-yonetici-bildirim-onaylari?sube_id=1", {
        method: "POST",
        body: { ay: "2099-01", birim_amiri_user_id: 1 }
      })
    ).resolves.toMatchObject({ status: 422 });
  });

  test("BIRIM_AMIRI, BOLUM_YONETICISI ve MUHASEBE ust onay POST yapamaz", async ({ page }) => {
    for (const role of ["BIRIM_AMIRI", "BOLUM_YONETICISI", "MUHASEBE"] as const) {
      await loginAs(page, role);
      await expect(
        apiFetch(page, "/api/genel-yonetici-bildirim-onaylari?sube_id=1", {
          method: "POST",
          body: { ay: "2026-07", birim_amiri_user_id: 1 }
        })
      ).resolves.toMatchObject({ status: 403 });
      await expect(
        apiFetch(page, "/api/genel-yonetici-bildirim-onaylari/ozet?ay=2026-07&sube_id=1&birim_amiri_user_id=1")
      ).resolves.toMatchObject({ status: 403 });
    }
  });

  test("GENEL_YONETICI nisan akisinda S72 sonrasi ust onay verir", async ({ page }) => {
    await loginAs(page, "BIRIM_AMIRI");
    const aylikApproved = await approveAylik(page);
    expect(aylikApproved.status).toBe(201);

    await loginAs(page, "GENEL_YONETICI");
    const approved = await apiFetchJson(
      page,
      `/api/genel-yonetici-bildirim-onaylari?sube_id=1`,
      {
        method: "POST",
        body: { ay, birim_amiri_user_id: 1 }
      }
    );
    expect(approved.status).toBe(201);
  });

  test("cross-scope birim amiri 403 doner", async ({ page }) => {
    await loginAs(page, "GENEL_YONETICI");
    await expect(
      apiFetch(
        page,
        "/api/genel-yonetici-bildirim-onaylari/ozet?ay=2026-07&sube_id=1&birim_amiri_user_id=4"
      )
    ).resolves.toMatchObject({ status: 403 });
  });
});

const DISMISS_BODY = {
  expected_state: "HAZIR",
  gerekce: "Mevcut puantaj kaydiyla cakisti."
} as const;

const YOK_SAY_ENDPOINT = "/api/puantaj/bildirim-etki-adaylari/1/yok-say";

test.describe("S74-C2A puantaj etki adayi yok-say API guards (mock-api)", () => {
  test("unauthenticated request returns 401", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await page.goto("/login");
    const result = await page.evaluate(async () => {
      const response = await fetch("/api/puantaj/bildirim-etki-adaylari/1/yok-say", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          expected_state: "INCELEME_GEREKLI",
          gerekce: "Mevcut puantaj kaydiyla cakisti."
        })
      });
      return { status: response.status };
    });
    expect(result.status).toBe(401);
  });

  test("MUHASEBE can dismiss puantaj etki adayi", async ({ page }) => {
    await loginAs(page, "MUHASEBE");
    const result = await apiFetchJson(page, YOK_SAY_ENDPOINT, {
      method: "POST",
      body: { ...DISMISS_BODY }
    });
    expect(result.status).toBe(200);
    expect(result.data).toMatchObject({
      id: 1,
      state: "YOK_SAYILDI",
      idempotent: false
    });
  });

  test("non-MUHASEBE roles are denied dismiss", async ({ page }) => {
    for (const role of ["GENEL_YONETICI", "BOLUM_YONETICISI", "BIRIM_AMIRI"] as const) {
      await loginAs(page, role);
      await expect(
        apiFetch(page, YOK_SAY_ENDPOINT, {
          method: "POST",
          body: { ...DISMISS_BODY }
        })
      ).resolves.toMatchObject({ status: 403 });
    }
  });

  test("cross-sube dismiss returns 403", async ({ page }) => {
    await loginAs(page, "MUHASEBE");
    const result = await page.evaluate(async () => {
      const storageKey = "medisa_auth_session";
      const raw = sessionStorage.getItem(storageKey) ?? localStorage.getItem(storageKey);
      const session = raw ? (JSON.parse(raw) as { token?: string }) : null;
      const token = session?.token ?? "mock-token";

      const response = await fetch("/api/puantaj/bildirim-etki-adaylari/2/yok-say", {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
          "X-Active-Sube-Id": "1"
        },
        body: JSON.stringify({
          expected_state: "INCELEME_GEREKLI",
          gerekce: "Mevcut puantaj kaydiyla cakisti."
        })
      });

      return { status: response.status };
    });
    expect(result.status).toBe(403);
  });
});
