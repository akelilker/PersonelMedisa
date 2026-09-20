import type { UserRole } from "../../types/auth";
import { ALL_ROLES } from "../../types/auth";

const CANONICAL_SET = new Set<string>(ALL_ROLES);

/**
 * Single FE normalization boundary for auth/session/permission resolution.
 * Canonical catalog only; anything else (including legacy role strings) → null
 * so the session fails closed instead of inferring an authority level.
 */
export function canonicalizeUserRole(value: unknown): UserRole | null {
  if (typeof value !== "string") {
    return null;
  }

  const normalized = value.trim().toUpperCase().replace(/-/g, "_");
  if (!normalized) {
    return null;
  }

  if (CANONICAL_SET.has(normalized)) {
    return normalized as UserRole;
  }

  return null;
}
