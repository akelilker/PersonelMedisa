import { getActiveSubeIdForApiHeader } from "../auth/auth-manager";
import { getAuthTokenForApi } from "../auth/auth-token-provider";
import type { ApiResponse } from "../types/api";
import { appendQueryParams } from "../utils/append-query-params";
import { ApiRequestError, apiRequest, buildApiUrl } from "./api-client";
import { normalizePaginatedList } from "./response-normalizers";

const PREFLIGHT_PATH = "/puantaj/donem-kapanis-preflight";
const AUDITS_PATH = "/puantaj/donem-kapanis-auditleri";

export type DonemKapanisSeverity = "BLOCKER" | "WARNING" | "INFO";

export type DonemKapanisIssue = {
  code: string;
  severity: DonemKapanisSeverity;
  domain: string;
  title: string;
  message: string;
  count: number;
  owner_role: string;
  action_route: string;
  action_permission: string;
  record_ids: number[];
  metadata: Record<string, unknown>;
};

export type DonemKapanisPreflightSummary = {
  sube: { id: number; ad: string } | null;
  yil: number;
  ay: number;
  donem: string;
  donem_state: string;
  muhur_state: string;
  muhur_id: number | null;
  kapanabilir_mi: boolean;
  blocker_count: number;
  warning_count: number;
  info_count: number;
  kategori_sayaclari: Record<string, number>;
  blockers: DonemKapanisIssue[];
  warnings: DonemKapanisIssue[];
  infos: DonemKapanisIssue[];
  candidate_state_counts: Record<string, number>;
  notification_chain_counts: Record<string, number>;
  puantaj_counts: Record<string, number>;
  finance_readiness: Record<string, unknown>;
  preflight_hash: string;
  schema_version: string;
  generated_at: string;
};

export type DonemKapanisPreflightItem = {
  record_id: number | null;
  personel_id: number | null;
  tarih: string | null;
  state: string | null;
  detail: string | null;
  severity: DonemKapanisSeverity;
  code?: string;
};

export type DonemKapanisPreflightItemsResult = {
  items: DonemKapanisPreflightItem[];
  page: number;
  limit: number;
  total: number;
  total_pages: number;
  has_next_page: boolean;
  has_prev_page: boolean;
};

export type DonemKapanisAudit = {
  id: number;
  sube_id: number;
  yil: number;
  ay: number;
  action: string;
  result_state: string;
  muhur_id: number | null;
  blocker_count: number;
  warning_count: number;
  preflight_hash: string;
  request_hash: string;
  result_hash: string;
  actor_user_id: number;
  created_at: string;
};

export type DonemKapanisPreflightParams = {
  sube_id: number;
  yil: number;
  ay: number;
  departman_id?: number;
  personel_id?: number;
};

export type DonemKapanisItemsParams = DonemKapanisPreflightParams & {
  code: string;
  severity?: DonemKapanisSeverity | "";
  page?: number;
  limit?: number;
};

export type DonemKapanisAuditsParams = {
  sube_id: number;
  yil: number;
  ay: number;
  page?: number;
  limit?: number;
};

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }
  return value as Record<string, unknown>;
}

function toNumber(value: unknown): number | null {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }
  if (typeof value === "string" && value.trim()) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }
  return null;
}

function toStringValue(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }
  const trimmed = value.trim();
  return trimmed || undefined;
}

function toStringMap(value: unknown): Record<string, number> {
  const record = toRecord(value);
  if (!record) {
    return {};
  }
  const out: Record<string, number> = {};
  Object.entries(record).forEach(([key, entry]) => {
    const parsed = toNumber(entry);
    if (parsed !== null) {
      out[key] = parsed;
    }
  });
  return out;
}

