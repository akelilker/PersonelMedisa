/**
 * Fail-closed gates for historical PASIF exit-date residual backfill ops wrapper.
 * Pure helpers — no network / FTP / git side effects (except optional binary probe via injectables).
 */

import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";

export const SHA40_RE = /^[0-9a-f]{40}$/;

export const CURL_BINARY_NOT_AVAILABLE = "CURL_BINARY_NOT_AVAILABLE";
export const FTP_NETRC_NOT_CONFIGURED = "FTP_NETRC_NOT_CONFIGURED";
export const FTP_NETRC_NOT_READABLE = "FTP_NETRC_NOT_READABLE";
export const FTP_COMMAND_FAILED = "FTP_COMMAND_FAILED";
export const FTP_REMOTE_READ_FAILED = "FTP_REMOTE_READ_FAILED";

/** Exact normalized full-name equality targets (Turkish chars foldable via normName). */
export const EXPECTED_FULL_NAMES = Object.freeze({
  202: "AHMED KHALIL ALSAMAR",
  208: "SEFINE OZCAN",
});

/** Authoritative HR exit dates for known residual targets. */
export const EXPECTED_EXIT_DATES = Object.freeze({
  202: "2025-12-31",
  208: "2026-05-25",
});

/** Known wrong live dates that require HISTORICAL_EXIT_DATE_CORRECTION (not backfill). */
export const WRONG_EXIT_DATES_REQUIRING_CORRECTION = Object.freeze({
  202: "2026-07-30",
  208: "2026-07-30",
});

export const CORRECTION_SUREC_IDS = Object.freeze({
  202: 38,
  208: 39,
});

/** Domain classifications for residual / conflict evaluation. */
export const BACKFILL_CLASSIFICATION = Object.freeze({
  CREATE_ELIGIBLE: "CREATE_ELIGIBLE",
  ALREADY_APPLIED: "ALREADY_APPLIED",
  CONFLICT: "CONFLICT",
});

/** Legacy Windows-only netrc fallback (used only when the path exists). */
export const LEGACY_WINDOWS_FTP_NETRC_SEGMENTS = Object.freeze([
  "Documents",
  "medisa-ops-tmp",
  "org-rollout",
  "private",
  "ftp.curl",
]);

export function normName(v) {
  return String(v ?? "")
    .trim()
    .replace(/\s+/g, " ")
    .toLocaleUpperCase("tr-TR")
    .replace(/İ/g, "I")
    .replace(/Ş/g, "S")
    .replace(/Ğ/g, "G")
    .replace(/Ü/g, "U")
    .replace(/Ö/g, "O")
    .replace(/Ç/g, "C");
}

/**
 * Parse `--name=value` or `--name value` from argv.
 * @param {string[]} argv
 * @param {string} name flag without leading dashes (e.g. "curl-bin")
 * @returns {string|null}
 */
export function parseNamedArg(argv, name) {
  const args = Array.isArray(argv) ? argv : [];
  const eq = `--${name}=`;
  const flag = `--${name}`;
  for (let i = 0; i < args.length; i += 1) {
    const arg = String(args[i] ?? "");
    if (arg.startsWith(eq)) {
      const value = arg.slice(eq.length).trim();
      return value === "" ? null : value;
    }
    if (arg === flag) {
      const value = String(args[i + 1] ?? "").trim();
      return value === "" ? null : value;
    }
  }
  return null;
}

/**
 * Parse required runtime --expected-sha. No silent baseline fallback.
 * @param {string[]} argv
 * @returns {string} lowercase 40-hex SHA
 */
export function parseExpectedSha(argv) {
  const valueRaw = parseNamedArg(argv, "expected-sha");
  const value = valueRaw === null ? null : valueRaw.trim().toLowerCase();
  if (value === null || value === "") {
    const err = new Error(
      "--expected-sha=<40-char hex SHA> is required (no silent baseline fallback)"
    );
    err.code = "EXPECTED_SHA_MISSING";
    throw err;
  }
  if (!SHA40_RE.test(value)) {
    const err = new Error(`--expected-sha must be exactly 40 hex chars, got: ${value}`);
    err.code = "EXPECTED_SHA_INVALID";
    throw err;
  }
  return value;
}

/**
 * Platform default curl binary (command name, not absolute path).
 * @param {NodeJS.Platform|string} [platform]
 */
export function defaultCurlBinary(platform = process.platform) {
  return platform === "win32" ? "curl.exe" : "curl";
}

/**
 * Resolve curl binary: CLI > MEDISA_OPS_CURL_BIN > platform default.
 * Does not probe executability (see assertCurlBinaryAvailable).
 */
