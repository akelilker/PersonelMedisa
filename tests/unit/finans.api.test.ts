import { afterEach, describe, expect, it, vi } from "vitest";
import {
  cancelFinansKalem,
  createFinansKalem,
  fetchFinansKalemList,
  updateFinansKalem
} from "../../src/api/finans.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("finans.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("fetches finans list and normalizes pagination metadata", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            items: [
              {
                id: 11,
                personel_id: 1,
                donem: "2026-04",
                kalem_turu: "AVANS",
                tutar: 2500,
                state: "AKTIF"
              }
            ]
          },
          meta: {
            page: 1,
            limit: 10,
            total: 24,
            total_pages: 3
          },
          errors: []
        },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchFinansKalemList({ page: 1, limit: 10 });

    const [url] = fetchMock.mock.calls[0] as [string];
    expect(url).toContain("/api/ek-odeme-kesinti");
    expect(url).toContain("page=1");
    expect(url).toContain("limit=10");
    expect(result.items[0]?.kalem_turu).toBe("AVANS");
    expect(result.pagination.totalPages).toBe(3);
    expect(result.pagination.hasNextPage).toBe(true);
  });

  it("posts create payload and returns normalized finans record", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: 90,
            personel_id: 5,
            donem: "2026-04",
            kalem_turu: "PRIM",
            tutar: 1200
          },
          meta: {},
          errors: []
        },
        200
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await createFinansKalem({
      personel_id: 5,
      donem: "2026-04",
      kalem_turu: "PRIM",
      tutar: 1200
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/ek-odeme-kesinti");
    expect(init.method).toBe("POST");
    expect(result.id).toBe(90);
  });

  it("sends update and cancel calls to detail endpoints", async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url.endsWith("/iptal")) {
        return createJsonResponse({ data: { id: 55, state: "IPTAL" }, meta: {}, errors: [] }, 200);
      }

      if (init?.method === "PUT") {
        return createJsonResponse(
          {
            data: {
              id: 55,
              personel_id: 7,
              donem: "2026-05",
              kalem_turu: "CEZA",
              tutar: 350,
              state: "AKTIF"
            },
            meta: {},
            errors: []
          },
          200
        );
      }

      return createJsonResponse({ data: null, meta: {}, errors: [] }, 200);
    });
    vi.stubGlobal("fetch", fetchMock);

    const updated = await updateFinansKalem(55, {
      kalem_turu: "CEZA",
      tutar: 350
    });
    await cancelFinansKalem(55);

    expect(updated.id).toBe(55);
    expect(updated.kalem_turu).toBe("CEZA");
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "/api/ek-odeme-kesinti/55",
      expect.objectContaining({ method: "PUT" })
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "/api/ek-odeme-kesinti/55/iptal",
      expect.objectContaining({ method: "POST" })
    );
  });
});