function normalizeIssue(data: unknown): DonemKapanisIssue {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Donem kapanis issue beklenen formatta degil.");
  }

  const severity = toStringValue(record.severity) as DonemKapanisSeverity | undefined;
  const code = toStringValue(record.code);
  const title = toStringValue(record.title);

  if (!severity || !code || !title) {
    throw new Error("Donem kapanis issue eksik alan iceriyor.");
  }

  const recordIds = Array.isArray(record.record_ids)
    ? record.record_ids.map((entry) => toNumber(entry)).filter((entry): entry is number => entry !== null)
    : [];

  return {
    code,
    severity,
    domain: toStringValue(record.domain) ?? "",
    title,
    message: toStringValue(record.message) ?? "",
    count: toNumber(record.count) ?? 0,
    owner_role: toStringValue(record.owner_role) ?? "",
    action_route: toStringValue(record.action_route) ?? "",
    action_permission: toStringValue(record.action_permission) ?? "",
    record_ids: recordIds,
    metadata: toRecord(record.metadata) ?? {}
  };
}

function normalizePreflightSummary(data: unknown): DonemKapanisPreflightSummary {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Donem kapanis preflight ozeti beklenen formatta degil.");
  }

  const yil = toNumber(record.yil);
  const ay = toNumber(record.ay);
  const donem = toStringValue(record.donem);

  if (!yil || !ay || !donem) {
    throw new Error("Donem kapanis preflight ozeti eksik alan iceriyor.");
  }

  const subeRecord = toRecord(record.sube);
  const sube =
    subeRecord && toNumber(subeRecord.id)
      ? { id: toNumber(subeRecord.id) as number, ad: toStringValue(subeRecord.ad) ?? `Sube ${subeRecord.id}` }
      : null;

  const blockers = Array.isArray(record.blockers) ? record.blockers.map(normalizeIssue) : [];
  const warnings = Array.isArray(record.warnings) ? record.warnings.map(normalizeIssue) : [];
  const infos = Array.isArray(record.infos) ? record.infos.map(normalizeIssue) : [];

  return {
    sube,
    yil,
    ay,
    donem,
    donem_state: toStringValue(record.donem_state) ?? "ACIK",
    muhur_state: toStringValue(record.muhur_state) ?? "ACIK",
    muhur_id: toNumber(record.muhur_id),
    kapanabilir_mi: record.kapanabilir_mi === true,
    blocker_count: toNumber(record.blocker_count) ?? blockers.length,
    warning_count: toNumber(record.warning_count) ?? warnings.length,
    info_count: toNumber(record.info_count) ?? infos.length,
    kategori_sayaclari: toStringMap(record.kategori_sayaclari),
    blockers,
    warnings,
    infos,
    candidate_state_counts: toStringMap(record.candidate_state_counts),
    notification_chain_counts: toStringMap(record.notification_chain_counts),
    puantaj_counts: toStringMap(record.puantaj_counts),
    finance_readiness: toRecord(record.finance_readiness) ?? {},
    preflight_hash: toStringValue(record.preflight_hash) ?? "",
    schema_version: toStringValue(record.schema_version) ?? "",
    generated_at: toStringValue(record.generated_at) ?? ""
  };
}

function normalizePreflightItem(data: unknown): DonemKapanisPreflightItem {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Donem kapanis item beklenen formatta degil.");
  }

  const severity = (toStringValue(record.severity) ?? "INFO") as DonemKapanisSeverity;

  return {
    record_id: toNumber(record.record_id),
    personel_id: toNumber(record.personel_id),
    tarih: toStringValue(record.tarih) ?? null,
    state: toStringValue(record.state) ?? null,
    detail: toStringValue(record.detail) ?? null,
    severity,
    code: toStringValue(record.code)
  };
}

function normalizeAudit(data: unknown): DonemKapanisAudit {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Donem kapanis audit beklenen formatta degil.");
  }

  const id = toNumber(record.id);
  const subeId = toNumber(record.sube_id);
  const yil = toNumber(record.yil);
  const ay = toNumber(record.ay);

  if (!id || !subeId || !yil || !ay) {
    throw new Error("Donem kapanis audit eksik alan iceriyor.");
  }

  return {
    id,
    sube_id: subeId,
    yil,
    ay,
    action: toStringValue(record.action) ?? "",
    result_state: toStringValue(record.result_state) ?? "",
    muhur_id: toNumber(record.muhur_id),
    blocker_count: toNumber(record.blocker_count) ?? 0,
    warning_count: toNumber(record.warning_count) ?? 0,
    preflight_hash: toStringValue(record.preflight_hash) ?? "",
    request_hash: toStringValue(record.request_hash) ?? "",
    result_hash: toStringValue(record.result_hash) ?? "",
    actor_user_id: toNumber(record.actor_user_id) ?? 0,
    created_at: toStringValue(record.created_at) ?? ""
  };
}

