export type ApiError = {
  code: string;
  field?: string;
  message: string;
};

export type ApiMeta = Record<string, unknown>;

export type ApiResponse<T, TMeta extends ApiMeta = ApiMeta> = {
  data: T;
  meta: TMeta;
  errors: ApiError[];
};

export type PaginationMeta = {
  page: number | null;
  limit: number | null;
  total: number | null;
  totalPages: number | null;
  hasNextPage: boolean | null;
  hasPreviousPage: boolean | null;
};

export type PaginatedResult<T> = {
  items: T[];
  pagination: PaginationMeta;
};
