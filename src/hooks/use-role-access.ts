import {
  hasUserPermission,
  type AppPermission
} from "../lib/authorization/role-permissions";
import type { UserRole } from "../types/auth";
import { useAuth } from "../state/auth.store";

export function useRoleAccess() {
  const { session } = useAuth();
  const activeRole = session?.user.rol;
  const personelId = session?.user.personel_id ?? null;
  const uiProfile = session?.ui_profile ?? null;

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
    return hasUserPermission(activeRole, permission, personelId);
  }

  function hasAnyPermission(permissions: AppPermission[]) {
    return permissions.some((permission) => hasUserPermission(activeRole, permission, personelId));
  }

  return {
    activeRole,
    uiProfile,
    hasRole,
    hasAnyRole,
    hasPermission,
    hasAnyPermission
  };
}
