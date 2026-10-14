import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode
} from "react";
import { dropExpiredAllows, fetchSessionPermissions } from "../api/auth.api";
import {
  login as loginWithStorage,
  clearSession as clearAuthEverywhere,
  getSession,
  patchSession,
  setActiveSubeId as persistActiveSubeId
} from "../auth/auth-manager";
import { logAction } from "../audit/audit-service";
import { bumpAppDataRevision, clearAllAppPersistence, loadDataFromServer } from "../data/data-manager";
import { disconnect as disconnectRealtime } from "../realtime/realtime-manager";
import { onAuthUnauthorized } from "../lib/storage/auth-events";
import type { AuthSession, LoginCredentials } from "../types/auth";

type AuthContextValue = {
  session: AuthSession | null;
  isAuthenticated: boolean;
  login: (credentials: LoginCredentials) => Promise<void>;
  logout: () => void;
  setActiveSubeId: (subeId: number | null) => void;
  clearMustChangePassword: () => void;
};

type AuthProviderProps = {
  children: ReactNode;
};

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

function samePermissionList(left: string[] | undefined, right: string[]): boolean {
  if (!left || left.length !== right.length) {
    return false;
  }
  const seen = new Set(right);
  return left.every((item) => seen.has(item));
}

function sameExpiringAllows(
  left: AuthSession["expiring_allows"],
  right: AuthSession["expiring_allows"]
): boolean {
  const a = left ?? [];
  const b = right ?? [];
  if (a.length !== b.length) {
    return false;
  }
  return a.every((item) => b.some((other) => other.permission === item.permission && other.ends_at_ms === item.ends_at_ms));
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [session, setSession] = useState<AuthSession | null>(() => getSession());
  const didBootstrapRef = useRef(false);

  const forceLogout = useCallback(() => {
    const before = getSession();
    logAction({
      action: "AUTH_LOGOUT",
      user_id: before?.user?.id ?? null,
      payload: { reason: "unauthorized_or_forbidden" }
    });
    disconnectRealtime();
    clearAuthEverywhere();
    clearAllAppPersistence();
    didBootstrapRef.current = false;
    setSession(null);
  }, []);

  const login = useCallback(async (credentials: LoginCredentials) => {
    const nextSession = await loginWithStorage(
      credentials.username.trim(),
      credentials.password,
      credentials.rememberMe === true
    );
    setSession(nextSession);
    logAction({
      action: "AUTH_LOGIN_SUCCESS",
      user_id: nextSession.user.id,
      payload: { username: credentials.username.trim(), ui_profile: nextSession.ui_profile }
    });
    bumpAppDataRevision();
    // Login sonrası tek preload; mount effect ile çakışmayı engelle.
    didBootstrapRef.current = true;
    void loadDataFromServer({ force: true });
  }, []);

  const logout = useCallback(() => {
    const before = getSession();
    logAction({
      action: "AUTH_LOGOUT",
      user_id: before?.user?.id ?? null,
      payload: { reason: "user_initiated" }
    });
    disconnectRealtime();
    clearAuthEverywhere();
    clearAllAppPersistence();
    didBootstrapRef.current = false;
    setSession(null);
  }, []);

  const setActiveSubeId = useCallback((subeId: number | null) => {
    persistActiveSubeId(subeId);
    setSession(getSession());
    bumpAppDataRevision();
    void loadDataFromServer({ force: true });
  }, []);

  // Stored valid session refresh: auth hazır olduktan sonra tek bootstrap.
  useEffect(() => {
    if (didBootstrapRef.current) {
      return;
    }
    if (!session) {
      return;
    }
    didBootstrapRef.current = true;
    void loadDataFromServer();
  }, [session]);

  useEffect(() => {
    return onAuthUnauthorized(() => {
      forceLogout();
    });
  }, [forceLogout]);

  useEffect(() => {
    if (!session?.token || session.permissions_source === "demo") {
      return;
    }
    let cancelled = false;
    let intervalId = 0;
    let expiryId = 0;

    const scheduleExpiry = (atMs: number | null) => {
      window.clearTimeout(expiryId);
      if (atMs == null) {
        return;
      }
      const delay = atMs - Date.now() + 1000;
      if (delay > 0 && delay < 24 * 60 * 60 * 1000) {
        expiryId = window.setTimeout(() => {
          void refresh();
        }, delay);
      }
    };

    const applyExpiredAllows = () => {
      const current = getSession();
      if (!current) {
        return;
      }
      const dropped = dropExpiredAllows(current, Date.now());
      if (!dropped) {
        return;
      }
      const next = patchSession({
        expiring_allows: dropped.expiring_allows,
        user: { ...current.user, effective_permissions: dropped.user.effective_permissions }
      });
      if (next) {
        setSession(next);
      }
    };

    const refresh = async () => {
      applyExpiredAllows();
      try {
        const snapshot = await fetchSessionPermissions();
        if (cancelled) {
          return;
        }
        const current = getSession();
        if (!current) {
          return;
        }
        const unchanged =
          current.permissions_hydrated === true &&
          current.permissions_stale !== true &&
          current.permissions_source === "server" &&
          samePermissionList(current.user.effective_permissions, snapshot.permissions) &&
          sameExpiringAllows(current.expiring_allows, snapshot.expiringAllows);
        if (!unchanged) {
          const next = patchSession({
            permissions_hydrated: true,
            permissions_stale: false,
            permissions_source: "server",
            expiring_allows: snapshot.expiringAllows,
            user: { ...current.user, effective_permissions: snapshot.permissions }
          });
          if (next) {
            setSession(next);
          }
        }
        scheduleExpiry(snapshot.refreshAtMs);
      } catch {
        if (cancelled) {
          return;
        }
        applyExpiredAllows();
        const current = getSession();
        // Hidrate liste yoksa bu bir kısıt değil, eksik/eski oturumdur.
        // Production zaten fail-closed; demo ve eski oturum rol matrisinde kalır.
        // Hidrate liste varken hata listeyi silmez ve süresi dolmuş ALLOW'ı geri açmaz.
        if (!current || current.permissions_hydrated !== true) {
          return;
        }
        if (current.permissions_stale !== true) {
          const next = patchSession({ permissions_stale: true });
          if (next) {
            setSession(next);
          }
        }
        const now = Date.now();
        const remaining = (getSession()?.expiring_allows ?? []).reduce<number | null>((soonest, item) => {
          if (item.ends_at_ms <= now) {
            return soonest;
          }
          return soonest === null || item.ends_at_ms < soonest ? item.ends_at_ms : soonest;
        }, null);
        scheduleExpiry(remaining);
      }
    };

    void refresh();
    const onFocus = () => {
      void refresh();
    };
    window.addEventListener("focus", onFocus);
    intervalId = window.setInterval(() => {
      void refresh();
    }, 60_000);
    return () => {
      cancelled = true;
      window.removeEventListener("focus", onFocus);
      window.clearInterval(intervalId);
      window.clearTimeout(expiryId);
    };
  }, [session?.permissions_source, session?.token]);

  const clearMustChangePassword = useCallback(() => {
    const next = patchSession({ must_change_password: false });
    setSession(next);
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      session,
      isAuthenticated: session !== null,
      login,
      logout,
      setActiveSubeId,
      clearMustChangePassword
    }),
    [clearMustChangePassword, login, logout, session, setActiveSubeId]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error("useAuth must be used within AuthProvider");
  }

  return context;
}
