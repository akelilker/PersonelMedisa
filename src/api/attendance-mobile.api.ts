import type { ApiResponse } from "../types/api";
import { ApiRequestError, apiRequest, shouldPreferDemoApi } from "./api-client";
import { endpoints } from "./endpoints";

export type MobileCapabilities = {
  calisan_kapsami: string;
  shell: boolean;
  qr_scan: boolean;
  attendance_correct: boolean;
  puantaj_write: boolean;
  izin_write: boolean;
  coming_soon_message: string | null;
};

export type AttendanceBoxEventStatus = {
  kind: string;
  label: string;
  delta_dakika: number;
};

export type AttendanceBoxEvent = {
  id: number;
  event_type: "GIRIS" | "CIKIS";
  occurred_at: string;
  local_time: string;
  display_local_time?: string;
  correction_allowed?: boolean;
  status?: AttendanceBoxEventStatus | null;
};

export type AttendanceTodayResponse = {
  business_date: string;
  capabilities: MobileCapabilities;
  personel: {
    id: number;
    ad_soyad: string;
    sube_ad: string;
    bolum_ad: string | null;
    birim_ad: string | null;
    gorev_ad: string | null;
  };
  giris: AttendanceBoxEvent | null;
  cikis: AttendanceBoxEvent | null;
  /** Canonical next QR action from backend open-shift state (`GIRIS` | `CIKIS` | null). */
  next_action?: "GIRIS" | "CIKIS" | null;
  can_scan_giris: boolean;
  can_scan_cikis: boolean;
  pending_giris_correction: { id: number; status: string; status_label: string; requested_local_time: string } | null;
  pending_cikis_correction: { id: number; status: string; status_label: string; requested_local_time: string } | null;
  /** Planned shift window from gunluk_puantaj (LateEarlyInfoService::loadPlannedDay); client computes countdown. */
  planned_shift?: {
    beklenen_giris_saati: string | null;
    beklenen_cikis_saati: string | null;
  } | null;
};

export type InboxNotification = {
  id: number;
  kind: string;
  title: string;
  body: string;
  payload: Record<string, unknown> | null;
  related_correction_id: number | null;
  popup_required: boolean;
  popup_consumed: boolean;
  created_at: string;
};

function toRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === "object" && value !== null ? (value as Record<string, unknown>) : null;
}

function unwrap(response: ApiResponse<unknown>, label: string): Record<string, unknown> {
  const data = toRecord(response.data);
  if (!data) {
    throw new ApiRequestError(`${label} gecersiz.`, 500, { code: "INVALID_RESPONSE" });
  }
  return data;
}

export async function fetchAttendanceToday(): Promise<AttendanceTodayResponse> {
  if (shouldPreferDemoApi()) {
    throw new ApiRequestError("Demo modda attendance today yok.", 503, { code: "DEMO_UNAVAILABLE" });
  }
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.me.attendanceToday);
  const data = unwrap(response, "/me/attendance/today");
  return data as unknown as AttendanceTodayResponse;
}

export async function createAttendanceCorrection(payload: {
  source_event_id: number;
  requested_local_time: string;
  explanation?: string;
}): Promise<{ id: number; message: string; status: string }> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.me.attendanceCorrectionRequests, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  const data = unwrap(response, "correction");
  return {
    id: Number(data.id),
    message: String(data.message ?? "Düzeltme Talebiniz Amirinize İletildi."),
    status: String(data.status ?? "BEKLIYOR")
  };
}

export async function fetchInboxNotifications(): Promise<{
  items: InboxNotification[];
  pending_popups: InboxNotification[];
}> {
  if (shouldPreferDemoApi()) {
    return { items: [], pending_popups: [] };
  }
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.me.inboxNotifications);
  const data = unwrap(response, "inbox");
  return {
    items: Array.isArray(data.items) ? (data.items as InboxNotification[]) : [],
    pending_popups: Array.isArray(data.pending_popups) ? (data.pending_popups as InboxNotification[]) : []
  };
}

export async function ackInboxPopup(id: number): Promise<void> {
  await apiRequest<ApiResponse<unknown>>(endpoints.me.ackInboxPopup(id), { method: "POST", body: "{}" });
}

export async function decideAttendanceCorrection(
  id: number,
  action: "ONAYLA" | "REDDET"
): Promise<void> {
  await apiRequest<ApiResponse<unknown>>(endpoints.attendance.decideCorrection(id), {
    method: "POST",
    body: JSON.stringify({ action })
  });
}
