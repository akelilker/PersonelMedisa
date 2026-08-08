import { beforeEach, describe, expect, it, vi } from "vitest";

const { apiRequestMock } = vi.hoisted(() => ({
  apiRequestMock: vi.fn()
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
  logAction: vi.fn()
}));

import { fetchPersonelBelgeHistory } from "../../src/api/personel-belge-kayitlari.api";

describe("fetchPersonelBelgeHistory", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("backend { auditler } şeklini kabul eder", async () => {
    apiRequestMock.mockResolvedValueOnce({
      data: {
        surumler: [],
        auditler: [
          {
            id: 9,
            islem_turu: "CREATED",
            yapan_kullanici_ad: "Yönetici",
            gerekce: null,
            dosya_adi: null,
            dosya_mime: null,
            dosya_byte: null,
            created_at: "2026-06-01T10:00:00+00:00"
          }
        ]
      },
      meta: {},
      errors: []
    });

    const items = await fetchPersonelBelgeHistory(55);

    expect(apiRequestMock).toHaveBeenCalledWith("/belge-kayitlari/55/gecmis");
    expect(items).toEqual([
      {
        id: 9,
        islem_turu: "CREATED",
        yapan_kullanici_ad: "Yönetici",
        gerekce: null,
        dosya_adi: null,
        dosya_mime: null,
        dosya_byte: null,
        created_at: "2026-06-01T10:00:00+00:00"
      }
    ]);
  });

  it("demo/mock { items } şeklini de kabul eder", async () => {
    apiRequestMock.mockResolvedValueOnce({
      data: {
        items: [
          {
            id: 3,
            islem_turu: "CANCELLED",
            yapan_kullanici_ad: "Ayşe",
            gerekce: "Eski",
            created_at: "2026-05-01T08:00:00+00:00"
          }
        ]
      },
      meta: {},
      errors: []
    });

    const items = await fetchPersonelBelgeHistory(12);

    expect(items).toHaveLength(1);
    expect(items[0]?.islem_turu).toBe("CANCELLED");
    expect(items[0]?.gerekce).toBe("Eski");
  });
});