function buildPreflightQuery(params: DonemKapanisPreflightParams) {
  return appendQueryParams(PREFLIGHT_PATH, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay,
    departman_id: params.departman_id,
    personel_id: params.personel_id
  });
}

async function downloadAuthenticatedFile(path: string, filename: string) {
  const { resolveDemoApiResponse } = await import("./mock-demo");
  const demoResponse = resolveDemoApiResponse(path, { method: "GET" });
  if (demoResponse !== null) {
    const csvContent =
      typeof demoResponse.data === "string"
        ? demoResponse.data
        : "code,severity\nDEMO,INFO\n";
    const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
    return;
  }

  const headers = new Headers();
  const token = getAuthTokenForApi();
  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }
  const subeHeader = getActiveSubeIdForApiHeader();
  if (subeHeader) {
    headers.set("X-Active-Sube-Id", subeHeader);
  }

  const response = await fetch(buildApiUrl(path), { headers });
  if (!response.ok) {
    throw new ApiRequestError("CSV indirilemedi.", response.status);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}

export async function fetchDonemKapanisPreflight(
  params: DonemKapanisPreflightParams
): Promise<DonemKapanisPreflightSummary> {
  const response = await apiRequest<ApiResponse<unknown>>(buildPreflightQuery(params));
  return normalizePreflightSummary(response.data);
}

export async function fetchDonemKapanisPreflightItems(
  params: DonemKapanisItemsParams
): Promise<DonemKapanisPreflightItemsResult> {
  const path = appendQueryParams(`${PREFLIGHT_PATH}/items`, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay,
    departman_id: params.departman_id,
    personel_id: params.personel_id,
    code: params.code,
    severity: params.severity || undefined,
    page: params.page,
    limit: params.limit
  });

  const response = await apiRequest<ApiResponse<unknown>>(path);
  const paginated = normalizePaginatedList<unknown>(response, {
    requestedPage: params.page,
    requestedLimit: params.limit
  });
  const items = paginated.items.map(normalizePreflightItem);
  const pagination = paginated.pagination;

  return {
    items,
    page: pagination.page ?? params.page ?? 1,
    limit: pagination.limit ?? params.limit ?? 20,
    total: pagination.total ?? items.length,
    total_pages: pagination.totalPages ?? 1,
    has_next_page: pagination.hasNextPage ?? false,
    has_prev_page: pagination.hasPreviousPage ?? false
  };
}

export async function downloadDonemKapanisPreflightCsv(params: DonemKapanisPreflightParams): Promise<void> {
  const path = appendQueryParams(`${PREFLIGHT_PATH}/export.csv`, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay,
    departman_id: params.departman_id,
    personel_id: params.personel_id
  });
  const filename = `donem-kapanis-preflight-${params.yil}-${String(params.ay).padStart(2, "0")}.csv`;
  await downloadAuthenticatedFile(path, filename);
}

export async function fetchDonemKapanisAudits(
  params: DonemKapanisAuditsParams
): Promise<{ items: DonemKapanisAudit[]; page: number; limit: number; total: number; total_pages: number }> {
  const path = appendQueryParams(AUDITS_PATH, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay,
    page: params.page,
    limit: params.limit
  });

  const response = await apiRequest<ApiResponse<unknown>>(path);
  const paginated = normalizePaginatedList<unknown>(response, {
    requestedPage: params.page,
    requestedLimit: params.limit
  });
  const pagination = paginated.pagination;

  return {
    items: paginated.items.map(normalizeAudit),
    page: pagination.page ?? params.page ?? 1,
    limit: pagination.limit ?? params.limit ?? 20,
    total: pagination.total ?? paginated.items.length,
    total_pages: pagination.totalPages ?? 1
  };
}
