/**
 * MG-PERSONNEL-HISTORICAL-EXIT-DATE-BACKFILL-001
 * Canonical owner: POST /personeller/lifecycle-bulk/{dry-run,apply}
 * Operation: HISTORICAL_EXIT_DATE_BACKFILL (PersonelHistoricalExitDateBackfillService)
 *
 * Targets (authoritative HR dates):
 *   202 -> 2025-12-31
 *   208 -> 2026-05-25
 *
 * Default: dry-run only (non-mutating).
 * Production apply requires explicit --apply after live PASIF residual preimage gate.
 *
 * Usage: node ops/personnel-lifecycle/deferred-exit-production-apply.mjs --expected-sha=<40-hex> [--apply]
 *
 * --expected-sha is mandatory at runtime (exact match to origin/main, live .deploy-sha,
 * worker heartbeat deployed_sha, and API deployed_sha). No silent code-prep baseline fallback.
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
  evaluateResidualPreimage,
  evaluateShaPreflight,
  isApplyRequested,
  parseExpectedSha,
} from "./lib/historical-exit-backfill-gates.mjs";

const DIR = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(DIR, "../..");
const LIVE_CONFIG = path.join(process.env.TEMP || "/tmp", "live-config.local.php");
const FTP_NETRC = path.join(
  process.env.USERPROFILE || process.env.HOME || "",
  "Documents",
  "medisa-ops-tmp",
  "org-rollout",
  "private",
  "ftp.curl"
);
const API = "https://www.karmotors.com.tr/personelmedisa/api";
const ACTOR_UID = 10;
const DO_APPLY = isApplyRequested(process.argv);
/** Set in main() via required --expected-sha (fail closed; no silent baseline). */
let EXPECTED_SHA = null;

const BACKFILL_ROWS = [
  {
    mutation_id: "mg-historical-exit-backfill-202",
    operation_type: "HISTORICAL_EXIT_DATE_BACKFILL",
    personel_id: 202,
    gerekce: "Tarihi PASIF residual cikis tarihi backfill (HR authoritative)",
    payload: {
      exit_date: "2025-12-31",
      aciklama: "HR authoritative historical exit date backfill",
    },
  },
  {
    mutation_id: "mg-historical-exit-backfill-208",
    operation_type: "HISTORICAL_EXIT_DATE_BACKFILL",
    personel_id: 208,
    gerekce: "Tarihi PASIF residual cikis tarihi backfill (HR authoritative)",
    payload: {
      exit_date: "2026-05-25",
      aciklama: "HR authoritative historical exit date backfill",
    },
  },
];


const report = {
  phase: "MG-PERSONNEL-HISTORICAL-EXIT-DATE-BACKFILL-001",
  timestamp: new Date().toISOString(),
  preflight: {},
  personnel_preimage: {},
  dry_run: null,
  apply: null,
  postcheck: null,
  production_mutation: DO_APPLY ? "REQUESTED" : "DRY_RUN_ONLY",
  live_preimage_required: true,
};

function fail(code, msg) {
  const e = new Error(msg);
  e.code = code;
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
    "curl.exe",
    ["-sS", "--netrc-file", FTP_NETRC, `ftp://ftp.karmotors.com.tr/${remote}`, "-o", local],
    { encoding: "utf8" }
  );
  if (r.status !== 0) fail("FTP", `FTP get failed ${remote}: ${r.stderr || r.stdout}`);
}

