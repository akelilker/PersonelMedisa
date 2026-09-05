/**
 * Fail-closed gates for historical PASIF exit-date residual backfill ops wrapper.
 * Pure helpers — no network / FTP / git side effects.
 */

export const SHA40_RE = /^[0-9a-f]{40}$/;

/** Exact normalized full-name equality targets (Turkish chars foldable via normName). */
export const EXPECTED_FULL_NAMES = Object.freeze({
  202: "AHMED KHALIL ALSAMAR",
  208: "SEFINE OZCAN",
});

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
 * Parse required runtime --expected-sha. No silent baseline fallback.
 * @param {string[]} argv
 * @returns {string} lowercase 40-hex SHA
 */
export function parseExpectedSha(argv) {
  const args = Array.isArray(argv) ? argv : [];
  let value = null;
  for (let i = 0; i < args.length; i += 1) {
    const arg = String(args[i] ?? "");
    if (arg.startsWith("--expected-sha=")) {
      value = arg.slice("--expected-sha=".length).trim().toLowerCase();
      break;
    }
    if (arg === "--expected-sha") {
      value = String(args[i + 1] ?? "")
        .trim()
        .toLowerCase();
      break;
    }
  }
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
 * Exact normalized full-name equality for a known target id.
 * @returns {boolean}
 */
export function matchesExactFullName(personelId, liveFullName, expectedMap = EXPECTED_FULL_NAMES) {
  const expected = expectedMap[Number(personelId)];
  if (!expected) return false;
  return normName(liveFullName) === normName(expected);
}

/**
 * Evaluate live residual preimage gate for one target.
 * Requires: exact personel_id + exact full name + PASIF + empty canonical exit + active ISTEN_AYRILMA count 0.
 */
export function evaluateResidualPreimage({
  personelId,
  ad,
  soyad,
  aktifDurum,
  istenCikisTarihi,
  exitSurecCount,
  expectedMap = EXPECTED_FULL_NAMES,
}) {
  const id = Number(personelId);
  const fullName = `${ad || ""} ${soyad || ""}`.trim();
  const nameExact = matchesExactFullName(id, fullName, expectedMap);
  const pasif = String(aktifDurum || "").toUpperCase() === "PASIF";
  const exitEmpty = !istenCikisTarihi && Number(exitSurecCount || 0) === 0;
  return {
    personel_id: id,
    full_name_normalized: normName(fullName),
    expected_full_name: expectedMap[id] ? normName(expectedMap[id]) : null,
    name_exact_pass: nameExact,
    pasif_pass: pasif,
    exit_date_empty_pass: exitEmpty,
    exit_surec_count: Number(exitSurecCount || 0),
    residual_preimage_pass: nameExact && pasif && exitEmpty,
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
