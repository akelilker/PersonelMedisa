import {
  hasRolePermission,
  type AppPermission
} from "../lib/authorization/role-permissions";
import type { UserRole } from "../types/auth";
import { useAuth } from "../state/auth.store";

export function useRoleAccess() {
  const { session } = useAuth();
  const activeRole = session?.user.rol;

  function hasRole(role: UserRole) {
    return activeRole === role;
  }

  function hasAnyRole(roles: UserRole[]) {
    if (!activeRole) {
      return false;
    }

    return roles.includes(activeRole);
  }

  function hasPermission(permission: AppPermission) {
    return hasRolePermission(activeRole, permission);
  }

  function hasAnyPermission(permissions: AppPermission[]) {
    if (!activeRole) {
      return false;
    }

    return permissions.some((permission) => hasRolePermission(activeRole, permission));
  }

  return {
    activeRole,
    hasRole,
    hasAnyRole,
    hasPermission,
    hasAnyPermission
  };
}
