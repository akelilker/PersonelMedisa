/**
 * MG-PERSONNEL-HISTORICAL-EXIT-DATE-CORRECTION-001
 * Canonical owner: POST /personeller/lifecycle-bulk/{dry-run,apply}
 * Operation: HISTORICAL_EXIT_DATE_CORRECTION (PersonelHistoricalExitDateCorrectionService)
 *
 * Targets (authoritative HR correction of wrong live surec dates):
 *   surec 38 / personel 202: 2026-07-30 -> 2025-12-31
 *   surec 39 / personel 208: 2026-07-30 -> 2026-05-25
 *
 * Default: dry-run only (non-mutating).
 * Production apply requires explicit --apply after live correction preimage gate.
 * Do NOT run production dry-run/apply from an undeployed feature branch.
 *
 * Usage:
 *   node ops/personnel-lifecycle/historical-exit-date-correction-production-apply.mjs \
 *     --expected-sha=<40-hex> \
 *     [--curl-bin=<path-or-command>] \
 *     [--ftp-netrc=<path>] \
 *     [--live-config=<path>] \
 *     [--apply]
 */
"use strict";

import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";
import {
  ExitPostcheckError,
  resolveSurecIdFromApplyResult,
  verifyPersonnelExitSurec,
} from "../../scripts/ops/personel-lifecycle-exit-postcheck.mjs";
import {
  CORRECTION_SUREC_IDS,
  EXPECTED_EXIT_DATES,
  FTP_COMMAND_FAILED,
  FTP_REMOTE_READ_FAILED,
  WRONG_EXIT_DATES_REQUIRING_CORRECTION,
  assertCurlBinaryAvailable,
  buildFtpFailure,
  evaluateCorrectionPreimage,
  evaluateShaPreflight,
  isApplyRequested,
  parseExpectedSha,
  resolveCurlBinary,
  resolveFtpNetrc,
  resolveLiveConfigPath,
  sanitizePathForReport,
} from "./lib/historical-exit-backfill-gates.mjs";

const DIR = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(DIR, "../..");
const API = "https://www.karmotors.com.tr/personelmedisa/api";
const ACTOR_UID = 10;
const DO_APPLY = isApplyRequested(process.argv);
let EXPECTED_SHA = null;
let CURL_BIN = null;
let FTP_NETRC = null;
let LIVE_CONFIG = null;

const CORRECTION_ROWS = [
  {
    mutation_id: "mg-historical-exit-date-correction-202",
    operation_type: "HISTORICAL_EXIT_DATE_CORRECTION",
    personel_id: 202,
    gerekce: "HR authoritative historical exit date correction",
    payload: {
      surec_id: CORRECTION_SUREC_IDS[202],
      expected_old_exit_date: WRONG_EXIT_DATES_REQUIRING_CORRECTION[202],
      exit_date: EXPECTED_EXIT_DATES[202],
      aciklama: "HR authoritative historical exit date correction",
    },
  },
  {
    mutation_id: "mg-historical-exit-date-correction-208",
    operation_type: "HISTORICAL_EXIT_DATE_CORRECTION",
    personel_id: 208,
    gerekce: "HR authoritative historical exit date correction",
    payload: {
      surec_id: CORRECTION_SUREC_IDS[208],
      expected_old_exit_date: WRONG_EXIT_DATES_REQUIRING_CORRECTION[208],
      exit_date: EXPECTED_EXIT_DATES[208],
      aciklama: "HR authoritative historical exit date correction",
    },
  },
];

const report = {
  phase: "MG-PERSONNEL-HISTORICAL-EXIT-DATE-CORRECTION-001",
  timestamp: new Date().toISOString(),
  preflight: {},
  personnel_preimage: {},
  dry_run: null,
  apply: null,
  postcheck: null,
  production_mutation: DO_APPLY ? "REQUESTED" : "DRY_RUN_ONLY",
  live_preimage_required: true,
};

function fail(code, msg, details) {
  const e = new Error(msg);
  e.code = code;
  if (details !== undefined) e.details = details;
  throw e;
}

function b64url(d) {
  return Buffer.from(d).toString("base64").replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function readJwtSecret(phpPath) {
  const src = fs.readFileSync(phpPath, "utf8");
  const m = src.match(/'jwt_secret'\s*=>\s*'([^']+)'/);
  if (!m) fail("CONFIG", "jwt_secret missing");
  return m[1];
}

function mintJwt(secret, sub, ttl = 7200) {
  const header = b64url(JSON.stringify({ typ: "JWT", alg: "HS256" }));
  const payload = b64url(
    JSON.stringify({
      sub: Number(sub),
      rol: "GENEL_YONETICI",
      iat: Math.floor(Date.now() / 1000),
      exp: Math.floor(Date.now() / 1000) + ttl,
    })
  );
  const sig = b64url(crypto.createHmac("sha256", secret).update(`${header}.${payload}`).digest());
  return `${header}.${payload}.${sig}`;
}

