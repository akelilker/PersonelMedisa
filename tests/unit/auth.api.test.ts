import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { login } from "../../src/api/auth.api";

const { mockIsRealBackendOnlyMode } = vi.hoisted(() => ({
  mockIsRealBackendOnlyMode: vi.fn(() => false)
}));

vi.mock("../../src/config/app-env", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../src/config/app-env")>();
  return {
    ...actual,
    isRealBackendOnlyMode: mockIsRealBackendOnlyMode
  };
});

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("auth.api login", () => {
  beforeEach(() => {
    mockIsRealBackendOnlyMode.mockReturnValue(false);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    mockIsRealBackendOnlyMode.mockReset();
    mockIsRealBackendOnlyMode.mockReturnValue(false);
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
    expect(session).toEqual(
      expect.objectContaining({
        token: "wrapped-token",
        ui_profile: "yonetim",
        user: expect.objectContaining({
          id: 1,
          ad_soyad: "Ilker A",
          rol: "GENEL_YONETICI"
        })
      })
    );
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
    expect(session).toEqual(
      expect.objectContaining({
        token: "raw-token",
        ui_profile: "birim_amiri",
        user: expect.objectContaining({
          id: 12,
          ad_soyad: "Birim Kullanici",
          rol: "BIRIM_AMIRI"
        })
      })
    );
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

  describe("in demo-enabled mode", () => {
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
      expect(session.user.sube_ids).toEqual([1]);
      expect(session.active_sube_id).toBe(1);
      expect(session.sube_list).toEqual([{ id: 1, ad: "Merkez" }]);
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
      expect(session.active_sube_id).toBeNull();
    });

    it("maps demo username containing patron to GENEL_YONETICI (safe alias)", async () => {
      vi.stubGlobal(
        "fetch",
        vi.fn(async () => createJsonResponse({ errors: [] }, 404))
      );

      const session = await login({ username: "patron", password: "demo123" });
      expect(session.user.rol).toBe("GENEL_YONETICI");
    });
  });

  describe("in real-backend-only mode", () => {
    beforeEach(() => {
      mockIsRealBackendOnlyMode.mockReturnValue(true);
    });

    it("fails closed when login endpoint returns 404", async () => {
      const error = { code: "NOT_FOUND", message: "Endpoint bulunamadi." };
      vi.stubGlobal("fetch", vi.fn(async () => createJsonResponse({ errors: [error] }, 404)));

      await expect(login({ username: "any", password: "user" })).rejects.toMatchObject({
        status: 404,
        message: error.message
      });
    });

    it("fails closed when login endpoint returns 503", async () => {
      const error = { code: "UNAVAILABLE", message: "Servis kullanilamiyor." };
      vi.stubGlobal("fetch", vi.fn(async () => createJsonResponse({ errors: [error] }, 503)));

      await expect(login({ username: "any", password: "user" })).rejects.toMatchObject({
        status: 503,
        message: error.message
      });
    });

    it("fails closed when backend returns html payload", async () => {
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

      await expect(login({ username: "any", password: "user" })).rejects.toMatchObject({
        message: "Login yanıtı beklenen oturum formatında değil."
      });
    });
  });

  it("canonicalizes IK_BORDRO login payload to IK_SORUMLUSU", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse({
          data: {
            token: "ik-token",
            ui_profile: "yonetim",
            user: {
              id: 8,
              ad_soyad: "Fixture Preparer",
              rol: "IK_BORDRO",
              sube_ids: [1, 2]
            },
            active_sube_id: 1
          }
        })
      )
    );

    const session = await login({ username: "hazirlayan", password: "secret" });
    expect(session.token).toBe("ik-token");
    expect(session.user.rol).toBe("IK_SORUMLUSU");
  });

  it("fail-closes unresolved SGK_KARAR_ONAY_YETKILISI login role", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse({
          data: {
            token: "appr-token",
            user: { rol: "SGK_KARAR_ONAY_YETKILISI" }
          }
        })
      )
    );

    await expect(login({ username: "onaylayan", password: "secret" })).rejects.toMatchObject({
      message: "Login yanıtı beklenen oturum formatında değil."
    });
  });

  it("fail-closes unknown role even when login HTTP 200", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse({
          data: {
            token: "bad-role-token",
            user: { rol: "HAYALI_ROL" }
          }
        })
      )
    );

    await expect(login({ username: "x", password: "y" })).rejects.toMatchObject({
      message: "Login yanıtı beklenen oturum formatında değil."
    });
  });
});
