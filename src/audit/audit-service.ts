import { MEDISA_AUTH_SESSION_KEY } from "../auth/auth-constants";

const AUDIT_STORAGE_KEY = "medisa_audit_trail";
const AUDIT_MAX_ENTRIES = 500;

function readUserIdFromAuthStorage(): number | null {
  if (typeof window === "undefined") {
    return null;
  }
  try {
    const raw =
      window.sessionStorage.getItem(MEDISA_AUTH_SESSION_KEY) ??
      window.localStorage.getItem(MEDISA_AUTH_SESSION_KEY);
    if (!raw?.trim()) {
      return null;
    }
    const parsed = JSON.parse(raw) as { user?: { id?: unknown } };
    const id = parsed.user?.id;
    return typeof id === "number" && Number.isFinite(id) ? id : null;
  } catch {
    return null;
  }
}

export type AuditAction =
  | "AUTH_LOGIN_SUCCESS"
  | "AUTH_LOGOUT"
  | "PERSONEL_CREATE"
  | "PERSONEL_UPDATE"
  | "SUREC_CREATE"
  | "SUREC_UPDATE"
  | "SUREC_CANCEL"
  | "BILDIRIM_CREATE"
  | "BILDIRIM_UPDATE"
  | "BILDIRIM_CANCEL"
  | "BILDIRIM_MARK_READ"
  | "FINANS_CREATE"
  | "FINANS_UPDATE"
  | "FINANS_CANCEL"
  | "PUANTAJ_UPSERT"
  | "HAFTALIK_KAPANIS_CLOSE";

export type AuditLogEntry = {
  action: AuditAction;
  user_id: number | null;
  payload?: unknown;
  timestamp: string;
};

export function logAction(params: {
  action: AuditAction;
  user_id?: number | null;
  payload?: unknown;
  timestamp?: string;
}): void {
  const user_id = params.user_id ?? readUserIdFromAuthStorage();
  const entry: AuditLogEntry = {
    action: params.action,
    user_id,
    payload: params.payload,
    timestamp: params.timestamp ?? new Date().toISOString()
  };

  if (import.meta.env.DEV) {
    console.info("[medisa-audit]", entry);
  }

  if (typeof window === "undefined") {
    return;
  }

  try {
    const raw = window.localStorage.getItem(AUDIT_STORAGE_KEY);
    const parsed = raw ? (JSON.parse(raw) as unknown) : [];
    const list = Array.isArray(parsed) ? parsed : [];
    list.push(entry);
    const next = list.slice(-AUDIT_MAX_ENTRIES);
    window.localStorage.setItem(AUDIT_STORAGE_KEY, JSON.stringify(next));
  } catch {
    /* quota / private mode */
  }
}
