import type { AuthSession, LoginCredentials } from "../types/auth";
import { apiRequest, ApiRequestError } from "./client";
import { endpoints } from "./endpoints";

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
  return role === "BIRIM_AMIRI" ? "birim" : "yonetim";
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
  const uiProfile =
    uiProfileRaw === "yonetim" || uiProfileRaw === "birim"
      ? uiProfileRaw
      : role
      ? deriveUiProfile(role)
      : null;

  if (!token || userId === null || !fullName || !role || !uiProfile) {
    return null;
  }

  return {
    token,
    ui_profile: uiProfile,
    user: {
      id: userId,
      ad_soyad: fullName,
      rol: role
    }
  };
}

export async function login(credentials: LoginCredentials): Promise<AuthSession> {
  const response = await apiRequest<unknown>(endpoints.auth.login, {
    method: "POST",
    body: JSON.stringify(credentials)
  });

  const session = normalizeAuthSession(response);
  if (session) {
    return session;
  }

  const backendMessage = extractErrorMessage(response);
  if (backendMessage) {
    throw new ApiRequestError(backendMessage, 200);
  }

  throw new ApiRequestError("Login yaniti beklenen oturum formatinda degil.", 200);
}
