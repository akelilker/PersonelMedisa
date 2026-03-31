import type { AuthSession, AuthUser, LoginCredentials } from "../types/auth";
import { login as requestLoginSession } from "../api/auth.api";
import { MEDISA_AUTH_SESSION_KEY } from "./auth-constants";
import { finalizeAuthSessionSube } from "./auth-session-sube";
import { registerAuthTokenSource } from "./auth-token-provider";

export { MEDISA_AUTH_SESSION_KEY };
const LEGACY_AUTH_SESSION_KEY = "medisa.auth.session.v1";

function migrateLegacyAuthIfNeeded(): void {
  if (typeof window === "undefined") {
    return;
  }

  try {
    const raw = window.localStorage.getItem(LEGACY_AUTH_SESSION_KEY);
    if (!raw) {
      return;
    }

    const session = parseStored(raw);
    if (session) {
      window.localStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(session));
    }
    window.localStorage.removeItem(LEGACY_AUTH_SESSION_KEY);
  } catch {
    /* ignore */
  }
}

function isAuthSession(value: unknown): value is AuthSession {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const session = value as Partial<AuthSession>;
  return (
    typeof session.token === "string" &&
    typeof session.ui_profile === "string" &&
    typeof session.user === "object" &&
    session.user !== null &&
    typeof session.user.id === "number" &&
    typeof session.user.ad_soyad === "string" &&
    typeof session.user.rol === "string"
  );
}

function parseStored(raw: string | null): AuthSession | null {
  if (raw === null || raw.trim() === "") {
    return null;
  }
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (!isAuthSession(parsed)) {
      return null;
    }
    const s = parsed as AuthSession;
    const sube_ids = Array.isArray(s.user.sube_ids) ? s.user.sube_ids : [];
    const active_raw = s.active_sube_id;
    const active_sube_id =
      active_raw !== null && active_raw !== undefined && typeof active_raw === "number" ? active_raw : null;
    const base: AuthSession = {
      ...s,
      user: { ...s.user, sube_ids },
      active_sube_id
    };
    return finalizeAuthSessionSube(base);
  } catch {
    return null;
  }
}

function readRawFromStorages(): { storage: Storage; raw: string } | null {
  if (typeof window === "undefined") {
    return null;
  }

  const fromSession = window.sessionStorage.getItem(MEDISA_AUTH_SESSION_KEY);
  if (fromSession) {
    return { storage: window.sessionStorage, raw: fromSession };
  }

  const fromLocal = window.localStorage.getItem(MEDISA_AUTH_SESSION_KEY);
  if (fromLocal) {
    return { storage: window.localStorage, raw: fromLocal };
  }

  return null;
}

export function getSession(): AuthSession | null {
  migrateLegacyAuthIfNeeded();

  const located = readRawFromStorages();
  if (!located) {
    return null;
  }

  const session = parseStored(located.raw);
  if (!session) {
    located.storage.removeItem(MEDISA_AUTH_SESSION_KEY);
    return null;
  }

  return session;
}

export function getToken(): string | null {
  return getSession()?.token ?? null;
}

export function getCurrentUser(): AuthUser | null {
  return getSession()?.user ?? null;
}

/** Oturumdaki yetkili sube id listesi (bos = tum subeler). */
export function getAllowedSubeIds(): number[] {
  return getSession()?.user.sube_ids ?? [];
}

/** Secili aktif sube; tum sube modunda null. */
export function getActiveSubeId(): number | null {
  return getSession()?.active_sube_id ?? null;
}

/** api-client: header degeri veya yok. */
export function getActiveSubeIdForApiHeader(): string | null {
  const id = getActiveSubeId();
  return id === null ? null : String(id);
}

/**
 * Aktif subeyi oturuma yazar (ayni storage konumu).
 * Coklu sube disinda veya yetkisiz id icin no-op.
 */
export function setActiveSubeId(nextId: number | null): void {
  const located = readRawFromStorages();
  const current = located ? parseStored(located.raw) : null;
  if (!current || !located) {
    return;
  }

  const ids = Array.isArray(current.user.sube_ids) ? current.user.sube_ids : [];

  if (ids.length === 0) {
    if (nextId !== null) {
      return;
    }
  } else if (nextId !== null && !ids.includes(nextId)) {
    return;
  }

  const next = finalizeAuthSessionSube({ ...current, active_sube_id: nextId });
  try {
    located.storage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(next));
  } catch {
    /* quota */
  }
}

export function isAuthenticated(): boolean {
  return getSession() !== null;
}

export function setToken(nextToken: string): void {
  const located = readRawFromStorages();
  const current = located ? parseStored(located.raw) : null;
  if (!current || !located) {
    return;
  }

  const next = finalizeAuthSessionSube({ ...current, token: nextToken });
  try {
    located.storage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(next));
  } catch {
    /* quota */
  }
}

function writeSession(session: AuthSession, rememberMe: boolean): void {
  if (typeof window === "undefined") {
    return;
  }

  const payload = JSON.stringify(session);
  try {
    if (rememberMe) {
      window.sessionStorage.removeItem(MEDISA_AUTH_SESSION_KEY);
      window.localStorage.setItem(MEDISA_AUTH_SESSION_KEY, payload);
    } else {
      window.localStorage.removeItem(MEDISA_AUTH_SESSION_KEY);
      window.sessionStorage.setItem(MEDISA_AUTH_SESSION_KEY, payload);
    }
  } catch {
    /* quota / private mode */
  }
}

/** Yalnizca auth anahtarlari; uygulama onbellegi auth.store / logout zincirinde temizlenir. */
export function clearSession(): void {
  if (typeof window === "undefined") {
    return;
  }

  try {
    window.sessionStorage.removeItem(MEDISA_AUTH_SESSION_KEY);
  } catch {
    /* ignore */
  }

  try {
    window.localStorage.removeItem(MEDISA_AUTH_SESSION_KEY);
  } catch {
    /* ignore */
  }
}

export async function login(username: string, password: string, rememberMe = false): Promise<AuthSession> {
  const credentials: LoginCredentials = { username, password };
  const session = await requestLoginSession(credentials);
  const finalized = finalizeAuthSessionSube(session);
  writeSession(finalized, rememberMe);
  return finalized;
}

export function logout(): void {
  clearSession();
}

registerAuthTokenSource(() => getSession()?.token ?? null);