async function api(pathname, { method = "GET", token, body } = {}) {
  const headers = { Accept: "application/json" };
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers["Content-Type"] = "application/json";
  const res = await fetch(API + pathname, {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    json = null;
  }
  return { status: res.status, text, json };
}

function unwrapItems(json) {
  const d = json?.data;
  if (Array.isArray(d)) return d;
  if (Array.isArray(d?.items)) return d.items;
  return [];
}

function ftpGet(remote, local) {
  const r = spawnSync(
    CURL_BIN,
    ["-sS", "--netrc-file", FTP_NETRC, `ftp://ftp.karmotors.com.tr/${remote}`, "-o", local],
    { encoding: "utf8" }
  );
  if (r.error || r.status !== 0) {
    throw buildFtpFailure({
      code: FTP_COMMAND_FAILED,
      message: `FTP command failed for ${remote}`,
      curlBin: CURL_BIN,
      exitStatus: r.status,
      stderr: r.stderr || r.error?.message || r.stdout || "",
      remote,
      local,
      netrcPath: FTP_NETRC,
    });
  }
  if (!fs.existsSync(local) || fs.statSync(local).size === 0) {
    throw buildFtpFailure({
      code: FTP_REMOTE_READ_FAILED,
      message: `FTP remote read failed (empty/missing local file) for ${remote}`,
      curlBin: CURL_BIN,
      exitStatus: r.status,
      stderr: r.stderr || "",
      remote,
      local,
      netrcPath: FTP_NETRC,
    });
  }
}

function resolveOpsRuntime(argv) {
  const curlResolved = resolveCurlBinary({ argv, env: process.env });
  assertCurlBinaryAvailable(curlResolved.binary);
  CURL_BIN = curlResolved.binary;

  const netrcResolved = resolveFtpNetrc({ argv, env: process.env });
  FTP_NETRC = netrcResolved.path;

  const liveResolved = resolveLiveConfigPath({
    argv,
    env: process.env,
    tempDir: process.env.TEMP || "/tmp",
  });
  LIVE_CONFIG = liveResolved.path;

  report.preflight.curl_bin = sanitizePathForReport(CURL_BIN);
  report.preflight.curl_bin_source = curlResolved.source;
  report.preflight.ftp_netrc = sanitizePathForReport(FTP_NETRC);
  report.preflight.ftp_netrc_source = netrcResolved.source;
  report.preflight.live_config = sanitizePathForReport(LIVE_CONFIG);
  report.preflight.live_config_source = liveResolved.source;
  report.preflight.live_config_needs_ftp_fetch = liveResolved.needs_ftp_fetch;

  return liveResolved;
}

function runPreflight(liveResolved) {
  report.preflight.expected_sha = EXPECTED_SHA;
  const origin = spawnSync("git", ["rev-parse", "origin/main"], { cwd: REPO_ROOT, encoding: "utf8" });
  const originMain = (origin.stdout || "").trim().toLowerCase();
  report.preflight.origin_main = originMain;
  report.preflight.origin_main_pass = originMain === EXPECTED_SHA;

  const tmp = process.env.TEMP || "/tmp";
  ftpGet("api/.deploy-sha", path.join(tmp, "live-deploy-sha.txt"));
  const liveSha = fs.readFileSync(path.join(tmp, "live-deploy-sha.txt"), "utf8").trim().toLowerCase();
  report.preflight.live_deploy_sha = liveSha;
  report.preflight.live_deploy_sha_pass = liveSha === EXPECTED_SHA;

  if (liveResolved.needs_ftp_fetch) {
    ftpGet("api/config.local.php", LIVE_CONFIG);
  }

  ftpGet("api/runtime/migration-control/status.json", path.join(tmp, "live-mig-status.json"));
  const migStatus = JSON.parse(fs.readFileSync(path.join(tmp, "live-mig-status.json"), "utf8"));
  report.preflight.migration_pending_after = migStatus.pending_versions_after || "";
  report.preflight.migration_pending_pass = String(migStatus.pending_versions_after || "").trim() === "";

  ftpGet("api/runtime/migration-control/worker-heartbeat.json", path.join(tmp, "live-worker-hb.json"));
  const hb = JSON.parse(fs.readFileSync(path.join(tmp, "live-worker-hb.json"), "utf8"));
  report.preflight.worker_heartbeat_sha = hb.deployed_sha || "";
  report.preflight.worker_heartbeat_pass =
    String(hb.deployed_sha || "").toLowerCase() === EXPECTED_SHA;
  report.preflight.worker_idle_pass = String(migStatus.state || "") === "SUCCEEDED";

  const health = spawnSync(CURL_BIN, ["-sS", "--max-time", "15", `${API}/health`], {
    encoding: "utf8",
  });
  report.preflight.api_health_pass = (health.stdout || "").includes('"status":"ok"');

  const shaGate = evaluateShaPreflight({
    expectedSha: EXPECTED_SHA,
    originMain: report.preflight.origin_main,
    liveDeploySha: report.preflight.live_deploy_sha,
    workerHeartbeatSha: report.preflight.worker_heartbeat_sha,
  });
  report.preflight.origin_main_pass = shaGate.origin_main_pass;
  report.preflight.live_deploy_sha_pass = shaGate.live_deploy_sha_pass;
  report.preflight.worker_heartbeat_pass = shaGate.worker_heartbeat_pass;
  if (!shaGate.all_sha_pass) {
    fail(
      "PREFLIGHT_SHA",
      `Expected SHA mismatch: expected=${shaGate.expected_sha} origin/main=${shaGate.origin_main} live=${shaGate.live_deploy_sha} heartbeat=${shaGate.worker_heartbeat_sha}`
    );
  }

  const required = [
    "origin_main_pass",
    "live_deploy_sha_pass",
    "migration_pending_pass",
    "worker_idle_pass",
    "worker_heartbeat_pass",
    "api_health_pass",
  ];
  report.preflight.all_pass = required.every((k) => report.preflight[k] === true);
  if (!report.preflight.all_pass) fail("PREFLIGHT", "Binding preflight failed");
}

async function verifyCorrectionPreimage(token) {
  for (const row of CORRECTION_ROWS) {
    const id = row.personel_id;
    const surecId = row.payload.surec_id;
    const res = await api(`/personeller/${id}`, { token });
    if (res.status !== 200) fail("PREIMAGE", `GET /personeller/${id} HTTP ${res.status}`);
    const p = res.json?.data ?? {};

    const surecRes = await api(`/surecler/${surecId}`, { token });
    if (surecRes.status !== 200) fail("PREIMAGE", `GET /surecler/${surecId} HTTP ${surecRes.status}`);
    const s = surecRes.json?.data ?? {};

    let nonIptalCount = 0;
    const listRes = await api(`/surecler?personel_id=${id}&surec_turu=ISTEN_AYRILMA`, { token });
    if (listRes.status === 200) {
      nonIptalCount = unwrapItems(listRes.json).filter(
        (x) => String(x.state || "").toUpperCase() !== "IPTAL"
      ).length;
    }

    const gate = evaluateCorrectionPreimage({
      personelId: id,
      ad: p.ad,
      soyad: p.soyad,
      aktifDurum: p.aktif_durum,
      surecId,
      currentSurecExitDate: s.baslangic_tarihi,
      expectedOldExitDate: row.payload.expected_old_exit_date,
      expectedNewExitDate: row.payload.exit_date,
      nonIptalIstenAyrilmaCount: nonIptalCount,
    });

    report.personnel_preimage[id] = {
      ...gate,
      aktif_durum: String(p.aktif_durum || "").toUpperCase(),
      ise_giris_tarihi: p.ise_giris_tarihi ?? null,
      retention_trigger_date: p.retention_summary?.trigger_date ?? null,
      surec_state: s.state ?? null,
      surec_aciklama: s.aciklama ?? null,
    };
  }

  report.personnel_preimage.all_pass = CORRECTION_ROWS.every(
    (row) => report.personnel_preimage[row.personel_id]?.correction_preimage_pass === true
  );
  if (!report.personnel_preimage.all_pass) {
    fail(
      "PREIMAGE",
      "Historical exit-date correction preimage failed (exact PASIF + surec_id + old date + single ISTEN_AYRILMA)"
    );
  }
}

async function runDryApply(token) {
  const dry = await api("/personeller/lifecycle-bulk/dry-run", {
    method: "POST",
    token,
    body: { rows: CORRECTION_ROWS, deployed_sha: EXPECTED_SHA },
  });
  report.dry_run = {
    status: dry.status,
    can_apply: dry.json?.data?.can_apply === true,
    dry_run_checksum: dry.json?.data?.dry_run_checksum ?? dry.json?.data?.preimage_checksum ?? null,
    preimage_checksum: dry.json?.data?.preimage_checksum ?? null,
    ozet: dry.json?.data?.ozet ?? null,
    postcheck_errors: dry.json?.data?.postcheck_errors ?? null,
    satirlar: (dry.json?.data?.satirlar || []).map((s) => ({
      mutation_id: s.mutation_id,
      durum: s.durum,
      personel_id: s.personel_id,
      owner: s.mutation_plan?.owner ?? null,
      action: s.mutation_plan?.action ?? null,
      surec_id: s.mutation_plan?.surec_id ?? null,
      old_exit_date: s.mutation_plan?.old_exit_date ?? null,
      new_exit_date: s.mutation_plan?.new_exit_date ?? s.mutation_plan?.exit_date ?? null,
      retention_reconciliation: s.mutation_plan?.retention_reconciliation ?? null,
      audit: s.mutation_plan?.audit ?? null,
      postimage: s.mutation_plan?.postimage ?? null,
      hata_kodlari: s.hata_kodlari ?? [],
    })),
    errors: dry.json?.errors ?? null,
  };
  if (dry.status !== 200) fail("DRY_RUN", `dry-run HTTP ${dry.status} ${dry.text.slice(0, 400)}`);
  if (!report.dry_run.can_apply) fail("DRY_RUN", "can_apply=false");

  const ownersOk = (report.dry_run.satirlar || []).every(
    (s) => s.owner === "PersonelHistoricalExitDateCorrectionService"
  );
  if (!ownersOk) fail("DRY_RUN", "expected PersonelHistoricalExitDateCorrectionService owner");

  if (!DO_APPLY) return;

  const apply = await api("/personeller/lifecycle-bulk/apply", {
    method: "POST",
    token,
    body: {
      rows: CORRECTION_ROWS,
      dry_run_checksum: report.dry_run.dry_run_checksum,
      preimage_checksum: report.dry_run.preimage_checksum,
      deployed_sha: EXPECTED_SHA,
    },
  });
  report.apply = {
    status: apply.status,
    applied_count: apply.json?.data?.applied_count ?? null,
    failed_count: apply.json?.data?.failed_count ?? null,
    satir_sonuclari: apply.json?.data?.satir_sonuclari ?? null,
    errors: apply.json?.errors ?? null,
  };
  if (apply.status !== 200) fail("APPLY", `apply HTTP ${apply.status} ${apply.text.slice(0, 400)}`);
}

async function postcheck(token) {
  const applyRows = report.apply?.satir_sonuclari ?? null;
  const checks = {};
  for (const row of CORRECTION_ROWS) {
    const personelId = row.personel_id;
    const expectedExitDate = row.payload.exit_date;
    const surecId =
      resolveSurecIdFromApplyResult(applyRows, {
        mutationId: row.mutation_id,
        owner: "PersonelHistoricalExitDateCorrectionService",
      }) ?? row.payload.surec_id;
    try {
      const surecEvidence = await verifyPersonnelExitSurec(api, {
        token,
        personelId,
        surecId,
        expectedExitDate,
      });
      const personelRes = await api(`/personeller/${personelId}`, { token });
      const p = personelRes.json?.data ?? {};
      const aktifDurum = String(p.aktif_durum || "").toUpperCase();
      checks[personelId] = {
        ...surecEvidence,
        aktif_durum: aktifDurum,
        retention_trigger_date: p.retention_summary?.trigger_date ?? null,
        pass:
          aktifDurum === "PASIF" &&
          surecEvidence.baslangic_tarihi === expectedExitDate &&
          Number(surecEvidence.surec_id) === Number(row.payload.surec_id),
      };
    } catch (error) {
      if (error instanceof ExitPostcheckError) {
        checks[personelId] = {
          pass: false,
          error_code: error.code,
          error_message: error.message,
          error_details: error.details,
        };
        continue;
      }
      throw error;
    }
  }

  report.postcheck = {
    personnel: checks,
    personnel_pass: CORRECTION_ROWS.every((row) => checks[row.personel_id]?.pass === true),
    all_pass: CORRECTION_ROWS.every((row) => checks[row.personel_id]?.pass === true),
  };
  if (DO_APPLY && !report.postcheck.all_pass) fail("POSTCHECK", "Historical correction postcheck failed");
}

async function main() {
  try {
    const argv = process.argv.slice(2);
    EXPECTED_SHA = parseExpectedSha(argv);
    const liveResolved = resolveOpsRuntime(argv);
    runPreflight(liveResolved);
    const token = mintJwt(readJwtSecret(LIVE_CONFIG), ACTOR_UID);
    await verifyCorrectionPreimage(token);
    await runDryApply(token);
    if (DO_APPLY) {
      await postcheck(token);
    }
    report.final_status = DO_APPLY ? "APPLIED" : "DRY_RUN_PASS";
    fs.writeFileSync(
      path.join(DIR, "historical-exit-date-correction-report.json"),
      JSON.stringify(report, null, 2)
    );
    console.log(JSON.stringify(report, null, 2));
  } catch (e) {
    report.final_status = "FAIL";
    report.error = {
      code: e.code || "ERROR",
      message: e.message,
      ...(e.details ? { details: e.details } : {}),
    };
    fs.writeFileSync(
      path.join(DIR, "historical-exit-date-correction-report.json"),
      JSON.stringify(report, null, 2)
    );
    console.log(JSON.stringify(report, null, 2));
    process.exitCode = 1;
  }
}

main();
