import type { ApiMeta, ApiResponse, PaginatedResult, PaginationMeta } from "../types/api";

export type NormalizePaginatedListOptions = {
  requestedPage?: number;
  requestedLimit?: number;
};

const LIST_CONTAINER_KEYS = ["items", "records", "results", "list", "rows", "data"] as const;
const PAGE_KEYS = ["page", "current_page", "currentPage", "page_no", "pageNo"] as const;
const LIMIT_KEYS = ["limit", "per_page", "perPage", "page_size", "pageSize"] as const;
const TOTAL_KEYS = ["total", "total_count", "totalCount", "item_count", "itemCount"] as const;
const TOTAL_PAGE_KEYS = [
  "total_pages",
  "totalPages",
  "page_count",
  "pageCount",
  "last_page",
  "lastPage"
] as const;
const HAS_NEXT_KEYS = ["has_next_page", "hasNextPage", "has_more", "hasMore"] as const;
const HAS_PREVIOUS_KEYS = ["has_prev_page", "hasPreviousPage", "has_prev", "hasPrev"] as const;
const NEXT_PAGE_KEYS = ["next_page", "nextPage"] as const;
const PREVIOUS_PAGE_KEYS = ["prev_page", "previous_page", "prevPage", "previousPage"] as const;

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function toInteger(value: unknown): number | null {
  if (typeof value === "number" && Number.isFinite(value)) {
    return Math.trunc(value);
  }

  if (typeof value === "string") {
    const trimmed = value.trim();
    if (!trimmed) {
      return null;
    }

    const parsed = Number.parseInt(trimmed, 10);
    return Number.isNaN(parsed) ? null : parsed;
  }

  return null;
}

function toPositiveInteger(value: unknown): number | null {
  const number = toInteger(value);
  if (number === null || number <= 0) {
    return null;
  }

  return number;
}

function toNonNegativeInteger(value: unknown): number | null {
  const number = toInteger(value);
  if (number === null || number < 0) {
    return null;
  }

  return number;
}

function toBoolean(value: unknown): boolean | null {
  if (typeof value === "boolean") {
    return value;
  }

  if (typeof value === "number") {
    if (value === 1) {
      return true;
    }

    if (value === 0) {
      return false;
    }
  }

  if (typeof value === "string") {
    const normalized = value.trim().toLowerCase();
    if (normalized === "true" || normalized === "1") {
      return true;
    }

    if (normalized === "false" || normalized === "0") {
      return false;
    }
  }

  return null;
}

function pickFromSources<T>(
  sources: Record<string, unknown>[],
  keys: readonly string[],
  parser: (value: unknown) => T | null
): T | null {
  for (const source of sources) {
    for (const key of keys) {
      const parsed = parser(source[key]);
      if (parsed !== null) {
        return parsed;
      }
    }
  }

  return null;
}

function collectPaginationSources(meta: ApiMeta, data: unknown): Record<string, unknown>[] {
  const sources: Record<string, unknown>[] = [];
  const metaRecord = toRecord(meta);
  const dataRecord = toRecord(data);
  const nestedMetaRecord = toRecord(dataRecord?.meta);

  const nestedCandidates = [
    metaRecord?.pagination,
    metaRecord?.paging,
    nestedMetaRecord?.pagination,
    nestedMetaRecord?.paging,
    dataRecord?.pagination,
    dataRecord?.paging
  ];

  for (const candidate of nestedCandidates) {
    const record = toRecord(candidate);
    if (record) {
      sources.push(record);
    }
  }

  if (metaRecord) {
    sources.push(metaRecord);
  }

  if (nestedMetaRecord) {
    sources.push(nestedMetaRecord);
  }

  if (dataRecord) {
    sources.push(dataRecord);
  }

  return sources;
}

function buildPaginationMeta(
  meta: ApiMeta,
  data: unknown,
  itemCount: number,
  options: NormalizePaginatedListOptions
): PaginationMeta {
  const sources = collectPaginationSources(meta, data);

  const page = pickFromSources(sources, PAGE_KEYS, toPositiveInteger) ?? toPositiveInteger(options.requestedPage);
  const limit =
    pickFromSources(sources, LIMIT_KEYS, toPositiveInteger) ?? toPositiveInteger(options.requestedLimit);
  const total = pickFromSources(sources, TOTAL_KEYS, toNonNegativeInteger);

  let totalPages = pickFromSources(sources, TOTAL_PAGE_KEYS, toPositiveInteger);
  if (totalPages === null && total !== null && limit !== null) {
    totalPages = Math.max(1, Math.ceil(total / limit));
  }

  const nextPage = pickFromSources(sources, NEXT_PAGE_KEYS, toPositiveInteger);
  const previousPage = pickFromSources(sources, PREVIOUS_PAGE_KEYS, toPositiveInteger);

  let hasNextPage = pickFromSources(sources, HAS_NEXT_KEYS, toBoolean);
  if (hasNextPage === null && nextPage !== null) {
    hasNextPage = true;
  }
  if (hasNextPage === null && totalPages !== null && page !== null) {
    hasNextPage = page < totalPages;
  }
  if (hasNextPage === null && total !== null && page !== null && limit !== null) {
    hasNextPage = page * limit < total;
  }
  if (hasNextPage === null && limit !== null) {
    hasNextPage = itemCount >= limit;
  }

  let hasPreviousPage = pickFromSources(sources, HAS_PREVIOUS_KEYS, toBoolean);
  if (hasPreviousPage === null && previousPage !== null) {
    hasPreviousPage = true;
  }
  if (hasPreviousPage === null && page !== null) {
    hasPreviousPage = page > 1;
  }

  return {
    page,
    limit,
    total,
    totalPages,
    hasNextPage,
    hasPreviousPage
  };
}

export function extractListItems<T>(data: unknown): T[] {
  if (Array.isArray(data)) {
    return data as T[];
  }

  const dataRecord = toRecord(data);
  if (!dataRecord) {
    return [];
  }

  for (const key of LIST_CONTAINER_KEYS) {
    const candidate = dataRecord[key];
    if (Array.isArray(candidate)) {
      return candidate as T[];
    }
  }

  return [];
}

export function normalizePaginatedList<T>(
  response: ApiResponse<unknown>,
  options: NormalizePaginatedListOptions = {}
): PaginatedResult<T> {
  const items = extractListItems<T>(response.data);
  const pagination = buildPaginationMeta(response.meta, response.data, items.length, options);

  return {
    items,
    pagination
  };
}
