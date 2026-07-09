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

function expectForbidden(response: ReturnType<typeof resolveDemoApiResponse>) {
  expect(response?.data).toBeNull();
  expect(response?.errors?.[0]?.code).toBe("FORBIDDEN");
}

describe("mock-demo API guards", () => {
  it("GENEL_YONETICI bolum onayi yapamaz", () => {
    const response = resolveDemoApiResponse("/yonetim/aylik-ozet/bolum-onay", {
      method: "POST",
      headers: demoHeaders("GENEL_YONETICI", 1),
      body: JSON.stringify({ ay: "2099-01", sube_id: 1 })
    });

    expectForbidden(response);
  });

  it("BOLUM_YONETICISI bolum onayi yapabilir", () => {
    const response = resolveDemoApiResponse("/yonetim/aylik-ozet/bolum-onay", {
      method: "POST",
      headers: demoHeaders("BOLUM_YONETICISI", 2),
      body: JSON.stringify({ ay: "2099-02", sube_id: 2 })
    });

    expect(response?.errors ?? []).toHaveLength(0);
    expect(response?.data).not.toBeNull();
  });

  it("GENEL_YONETICI bekleyen bolum onayi varken ay kapatamaz", () => {
    const response = resolveDemoApiResponse("/yonetim/aylik-ozet/ay-kapat", {
      method: "POST",
      headers: demoHeaders("GENEL_YONETICI", 1),
      body: JSON.stringify({ ay: "2099-03", sube_id: 1 })
    });

    expect(response?.data).toBeNull();
    expect(response?.errors?.[0]?.code).toBe("PENDING_BOLUM_ONAY");
    expect(response?.errors?.[0]?.message).toBe(
      "Bekleyen bölüm onayları tamamlanmadan genel yönetici onayı verilemez."
    );
  });

  it("BIRIM_AMIRI kontrol-only PUT yapabilir", () => {
    const response = resolveDemoApiResponse("/gunluk-puantaj/1/2026-04-09", {
      method: "PUT",
      headers: demoHeaders("BIRIM_AMIRI", 3),
      body: JSON.stringify({ kontrol_durumu: "AMIR_KONTROL_ETTI" })
    });

    expect(response?.errors ?? []).toHaveLength(0);
    expect(response?.data).not.toBeNull();
  });

  it("BIRIM_AMIRI tam update yapamaz", () => {
    const response = resolveDemoApiResponse("/gunluk-puantaj/1/2026-04-09", {
      method: "PUT",
      headers: demoHeaders("BIRIM_AMIRI", 3),
      body: JSON.stringify({ giris_saati: "09:00", cikis_saati: "18:00" })
    });

    expectForbidden(response);
  });

  it("BIRIM_AMIRI mixed payload yapamaz", () => {
    const response = resolveDemoApiResponse("/gunluk-puantaj/1/2026-04-09", {
      method: "PUT",
      headers: demoHeaders("BIRIM_AMIRI", 3),
      body: JSON.stringify({
        kontrol_durumu: "AMIR_KONTROL_ETTI",
        giris_saati: "09:00"
      })
    });

    expectForbidden(response);
  });

  it("MUHASEBE muhurleme yapamaz", () => {
    const response = resolveDemoApiResponse("/puantaj/muhurle", {
      method: "POST",
      headers: demoHeaders("MUHASEBE"),
      body: JSON.stringify({ yil: 2026, ay: 4 })
    });

    expectForbidden(response);
  });

  it("BOLUM_YONETICISI muhurleme yapabilir", () => {
    const response = resolveDemoApiResponse("/puantaj/muhurle", {
      method: "POST",
      headers: demoHeaders("BOLUM_YONETICISI", 2),
      body: JSON.stringify({ yil: 2026, ay: 4 })
    });

    expect(response?.errors ?? []).toHaveLength(0);
    expect(response?.data).not.toBeNull();
  });
});
