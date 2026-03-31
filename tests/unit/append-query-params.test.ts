import { describe, expect, it } from "vitest";
import { appendQueryParams } from "../../src/utils/append-query-params";

describe("appendQueryParams", () => {
  it("returns the original path when all query values are empty", () => {
    const result = appendQueryParams("/personeller", {
      search: "",
      page: undefined,
      limit: null
    });

    expect(result).toBe("/personeller");
  });

  it("builds a query string from provided values", () => {
    const result = appendQueryParams("/personeller", {
      search: "ahmet",
      page: 2,
      limit: 10
    });

    expect(result).toBe("/personeller?search=ahmet&page=2&limit=10");
  });

  it("stringifies boolean values", () => {
    const result = appendQueryParams("/surecler", {
      ucretli_mi: false
    });

    expect(result).toBe("/surecler?ucretli_mi=false");
  });
});
