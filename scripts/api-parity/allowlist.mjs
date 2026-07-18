/**
 * Load and validate the API parity allowlist.
 */

const VALID_CATEGORIES = new Set([
  "known_active_gap",
  "approved_deferred",
  "test_only",
  "demo_only",
  "php_external_or_ops",
  "legacy_active"
]);

const REQUIRES_TARGET_PHASE = new Set(["known_active_gap", "approved_deferred"]);

/**
 * @typedef {{
 *   method: string,
 *   path: string,
 *   category: string,
 *   gapId?: string,
 *   reason: string,
 *   owner: string,
 *   targetPhase?: string,
 *   introducedOrConfirmedAt?: string
 * }} AllowlistEntry
 */

/**
 * @param {unknown} raw
 * @returns {{ entries: AllowlistEntry[], errors: string[] }}
 */
export function validateAllowlist(raw) {
  /** @type {string[]} */
  const errors = [];

  if (!raw || typeof raw !== "object") {
    return { entries: [], errors: ["Allowlist root must be an object"] };
  }

  const doc = /** @type {Record<string, unknown>} */ (raw);
  if (!Array.isArray(doc.entries)) {
    return { entries: [], errors: ["Allowlist.entries must be an array"] };
  }

  /** @type {AllowlistEntry[]} */
  const entries = [];
  const seen = new Map();

  doc.entries.forEach((item, index) => {
    const prefix = `entries[${index}]`;
    if (!item || typeof item !== "object") {
      errors.push(`${prefix}: must be an object`);
      return;
    }
    const e = /** @type {Record<string, unknown>} */ (item);

    const method = typeof e.method === "string" ? e.method.trim().toUpperCase() : "";
    const path = typeof e.path === "string" ? e.path.trim() : "";
    const category = typeof e.category === "string" ? e.category.trim() : "";
    const reason = typeof e.reason === "string" ? e.reason.trim() : "";
    const owner = typeof e.owner === "string" ? e.owner.trim() : "";
    const gapId = typeof e.gapId === "string" ? e.gapId.trim() : undefined;
    const targetPhase = typeof e.targetPhase === "string" ? e.targetPhase.trim() : undefined;
    const introducedOrConfirmedAt =
      typeof e.introducedOrConfirmedAt === "string"
        ? e.introducedOrConfirmedAt.trim()
        : undefined;

    if (!method) errors.push(`${prefix}: method is required`);
    if (!path || !path.startsWith("/")) errors.push(`${prefix}: path must start with /`);
    if (!VALID_CATEGORIES.has(category)) {
      errors.push(`${prefix}: category must be one of ${[...VALID_CATEGORIES].join(", ")}`);
    }
    if (!reason) errors.push(`${prefix}: reason must be non-empty`);
    if (!owner) errors.push(`${prefix}: owner is required`);
    if (REQUIRES_TARGET_PHASE.has(category) && !targetPhase) {
      errors.push(`${prefix}: targetPhase is required for ${category}`);
    }
    if ((category === "known_active_gap" || gapId) && category === "known_active_gap" && !gapId) {
      errors.push(`${prefix}: gapId is required for known_active_gap`);
    }

    const key = `${method} ${path}`;
    if (seen.has(key)) {
      errors.push(`${prefix}: duplicate allowlist key ${key} (also at ${seen.get(key)})`);
    } else {
      seen.set(key, prefix);
    }

    if (errors.some((err) => err.startsWith(prefix))) {
      return;
    }

    entries.push({
      method,
      path,
      category,
      gapId,
      reason,
      owner,
      targetPhase,
      introducedOrConfirmedAt
    });
  });

  return { entries, errors };
}

/**
 * @param {AllowlistEntry[]} entries
 * @param {string} method
 * @param {string} normalizedPath
 * @returns {AllowlistEntry | undefined}
 */
export function findAllowlistEntry(entries, method, normalizedPath) {
  return entries.find(
    (e) => e.method === method.toUpperCase() && e.path === normalizedPath
  );
}

export { VALID_CATEGORIES, REQUIRES_TARGET_PHASE };
