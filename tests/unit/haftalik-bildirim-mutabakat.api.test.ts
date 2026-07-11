import { afterEach, describe, expect, it, vi } from "vitest";
import {
  approveHaftalikBildirimMutabakat,
  fetchHaftalikBildirimMutabakatDetail,
  fetchHaftalikBildirimMutabakatOzet
} from "../../src/api/haftalik-bildirim-mutabakatlari.api";

function response(data: unknown, status = 200) {
  return new Response(JSON.stringify({ data, meta: {}, errors: [] }), {
    status,
    headers: { "Content-Type": "application/json" }
  });
}

describe("haftalik-bildirim-mutabakatlari.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("ozet endpointine hafta baslangici ile GET yapar", async () => {
    const fetchMock = vi.fn(async () => response({ hafta_baslangic: "2026-04-06" }));
    vi.stubGlobal("fetch", fetchMock);
    await fetchHaftalikBildirimMutabakatOzet("2026-04-06");
    expect(fetchMock.mock.calls[0]?.[0]).toBe(
      "/api/haftalik-bildirim-mutabakatlari/ozet?hafta_baslangic=2026-04-06"
    );
  });

  it("read-only panel baglamini query parametreleriyle gonderir", async () => {
    const fetchMock = vi.fn(async () => response({ hafta_baslangic: "2026-04-06" }));
    vi.stubGlobal("fetch", fetchMock);
    await fetchHaftalikBildirimMutabakatOzet("2026-04-06", {
      subeId: 2,
      birimAmiriUserId: 7
    });
    expect(fetchMock.mock.calls[0]?.[0]).toBe(
      "/api/haftalik-bildirim-mutabakatlari/ozet?hafta_baslangic=2026-04-06&sube_id=2&birim_amiri_user_id=7"
    );
  });

  it("onay endpointine POST body gonderir", async () => {
    const fetchMock = vi.fn(async () => response({ mutabakat: { id: 5 }, gunluk_bildirimler: [], counts: {} }, 201));
    vi.stubGlobal("fetch", fetchMock);
    await approveHaftalikBildirimMutabakat("2026-04-06");
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({ hafta_baslangic: "2026-04-06" });
  });

  it("detail endpointinden kaydi getirir", async () => {
    const fetchMock = vi.fn(async () => response({ mutabakat: { id: 7 }, gunluk_bildirimler: [], counts: {} }));
    vi.stubGlobal("fetch", fetchMock);
    await fetchHaftalikBildirimMutabakatDetail(7);
    expect(fetchMock.mock.calls[0]?.[0]).toBe("/api/haftalik-bildirim-mutabakatlari/7");
  });
});
