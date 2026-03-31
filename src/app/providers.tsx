import { useEffect, type ReactNode } from "react";
import { BrowserRouter, useLocation, useNavigate } from "react-router-dom";
import { onAuthForbidden } from "../lib/storage/auth-events";
import { AuthProvider } from "../state/auth.store";

type AppProvidersProps = {
  children: ReactNode;
};

const ROUTER_FUTURE_FLAGS = {
  v7_startTransition: true,
  v7_relativeSplatPath: true
} as const;

function resolveRouterBasename(): string | undefined {
  if (typeof window === "undefined") {
    return undefined;
  }

  const subfolderBase = "/personelmedisa";
  return window.location.pathname.startsWith(subfolderBase) ? subfolderBase : undefined;
}

const ROUTER_BASENAME = resolveRouterBasename();

function AuthNavigationEffects() {
  const location = useLocation();
  const navigate = useNavigate();

  useEffect(() => {
    return onAuthForbidden(() => {
      if (location.pathname === "/yetkisiz") {
        return;
      }

      navigate("/yetkisiz", { replace: true, state: { from: location.pathname } });
    });
  }, [location.pathname, navigate]);

  return null;
}

export function AppProviders({ children }: AppProvidersProps) {
  return (
    <BrowserRouter basename={ROUTER_BASENAME} future={ROUTER_FUTURE_FLAGS}>
      <AuthProvider>
        <AuthNavigationEffects />
        {children}
      </AuthProvider>
    </BrowserRouter>
  );
}
