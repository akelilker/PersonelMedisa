/**
 * MG-PERSONNEL-DEFERRED-EXIT-001
 * Canonical owner: POST /personeller/lifecycle-bulk/{dry-run,apply}
 * Postcheck owner: scripts/ops/personel-lifecycle-exit-postcheck.mjs
 *
 * Usage: node ops/personnel-lifecycle/deferred-exit-production-apply.mjs [--apply]
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
  verifyPersonnelExitPreimage,
  verifyPersonnelExitSurec,
} from "../../scripts/ops/personel-lifecycle-exit-postcheck.mjs";

const DIR = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.join(DIR, "../..");
const LIVE_CONFIG = path.join(process.env.TEMP, "live-config.local.php");
const FTP_NETRC = path.join(process.env.USERPROFILE, "Documents", "medisa-ops-tmp", "org-rollout", "private", "ftp.curl");
const API = "https://www.karmotors.com.tr/personelmedisa/api";
const EXPECTED_ORIGIN_MAIN = "51c6bad5b85d8735ffc101fc6c69ac34dadecaa6";
const ACTOR_UID = 10;
const DO_APPLY = process.argv.includes("--apply");

const EXIT_ROWS = [
  {
    mutation_id: "mg-exit-202",
    operation_type: "PERSONEL_EXIT",
    personel_id: 202,
    gerekce: "İşveren feshi",
    payload: { exit_date: "2026-07-30", aciklama: "İşveren feshi" },
  },
  {
    mutation_id: "mg-exit-208",
    operation_type: "PERSONEL_EXIT",
    personel_id: 208,
    gerekce: "İşveren feshi",
    payload: { exit_date: "2026-07-30", aciklama: "İşveren feshi" },
  },
];

const report = {
  phase: "MG-PERSONNEL-DEFERRED-EXIT-001",
  timestamp: new Date().toISOString(),
  preflight: {},
  personnel_preimage: {},
  dry_run: null,
  apply: null,
  postcheck: null,
  production_mutation: DO_APPLY ? "REQUESTED" : "DRY_RUN_ONLY",
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
    JSON.stringify({ sub: Number(sub), rol: "GENEL_YONETICI", iat: Math.floor(Date.now() / 1000), exp: Math.floor(Date.now() / 1000) + ttl })
  );
  const sig = b64url(crypto.createHmac("sha256", secret).update(`${header}.${payload}`).digest());
  return `${header}.${payload}.${sig}`;
}
async function api(pathname, { method = "GET", token, body, accept } = {}) {
  const h = { Accept: accept || "application/json" };
  if (token) h.Authorization = `Bearer ${token}`;
  if (body !== undefined) h["Content-Type"] = "application/json";
  const res = await fetch(API + pathname, { method, headers: h, body: body !== undefined ? JSON.stringify(body) : undefined });
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
function unwrapMeta(json) {
  return json?.meta ?? json?.data?.meta ?? {};
}
function normName(v) {
  return String(v ?? "")
    .trim()
    .replace(/\s+/g, " ")
    .toLocaleUpperCase("tr-TR");
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
  const origin = spawnSync("git", ["rev-parse", "origin/main"], { cwd: REPO_ROOT, encoding: "utf8" });
  const originMain = (origin.stdout || "").trim().toLowerCase();
  report.preflight.origin_main = originMain;
  report.preflight.origin_main_pass = originMain === EXPECTED_ORIGIN_MAIN;

  ftpGet("api/.deploy-sha", path.join(process.env.TEMP, "live-deploy-sha.txt"));
  const liveSha = fs.readFileSync(path.join(process.env.TEMP, "live-deploy-sha.txt"), "utf8").trim().toLowerCase();
  report.preflight.live_deploy_sha = liveSha;
  report.preflight.live_deploy_sha_pass = liveSha === EXPECTED_ORIGIN_MAIN;

  if (!fs.existsSync(LIVE_CONFIG)) {
    ftpGet("api/config.local.php", LIVE_CONFIG);
  }

  ftpGet("api/runtime/migration-control/status.json", path.join(process.env.TEMP, "live-mig-status.json"));
  const migStatus = JSON.parse(fs.readFileSync(path.join(process.env.TEMP, "live-mig-status.json"), "utf8"));
  report.preflight.migration_applied = migStatus.applied_versions || "";
  report.preflight.migration_pending_after = migStatus.pending_versions_after || "";
  report.preflight.migration_tip_pass = String(migStatus.applied_versions || "").split(",").includes("083");
  report.preflight.migration_pending_pass = String(migStatus.pending_versions_after || "").trim() === "";

  ftpGet("api/runtime/migration-control/worker-heartbeat.json", path.join(process.env.TEMP, "live-worker-hb.json"));
  const hb = JSON.parse(fs.readFileSync(path.join(process.env.TEMP, "live-worker-hb.json"), "utf8"));
  report.preflight.worker_heartbeat_sha = hb.deployed_sha || "";
  report.preflight.worker_heartbeat_pass = String(hb.deployed_sha || "").toLowerCase() === EXPECTED_ORIGIN_MAIN;
  report.preflight.worker_idle_pass = String(migStatus.state || "") === "SUCCEEDED";

  const health = spawnSync("curl.exe", ["-sS", "--max-time", "15", `${API}/health`], { encoding: "utf8" });
  report.preflight.api_health_pass = health.stdout.includes('"status":"ok"');

  const required = [
    "origin_main_pass",
    "live_deploy_sha_pass",
    "migration_tip_pass",
    "migration_pending_pass",
    "worker_idle_pass",
    "worker_heartbeat_pass",
    "api_health_pass",
  ];
  report.preflight.all_pass = required.every((k) => report.preflight[k] === true);
  if (!report.preflight.all_pass) fail("PREFLIGHT", "Binding preflight failed");
}

async function verifyPersonnelPreimage(token) {
  const expected = {
    202: { ad: "AHMED", soyad: "KHALIL ALSAMAR" },
    208: { ad: "SEFİNE", soyad: "ÖZCAN" },
  };
  for (const id of [202, 208]) {
    const preimage = await verifyPersonnelExitPreimage(api, { token, personelId: id });
    const res = await api(`/personeller/${id}`, { token });
    const p = res.json?.data ?? {};
    report.personnel_preimage[id] = {
      ...preimage,
      ad: normName(p.ad),
      soyad: normName(p.soyad),
      name_match:
        normName(`${p.ad} ${p.soyad}`).includes(expected[id].ad.replace("İ", "I")) ||
        normName(`${p.ad} ${p.soyad}`).includes("SEFINE") ||
        normName(`${p.ad} ${p.soyad}`).includes("AHMED"),
      active_pass: preimage.aktif_durum === "AKTIF",
      exit_date_empty_pass: !preimage.isten_cikis_tarihi && preimage.exit_surec_count === 0,
      preimage_pass: preimage.preimage_pass,
    };
  }
  report.personnel_preimage.all_pass = [202, 208].every((id) => report.personnel_preimage[id]?.preimage_pass === true);
  if (!report.personnel_preimage.all_pass) fail("PREIMAGE", "Personnel preimage failed");
}

async function runDryApply(token) {
  const dry = await api("/personeller/lifecycle-bulk/dry-run", {
    method: "POST",
    token,
    body: { rows: EXIT_ROWS, deployed_sha: EXPECTED_ORIGIN_MAIN },
  });
  report.dry_run = {
    status: dry.status,
    can_apply: dry.json?.data?.can_apply === true,
    dry_run_checksum: dry.json?.data?.dry_run_checksum ?? null,
    preimage_checksum: dry.json?.data?.preimage_checksum ?? null,
    ozet: dry.json?.data?.ozet ?? null,
    postcheck: dry.json?.data?.postcheck ?? null,
    postcheck_errors: dry.json?.data?.postcheck_errors ?? null,
    satirlar: (dry.json?.data?.satirlar || []).map((s) => ({
      mutation_id: s.mutation_id,
      durum: s.durum,
      personel_id: s.personel_id,
      owner: s.mutation_plan?.owner ?? null,
      hata_kodlari: s.hata_kodlari ?? [],
    })),
    errors: dry.json?.errors ?? null,
  };
  if (dry.status !== 200) fail("DRY_RUN", `dry-run HTTP ${dry.status} ${dry.text.slice(0, 400)}`);
  if (!report.dry_run.can_apply) fail("DRY_RUN", "can_apply=false");

  if (!DO_APPLY) return;

  const apply = await api("/personeller/lifecycle-bulk/apply", {
    method: "POST",
    token,
    body: {
      rows: EXIT_ROWS,
      dry_run_checksum: report.dry_run.dry_run_checksum,
      preimage_checksum: report.dry_run.preimage_checksum,
      deployed_sha: EXPECTED_ORIGIN_MAIN,
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
  let total = 0;
  let active = 0;
  let page = 1;
  for (;;) {
    const res = await api(`/personeller?limit=200&page=${page}`, { token });
    if (res.status !== 200) fail("POSTCHECK", `personeller list ${res.status}`);
    const items = unwrapItems(res.json);
    total += items.length;
    active += items.filter((p) => String(p.aktif_durum).toUpperCase() === "AKTIF").length;
    const metaTotal = Number(unwrapMeta(res.json).total ?? total);
    if (total >= metaTotal || items.length === 0) break;
    page += 1;
  }

  const applyRows = report.apply?.satir_sonuclari ?? null;
  const checks = {};
  for (const row of EXIT_ROWS) {
    const personelId = row.personel_id;
    const surecId = resolveSurecIdFromApplyResult(applyRows, { mutationId: row.mutation_id });
    try {
      const surecEvidence = await verifyPersonnelExitSurec(api, {
        token,
        personelId,
        surecId,
        expectedExitDate: row.payload.exit_date,
        expectedAciklama: row.payload.aciklama,
      });
      const personelRes = await api(`/personeller/${personelId}`, { token });
      const p = personelRes.json?.data ?? {};
      checks[personelId] = {
        ...surecEvidence,
        aktif_durum: String(p.aktif_durum || "").toUpperCase(),
        pass:
          String(p.aktif_durum || "").toUpperCase() === "PASIF" &&
          surecEvidence.baslangic_tarihi === row.payload.exit_date &&
          surecEvidence.aciklama === row.payload.aciklama,
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
    total_personel: total,
    active_personel: active,
    expected_total: 153,
    expected_active: 144,
    total_pass: total === 153,
    active_pass: active === 144,
    personnel: checks,
    personnel_pass: EXIT_ROWS.every((row) => checks[row.personel_id]?.pass === true),
    all_pass: total === 153 && active === 144 && EXIT_ROWS.every((row) => checks[row.personel_id]?.pass === true),
  };
  if (DO_APPLY && !report.postcheck.all_pass) fail("POSTCHECK", "Postcheck failed");
}

async function main() {
  try {
    runPreflight();
    const token = mintJwt(readJwtSecret(LIVE_CONFIG), ACTOR_UID);
    await verifyPersonnelPreimage(token);
    await runDryApply(token);
    await postcheck(token);
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
