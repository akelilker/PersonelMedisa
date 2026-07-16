import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AUTH_FORBIDDEN_EVENT, AUTH_UNAUTHORIZED_EVENT } from "../../src/lib/storage/auth-events";
import { shouldEmitGlobalAuthForbidden } from "../../src/lib/api-forbidden-policy";
import {
  ApiRequestError,
  apiRequest,
  getApiErrorDetail,
  getApiErrorMessage,
  isApiRequestError
} from "../../src/api/api-client";
import { getAuthTokenForApi } from "../../src/auth/auth-token-provider";

const { mockActiveSubeHeader } = vi.hoisted(() => ({
  mockActiveSubeHeader: vi.fn<[], string | null>(() => null)
}));

vi.mock("../../src/auth/auth-token-provider", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../src/auth/auth-token-provider")>();
  return {
    ...actual,
    getAuthTokenForApi: vi.fn(() => "test-token")
  };
});

vi.mock("../../src/auth/auth-manager", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../src/auth/auth-manager")>();
  return {
    ...actual,
    getActiveSubeIdForApiHeader: mockActiveSubeHeader
  };
});

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
    mockActiveSubeHeader.mockReset();
    mockActiveSubeHeader.mockReturnValue(null);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("attaches X-Active-Sube-Id when session sube header provider returns a value", async () => {
    mockActiveSubeHeader.mockReturnValue("7");

    const fetchMock = vi.fn(async () =>
      createJsonResponse({ data: { ok: true }, meta: {}, errors: [] }, 200)
    );
    vi.stubGlobal("fetch", fetchMock);

    await apiRequest("/personeller");

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = new Headers(init?.headers);
    expect(headers.get("X-Active-Sube-Id")).toBe("7");
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
    expect(headers.get("X-Active-Sube-Id")).toBeNull();
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

  it("emits forbidden event and throws ApiRequestError for global 403 response", async () => {
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

    await expect(apiRequest("/yonetim/kullanicilar")).rejects.toMatchObject({
      status: 403,
      message: "Bu kaynak icin yetkin yok.",
      code: "FORBIDDEN"
    });

    expect(forbiddenListener).toHaveBeenCalledWith({
      status: 403,
      path: "/yonetim/kullanicilar"
    });
  });

  it("throws ApiRequestError without forbidden event for 403 POST /personeller", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: null,
          meta: {},
          errors: [{ code: "FORBIDDEN", message: "Secili sube icin yetkiniz yok." }]
        },
        403
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const forbiddenListener = vi.fn();
    window.addEventListener(AUTH_FORBIDDEN_EVENT, (event) => {
      forbiddenListener((event as CustomEvent<{ status: number; path: string }>).detail);
    });

    await expect(
      apiRequest("/personeller", {
        method: "POST",
        body: JSON.stringify({ sube_id: 2 })
      })
    ).rejects.toMatchObject({
      status: 403,
      message: "Secili sube icin yetkiniz yok.",
      code: "FORBIDDEN"
    });

    expect(forbiddenListener).not.toHaveBeenCalled();
  });

  it("throws ApiRequestError without forbidden event for 403 GET /personeller/:id", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: null,
          meta: {},
          errors: [{ code: "FORBIDDEN", message: "Bu kayit aktif sube baglaminda goruntulenemiyor." }]
        },
        403
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const forbiddenListener = vi.fn();
    window.addEventListener(AUTH_FORBIDDEN_EVENT, (event) => {
      forbiddenListener((event as CustomEvent<{ status: number; path: string }>).detail);
    });

    await expect(apiRequest("/personeller/2")).rejects.toMatchObject({
      status: 403,
      message: "Bu kayit aktif sube baglaminda goruntulenemiyor.",
      code: "FORBIDDEN"
    });

    expect(forbiddenListener).not.toHaveBeenCalled();
  });

  it("throws ApiRequestError without forbidden event for 403 GET /surecler list", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: null,
          meta: {},
          errors: [{ code: "FORBIDDEN", message: "Bu kayit aktif sube baglaminda goruntulenemiyor." }]
        },
        403
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const forbiddenListener = vi.fn();
    window.addEventListener(AUTH_FORBIDDEN_EVENT, (event) => {
      forbiddenListener((event as CustomEvent<{ status: number; path: string }>).detail);
    });

    await expect(apiRequest("/surecler?personel_id=2&sube_id=1")).rejects.toMatchObject({
      status: 403,
      message: "Bu kayit aktif sube baglaminda goruntulenemiyor.",
      code: "FORBIDDEN"
    });

    expect(forbiddenListener).not.toHaveBeenCalled();
  });

  it("emits forbidden event and throws ApiRequestError for 403 GET /gunluk-puantaj/:id/:date", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: null,
          meta: {},
          errors: [{ code: "FORBIDDEN", message: "Bu kayit aktif sube baglaminda goruntulenemiyor." }]
        },
        403
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const forbiddenListener = vi.fn();
    window.addEventListener(AUTH_FORBIDDEN_EVENT, (event) => {
      forbiddenListener((event as CustomEvent<{ status: number; path: string }>).detail);
    });

    await expect(apiRequest("/gunluk-puantaj/2/2026-01-01")).rejects.toMatchObject({
      status: 403,
      code: "FORBIDDEN"
    });

    expect(forbiddenListener).toHaveBeenCalledWith({
      status: 403,
      path: "/gunluk-puantaj/2/2026-01-01"
    });
  });

  it("emits forbidden event and throws ApiRequestError for 403 PUT /gunluk-puantaj/:id/:date", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: null,
          meta: {},
          errors: [{ code: "FORBIDDEN", message: "Bu islem icin yetkin yok." }]
        },
        403
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const forbiddenListener = vi.fn();
    window.addEventListener(AUTH_FORBIDDEN_EVENT, (event) => {
      forbiddenListener((event as CustomEvent<{ status: number; path: string }>).detail);
    });

    await expect(
      apiRequest("/gunluk-puantaj/2/2026-01-01", {
        method: "PUT",
        body: JSON.stringify({ kontrol_durumu: "AMIR_KONTROL_ETTI" })
      })
    ).rejects.toMatchObject({
      status: 403,
      code: "FORBIDDEN"
    });

    expect(forbiddenListener).toHaveBeenCalledWith({
      status: 403,
      path: "/gunluk-puantaj/2/2026-01-01"
    });
  });

  it("emits forbidden event and throws ApiRequestError for 403 POST /puantaj/muhurle", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: null,
          meta: {},
          errors: [{ code: "FORBIDDEN", message: "Bu islem icin yetkin yok." }]
        },
        403
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const forbiddenListener = vi.fn();
    window.addEventListener(AUTH_FORBIDDEN_EVENT, (event) => {
      forbiddenListener((event as CustomEvent<{ status: number; path: string }>).detail);
    });

    await expect(
      apiRequest("/puantaj/muhurle", {
        method: "POST",
        body: JSON.stringify({ yil: 2026, ay: 4 })
      })
    ).rejects.toMatchObject({
      status: 403,
      code: "FORBIDDEN"
    });

    expect(forbiddenListener).toHaveBeenCalledWith({
      status: 403,
      path: "/puantaj/muhurle"
    });
  });

  it("emits forbidden event and throws ApiRequestError for 403 GET /personeller list", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: null,
          meta: {},
          errors: [{ code: "FORBIDDEN", message: "Secili sube icin yetkiniz yok." }]
        },
        403
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const forbiddenListener = vi.fn();
    window.addEventListener(AUTH_FORBIDDEN_EVENT, (event) => {
      forbiddenListener((event as CustomEvent<{ status: number; path: string }>).detail);
    });

    await expect(apiRequest("/personeller?page=1&limit=10")).rejects.toMatchObject({
      status: 403,
      code: "FORBIDDEN"
    });

    expect(forbiddenListener).toHaveBeenCalledWith({
      status: 403,
      path: "/personeller?page=1&limit=10"
    });
  });
});

