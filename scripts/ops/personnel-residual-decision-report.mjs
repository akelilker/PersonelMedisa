/**
 * Personnel residual decision-pack reporter (read-only local artifact).
 * Does not connect to production. Does not invent assignment targets.
 */
"use strict";

import { readFileSync } from "node:fs";
import { resolve } from "node:path";

export const PERSONNEL_DECISION_REQUIRED_COLUMNS = Object.freeze([
  "PERSONEL_ID",
  "NAME",
  "STATUS",
  "COMPANY",
  "BRANCH",
  "SGK",
  "CURRENT_LOCATION",
  "DEPARTMENT",
  "BOLUM",
  "BIRIM",
  "WHY_UNRESOLVED",
  "WHAT_BUSINESS_DECISION_IS_NEEDED",
]);

/**
 * @param {string} [packPath]
 */
export function loadPersonnelDecisionPack(packPath) {
  const path = resolve(
    packPath ||
      resolve(process.cwd(), "ops/organization-mapping/personnel-residual-decision-pack.json"),
  );
  return JSON.parse(readFileSync(path, "utf8"));
}

/**
 * @param {unknown} pack
 */
export function validatePersonnelDecisionPack(pack) {
  if (!pack || typeof pack !== "object") {
    return { ok: false, code: "PACK_NOT_OBJECT" };
  }
  const body = /** @type {Record<string, unknown>} */ (pack);
  if (body.production_mutation !== 0) {
    return { ok: false, code: "MUTATION_MUST_BE_ZERO" };
  }
  if (body.status !== "DECISION_PACK_ONLY_NO_APPLY") {
    return { ok: false, code: "STATUS_MUST_BE_NO_APPLY" };
  }
  if (!Array.isArray(body.rows)) {
    return { ok: false, code: "ROWS_REQUIRED" };
  }
  for (const row of body.rows) {
    if (!row || typeof row !== "object") {
      return { ok: false, code: "ROW_INVALID" };
    }
    const rec = /** @type {Record<string, unknown>} */ (row);
    for (const col of PERSONNEL_DECISION_REQUIRED_COLUMNS) {
      if (!(col in rec)) {
        return { ok: false, code: `MISSING_COLUMN_${col}` };
      }
    }
    if (rec.CLASS === "BUSINESS_DECISION_REQUIRED" && rec.PERSONEL_ID === 212) {
      // Explicit lock: never auto-suggest a target for 212 in this pack.
      if ("TARGET" in rec && rec.TARGET != null) {
        return { ok: false, code: "PERSONEL_212_TARGET_FORBIDDEN" };
      }
    }
  }
  return { ok: true };
}

/**
 * @param {Record<string, string | undefined>} [env]
 * @param {(msg: string) => void} [log]
 */
export function main(env = process.env, log = console.log) {
  if (String(env.PERSONNEL_DECISION_APPLY || "") === "1") {
    throw new Error("PERSONNEL_DECISION_APPLY_FORBIDDEN");
  }
  const pack = loadPersonnelDecisionPack();
  const validation = validatePersonnelDecisionPack(pack);
  if (!validation.ok) {
    throw new Error(`PERSONNEL_DECISION_PACK_INVALID:${validation.code}`);
  }
  const summary = {
    tool: "personnel-residual-decision-report",
    mutation: 0,
    row_count: pack.rows.length,
    business_decision_ids: pack.classes.BUSINESS_DECISION_REQUIRED,
    cross_company_ids: pack.classes.CROSS_COMPANY_SEMANTICALLY_VALID,
    auto_resolvable: pack.classes.AUTO_RESOLVABLE_BY_CONFIRMED_TRUTH,
    closed: pack.closed_do_not_reopen,
  };
  log(JSON.stringify(summary, null, 2));
  return summary;
}

const isDirect =
  typeof process.argv[1] === "string" &&
  process.argv[1].replace(/\\/g, "/").endsWith("personnel-residual-decision-report.mjs");

if (isDirect) {
  main();
}
