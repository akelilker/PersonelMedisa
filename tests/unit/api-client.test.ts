import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AUTH_FORBIDDEN_EVENT, AUTH_UNAUTHORIZED_EVENT } from "../../src/lib/storage/auth-events";
import { apiRequest } from "../../src/api/api-client";
import { getAuthTokenForApi } from "../../src/auth/auth-token-provider";

vi.mock("../../src/auth/auth-token-provider", () => ({
  getAuthTokenForApi: vi.fn(() => "test-token")
}));

type WindowLike = EventTarget;

function createWindowLike() {
  return new EventTarget() as WindowLike;
}

function createJsonResponse(body: unknown, status: number) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("apiRequest", () => {
  const getAuthTokenForApiMock = vi.mocked(getAuthTokenForApi);

  beforeEach(() => {
    vi.stubGlobal("window", createWindowLike());
    getAuthTokenForApiMock.mockReset();
    getAuthTokenForApiMock.mockReturnValue("test-token");
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("attaches auth header for protected endpoints", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({ data: { ok: true }, meta: {}, errors: [] }, 200)
    );
    vi.stubGlobal("fetch", fetchMock);

    await apiRequest("/personeller");

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = new Headers(init?.headers);
    expect(headers.get("Authorization")).toBe("Bearer test-token");
    expect(getAuthTokenForApiMock).toHaveBeenCalledTimes(1);
  });

  it("does not attach auth header for login endpoint", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse({ data: { token: "x" }, meta: {}, errors: [] }, 200)
    );
    vi.stubGlobal("fetch", fetchMock);

    await apiRequest("/auth/login", {
      method: "POST",
      body: JSON.stringify({ username: "user", password: "secret" })
    });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = new Headers(init?.headers);
    expect(headers.get("Authorization")).toBeNull();
    expect(getAuthTokenForApiMock).not.toHaveBeenCalled();
  });

  it("emits unauthorized event and throws ApiRequestError for 401 response", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: null,
          meta: {},
          errors: [{ code: "UNAUTHORIZED", message: "Oturum suresi doldu." }]
        },
        401
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const unauthorizedListener = vi.fn();
    window.addEventListener(AUTH_UNAUTHORIZED_EVENT, (event) => {
      unauthorizedListener((event as CustomEvent<{ status: number; path: string }>).detail);
    });

    await expect(apiRequest("/personeller")).rejects.toMatchObject({
      status: 401,
      message: "Oturum suresi doldu.",
      code: "UNAUTHORIZED"
    });

    expect(unauthorizedListener).toHaveBeenCalledWith({
      status: 401,
      path: "/personeller"
    });
  });

  it("emits forbidden event and throws ApiRequestError for 403 response", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: null,
          meta: {},
          errors: [{ code: "FORBIDDEN", message: "Bu kaynak icin yetkin yok." }]
        },
        403
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const forbiddenListener = vi.fn();
    window.addEventListener(AUTH_FORBIDDEN_EVENT, (event) => {
      forbiddenListener((event as CustomEvent<{ status: number; path: string }>).detail);
    });

    await expect(apiRequest("/surecler")).rejects.toMatchObject({
      status: 403,
      message: "Bu kaynak icin yetkin yok.",
      code: "FORBIDDEN"
    });

    expect(forbiddenListener).toHaveBeenCalledWith({
      status: 403,
      path: "/surecler"
    });
  });
});
