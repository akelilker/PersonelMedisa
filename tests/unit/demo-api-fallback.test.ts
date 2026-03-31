import { afterEach, describe, expect, it, vi } from "vitest";
import { fetchPersonellerList } from "../../src/api/personeller.api";

function createJsonResponse(body: unknown, status: number) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json"
    }
  });
}

describe("demo api fallback", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("returns demo personeller list when all api candidates return 404", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        createJsonResponse(
          {
            data: null,
            meta: {},
            errors: [{ code: "NOT_FOUND", message: "Not found" }]
          },
          404
        )
      )
    );

    const result = await fetchPersonellerList({ page: 1, limit: 10, aktiflik: "tum" });
    expect(result.items.length).toBeGreaterThan(0);
    expect(result.items[0]?.ad).toBeTypeOf("string");
  });
});
