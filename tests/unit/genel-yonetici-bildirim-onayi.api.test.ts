import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import {
  approveGenelYoneticiBildirimOnayi,
  fetchGenelYoneticiBildirimOnayiOzet
} from "../../src/api/genel-yonetici-bildirim-onaylari.api";

function response(data: unknown, status = 200, errors: unknown[] = []) {
  return new Response(JSON.stringify({ data, meta: {}, errors }), {
    status,
    headers: { "Content-Type": "application/json" }
  });
}

describe("genel-yonetici-bildirim-onaylari.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("summary baglamini query stringe tasir", async () => {
    const fetchMock = vi.fn(async () => response({ ay: "2026-06" }));
    vi.stubGlobal("fetch", fetchMock);
    await fetchGenelYoneticiBildirimOnayiOzet("2026-06", 1, 3);
    expect(fetchMock.mock.calls[0]?.[0]).toBe(
      "/api/genel-yonetici-bildirim-onaylari/ozet?ay=2026-06&sube_id=1&birim_amiri_user_id=3"
    );
  });

  it("approve body icinde yalniz kilitli baglami gonderir", async () => {
    const fetchMock = vi.fn(async () => response({ id: 8 }, 201));
    vi.stubGlobal("fetch", fetchMock);
    await approveGenelYoneticiBildirimOnayi({ ay: "2026-06", sube_id: 1, birim_amiri_user_id: 3 });
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/genel-yonetici-bildirim-onaylari?sube_id=1");
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({ ay: "2026-06", sube_id: 1, birim_amiri_user_id: 3 });
  });

  for (const [status, code] of [[409, "GENEL_YONETICI_BILDIRIM_ONAYI_MEVCUT"], [422, "AYLIK_BILDIRIM_ONAYI_GEREKLI"]] as const) {
    it(`${status} API hatasini status ve code ile tasir`, async () => {
      vi.stubGlobal("fetch", vi.fn(async () => response(null, status, [{ code, message: "safe" }])));
      await expect(
        approveGenelYoneticiBildirimOnayi({ ay: "2026-06", sube_id: 1, birim_amiri_user_id: 3 })
      ).rejects.toMatchObject<ApiRequestError>({ status, code });
    });
  }
});
