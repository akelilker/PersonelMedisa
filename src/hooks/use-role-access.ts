import {
  hasSessionPermission,
  sessionAllowsSubeWrite,
  type AppPermission
} from "../lib/authorization/role-permissions";
import type { UserRole } from "../types/auth";
import { useAuth } from "../state/auth.store";

export function useRoleAccess() {
  const { session } = useAuth();
  const activeRole = session?.user.rol;
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
    return hasSessionPermission(session, permission);
  }

  function hasAnyPermission(permissions: AppPermission[]) {
    return permissions.some((permission) => hasSessionPermission(session, permission));
  }

  /**
   * Bir subedeki kayit uzerinde dogrudan islem yapilabilir mi.
   *
   * Yalniz yazma kapsami okuma kapsamindan dar olan roller icin daraltir; diger
   * roller icin izin kontrolu tek belirleyici olmaya devam eder. Bu kontrol
   * kullaniciyi reddedilecek bir islemden onceden korur, guvenlik owner'i
   * degildir: kapsam disi yazmayi backend 403 ile reddeder.
   */
  function canWriteInSube(subeId: number | null | undefined) {
    return sessionAllowsSubeWrite(session ?? null, subeId);
  }

  return {
    activeRole,
    uiProfile,
    hasRole,
    hasAnyRole,
    hasPermission,
    hasAnyPermission,
    canWriteInSube
  };
}
