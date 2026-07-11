import { afterEach, describe, expect, it, vi } from "vitest";
import {
  approveAylikBildirimOnayi,
  fetchAylikBildirimOnayiDetail,
  fetchAylikBildirimOnayiOzet
} from "../../src/api/aylik-bildirim-onaylari.api";

function response(data: unknown, status = 200) {
  return new Response(JSON.stringify({ data, meta: {}, errors: [] }), {
    status,
    headers: { "Content-Type": "application/json" }
  });
}

describe("aylik-bildirim-onaylari.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("ozet endpointine ay parametresi ile GET yapar", async () => {
    const fetchMock = vi.fn(async () => response({ ay: "2026-04" }));
    vi.stubGlobal("fetch", fetchMock);
    await fetchAylikBildirimOnayiOzet("2026-04");
    expect(fetchMock.mock.calls[0]?.[0]).toBe("/api/aylik-bildirim-onaylari/ozet?ay=2026-04");
  });

  it("read-only panel baglamini query parametreleriyle gonderir", async () => {
    const fetchMock = vi.fn(async () => response({ ay: "2026-04" }));
    vi.stubGlobal("fetch", fetchMock);
    await fetchAylikBildirimOnayiOzet("2026-04", { subeId: 1, birimAmiriUserId: 3 });
    expect(fetchMock.mock.calls[0]?.[0]).toBe(
      "/api/aylik-bildirim-onaylari/ozet?ay=2026-04&sube_id=1&birim_amiri_user_id=3"
    );
  });

  it("onay endpointine POST body gonderir", async () => {
    const fetchMock = vi.fn(async () => response({ onay: { id: 3 } }, 201));
    vi.stubGlobal("fetch", fetchMock);
    await approveAylikBildirimOnayi({ ay: "2026-04", aciklama: "Kontrol tamam" });
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({ ay: "2026-04", aciklama: "Kontrol tamam" });
  });

  it("detail endpointinden kaydi getirir", async () => {
    const fetchMock = vi.fn(async () => response({ onay: { id: 8 } }));
    vi.stubGlobal("fetch", fetchMock);
    await fetchAylikBildirimOnayiDetail(8);
    expect(fetchMock.mock.calls[0]?.[0]).toBe("/api/aylik-bildirim-onaylari/8");
  });
});