describe("api error helpers", () => {
  it("isApiRequestError distinguishes ApiRequestError from generic Error", () => {
    expect(isApiRequestError(new ApiRequestError("Forbidden", 403, { code: "FORBIDDEN" }))).toBe(true);
    expect(isApiRequestError(new Error("Generic"))).toBe(false);
    expect(isApiRequestError(null)).toBe(false);
  });

  it("getApiErrorDetail preserves DUPLICATE_TC_KIMLIK_NO metadata", () => {
    const error = new ApiRequestError("Bu T.C. Kimlik No ile kayıt açılamaz.", 409, {
      code: "DUPLICATE_TC_KIMLIK_NO",
      field: "tc_kimlik_no"
    });

    expect(getApiErrorDetail(error, "Personel kaydı oluşturulamadı.")).toEqual({
      message: "Bu T.C. Kimlik No ile kayıt açılamaz.",
      status: 409,
      code: "DUPLICATE_TC_KIMLIK_NO",
      field: "tc_kimlik_no"
    });
  });

  it("maps personel-create FORBIDDEN yetkisiz sube message", () => {
    const error = new ApiRequestError("Secili sube icin yetkiniz yok.", 403, { code: "FORBIDDEN" });

    expect(
      getApiErrorDetail(error, "Personel kaydı oluşturulamadı.", { context: "personel-create" })
    ).toEqual({
      message: "Seçili şube için yetkiniz yok.",
      status: 403,
      code: "FORBIDDEN",
      field: undefined
    });
  });

  it("maps personel-create FORBIDDEN aktif sube mismatch message", () => {
    const error = new ApiRequestError("Bu kayit aktif sube baglaminda goruntulenemiyor.", 403, {
      code: "FORBIDDEN"
    });

    expect(
      getApiErrorDetail(error, "Personel kaydı oluşturulamadı.", { context: "personel-create" })
    ).toEqual({
      message: "Seçilen şube aktif şube filtresiyle uyuşmuyor.",
      status: 403,
      code: "FORBIDDEN",
      field: undefined
    });
  });

  it("passes through FORBIDDEN without personel-create context", () => {
    const error = new ApiRequestError("Bu kaynak icin yetkin yok.", 403, { code: "FORBIDDEN" });

    expect(getApiErrorDetail(error, "Islem basarisiz.")).toEqual({
      message: "Bu kaynak icin yetkin yok.",
      status: 403,
      code: "FORBIDDEN",
      field: undefined
    });
  });

  it("passes through VALIDATION_ERROR with field metadata", () => {
    const error = new ApiRequestError("Sube secilmelidir.", 422, {
      code: "VALIDATION_ERROR",
      field: "sube_id"
    });

    expect(getApiErrorDetail(error, "Personel kaydı oluşturulamadı.")).toEqual({
      message: "Sube secilmelidir.",
      status: 422,
      code: "VALIDATION_ERROR",
      field: "sube_id"
    });
  });

  it("returns generic Error message and fallback for unknown values", () => {
    expect(getApiErrorDetail(new Error("Client validation failed"), "Fallback")).toEqual({
      message: "Client validation failed"
    });
    expect(getApiErrorDetail(null, "Fallback")).toEqual({ message: "Fallback" });
  });

  it("uses fallback for empty ApiRequestError message", () => {
    const error = new ApiRequestError("   ", 500, { code: "INTERNAL_ERROR" });

    expect(getApiErrorDetail(error, "Sunucu hatasi.")).toEqual({
      message: "Sunucu hatasi.",
      status: 500,
      code: "INTERNAL_ERROR",
      field: undefined
    });
  });

  it("getApiErrorMessage returns detail message", () => {
    const error = new ApiRequestError("Secili sube icin yetkiniz yok.", 403, { code: "FORBIDDEN" });

    expect(getApiErrorMessage(error, "Personel kaydı oluşturulamadı.", { context: "personel-create" })).toBe(
      "Seçili şube için yetkiniz yok."
    );
    expect(getApiErrorMessage(error, "Personel kaydı oluşturulamadı.")).toBe("Secili sube icin yetkiniz yok.");
  });
});

