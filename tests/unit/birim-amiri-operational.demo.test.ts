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

describe("demo BIRIM_AMIRI operational unit roster", () => {
  it("returns scoped unit personnel and denies other roles", () => {
    const amir = resolveDemoApiResponse("/bildirimler/birim-gunluk-durum?tarih=2026-04-09", {
      method: "GET",
      headers: demoHeaders("BIRIM_AMIRI", 12)
    }) as {
      data?: { personeller?: Array<{ personel_id: number; durum: string; gec_kalma_dakika: number | null }> };
      errors?: Array<{ code?: string }>;
    };

    const ids = (amir.data?.personeller ?? []).map((row) => row.personel_id);
    expect(ids).toContain(1);
    expect(ids).not.toContain(2);
    const ayse = amir.data?.personeller?.find((row) => row.personel_id === 1);
    expect(ayse?.durum).toBe("GEC_GELDI");
    expect(ayse?.gec_kalma_dakika).toBe(18);

    const genel = resolveDemoApiResponse("/bildirimler/birim-gunluk-durum?tarih=2026-04-09", {
      method: "GET",
      headers: demoHeaders("GENEL_YONETICI", 1)
    }) as { errors?: Array<{ code?: string }> };
    expect(genel.errors?.[0]?.code).toBe("FORBIDDEN");
  });
});
