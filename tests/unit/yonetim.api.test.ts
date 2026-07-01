import { afterEach, describe, expect, it, vi } from "vitest";
import {
  createYonetimKullanici,
  fetchYonetimKullanicilari,
  updateYonetimKullanici
} from "../../src/api/yonetim.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("yonetim.api kullanicilar", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("fetchYonetimKullanicilari normalizes list without password fields", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({
        data: {
          items: [
            {
              id: 2,
              username: "muhasebe",
              ad_soyad: "Test Muhasebe",
              rol: "MUHASEBE",
              durum: "AKTIF",
              sube_ids: [1, 2],
              varsayilan_sube_id: 1,
              kullanici_tipi: "HARICI",
              password_hash: "must-not-leak"
            }
          ]
        },
        meta: [],
        errors: []
      })
    );
    vi.stubGlobal("fetch", fetchMock);

    const items = await fetchYonetimKullanicilari();
    expect(items).toHaveLength(1);
    expect(items[0]?.username).toBe("muhasebe");
    expect(items[0]?.sube_ids).toEqual([1, 2]);
    expect(items[0]).not.toHaveProperty("password");
    expect(items[0]).not.toHaveProperty("password_hash");
  });

  it("createYonetimKullanici sends username and password in request body", async () => {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
      const body = JSON.parse(String(init?.body ?? "{}")) as Record<string, unknown>;
      expect(body.username).toBe("muhasebe");
      expect(body.password).toBe("GeciciSifre2026");
      expect(body).not.toHaveProperty("password_hash");

      return createJsonResponse({
        data: {
          id: 9,
          username: "muhasebe",
          ad_soyad: "Test Muhasebe",
          rol: "MUHASEBE",
          durum: "AKTIF",
          sube_ids: [1, 2],
          varsayilan_sube_id: 1,
          kullanici_tipi: "HARICI"
        },
        meta: [],
        errors: []
      });
    });
    vi.stubGlobal("fetch", fetchMock);

    const created = await createYonetimKullanici({
      username: "muhasebe",
      password: "GeciciSifre2026",
      ad_soyad: "Test Muhasebe",
      kullanici_tipi: "HARICI",
      rol: "MUHASEBE",
      sube_ids: [1, 2],
      varsayilan_sube_id: 1,
      durum: "AKTIF"
    });

    expect(created.username).toBe("muhasebe");
    expect(created).not.toHaveProperty("password");
    expect(created).not.toHaveProperty("password_hash");
  });

  it("updateYonetimKullanici omits password when not provided", async () => {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
      const body = JSON.parse(String(init?.body ?? "{}")) as Record<string, unknown>;
      expect(body.username).toBe("muhasebe");
      expect(body).not.toHaveProperty("password");

      return createJsonResponse({
        data: {
          id: 9,
          username: "muhasebe",
          ad_soyad: "Test Muhasebe",
          rol: "MUHASEBE",
          durum: "AKTIF",
          sube_ids: [1, 2],
          varsayilan_sube_id: 1,
          kullanici_tipi: "HARICI"
        },
        meta: [],
        errors: []
      });
    });
    vi.stubGlobal("fetch", fetchMock);

    await updateYonetimKullanici(9, {
      username: "muhasebe",
      ad_soyad: "Test Muhasebe",
      kullanici_tipi: "HARICI",
      rol: "MUHASEBE",
      sube_ids: [1, 2],
      varsayilan_sube_id: 1,
      durum: "AKTIF"
    });

    expect(fetchMock).toHaveBeenCalledOnce();
  });
});
