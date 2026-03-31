import { afterEach, describe, expect, it, vi } from "vitest";
import { login } from "../../src/api/auth.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("auth.api login", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("accepts wrapped api response shape", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse({
          data: {
            token: "wrapped-token",
            ui_profile: "yonetim",
            user: {
              id: 1,
              ad_soyad: "Ilker A",
              rol: "GENEL_YONETICI"
            }
          },
          meta: {},
          errors: []
        })
      )
    );

    const session = await login({ username: "ilker", password: "secret" });
    expect(session).toEqual({
      token: "wrapped-token",
      ui_profile: "yonetim",
      user: {
        id: 1,
        ad_soyad: "Ilker A",
        rol: "GENEL_YONETICI"
      }
    });
  });

  it("accepts raw backend response and normalizes role/profile fields", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse({
          token: "raw-token",
          user: {
            id: "12",
            full_name: "Birim Kullanici",
            role: "birim_amiri"
          }
        })
      )
    );

    const session = await login({ username: "birim", password: "secret" });
    expect(session).toEqual({
      token: "raw-token",
      ui_profile: "birim_amiri",
      user: {
        id: 12,
        ad_soyad: "Birim Kullanici",
        rol: "BIRIM_AMIRI"
      }
    });
  });

  it("bubbles backend message from successful but non-session payload", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse({
          data: null,
          message: "Kullanici adi veya sifre hatali."
        })
      )
    );

    await expect(login({ username: "bad", password: "bad" })).rejects.toMatchObject({
      message: "Kullanici adi veya sifre hatali."
    });
  });

  it("falls back to demo session when login endpoint returns 404", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: null,
            errors: [{ code: "NOT_FOUND", message: "Endpoint bulunamadi." }]
          },
          404
        )
      )
    );

    const session = await login({ username: "birim_demo", password: "secret" });
    expect(session.user.rol).toBe("BIRIM_AMIRI");
    expect(session.ui_profile).toBe("birim_amiri");
  });

  it("falls back to demo session when backend returns html payload", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        new Response("<!doctype html><html><body>fallback</body></html>", {
          status: 200,
          headers: {
            "Content-Type": "text/html"
          }
        })
      )
    );

    const session = await login({ username: "yonetici_demo", password: "secret" });
    expect(session.user.rol).toBe("GENEL_YONETICI");
    expect(session.ui_profile).toBe("yonetim");
  });
});
