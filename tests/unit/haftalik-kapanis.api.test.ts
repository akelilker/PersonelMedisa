import { afterEach, describe, expect, it, vi } from "vitest";
import { createHaftalikKapanis } from "../../src/api/haftalik-kapanis.api";

function createJsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("haftalik-kapanis.api", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("posts weekly close payload and normalizes response fields", async () => {
    const fetchMock = vi.fn(async () =>
      createJsonResponse(
        {
          data: {
            id: "99",
            week_start: "2026-04-06",
            week_end: "2026-04-12",
            departman_id: 3,
            durum: "KAPANDI",
            personel_sayisi: 24
          },
          meta: {},
          errors: []
        },
        200
      )
    );

    vi.stubGlobal("fetch", fetchMock);

    const result = await createHaftalikKapanis({
      hafta_baslangic: "2026-04-06",
      hafta_bitis: "2026-04-12",
      departman_id: 3
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/haftalik-kapanis");
    expect(init.method).toBe("POST");
    expect(result.id).toBe(99);
    expect(result.hafta_baslangic).toBe("2026-04-06");
    expect(result.hafta_bitis).toBe("2026-04-12");
    expect(result.state).toBe("KAPANDI");
    expect(result.personel_sayisi).toBe(24);
  });
});