function runPreflight() {
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

  if (!fs.existsSync(LIVE_CONFIG)) {
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

  const health = spawnSync("curl.exe", ["-sS", "--max-time", "15", `${API}/health`], {
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

/**
 * Historical residual preimage: PASIF + missing canonical exit date.
 * Does NOT accept AKTIF (that belongs to normal PERSONEL_EXIT).
 */
async function verifyHistoricalResidualPreimage(token) {
  for (const row of BACKFILL_ROWS) {
    const id = row.personel_id;
    const expectedExit = row.payload.exit_date;
    const res = await api(`/personeller/${id}`, { token });
    if (res.status !== 200) fail("PREIMAGE", `GET /personeller/${id} HTTP ${res.status}`);
    const p = res.json?.data ?? {};
    const istenCikis = p.isten_cikis_tarihi ?? p.cikis_tarihi ?? null;

    let exitSurecCount = 0;
    const surecRes = await api(`/surecler?personel_id=${id}&surec_turu=ISTEN_AYRILMA`, { token });
    if (surecRes.status === 200) {
      const items = unwrapItems(surecRes.json).filter(
        (s) => String(s.state || "").toUpperCase() !== "IPTAL"
      );
      exitSurecCount = items.length;
    }

    const gate = evaluateResidualPreimage({
      personelId: id,
      ad: p.ad,
      soyad: p.soyad,
      aktifDurum: p.aktif_durum,
      istenCikisTarihi: istenCikis,
      exitSurecCount,
    });

    report.personnel_preimage[id] = {
      personel_id: id,
      aktif_durum: String(p.aktif_durum || "").toUpperCase(),
      isten_cikis_tarihi: istenCikis,
      exit_surec_count: exitSurecCount,
      expected_exit_date: expectedExit,
      full_name_normalized: gate.full_name_normalized,
      expected_full_name: gate.expected_full_name,
      name_exact_pass: gate.name_exact_pass,
      pasif_pass: gate.pasif_pass,
      exit_date_empty_pass: gate.exit_date_empty_pass,
      residual_preimage_pass: gate.residual_preimage_pass,
    };
  }

  report.personnel_preimage.all_pass = BACKFILL_ROWS.every(
    (row) => report.personnel_preimage[row.personel_id]?.residual_preimage_pass === true
  );
  if (!report.personnel_preimage.all_pass) {
    fail(
      "PREIMAGE",
      "Historical residual preimage failed (require exact personel_id + exact full name + PASIF + empty exit + 0 ISTEN_AYRILMA)"
    );
  }
}

async function runDryApply(token) {
  const dry = await api("/personeller/lifecycle-bulk/dry-run", {
    method: "POST",
    token,
    body: { rows: BACKFILL_ROWS, deployed_sha: EXPECTED_SHA },
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
      hata_kodlari: s.hata_kodlari ?? [],
    })),
    errors: dry.json?.errors ?? null,
  };
  if (dry.status !== 200) fail("DRY_RUN", `dry-run HTTP ${dry.status} ${dry.text.slice(0, 400)}`);
  if (!report.dry_run.can_apply) fail("DRY_RUN", "can_apply=false");

  const ownersOk = (report.dry_run.satirlar || []).every(
    (s) => s.owner === "PersonelHistoricalExitDateBackfillService"
  );
  if (!ownersOk) fail("DRY_RUN", "expected PersonelHistoricalExitDateBackfillService owner");

  if (!DO_APPLY) return;

  const apply = await api("/personeller/lifecycle-bulk/apply", {
    method: "POST",
    token,
    body: {
      rows: BACKFILL_ROWS,
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
  for (const row of BACKFILL_ROWS) {
    const personelId = row.personel_id;
    const expectedExitDate = row.payload.exit_date;
    const surecId = resolveSurecIdFromApplyResult(applyRows, {
      mutationId: row.mutation_id,
      owner: "PersonelHistoricalExitDateBackfillService",
    });
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
      const istenCikis = p.isten_cikis_tarihi ?? null;
      checks[personelId] = {
        ...surecEvidence,
        aktif_durum: aktifDurum,
        isten_cikis_tarihi: istenCikis,
        pass:
          aktifDurum === "PASIF" &&
          (surecEvidence.baslangic_tarihi === expectedExitDate || istenCikis === expectedExitDate),
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
    personnel_pass: BACKFILL_ROWS.every((row) => checks[row.personel_id]?.pass === true),
    all_pass: BACKFILL_ROWS.every((row) => checks[row.personel_id]?.pass === true),
  };
  if (DO_APPLY && !report.postcheck.all_pass) fail("POSTCHECK", "Historical backfill postcheck failed");
}

async function main() {
  try {
    EXPECTED_SHA = parseExpectedSha(process.argv.slice(2));
    runPreflight();
    const token = mintJwt(readJwtSecret(LIVE_CONFIG), ACTOR_UID);
    await verifyHistoricalResidualPreimage(token);
    await runDryApply(token);
    if (DO_APPLY) {
      await postcheck(token);
    }
    report.final_status = DO_APPLY ? "APPLIED" : "DRY_RUN_PASS";
    fs.writeFileSync(path.join(DIR, "deferred-exit-report.json"), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
  } catch (e) {
    report.final_status = "FAIL";
    report.error = { code: e.code || "ERROR", message: e.message };
    fs.writeFileSync(path.join(DIR, "deferred-exit-report.json"), JSON.stringify(report, null, 2));
    console.error(JSON.stringify(report, null, 2));
    process.exit(1);
  }
}

main();
