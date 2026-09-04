import { describe, expect, it } from "vitest";
import { resolveDemoApiResponse } from "../../src/api/mock-demo";
import type { UserRole } from "../../src/types/auth";

function demoHeaders(role: UserRole, userId?: number): Headers {
  const headers = new Headers();
  headers.set("X-Demo-Role", role);
  if (userId !== undefined) {
    headers.set("X-Demo-User-Id", String(userId));
  }
  return headers;
}

describe("demo daily attendance summary notification", () => {
  it("complete day yields one header item; retry is idempotent; detail is authorized", () => {
    const tarih = "2099-07-21";
    const amirHeaders = demoHeaders("BIRIM_AMIRI", 12);

    const created = resolveDemoApiResponse("/bildirimler", {
      method: "POST",
      headers: amirHeaders,
      body: JSON.stringify({
        tarih,
        personel_id: 1,
        bildirim_turu: "GELMEDI"
      })
    }) as { data?: { id?: number }; errors?: unknown[] };

    const bildirimId = created.data?.id;
    expect(bildirimId).toBeTypeOf("number");

    resolveDemoApiResponse(`/bildirimler/${bildirimId}/submit`, {
      method: "POST",
      headers: amirHeaders
    });

    const first = resolveDemoApiResponse("/bildirimler/gunluk-tamamlama", {
      method: "POST",
      headers: amirHeaders,
      body: JSON.stringify({ tarih })
    }) as { data: { id: number; tamamlayan_ad_soyad?: string }; errors?: unknown[] };

    expect(first.errors ?? []).toHaveLength(0);
    expect(typeof first.data.id).toBe("number");

    const second = resolveDemoApiResponse("/bildirimler/gunluk-tamamlama", {
      method: "POST",
      headers: amirHeaders,
      body: JSON.stringify({ tarih })
    }) as { data: { id: number } };

    expect(second.data.id).toBe(first.data.id);

    const header = resolveDemoApiResponse("/bildirimler/gunluk-tamamlamalari?limit=50", {
      method: "GET",
      headers: demoHeaders("GENEL_YONETICI", 1)
    }) as {
      data: { items: Array<{ id: number; kind?: string; tarih: string }> };
    };

    const matches = header.data.items.filter((item) => item.id === first.data.id);
    expect(matches).toHaveLength(1);
    expect(matches[0]?.kind).toBe("gunluk_tamamlama");
    expect(matches[0]?.tarih).toBe(tarih);

    // Individual row list still exists as source data, but is not the header endpoint.
    const rows = resolveDemoApiResponse(`/bildirimler?tarih=${tarih}&limit=20`, {
      method: "GET",
      headers: demoHeaders("GENEL_YONETICI", 1)
    }) as { data: { items: Array<{ bildirim_turu: string }> } };
    expect(rows.data.items.some((item) => item.bildirim_turu === "GELMEDI")).toBe(true);

    const detail = resolveDemoApiResponse(`/bildirimler/gunluk-tamamlama/${first.data.id}`, {
      method: "GET",
      headers: demoHeaders("GENEL_YONETICI", 1)
    }) as {
      data: {
        ozet: { gelmeyen: number; izinli_raporlu: number };
        kategoriler: Array<{ tur: string; count: number }>;
        submission: { tamamlayan_ad_soyad: string };
      };
    };

    expect(detail.data.ozet.gelmeyen).toBeGreaterThanOrEqual(1);
    expect(detail.data.kategoriler.find((k) => k.tur === "GELMEDI")?.count).toBeGreaterThanOrEqual(1);
    expect(detail.data.submission.tamamlayan_ad_soyad.length).toBeGreaterThan(0);

    const denied = resolveDemoApiResponse(`/bildirimler/gunluk-tamamlama/${first.data.id}`, {
      method: "GET",
      headers: demoHeaders("BOLUM_YONETICISI", 2)
    }) as { data: unknown; errors?: Array<{ code?: string }> };

    expect(denied.data).toBeNull();
    expect(denied.errors?.[0]?.code).toBe("FORBIDDEN");
  });
});
