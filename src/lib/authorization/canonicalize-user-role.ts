import type { UserRole } from "../../types/auth";
import { ALL_ROLES, TECHNICAL_ROLES } from "../../types/auth";

/** Legacy strings that safely map to a canonical human role. */
export const SAFE_LEGACY_ROLE_ALIASES: Readonly<Record<string, UserRole>> = {
  PATRON: "GENEL_YONETICI",
  IK_BORDRO: "IK_SORUMLUSU"
};

/**
 * Legacy roles that must NOT auto-map to a business role without production inventory.
 * Fail-closed: unresolved → null (no privilege escalation).
 */
export const UNRESOLVED_LEGACY_ROLES: readonly string[] = [
  "SGK_KARAR_ONAY_YETKILISI",
  "IDARI_ISLER"
];

const CANONICAL_SET = new Set<string>(ALL_ROLES);

/**
 * Single FE normalization boundary for auth/session/permission resolution.
 * Safe aliases only: PATRON → GENEL_YONETICI, IK_BORDRO → IK_SORUMLUSU.
 */
export function canonicalizeUserRole(value: unknown): UserRole | null {
  if (typeof value !== "string") {
    return null;
  }

  const normalized = value.trim().toUpperCase().replace(/-/g, "_");
  if (!normalized) {
    return null;
  }

  if (Object.prototype.hasOwnProperty.call(SAFE_LEGACY_ROLE_ALIASES, normalized)) {
    return SAFE_LEGACY_ROLE_ALIASES[normalized] ?? null;
  }

  if (UNRESOLVED_LEGACY_ROLES.includes(normalized)) {
    return null;
  }

  if (CANONICAL_SET.has(normalized)) {
    return normalized as UserRole;
  }

  return null;
}

export function isTechnicalRole(role: UserRole | null | undefined): boolean {
  if (!role) {
    return false;
  }
  return (TECHNICAL_ROLES as readonly string[]).includes(role);
}
