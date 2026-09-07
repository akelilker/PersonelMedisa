/**
 * A1 approver read-only probe helper (Sinem Hamaloğlu business lock).
 * Plan-only / local prep: does NOT connect to production unless explicitly forced
 * with TARGET_ENV + A1_RO_PROBE_EXECUTE=1 and a DSN. Default is dry checklist mode.
 *
 * Mutation: never. Forbidden SQL verbs are rejected if a statement is supplied.
 * Live username/role/scopes remain VERIFY_LIVE_REQUIRED until hosting recovery.
 */
"use strict";

import { readFileSync } from "node:fs";
import { resolve } from "node:path";

/** Historical local keys used to find Sinem for live verify — not certified live values. */
export const A1_APPROVER_DISPLAY_NAME = "Sinem Hamaloğlu";
export const A1_APPROVER_INTENDED_ROLE_MODEL = "BOLUM_YONETICISI";
export const A1_APPROVER_PROBE_USERNAMES = Object.freeze(["sinemH"]);
export const A1_APPROVER_LOCAL_USER_ID = 110;
export const A1_APPROVER_LOCAL_PERSONEL_ID = 173;
/** @deprecated retained empty so older imports do not crash; probe no longer uses sicil candidates */
export const A1_APPROVER_CANDIDATES = Object.freeze([]);
export const A1_PREPARER_USERNAME = "sedanurB";
export const A1_EXCLUDED_SENAY_LINKED = Object.freeze(["005", "004"]);
export const A1_REQUIRED_OUTPUT_COLUMNS = Object.freeze([
  "USERNAME",
  "USER_ID",
  "PERSONEL_ID",
  "PERSONEL_NAME",
  "ROLE",
  "AKTIF",
  "SIRKET",
  "SUBE",
  "DEPARTMAN",
  "BOLUM",
  "BIRIM",
  "ACTOR_IDENTITY_STATUS",
  "CURRENT_BOLUM_IDS",
  "CURRENT_USER_SUBELER",
  "MEDISA_FIT",
]);

const FORBIDDEN_SQL = /\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE|GRANT|REVOKE|CALL|LOAD|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i;

/**
 * @param {string} sql
 * @returns {{ ok: boolean, code?: string }}
 */
export function assertReadOnlySql(sql) {
  const text = String(sql || "");
  if (!text.trim()) {
    return { ok: false, code: "EMPTY_SQL" };
  }
  // Strip line/block comments before verb scan so header notes cannot false-positive.
  const withoutComments = text
    .replace(/\/\*[\s\S]*?\*\//g, " ")
    .replace(/--[^\n\r]*/g, " ");
  if (FORBIDDEN_SQL.test(withoutComments)) {
    return { ok: false, code: "WRITE_SQL_FORBIDDEN" };
  }
  if (!/\bSELECT\b/i.test(withoutComments)) {
    return { ok: false, code: "SELECT_REQUIRED" };
  }
  return { ok: true };
}

/**
 * @param {string} [sqlPath]
 * @returns {string}
 */
export function loadApproverProbeSql(sqlPath) {
  const path = resolve(
    sqlPath || resolve(process.cwd(), "ops/sgk/a1-approver-candidate-ro-probe.sql"),
  );
  return readFileSync(path, "utf8");
}

/**
 * @param {Record<string, string | undefined>} env
 * @returns {{
 *   mode: "CHECKLIST" | "EXECUTE",
 *   targetEnv: string | null,
 *   canExecute: boolean,
 *   blockers: string[],
 *   candidates: readonly string[],
 *   excluded: readonly string[],
 *   preparer: string,
 *   approverDisplayName: string,
 *   intendedRoleModel: string,
 *   localUserId: number,
 *   localPersonelId: number,
 *   requiredColumns: readonly string[],
 * }}
 */
export function buildApproverProbePlan(env = process.env) {
  const targetEnv = String(env.TARGET_ENV || "").trim().toLowerCase();
  const executeFlag = String(env.A1_RO_PROBE_EXECUTE || "").trim() === "1";
  const dsnPresent = Boolean(String(env.MEDISA_RO_DSN || env.DATABASE_URL || "").trim());
  const blockers = [];

  if (!targetEnv) {
    blockers.push("TARGET_ENV_REQUIRED");
  } else if (!["production", "staging", "local"].includes(targetEnv)) {
    blockers.push("TARGET_ENV_INVALID");
  }

  if (executeFlag && !dsnPresent) {
    blockers.push("RO_DSN_REQUIRED_FOR_EXECUTE");
  }
  if (executeFlag && targetEnv === "production" && String(env.CONFIRM_PRODUCTION_RO || "") !== "YES") {
    blockers.push("CONFIRM_PRODUCTION_RO_REQUIRED");
  }

  const canExecute = executeFlag && blockers.length === 0;
  return {
    mode: canExecute ? "EXECUTE" : "CHECKLIST",
    targetEnv: targetEnv || null,
    canExecute,
    blockers,
    candidates: A1_APPROVER_PROBE_USERNAMES,
    excluded: A1_EXCLUDED_SENAY_LINKED,
    preparer: A1_PREPARER_USERNAME,
    approverDisplayName: A1_APPROVER_DISPLAY_NAME,
    intendedRoleModel: A1_APPROVER_INTENDED_ROLE_MODEL,
    localUserId: A1_APPROVER_LOCAL_USER_ID,
    localPersonelId: A1_APPROVER_LOCAL_PERSONEL_ID,
    requiredColumns: A1_REQUIRED_OUTPUT_COLUMNS,
  };
}

/**
 * Redact secret-looking env keys from a plan dump.
 * @param {Record<string, unknown>} row
 */
export function redactSecrets(row) {
  const out = { ...row };
  for (const key of Object.keys(out)) {
    if (/dsn|password|secret|token|ftp|key/i.test(key)) {
      out[key] = "[REDACTED]";
    }
  }
  return out;
}

/**
 * CLI: default checklist only. Never auto-executes against production.
 */
export function main(env = process.env, log = console.log) {
  const sql = loadApproverProbeSql();
  const ro = assertReadOnlySql(sql);
  if (!ro.ok) {
    throw new Error(`A1_RO_PROBE_SQL_UNSAFE:${ro.code}`);
  }
  const plan = buildApproverProbePlan(env);
  log(
    JSON.stringify(
      redactSecrets({
        tool: "a1-approver-candidate-ro-probe",
        mutation: 0,
        sql_ok: true,
        ...plan,
        note:
          plan.mode === "CHECKLIST"
            ? "Hosting recovery required before live execute; set TARGET_ENV + A1_RO_PROBE_EXECUTE=1 + RO DSN"
            : "Execute path armed — caller must run SELECT via external RO client; this helper does not open sockets by default",
      }),
      null,
      2,
    ),
  );
  return plan;
}

const isDirect =
  typeof process.argv[1] === "string" &&
  process.argv[1].replace(/\\/g, "/").endsWith("a1-approver-candidate-ro-probe.mjs");

if (isDirect) {
  main();
}