export function resolveCurlBinary({
  argv = [],
  env = process.env,
  platform = process.platform,
} = {}) {
  const cli = parseNamedArg(argv, "curl-bin");
  if (cli) {
    return { source: "cli", binary: cli };
  }
  const fromEnv = String(env?.MEDISA_OPS_CURL_BIN ?? "").trim();
  if (fromEnv) {
    return { source: "env", binary: fromEnv };
  }
  return { source: "platform_default", binary: defaultCurlBinary(platform) };
}

/**
 * Fail closed if selected curl binary is not runnable.
 * @param {string} binary
 */
export function assertCurlBinaryAvailable(
  binary,
  { spawnSyncFn = spawnSync } = {}
) {
  const label = String(binary || "").trim() || "(empty)";
  let result;
  try {
    result = spawnSyncFn(label, ["--version"], {
      encoding: "utf8",
      timeout: 10_000,
    });
  } catch (e) {
    const err = new Error(`curl binary not available: ${label}`);
    err.code = CURL_BINARY_NOT_AVAILABLE;
    err.details = { curl_bin: label, probe_error: String(e?.message || e) };
    throw err;
  }
  if (result?.error || result?.status !== 0) {
    const err = new Error(`curl binary not available: ${label}`);
    err.code = CURL_BINARY_NOT_AVAILABLE;
    err.details = {
      curl_bin: label,
      exit_status: result?.status ?? null,
      stderr: sanitizeDiagnosticText(result?.stderr || result?.error?.message || ""),
    };
    throw err;
  }
  return true;
}

/**
 * Legacy Windows Documents/.../ftp.curl path (compatibility only).
 */
export function legacyWindowsFtpNetrcPath(env = process.env) {
  const home = String(env?.USERPROFILE || env?.HOME || "").trim();
  if (!home) return null;
  return path.join(home, ...LEGACY_WINDOWS_FTP_NETRC_SEGMENTS);
}

function assertNetrcReadable(netrcPath, source, { existsSyncFn, accessSyncFn }) {
  const sanitized = sanitizePathForReport(netrcPath);
  if (!existsSyncFn(netrcPath)) {
    const err = new Error(`FTP netrc not readable (missing): ${sanitized}`);
    err.code = FTP_NETRC_NOT_READABLE;
    err.details = { ftp_netrc: sanitized, source, exists: false, readable: false };
    throw err;
  }
  try {
    accessSyncFn(netrcPath, fs.constants.R_OK);
  } catch {
    const err = new Error(`FTP netrc not readable: ${sanitized}`);
    err.code = FTP_NETRC_NOT_READABLE;
    err.details = { ftp_netrc: sanitized, source, exists: true, readable: false };
    throw err;
  }
}

/**
 * Resolve FTP netrc: CLI > MEDISA_OPS_FTP_NETRC > legacy Windows path if exists > FAIL.
 * Never guesses a Linux secret path. Never fabricates credentials.
 */
export function resolveFtpNetrc({
  argv = [],
  env = process.env,
  existsSyncFn = fs.existsSync,
  accessSyncFn = fs.accessSync,
} = {}) {
  const cli = parseNamedArg(argv, "ftp-netrc");
  if (cli) {
    assertNetrcReadable(cli, "cli", { existsSyncFn, accessSyncFn });
    return { source: "cli", path: cli };
  }
  const fromEnv = String(env?.MEDISA_OPS_FTP_NETRC ?? "").trim();
  if (fromEnv) {
    assertNetrcReadable(fromEnv, "env", { existsSyncFn, accessSyncFn });
    return { source: "env", path: fromEnv };
  }
  const legacy = legacyWindowsFtpNetrcPath(env);
  if (legacy && existsSyncFn(legacy)) {
    assertNetrcReadable(legacy, "legacy_windows", { existsSyncFn, accessSyncFn });
    return { source: "legacy_windows", path: legacy };
  }
  const err = new Error(
    "FTP netrc not configured: pass --ftp-netrc=<path> or MEDISA_OPS_FTP_NETRC (no guessed Linux path)"
  );
  err.code = FTP_NETRC_NOT_CONFIGURED;
  err.details = {
    ftp_netrc: null,
    legacy_windows_path: legacy ? sanitizePathForReport(legacy) : null,
    legacy_windows_exists: false,
  };
  throw err;
}

/**
 * Resolve live config path: CLI/env explicit > existing temp default > needs FTP fetch.
 * Never prints jwt_secret. Never commits config.local.php.
 */
export function resolveLiveConfigPath({
  argv = [],
  env = process.env,
  tempDir = process.env.TEMP || "/tmp",
  existsSyncFn = fs.existsSync,
} = {}) {
  const cli = parseNamedArg(argv, "live-config");
  if (cli) {
    return {
      source: "cli",
      path: cli,
      needs_ftp_fetch: !existsSyncFn(cli),
    };
  }
  const fromEnv = String(env?.MEDISA_OPS_LIVE_CONFIG ?? "").trim();
  if (fromEnv) {
    return {
      source: "env",
      path: fromEnv,
      needs_ftp_fetch: !existsSyncFn(fromEnv),
    };
  }
  const defaultPath = path.join(tempDir, "live-config.local.php");
  if (existsSyncFn(defaultPath)) {
    return {
      source: "temp_existing",
      path: defaultPath,
      needs_ftp_fetch: false,
    };
  }
  return {
    source: "temp_default",
    path: defaultPath,
    needs_ftp_fetch: true,
  };
}

