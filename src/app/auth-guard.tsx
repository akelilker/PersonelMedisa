import type { ReactNode } from "react";
import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "../state/auth.store";
import type { UserRole } from "../types/auth";

type AuthGuardProps = {
  children: ReactNode;
  allowedRoles?: UserRole[];
};

export function AuthGuard({ children, allowedRoles }: AuthGuardProps) {
  const { session, isAuthenticated } = useAuth();
  const location = useLocation();

  if (!isAuthenticated) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  if (allowedRoles && session && !allowedRoles.includes(session.user.rol)) {
    return <Navigate to="/yetkisiz" replace />;
  }

  return <>{children}</>;
}
