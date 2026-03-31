import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode
} from "react";
import { login as loginWithStorage, clearSession as clearAuthEverywhere, getSession } from "../auth/auth-manager";
import { syncActiveSubeFromAuthUser } from "../data/data-manager";
import { onAuthUnauthorized } from "../lib/storage/auth-events";
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
  const [session, setSession] = useState<AuthSession | null>(() => getSession());

  const forceLogout = useCallback(() => {
    clearAuthEverywhere();
    setSession(null);
  }, []);

  const login = useCallback(async (credentials: LoginCredentials) => {
    const nextSession = await loginWithStorage(
      credentials.username.trim(),
      credentials.password,
      credentials.rememberMe === true
    );
    setSession(nextSession);
  }, []);

  const logout = useCallback(() => {
    clearAuthEverywhere();
    setSession(null);
  }, []);

  useEffect(() => {
    return onAuthUnauthorized(() => {
      forceLogout();
    });
  }, [forceLogout]);

  useEffect(() => {
    if (session) {
      syncActiveSubeFromAuthUser(session.user.sube_ids);
    }
  }, [session?.token, session?.user.id]);

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
