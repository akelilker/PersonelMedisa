import { beforeEach, describe, expect, it, vi } from "vitest";
import type { CreateZimmetPayload, Zimmet } from "../../src/types/zimmet";

const { apiRequestMock, logActionMock } = vi.hoisted(() => ({
  apiRequestMock: vi.fn(),
  logActionMock: vi.fn()
}));

vi.mock("../../src/api/api-client", async () => {
  const actual = await vi.importActual<typeof import("../../src/api/api-client")>(
    "../../src/api/api-client"
  );
  return {
    ...actual,
    apiRequest: apiRequestMock
  };
});

vi.mock("../../src/audit/audit-service", () => ({
  logAction: logActionMock
}));

import { ApiRequestError } from "../../src/api/api-client";
import { createZimmet } from "../../src/api/zimmetler.api";

const payload: CreateZimmetPayload = {
  personel_id: 7,
  urun_turu: "KASK",
  teslim_tarihi: "2026-07-26",
  teslim_eden: "İdari İşler",
  teslim_durumu: "YENI"
};

const createdZimmet: Zimmet = {
  id: 31,
  personel_id: 7,
  urun_turu: "KASK",
  teslim_tarihi: "2026-07-26",
  teslim_eden: "İdari İşler",
  teslim_durumu: "YENI",
  zimmet_durumu: "AKTIF"
};

async function captureCreateError(): Promise<unknown> {
  try {
    await createZimmet(payload);
    return null;
  } catch (error) {
    return error;
  }
}

describe("createZimmet demo error envelope parity", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("canonical validation message, code, field ve status bilgisini korur", async () => {
    apiRequestMock.mockResolvedValueOnce({
      data: null,
      meta: {},
      errors: [
        {
          code: "VALIDATION_ERROR",
          field: "urun_turu",
          message: "Ürün türü zorunludur."
        }
      ]
    });

    const error = await captureCreateError();

    expect(error).toBeInstanceOf(ApiRequestError);
    expect(error).toMatchObject({
      status: 422,
      code: "VALIDATION_ERROR",
      field: "urun_turu",
      message: "Ürün türü zorunludur."
    });
  });

  it("validation mesajini normalize format fallback'i ile maskelemez", async () => {
    apiRequestMock.mockResolvedValueOnce({
      data: null,
      meta: {},
      errors: [
        {
          code: "VALIDATION_ERROR",
          field: "urun_turu",
          message: "Ürün türü zorunludur."
        }
      ]
    });

    const error = await captureCreateError();

    expect(error).toBeInstanceOf(ApiRequestError);
    expect((error as Error).message).not.toBe("Zimmet yaniti beklenen formatta degil.");
  });

  it("bos API message degerinde canonical fallback kullanir", async () => {
    apiRequestMock.mockResolvedValueOnce({
      data: null,
      meta: {},
      errors: [
        {
          code: "VALIDATION_ERROR",
          field: "urun_turu",
          message: "   "
        }
      ]
    });

    const error = await captureCreateError();

    expect(error).toMatchObject({
      status: 422,
      code: "VALIDATION_ERROR",
      field: "urun_turu",
      message: "Zimmet kaydı yapılamadı."
    });
  });

  it("farkli validation field bilgisini kaybetmez", async () => {
    apiRequestMock.mockResolvedValueOnce({
      data: null,
      meta: {},
      errors: [
        {
          code: "VALIDATION_ERROR",
          field: "teslim_tarihi",
          message: "Teslim tarihi zorunludur."
        }
      ]
    });

    const error = await captureCreateError();

    expect(error).toMatchObject({
      status: 422,
      code: "VALIDATION_ERROR",
      field: "teslim_tarihi",
      message: "Teslim tarihi zorunludur."
    });
  });

  it("basarili create sonucunu dondurur ve audit logu bir kez yazar", async () => {
    apiRequestMock.mockResolvedValueOnce({
      data: createdZimmet,
      meta: {},
      errors: []
    });

    await expect(createZimmet(payload)).resolves.toEqual(createdZimmet);
    expect(logActionMock).toHaveBeenCalledTimes(1);
    expect(logActionMock).toHaveBeenCalledWith({
      action: "ZIMMET_CREATE",
      payload: { zimmet_id: 31, personel_id: 7 }
    });
  });

  it("errors bos oldugunda mevcut normalize akisina devam eder", async () => {
    apiRequestMock.mockResolvedValueOnce({
      data: createdZimmet,
      meta: {},
      errors: []
    });

    const result = await createZimmet(payload);

    expect(result.id).toBe(31);
    expect(result.personel_id).toBe(7);
    expect(result.zimmet_durumu).toBe("AKTIF");
  });
});
