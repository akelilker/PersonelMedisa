import { describe, expect, it } from "vitest";
import type { ApiResponse } from "../../src/types/api";
import { extractListItems, normalizePaginatedList } from "../../src/api/response-normalizers";

describe("extractListItems", () => {
  it("returns array data directly", () => {
    const data = [{ id: 1 }, { id: 2 }];
    expect(extractListItems<{ id: number }>(data)).toEqual(data);
  });

  it("picks list from records key when response is object-shaped", () => {
    const data = {
      records: [{ id: 10 }, { id: 20 }]
    };

    expect(extractListItems<{ id: number }>(data)).toEqual([{ id: 10 }, { id: 20 }]);
  });
});

describe("normalizePaginatedList", () => {
  it("resolves pagination from nested meta.pagination values", () => {
    const response: ApiResponse<unknown> = {
      data: {
        items: [{ id: 1 }, { id: 2 }]
      },
      meta: {
        pagination: {
          current_page: 2,
          per_page: 10,
          total_count: 15,
          has_next_page: false,
          has_prev_page: true
        }
      },
      errors: []
    };

    const result = normalizePaginatedList<{ id: number }>(response);

    expect(result.items).toEqual([{ id: 1 }, { id: 2 }]);
    expect(result.pagination).toEqual({
      page: 2,
      limit: 10,
      total: 15,
      totalPages: 2,
      hasNextPage: false,
      hasPreviousPage: true
    });
  });

  it("uses requested page and limit fallback when metadata is absent", () => {
    const response: ApiResponse<unknown> = {
      data: [{ id: 1 }, { id: 2 }, { id: 3 }],
      meta: {},
      errors: []
    };

    const result = normalizePaginatedList<{ id: number }>(response, {
      requestedPage: 1,
      requestedLimit: 3
    });

    expect(result.pagination.page).toBe(1);
    expect(result.pagination.limit).toBe(3);
    expect(result.pagination.total).toBeNull();
    expect(result.pagination.totalPages).toBeNull();
    expect(result.pagination.hasNextPage).toBe(true);
    expect(result.pagination.hasPreviousPage).toBe(false);
  });
});