/** Sanitize path for reports (no credential content). */
export function sanitizePathForReport(p) {
  return String(p ?? "").replace(/\\/g, "/");
}

/**
 * Strip likely secrets from diagnostic text (passwords, netrc machine lines, jwt).
 * Never emit raw credential file contents.
 */
export function sanitizeDiagnosticText(text, { maxLen = 800 } = {}) {
  let s = String(text ?? "");
  s = s.replace(/jwt_secret\s*[=:>]+\s*['"]?[^'"\s]+['"]?/gi, "jwt_secret=[REDACTED]");
  // netrc / curl style: "password SECRET" or "password=SECRET" / "password: SECRET"
  s = s.replace(/(password|passwd|pass)\s*[:=]\s*\S+/gi, "$1=[REDACTED]");
  s = s.replace(/(password|passwd|pass)\s+\S+/gi, "$1 [REDACTED]");
  s = s.replace(/\/\/[^:@\s]+:[^@\s]+@/g, "//[REDACTED]@");
  s = s.replace(/\r/g, " ").replace(/\n+/g, " ").trim();
  if (s.length > maxLen) s = `${s.slice(0, maxLen)}…`;
  return s;
}

/**
 * Build fail-closed FTP diagnostic (never includes netrc contents).
 */
export function buildFtpFailure({
  code,
  message,
  curlBin,
  exitStatus,
  stderr,
  remote,
  local,
  netrcPath,
}) {
  const err = new Error(message);
  err.code = code;
  err.details = {
    curl_bin: curlBin ? sanitizePathForReport(curlBin) : null,
    exit_status: exitStatus ?? null,
    stderr: sanitizeDiagnosticText(stderr || ""),
    remote: remote ?? null,
    destination: local ? sanitizePathForReport(local) : null,
    ftp_netrc: netrcPath ? sanitizePathForReport(netrcPath) : null,
  };
  return err;
}

/**
 * Exact normalized full-name equality for a known target id.
 * @returns {boolean}
 */
export function matchesExactFullName(personelId, liveFullName, expectedMap = EXPECTED_FULL_NAMES) {
  const expected = expectedMap[Number(personelId)];
  if (!expected) return false;
  return normName(liveFullName) === normName(expected);
}

/**
 * Normalize YYYY-MM-DD (or datetime prefix) to date-only, else null.
 * @param {unknown} value
 * @returns {string|null}
 */
export function normalizeDateOnly(value) {
  if (value === null || value === undefined || value === "") return null;
  const raw = String(value).trim();
  const m = raw.match(/^(\d{4}-\d{2}-\d{2})/);
  return m ? m[1] : null;
}

/**
 * Evaluate live residual preimage gate for one target against domain owner semantics.
 *
 * Domain (PersonelHistoricalExitDateBackfillService):
 * - no existing exit => CREATE_ELIGIBLE (backfill may create)
 * - existing exit same as expected => ALREADY_APPLIED (no-op)
 * - existing exit different => CONFLICT (STOP; route to HISTORICAL_EXIT_DATE_CORRECTION)
 *
 * Does NOT require exitSurecCount === 0 when an existing matching surec date is present.
 * Does NOT auto-correct conflicts through backfill.
 *
 * @returns {{
 *   personel_id:number,
 *   full_name_normalized:string,
 *   expected_full_name:string|null,
 *   name_exact_pass:boolean,
 *   pasif_pass:boolean,
 *   exit_date_empty_pass:boolean,
 *   exit_surec_count:number,
 *   current_exit_date:string|null,
 *   expected_exit_date:string|null,
 *   classification:string,
 *   already_applied_pass:boolean,
 *   conflict:boolean,
 *   residual_preimage_pass:boolean,
 *   backfill_gate_pass:boolean
 * }}
 */
