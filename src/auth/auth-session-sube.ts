import type { AuthSession } from "../types/auth";

/**
 * sube_ids ve active_sube_id tutarliligini tek yerde kurar.
 * - Bos sube_ids: tum subeler (yonetim), active_sube_id null
 * - Tek sube: active o sube
 * - Coklu: kayitli active yetkili listesinde degilse ilk yetkili sube
 */
export function finalizeAuthSessionSube(session: AuthSession): AuthSession {
  const ids = Array.isArray(session.user.sube_ids) ? [...session.user.sube_ids] : [];

  if (ids.length === 0) {
    return {
      ...session,
      user: { ...session.user, sube_ids: ids },
      active_sube_id: null
    };
  }

  if (ids.length === 1) {
    const only = ids[0]!;
    return {
      ...session,
      user: { ...session.user, sube_ids: ids },
      active_sube_id: only
    };
  }

  const current = session.active_sube_id;
  if (current !== null && typeof current === "number" && ids.includes(current)) {
    return {
      ...session,
      user: { ...session.user, sube_ids: ids },
      active_sube_id: current
    };
  }

  return {
    ...session,
    user: { ...session.user, sube_ids: ids },
    active_sube_id: ids[0] ?? null
  };
}
