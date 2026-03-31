import type { AuthSession } from "../../types/auth";

export const AUTH_STORAGE_KEY = "medisa.auth.session.v1";

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

export function getStoredAuthSession(): AuthSession | null {
  if (typeof window === "undefined") {
    return null;
  }

  const raw = window.localStorage.getItem(AUTH_STORAGE_KEY);
  if (!raw) {
    return null;
  }

  try {
    const parsed = JSON.parse(raw) as unknown;
    if (!isAuthSession(parsed)) {
      window.localStorage.removeItem(AUTH_STORAGE_KEY);
      return null;
    }

    return parsed;
  } catch {
    window.localStorage.removeItem(AUTH_STORAGE_KEY);
    return null;
  }
}

export function setStoredAuthSession(session: AuthSession) {
  if (typeof window === "undefined") {
    return;
  }

  window.localStorage.setItem(AUTH_STORAGE_KEY, JSON.stringify(session));
}

export function clearStoredAuthSession() {
  if (typeof window === "undefined") {
    return;
  }

  window.localStorage.removeItem(AUTH_STORAGE_KEY);
}

export function getStoredAuthToken(): string | null {
  return getStoredAuthSession()?.token ?? null;
}