describe("shouldEmitGlobalAuthForbidden", () => {
  it("suppresses global forbidden for scoped personel write/detail, belge and surecler paths", () => {
    expect(shouldEmitGlobalAuthForbidden("/personeller", "POST")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/personeller/2", "GET")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/personeller/2", "PUT")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/personeller/2/belge-durumu", "GET")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/personeller/2/belge-durumu", "PUT")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/personeller/3/belge-kayitlari", "GET")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/personeller/3/belge-kayitlari", "POST")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/api/personeller/2/belge-durumu", "GET")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/belge-kayitlari/9/iptal", "POST")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/surecler", "GET")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/surecler?personel_id=2&sube_id=1", "GET")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/api/surecler?personel_id=2", "GET")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/surecler/9", "GET")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/bildirimler/4", "GET")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/yonetim/subeler", "GET")).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/api/yonetim/subeler", "GET")).toBe(false);
  });

  it("keeps global forbidden for list, sub-resources, puantaj and unknown paths", () => {
    expect(shouldEmitGlobalAuthForbidden("/personeller", "GET")).toBe(true);
    expect(shouldEmitGlobalAuthForbidden("/personeller?page=1", "GET")).toBe(true);
    expect(shouldEmitGlobalAuthForbidden("/gunluk-puantaj/2/2026-01-01", "GET")).toBe(true);
    expect(shouldEmitGlobalAuthForbidden("/yonetim/kullanicilar", "GET")).toBe(true);
    expect(shouldEmitGlobalAuthForbidden("/belge-kayitlari/9", "PUT")).toBe(true);
    expect(shouldEmitGlobalAuthForbidden("/unknown-endpoint", "GET")).toBe(true);
  });
});
