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
import {
  login as loginWithStorage,
  clearSession as clearAuthEverywhere,
  getSession,
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
};

type AuthProviderProps = {
  children: ReactNode;
};

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

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

  const value = useMemo<AuthContextValue>(
    () => ({
      session,
      isAuthenticated: session !== null,
      login,
      logout,
      setActiveSubeId
    }),
    [login, logout, session, setActiveSubeId]
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
