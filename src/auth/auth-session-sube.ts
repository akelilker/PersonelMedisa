import type { AuthSession } from "../types/auth";
import { GLOBAL_SCOPE_ROLES } from "../types/auth";
import { canonicalizeUserRole } from "../lib/authorization/canonicalize-user-role";

/**
 * sube_ids / sube_list / active_sube_id tutarliligini tek yerde kurar.
 * - Global + bos sube_ids: tum subeler, active_sube_id null (varsayilan)
 * - Global + coklu sube_list: null = tum subeler gorunumu; tek sube gecici filtre
 * - Atanmis tek sube: active o sube
 * - Coklu atanmis: kayitli active listede degilse ilk (scoped roller)
 */
export function finalizeAuthSessionSube(session: AuthSession): AuthSession {
  const role = canonicalizeUserRole(session.user.rol);
  const assigned = Array.isArray(session.user.sube_ids) ? [...session.user.sube_ids] : [];
  const listIds = (session.sube_list ?? []).map((s) => s.id).filter((id) => id > 0);
  const selectorIds = assigned.length > 0 ? assigned : listIds;
  const isGlobal = role != null && (GLOBAL_SCOPE_ROLES as readonly string[]).includes(role);
  const isGlobalUnrestricted = isGlobal && assigned.length === 0;

  const baseUser = {
    ...session.user,
    sube_ids: assigned,
    bolum_ids: session.user.bolum_ids ?? [],
    birim_ids: session.user.birim_ids ?? []
  };

  if (selectorIds.length === 0) {
    return {
      ...session,
      user: baseUser,
      active_sube_id: isGlobal ? null : session.active_sube_id
    };
  }

  if (selectorIds.length === 1) {
    const only = selectorIds[0]!;
    return {
      ...session,
      user: baseUser,
      active_sube_id: only
    };
  }

  const current = session.active_sube_id;
  if (current !== null && typeof current === "number" && selectorIds.includes(current)) {
    return {
      ...session,
      user: baseUser,
      active_sube_id: current
    };
  }

  return {
    ...session,
    user: baseUser,
    active_sube_id: isGlobalUnrestricted ? null : selectorIds[0] ?? null
  };
}
