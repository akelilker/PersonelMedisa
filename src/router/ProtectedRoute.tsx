import type { ReactNode } from "react";
import { Navigate, useLocation } from "react-router-dom";
import { hasSessionPermission, type AppPermission } from "../lib/authorization/role-permissions";
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

  const onChangePassword = location.pathname === "/change-password";
  if (session.must_change_password === true && !onChangePassword) {
    return <Navigate to="/change-password" replace />;
  }

  // Etkin izin listesi oturumdadır. QR ve self-service temeli sunucu listesinde
  // gelir; backend 403 güvenlik sahibi olmaya devam eder.
  if (requireAny && requireAny.length > 0) {
    const allowed = requireAny.some((p) => hasSessionPermission(session, p));
    if (!allowed) {
      return <Navigate to="/yetkisiz" replace />;
    }
  }

  const permissions = [
    ...(requirePermission ? [requirePermission] : []),
    ...(requireAll ?? [])
  ];

  if (permissions.length > 0) {
    const allowed = permissions.every((p) => hasSessionPermission(session, p));
    if (!allowed) {
      return <Navigate to="/yetkisiz" replace />;
    }
  }

  return <>{children}</>;
}
