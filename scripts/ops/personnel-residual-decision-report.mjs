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
  }
  const classes = /** @type {Record<string, unknown>} */ (body.classes || {});
  if (!Array.isArray(classes.AUTO_RESOLVABLE_BY_CONFIRMED_TRUTH)) {
    return { ok: false, code: "AUTO_RESOLVABLE_REQUIRED" };
  }
  if (!Array.isArray(classes.BUSINESS_DECISION_REQUIRED)) {
    return { ok: false, code: "BUSINESS_DECISION_REQUIRED_ARRAY" };
  }
  if (!Array.isArray(classes.CROSS_COMPANY_SEMANTICALLY_VALID)) {
    return { ok: false, code: "CROSS_COMPANY_REQUIRED" };
  }
  // Locked loc5 set remains no-apply (BUSINESS_TRUTH_RESOLVED_NO_APPLY) — no apply needed; historical hosting-recovery gate CLOSED.
  const expectedLoc5 = [200, 201, 203, 204, 205, 206, 209, 210, 212, 217];
  const auto = classes.AUTO_RESOLVABLE_BY_CONFIRMED_TRUTH.map(Number);
  for (const id of expectedLoc5) {
    if (!auto.includes(id)) {
      return { ok: false, code: `LOC5_TRUTH_MISSING_${id}` };
    }
  }
  if (classes.BUSINESS_DECISION_REQUIRED.length !== 0) {
    return { ok: false, code: "UNEXPECTED_BUSINESS_DECISION_ROWS" };
  }
  const cross = classes.CROSS_COMPANY_SEMANTICALLY_VALID.map(Number);
  for (const id of [120, 158, 219]) {
    if (!cross.includes(id)) {
      return { ok: false, code: `CROSS_COMPANY_MISSING_${id}` };
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
