import { clearAllAppPersistence } from "../data/data-manager";
import type { AuthSession, AuthUser, LoginCredentials } from "../types/auth";
import { login as requestLoginSession } from "../api/auth.api";
import { registerAuthTokenSource } from "./auth-token-provider";

export const MEDISA_AUTH_SESSION_KEY = "medisa_auth_session";

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
    return parsed;
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

export function isAuthenticated(): boolean {
  return getSession() !== null;
}

export function setToken(nextToken: string): void {
  const located = readRawFromStorages();
  const current = located ? parseStored(located.raw) : null;
  if (!current || !located) {
    return;
  }

  const next: AuthSession = { ...current, token: nextToken };
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

  clearAllAppPersistence();
}

export async function login(username: string, password: string, rememberMe = false): Promise<AuthSession> {
  const credentials: LoginCredentials = { username, password };
  const session = await requestLoginSession(credentials);
  writeSession(session, rememberMe);
  return session;
}

export function logout(): void {
  clearSession();
}

registerAuthTokenSource(() => getSession()?.token ?? null);
