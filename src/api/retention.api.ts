import type { ApiResponse } from "../types/api";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export type LegalHoldItem = {
  id: number;
  target_domain: string;
  target_category?: string | null;
  target_record_id?: number | null;
  personel_id?: number | null;
  reason: string;
  hold_state: "ACTIVE" | "RELEASED";
  created_by?: number;
  created_at?: string;
  released_by?: number | null;
  released_at?: string | null;
  release_reason?: string | null;
};

export type RetentionEligibility = {
  eligible?: boolean;
  code: string;
  category?: string;
  trigger_type?: string | null;
  trigger_date?: string | null;
  retention_until?: string | null;
  policy_note?: string;
  message?: string;
};

export type RetentionImhaTalep = {
  id: number;
  category: string;
  entity_type: string;
  record_id: number;
  personel_id?: number | null;
  reason: string;
  status: "REQUESTED" | "APPROVED" | "REJECTED" | "BLOCKED";
  requested_by?: number;
  requested_at?: string;
  approved_by?: number | null;
  approved_at?: string | null;
  approval_reason?: string | null;
  retention_until_snapshot?: string | null;
  source_identity_snapshot?: string | null;
};

export async function fetchLegalHoldlar(activeOnly = true): Promise<LegalHoldItem[]> {
  const path = appendQueryParams(endpoints.legalHoldlar.list, {
    active_only: activeOnly ? 1 : 0
  });
  const response = await apiRequest<ApiResponse<{ items?: LegalHoldItem[] }>>(path);
  return Array.isArray(response.data?.items) ? response.data.items : [];
}

export async function createLegalHold(payload: {
  target_domain: string;
  target_category?: string;
  target_record_id?: number;
  personel_id?: number;
  reason: string;
}): Promise<LegalHoldItem> {
  const response = await apiRequest<ApiResponse<{ item?: LegalHoldItem }>>(
    endpoints.legalHoldlar.create,
    { method: "POST", body: JSON.stringify(payload) }
  );
  if (!response.data?.item) {
    throw new Error("Legal hold olusturulamadi.");
  }
  return response.data.item;
}

export async function releaseLegalHold(
  id: number,
  releaseReason: string
): Promise<LegalHoldItem> {
  const response = await apiRequest<ApiResponse<{ item?: LegalHoldItem }>>(
    endpoints.legalHoldlar.release(id),
    { method: "POST", body: JSON.stringify({ release_reason: releaseReason }) }
  );
  if (!response.data?.item) {
    throw new Error("Legal hold serbest birakilamadi.");
  }
  return response.data.item;
}

export async function fetchRetentionEligibility(params: {
  category: string;
  personel_id?: number;
  entity_type?: string;
  record_id?: number;
  sube_id?: number;
  yil?: number;
  ay?: number;
  talep_id?: number;
  parent_category?: string;
}): Promise<RetentionEligibility> {
  const path = appendQueryParams(endpoints.retention.eligibility, {
    category: params.category,
    personel_id: params.personel_id,
    entity_type: params.entity_type,
    record_id: params.record_id,
    sube_id: params.sube_id,
    yil: params.yil,
    ay: params.ay,
    talep_id: params.talep_id,
    parent_category: params.parent_category
  });
  const response = await apiRequest<
    ApiResponse<{ eligibility?: RetentionEligibility; policy_note?: string }>
  >(path);
  if (!response.data?.eligibility) {
    throw new Error("Saklama uygunlugu alinamadi.");
  }
  return response.data.eligibility;
}

export async function fetchRetentionImhaTalepleri(
  status?: string
): Promise<RetentionImhaTalep[]> {
  const path = appendQueryParams(endpoints.retention.imhaTalepleri, {
    status: status || undefined
  });
  const response = await apiRequest<ApiResponse<{ items?: RetentionImhaTalep[] }>>(path);
  return Array.isArray(response.data?.items) ? response.data.items : [];
}

export async function requestRetentionImha(payload: {
  category: string;
  entity_type: string;
  record_id: number;
  personel_id?: number;
  reason: string;
  sube_id?: number;
  yil?: number;
  ay?: number;
  source_identity?: string;
}): Promise<{ item: RetentionImhaTalep; eligibility: RetentionEligibility }> {
  const response = await apiRequest<
    ApiResponse<{ item?: RetentionImhaTalep; eligibility?: RetentionEligibility }>
  >(endpoints.retention.imhaTalepleri, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  if (!response.data?.item || !response.data.eligibility) {
    throw new Error("Imha talebi olusturulamadi.");
  }
  return { item: response.data.item, eligibility: response.data.eligibility };
}

export async function approveRetentionImha(
  id: number,
  approvalReason: string,
  approve = true
): Promise<RetentionImhaTalep> {
  const response = await apiRequest<ApiResponse<{ item?: RetentionImhaTalep }>>(
    endpoints.retention.imhaApprove(id),
    {
      method: "POST",
      body: JSON.stringify({ approval_reason: approvalReason, approve })
    }
  );
  if (!response.data?.item) {
    throw new Error("Imha onayi islenemedi.");
  }
  return response.data.item;
}