export function evaluateResidualPreimage({
  personelId,
  ad,
  soyad,
  aktifDurum,
  istenCikisTarihi,
  exitSurecCount,
  expectedExitDate = null,
  currentSurecExitDate = null,
  expectedMap = EXPECTED_FULL_NAMES,
}) {
  const id = Number(personelId);
  const fullName = `${ad || ""} ${soyad || ""}`.trim();
  const nameExact = matchesExactFullName(id, fullName, expectedMap);
  const pasif = String(aktifDurum || "").toUpperCase() === "PASIF";
  const count = Number(exitSurecCount || 0);
  const expected =
    normalizeDateOnly(expectedExitDate) ??
    normalizeDateOnly(EXPECTED_EXIT_DATES[id] ?? null);
  const currentExit =
    normalizeDateOnly(currentSurecExitDate) ?? normalizeDateOnly(istenCikisTarihi);
  const exitEmpty = !currentExit && count === 0;

  let classification = BACKFILL_CLASSIFICATION.CREATE_ELIGIBLE;
  if (!exitEmpty) {
    if (expected && currentExit === expected) {
      classification = BACKFILL_CLASSIFICATION.ALREADY_APPLIED;
    } else {
      classification = BACKFILL_CLASSIFICATION.CONFLICT;
    }
  }

  const residual_preimage_pass =
    nameExact && pasif && classification === BACKFILL_CLASSIFICATION.CREATE_ELIGIBLE;
  const already_applied_pass =
    nameExact && pasif && classification === BACKFILL_CLASSIFICATION.ALREADY_APPLIED;
  const conflict =
    nameExact && pasif && classification === BACKFILL_CLASSIFICATION.CONFLICT;
  const backfill_gate_pass = residual_preimage_pass || already_applied_pass;

  return {
    personel_id: id,
    full_name_normalized: normName(fullName),
    expected_full_name: expectedMap[id] ? normName(expectedMap[id]) : null,
    name_exact_pass: nameExact,
    pasif_pass: pasif,
    exit_date_empty_pass: exitEmpty,
    exit_surec_count: count,
    current_exit_date: currentExit,
    expected_exit_date: expected,
    classification,
    already_applied_pass,
    conflict,
    residual_preimage_pass,
    backfill_gate_pass,
  };
}

/**
 * Fail-closed preimage for HISTORICAL_EXIT_DATE_CORRECTION targets.
 */
export function evaluateCorrectionPreimage({
  personelId,
  ad,
  soyad,
  aktifDurum,
  surecId,
  currentSurecExitDate,
  expectedOldExitDate,
  expectedNewExitDate,
  nonIptalIstenAyrilmaCount,
  expectedMap = EXPECTED_FULL_NAMES,
}) {
  const id = Number(personelId);
  const fullName = `${ad || ""} ${soyad || ""}`.trim();
  const nameExact = matchesExactFullName(id, fullName, expectedMap);
  const pasif = String(aktifDurum || "").toUpperCase() === "PASIF";
  const expectedSurecId = Number(CORRECTION_SUREC_IDS[id] ?? 0);
  const surecIdOk = Number(surecId) === expectedSurecId && expectedSurecId > 0;
  const oldExpected =
    normalizeDateOnly(expectedOldExitDate) ??
    normalizeDateOnly(WRONG_EXIT_DATES_REQUIRING_CORRECTION[id] ?? null);
  const newExpected =
    normalizeDateOnly(expectedNewExitDate) ??
    normalizeDateOnly(EXPECTED_EXIT_DATES[id] ?? null);
  const current = normalizeDateOnly(currentSurecExitDate);
  const oldMatch = current !== null && oldExpected !== null && current === oldExpected;
  const countOk = Number(nonIptalIstenAyrilmaCount || 0) === 1;
  const correction_preimage_pass =
    nameExact && pasif && surecIdOk && oldMatch && countOk && newExpected !== null;

  return {
    personel_id: id,
    surec_id: Number(surecId) || null,
    expected_surec_id: expectedSurecId || null,
    name_exact_pass: nameExact,
    pasif_pass: pasif,
    surec_id_pass: surecIdOk,
    old_exit_date_pass: oldMatch,
    single_exit_pass: countOk,
    current_exit_date: current,
    expected_old_exit_date: oldExpected,
    expected_new_exit_date: newExpected,
    correction_preimage_pass,
  };
}

/**
 * Compare runtime SHA pins against explicit expected SHA (no hardcoded baseline).
 */
export function evaluateShaPreflight({
  expectedSha,
  originMain,
  liveDeploySha,
  workerHeartbeatSha,
}) {
  const expected = String(expectedSha || "")
    .trim()
    .toLowerCase();
  const origin = String(originMain || "")
    .trim()
    .toLowerCase();
  const live = String(liveDeploySha || "")
    .trim()
    .toLowerCase();
  const heartbeat = String(workerHeartbeatSha || "")
    .trim()
    .toLowerCase();

  return {
    expected_sha: expected,
    origin_main: origin,
    live_deploy_sha: live,
    worker_heartbeat_sha: heartbeat,
    origin_main_pass: origin === expected,
    live_deploy_sha_pass: live === expected,
    worker_heartbeat_pass: heartbeat === expected,
    all_sha_pass: origin === expected && live === expected && heartbeat === expected,
  };
}

export function isApplyRequested(argv = process.argv) {
  return Array.isArray(argv) && argv.includes("--apply");
}
