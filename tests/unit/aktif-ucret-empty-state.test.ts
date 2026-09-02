import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";

const { apiRequestMock } = vi.hoisted(() => ({
  apiRequestMock: vi.fn()
}));

vi.mock("../../src/api/api-client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../src/api/api-client")>();
  return {
    ...actual,
    apiRequest: apiRequestMock
  };
});

const { fetchPersonelAktifUcret } = await import("../../src/api/ucretler.api");

describe("fetchPersonelAktifUcret empty state (no wage record)", () => {
  afterEach(() => {
    apiRequestMock.mockReset();
  });

  it("returns the record when an active wage exists", async () => {
    apiRequestMock.mockResolvedValue({
      data: {
        id: 5,
        personel_id: 211,
        ucret_tutari: "1000",
        ucret_turu: "NET",
        para_birimi: "TRY",
        gecerlilik_baslangic: "2026-01-01",
        gecerlilik_bitis: null,
        durum: "AKTIF"
      },
      meta: {},
      errors: []
    });

    const result = await fetchPersonelAktifUcret(211);
    expect(result?.id).toBe(5);
    expect(apiRequestMock).toHaveBeenCalledTimes(1);
  });

  it("treats a domain 404 as an empty state with a single request", async () => {
    apiRequestMock.mockRejectedValue(
      new ApiRequestError("Belirtilen tarihte gecerli ucret kaydi yok.", 404, {
        code: "SALARY_MISSING",
        message: "Belirtilen tarihte gecerli ucret kaydi yok."
      })
    );

    await expect(fetchPersonelAktifUcret(211)).resolves.toBeNull();
    expect(apiRequestMock).toHaveBeenCalledTimes(1);
  });

  it("treats HTTP 200 with null data as empty state without throwing", async () => {
    apiRequestMock.mockResolvedValue({
      data: null,
      meta: {},
      errors: []
    });

    await expect(fetchPersonelAktifUcret(4)).resolves.toBeNull();
    expect(apiRequestMock).toHaveBeenCalledTimes(1);
  });

  it.each([
    [401, "UNAUTHORIZED"],
    [403, "FORBIDDEN"],
    [500, "SERVER_ERROR"]
  ])("keeps a %i failure as a real error", async (status, code) => {
    apiRequestMock.mockRejectedValue(
      new ApiRequestError("Hata", status as number, { code: code as string, message: "Hata" })
    );

    await expect(fetchPersonelAktifUcret(211)).rejects.toMatchObject({ status });
  });
});
