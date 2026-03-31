import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode
} from "react";
import { login as loginApi } from "../api/auth.api";
import { onAuthUnauthorized } from "../lib/storage/auth-events";
import {
  clearStoredAuthSession,
  getStoredAuthSession,
  setStoredAuthSession
} from "../lib/storage/auth-session";
import type { AuthSession, LoginCredentials } from "../types/auth";

type AuthContextValue = {
  session: AuthSession | null;
  isAuthenticated: boolean;
  login: (credentials: LoginCredentials) => Promise<void>;
  logout: () => void;
};

type AuthProviderProps = {
  children: ReactNode;
};

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: AuthProviderProps) {
  const [session, setSession] = useState<AuthSession | null>(() => getStoredAuthSession());

  const forceLogout = useCallback(() => {
    setSession(null);
    clearStoredAuthSession();
  }, []);

  const login = useCallback(async (credentials: LoginCredentials) => {
    const nextSession = await loginApi(credentials);
    setSession(nextSession);
    setStoredAuthSession(nextSession);
  }, []);

  const logout = useCallback(() => {
    forceLogout();
  }, [forceLogout]);

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
      logout
    }),
    [login, logout, session]
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
