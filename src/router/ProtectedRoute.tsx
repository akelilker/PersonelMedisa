import type { ReactNode } from "react";
import { Navigate, useLocation } from "react-router-dom";
import { hasRolePermission, type AppPermission } from "../lib/authorization/role-permissions";
import { useAuth } from "../state/auth.store";

type ProtectedRouteProps = {
  children: ReactNode;
  requirePermission?: AppPermission;
  requireAll?: AppPermission[];
  /** En az biri yeterli (liste modulleri icin) */
  requireAny?: AppPermission[];
};

export function ProtectedRoute({ children, requirePermission, requireAll, requireAny }: ProtectedRouteProps) {
  const { session, isAuthenticated } = useAuth();
  const location = useLocation();

  if (!isAuthenticated || !session) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  if (requireAny && requireAny.length > 0) {
    const allowed = requireAny.some((p) => hasRolePermission(session.user.rol, p));
    if (!allowed) {
      return <Navigate to="/yetkisiz" replace />;
    }
  }

  const permissions = [
    ...(requirePermission ? [requirePermission] : []),
    ...(requireAll ?? [])
  ];

  if (permissions.length > 0) {
    const allowed = permissions.every((p) => hasRolePermission(session.user.rol, p));
    if (!allowed) {
      return <Navigate to="/yetkisiz" replace />;
    }
  }

  return <>{children}</>;
}
