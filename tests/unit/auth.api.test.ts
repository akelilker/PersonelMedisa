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
      ui_profile: "birim",
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
});
