import { finalizeAuthSessionSube } from "../auth/auth-session-sube";
import type { AuthSession, LoginCredentials } from "../types/auth";
import { apiRequest, ApiRequestError } from "./api-client";
import { endpoints } from "./endpoints";

const DEMO_LOGIN_ENABLED = true;

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function readString(value: unknown): string | null {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : null;
}

function readNumber(value: unknown): number | null {
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

function normalizeRole(value: unknown): AuthSession["user"]["rol"] | null {
  const raw = readString(value);
  if (!raw) {
    return null;
  }

  const normalized = raw.trim().toUpperCase().replace(/-/g, "_");
  if (
    normalized === "GENEL_YONETICI" ||
    normalized === "BOLUM_YONETICISI" ||
    normalized === "MUHASEBE" ||
    normalized === "BIRIM_AMIRI"
  ) {
    return normalized;
  }

  return null;
}

function deriveUiProfile(role: AuthSession["user"]["rol"]): AuthSession["ui_profile"] {
  return role === "BIRIM_AMIRI" ? "birim_amiri" : "yonetim";
}

function readSubeIds(record: Record<string, unknown>): number[] {
  const raw =
    record.sube_ids ??
    record.subeIds ??
    record.branch_ids ??
    record.branchIds ??
    record.allowed_sube_ids;
  if (!Array.isArray(raw)) {
    return [];
  }
  const ids: number[] = [];
  for (const item of raw) {
    const n = readNumber(item);
    if (n !== null) {
      ids.push(n);
    }
  }
  return ids;
}

function readSubeList(record: Record<string, unknown>): AuthSession["sube_list"] {
  const raw = record.sube_list ?? record.subeler ?? record.branches;
  if (!Array.isArray(raw)) {
    return undefined;
  }
  const out: NonNullable<AuthSession["sube_list"]> = [];
  for (const item of raw) {
    if (typeof item !== "object" || item === null) {
      continue;
    }
    const row = item as Record<string, unknown>;
    const id = readNumber(row.id);
    const ad =
      readString(row.ad) ?? readString(row.name) ?? readString(row.label) ?? (id !== null ? `Sube ${id}` : null);
    if (id !== null && ad) {
      out.push({ id, ad });
    }
  }
  return out.length ? out : undefined;
}

function extractErrorMessage(payload: unknown): string | null {
  const record = toRecord(payload);
  if (!record) {
    return null;
  }

  const directMessage = readString(record.message);
  if (directMessage) {
    return directMessage;
  }

  const errors = record.errors;
  if (!Array.isArray(errors) || errors.length === 0) {
    return null;
  }

  const firstError = toRecord(errors[0]);
  return firstError ? readString(firstError.message) : null;
}

function normalizeAuthSession(payload: unknown): AuthSession | null {
  const root = toRecord(payload);
  if (!root) {
    return null;
  }

  const source = toRecord(root.data) ?? root;
  const token =
    readString(source.token) ??
    readString(source.access_token) ??
    readString(source.accessToken) ??
    readString(source.jwt);

  const userSource = toRecord(source.user) ?? source;
  const userId = readNumber(userSource.id) ?? readNumber(userSource.user_id);
  const role = normalizeRole(userSource.rol ?? userSource.role ?? source.rol ?? source.role);
  const fullName =
    readString(userSource.ad_soyad) ??
    readString(userSource.adSoyad) ??
    readString(userSource.full_name) ??
    readString(userSource.fullName) ??
    readString(userSource.name) ??
    readString(userSource.ad);

  const uiProfileRaw = readString(source.ui_profile) ?? readString(source.uiProfile);
  let uiProfile: AuthSession["ui_profile"] | null = null;
  if (uiProfileRaw === "yonetim" || uiProfileRaw === "birim_amiri") {
    uiProfile = uiProfileRaw;
  } else if (uiProfileRaw === "birim") {
    uiProfile = "birim_amiri";
  } else if (role) {
    uiProfile = deriveUiProfile(role);
  }

  if (!token || userId === null || !fullName || !role || !uiProfile) {
    return null;
  }

  const fromUser = readSubeIds(userSource ?? {});
  const fromRoot = readSubeIds(toRecord(source) ?? {});
  const sube_ids = fromUser.length > 0 ? fromUser : fromRoot;
  const sube_list = readSubeList(toRecord(source) ?? {});

  const preferredActive =
    readNumber(userSource.active_sube_id) ??
    readNumber(userSource.activeSubeId) ??
    readNumber(source.active_sube_id) ??
    readNumber(source.activeSubeId);

  const draft: AuthSession = {
    token,
    ui_profile: uiProfile,
    sube_list,
    active_sube_id: preferredActive,
    user: {
      id: userId,
      ad_soyad: fullName,
      rol: role,
      sube_ids
    }
  };

  return finalizeAuthSessionSube(draft);
}

function resolveDemoRole(username: string): AuthSession["user"]["rol"] {
  const normalized = username.trim().toLowerCase();
  if (normalized.includes("birim")) {
    return "BIRIM_AMIRI";
  }
  if (normalized.includes("muhasebe")) {
    return "MUHASEBE";
  }
  if (normalized.includes("bolum") || normalized.includes("bölüm")) {
    return "BOLUM_YONETICISI";
  }

  return "GENEL_YONETICI";
}

function toDisplayName(username: string): string {
  const trimmed = username.trim();
  if (!trimmed) {
    return "Demo Kullanici";
  }

  return trimmed;
}

function createDemoSession(credentials: LoginCredentials): AuthSession {
  const role = resolveDemoRole(credentials.username);
  const userId =
    credentials.username
      .split("")
      .reduce((acc, ch) => acc + ch.charCodeAt(0), 0) % 10_000 || 1;

  const sube_ids = role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : [];

  const sube_list: NonNullable<AuthSession["sube_list"]> =
    sube_ids.length === 0
      ? []
      : sube_ids.map((id) => ({ id, ad: id === 1 ? "Merkez" : `Sube ${id}` }));

  return finalizeAuthSessionSube({
    token: "demo-token",
    ui_profile: deriveUiProfile(role),
    sube_list: sube_list.length ? sube_list : undefined,
    active_sube_id: null,
    user: {
      id: userId,
      ad_soyad: toDisplayName(credentials.username),
      rol: role,
      sube_ids
    }
  });
}

function shouldUseDemoFallback(error: unknown): boolean {
  if (!DEMO_LOGIN_ENABLED) {
    return false;
  }

  if (error instanceof ApiRequestError) {
    return [404, 502, 503, 504].includes(error.status);
  }

  if (error instanceof TypeError) {
    return true;
  }

  return false;
}

export async function login(credentials: LoginCredentials): Promise<AuthSession> {
  try {
    const response = await apiRequest<unknown>(endpoints.auth.login, {
      method: "POST",
      body: JSON.stringify({
        username: credentials.username,
        password: credentials.password
      })
    });

    const session = normalizeAuthSession(response);
    if (session) {
      return session;
    }

    const backendMessage = extractErrorMessage(response);
    if (backendMessage) {
      throw new ApiRequestError(backendMessage, 200);
    }

    if (DEMO_LOGIN_ENABLED) {
      return createDemoSession(credentials);
    }

    throw new ApiRequestError("Login yaniti beklenen oturum formatinda degil.", 200);
  } catch (error) {
    if (shouldUseDemoFallback(error)) {
      return createDemoSession(credentials);
    }

    throw error;
  }
}
