import type { ApiResponse } from "../types/api";
import { appendQueryParams } from "../utils/append-query-params";
import { ApiRequestError, apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export type MaasHesaplamaSeverity = "BLOCKER" | "WARNING" | "INFO";

export type MaasHesaplamaIssue = {
  severity: MaasHesaplamaSeverity;
  code: string;
  message: string;
  record_type: string;
  record_id: number | null;
  personel_id: number | null;
  personel_adi: string | null;
  metadata: Record<string, unknown>;
};

export type MaasHesaplamaPersonelSummary = {
  personel_id: number;
  ad_soyad: string;
  istihdam_baslangic: string;
  istihdam_bitis: string;
  ucret_segment_sayisi: number;
  puantaj_kayit_sayisi: number;
  finans_kalem_sayisi: number;
  hazir_mi: boolean;
  blocker_count: number;
  warning_count: number;
};

export type MaasHesaplamaPreflight = {
  sube: { id: number; ad: string; kod?: string } | null;
  yil: number;
  ay: number;
  donem: string;
  donem_baslangic: string;
  donem_bitis: string;
  muhur: {
    id: number;
    durum: string;
    muhurlenen_kayit_sayisi: number;
    created_at: string;
  } | null;
  snapshot_olusturulabilir_mi: boolean;
  blocker_count: number;
  warning_count: number;
  info_count: number;
  items: MaasHesaplamaIssue[];
  personel_summary: MaasHesaplamaPersonelSummary[];
  source_summary: Record<string, unknown>;
  existing_snapshot: {
    id: number;
    state: string;
    revision_no: number;
    source_hash: string;
    snapshot_hash: string;
    created_at: string;
    source_changed: boolean;
  } | null;
  preflight_hash: string;
  source_hash: string;
  hashes: Record<string, string>;
  schema_version: string;
  contract_version: string;
  generated_at: string;
};

export type MaasHesaplamaSnapshot = {
  id: number;
  snapshot_id: number;
  sube_id: number;
  yil: number;
  ay: number;
  donem: string;
  donem_baslangic: string;
  donem_bitis: string;
  muhur_id: number;
  revision_no: number;
  parent_snapshot_id: number | null;
  state: string;
  contract_version: string;
  cutoff_at: string;
  preflight_hash: string;
  source_hash: string;
  snapshot_hash: string;
  personel_sayisi: number;
  girdi_sayisi: number;
  blocker_count: number;
  warning_count: number;
  created_by: number | null;
  created_at: string;
  iptal_edildi_by: number | null;
  iptal_edildi_at: string | null;
  iptal_nedeni: string | null;
};

export type MaasHesaplamaAudit = {
  id: number;
  donem_snapshot_id: number | null;
  sube_id: number;
  yil: number;
  ay: number;
  muhur_id: number | null;
  aksiyon: string;
  sonuc: string;
  actor_id: number | null;
  actor_rol: string | null;
  request_hash: string;
  preflight_hash: string | null;
  source_hash: string | null;
  result_hash: string | null;
  blocker_count: number;
  warning_count: number;
  created_at: string;
};

export type MaasHesaplamaSnapshotDetail = MaasHesaplamaSnapshot & {
  personeller?: unknown[];
  girdi_ozet?: Record<string, number>;
  girdiler?: unknown[];
  hash_dogrulama?: { dogrulandi: boolean; hesaplanan_snapshot_hash?: string };
};

export type MaasHesaplamaParams = {
  sube_id: number;
  yil: number;
  ay: number;
};

function assertNoMaasApiErrors(response: ApiResponse<unknown>, fallback: string): void {
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    const first = response.errors[0] as { code?: string; message?: string };
    throw new ApiRequestError(first.message ?? fallback, 409, { code: first.code ?? "PAYROLL_ERROR" });
  }
}

function unwrapData<T>(payload: ApiResponse<T> | T, fallback: string): T {
  if (typeof payload === "object" && payload !== null && "data" in payload) {
    assertNoMaasApiErrors(payload as ApiResponse<unknown>, fallback);
    return (payload as ApiResponse<T>).data;
  }
  return payload as T;
}

export async function fetchMaasHesaplamaPreflight(
  params: MaasHesaplamaParams
): Promise<MaasHesaplamaPreflight> {
  const path = appendQueryParams(endpoints.maasHesaplama.preflight, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay
  });
  const response = await apiRequest<ApiResponse<MaasHesaplamaPreflight> | MaasHesaplamaPreflight>(path);
  return unwrapData(response, "Preflight alinamadi.");
}

export async function fetchMaasHesaplamaSnapshots(
  params: MaasHesaplamaParams
): Promise<MaasHesaplamaSnapshot[]> {
  const path = appendQueryParams(endpoints.maasHesaplama.snapshots, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay
  });
  const response = await apiRequest<
    ApiResponse<{ items: MaasHesaplamaSnapshot[] }> | { items: MaasHesaplamaSnapshot[] }
  >(path);
  return unwrapData(response, "Snapshot listesi alinamadi.").items ?? [];
}

export async function fetchMaasHesaplamaSnapshotDetail(
  snapshotId: number,
  includePayloads = false
): Promise<MaasHesaplamaSnapshotDetail> {
  const path = appendQueryParams(endpoints.maasHesaplama.snapshotDetail(snapshotId), {
    ...(includePayloads ? { include_payloads: 1 } : {})
  });
  const response = await apiRequest<ApiResponse<MaasHesaplamaSnapshotDetail> | MaasHesaplamaSnapshotDetail>(
    path
  );
  return unwrapData(response, "Snapshot detayi alinamadi.");
}

export async function createMaasHesaplamaSnapshot(input: {
  sube_id: number;
  yil: number;
  ay: number;
  expected_preflight_hash: string;
}): Promise<{ snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }> {
  const response = await apiRequest<
    | ApiResponse<{ snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }>
    | { snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }
  >(endpoints.maasHesaplama.snapshots, {
    method: "POST",
    body: JSON.stringify(input)
  });
  return unwrapData(response, "Snapshot olusturulamadi.");
}

export async function cancelMaasHesaplamaSnapshot(
  snapshotId: number,
  neden: string
): Promise<{ snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }> {
  const response = await apiRequest<
    | ApiResponse<{ snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }>
    | { snapshot: MaasHesaplamaSnapshot; idempotent: boolean; audit: MaasHesaplamaAudit | null }
  >(endpoints.maasHesaplama.cancel(snapshotId), {
    method: "POST",
    body: JSON.stringify({ neden })
  });
  return unwrapData(response, "Snapshot iptal edilemedi.");
}

export async function fetchMaasHesaplamaAudits(
  params: MaasHesaplamaParams
): Promise<MaasHesaplamaAudit[]> {
  const path = appendQueryParams(endpoints.maasHesaplama.audits, {
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay
  });
  const response = await apiRequest<ApiResponse<{ items: MaasHesaplamaAudit[] }> | { items: MaasHesaplamaAudit[] }>(
    path
  );
  return unwrapData(response, "Audit listesi alinamadi.").items ?? [];
}
