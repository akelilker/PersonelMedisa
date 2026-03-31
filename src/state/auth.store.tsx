import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode
} from "react";
import {
  login as loginWithStorage,
  clearSession as clearAuthEverywhere,
  getSession,
  setActiveSubeId as persistActiveSubeId
} from "../auth/auth-manager";
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

  const forceLogout = useCallback(() => {
    disconnectRealtime();
    clearAuthEverywhere();
    clearAllAppPersistence();
    setSession(null);
  }, []);

  const login = useCallback(async (credentials: LoginCredentials) => {
    const nextSession = await loginWithStorage(
      credentials.username.trim(),
      credentials.password,
      credentials.rememberMe === true
    );
    setSession(nextSession);
    bumpAppDataRevision();
    void loadDataFromServer();
  }, []);

  const logout = useCallback(() => {
    disconnectRealtime();
    clearAuthEverywhere();
    clearAllAppPersistence();
    setSession(null);
  }, []);

  const setActiveSubeId = useCallback((subeId: number | null) => {
    persistActiveSubeId(subeId);
    setSession(getSession());
    bumpAppDataRevision();
    void loadDataFromServer();
  }, []);

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
