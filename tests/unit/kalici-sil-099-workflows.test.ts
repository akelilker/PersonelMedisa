// 099 KALICI_SIL PREFLIGHT / APPLY workflow gates, executed for real.
//
// The run blocks are extracted from the two workflow files and executed with
// bash against a fake lftp / git / gh on PATH. The fake FTP server records every
// upload, so each negative scenario proves that NO request file reached the
// control plane, not just that the step exited non-zero.
import { spawnSync } from "node:child_process";
import { chmodSync, copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { afterEach, describe, expect, it } from "vitest";

const repoRoot = process.cwd();
const diagnosticsPath = resolve(repoRoot, ".github/workflows/ops-migration-worker-diagnostics.yml");
const applyPath = resolve(repoRoot, ".github/workflows/apply-kalici-sil-099.yml");
const TARGET = "099_user_kalici_silme_auditleri.sql";
const DEPLOYED = "9d921d7ec068d40fef417fb028fd004681012cae";
const OTHER_SHA = "1111111111111111111111111111111111111111";
const CONTROL = "api/runtime/migration-control";

function findBash(): string | null {
  for (const candidate of ["/usr/bin/bash", "/bin/bash", "C:/Program Files/Git/bin/bash.exe"]) {
    if (existsSync(candidate)) return candidate;
  }
  return null;
}
const bash = findBash();
const jqAvailable = bash !== null && spawnSync(bash, ["-c", "command -v jq"], { encoding: "utf8" }).status === 0;

/** Extract the literal `run: |` block of the step with the given name. */
function runBlock(workflowPath: string, stepName: string): string {
  const lines = readFileSync(workflowPath, "utf8").split(/\r?\n/);
  const start = lines.findIndex((line) => line.trim() === `- name: ${stepName}`);
  if (start < 0) throw new Error(`step not found: ${stepName}`);
  const runIndex = lines.findIndex((line, index) => index > start && line.trim() === "run: |");
  const runIndent = lines[runIndex].indexOf("run:");
  const body: string[] = [];
  for (let index = runIndex + 1; index < lines.length; index += 1) {
    const line = lines[index];
    if (line.trim() !== "" && line.search(/\S/) <= runIndent) break;
    body.push(line);
  }
  const indent = Math.min(...body.filter((line) => line.trim() !== "").map((line) => line.search(/\S/)));
  return body.map((line) => line.slice(indent)).join("\n") + "\n";
}

const FAKE_LFTP = String.raw`#!/usr/bin/env bash
# Minimal fake of the lftp subset the workflows use. Remote files live under
# $FAKE_FTP_ROOT/remote; after a request upload (mv) the server serves the
# "<file>.after" variant when present, modelling the worker having run.
set -u
cmds=""
while (( $# )); do
  if [[ "$1" == "-e" ]]; then cmds="$2"; shift 2; continue; fi
  shift
done
root="$FAKE_FTP_ROOT"
remote="$root/remote"
lcd="."
while IFS= read -r raw; do
  cmd="$(echo "$raw" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
  [[ -z "$cmd" ]] && continue
  case "$cmd" in
    set\ *|bye|pwd|cd\ *|mkdir\ *|"cls -la") ;;
    lcd\ *) lcd="$__{cmd#lcd }" ;;
    "cls -1 "*" > "*)
      [[ -f "$root/fail-list" ]] && { echo "cls: Access failed" >&2; exit 1; }
      out="$__{cmd##*> }"
      cp "$root/listing.txt" "$out" ;;
    "get "*" -o "*)
      src="$__{cmd#get }"; src="$__{src%% -o *}"; out="$__{cmd##* -o }"
      if [[ -f "$root/uploaded" && -f "$remote/$src.after" ]]; then cp "$remote/$src.after" "$out"
      elif [[ -f "$remote/$src" ]]; then cp "$remote/$src" "$out"
      else echo "get: Access failed: 550 ($src)" >&2; exit 1; fi ;;
    "put -O "*)
      rest="$__{cmd#put -O }"; dir="$__{rest%% *}"; file="$__{rest#* }"
      mkdir -p "$remote/$dir"; cp "$lcd/$file" "$remote/$dir/$file"
      echo "PUT $dir/$file" >> "$root/uploads.log" ;;
    "mv "*)
      rest="$__{cmd#mv }"; from="$__{rest%% *}"; to="$__{rest#* }"
      mv "$remote/$from" "$remote/$to"; echo "MV $to" >> "$root/uploads.log"; touch "$root/uploaded" ;;
    *) echo "fake lftp: unsupported command: $cmd" >&2; exit 1 ;;
  esac
done < <(printf '%s\n' "$cmds" | tr ';' '\n')
exit 0
`.replaceAll("$__{", "${");

const FAKE_GIT = `#!/usr/bin/env bash
if [[ "$1" == "rev-parse" && "$2" == "HEAD" ]]; then echo "$FAKE_HEAD"; exit 0; fi
echo "fake git: unsupported" >&2; exit 1
`;

const FAKE_GH = `#!/usr/bin/env bash
if [[ "$1" == "api" && "$2" == "repos/$GITHUB_REPOSITORY/environments/kalici-sil-099-apply" && -f "$FAKE_GH_ENV" ]]; then
  cat "$FAKE_GH_ENV"; exit 0
fi
echo '{"message":"Not Found","status":"404"}'; exit 1
`;

type Sandbox = { dir: string; root: string; workspace: string; bin: string };
const sandboxes: string[] = [];
afterEach(() => {
  while (sandboxes.length) rmSync(sandboxes.pop() as string, { recursive: true, force: true });
});

const sha256 = (value: string | Buffer) => createHash("sha256").update(value).digest("hex");
const migrationChecksum = () => sha256(readFileSync(resolve(repoRoot, "api/migrations", TARGET)));
const isoNow = (offsetSeconds = 0) => new Date(Date.now() + offsetSeconds * 1000).toISOString().replace(/\.\d{3}Z$/, "Z");

function writeFile(path: string, content: string) {
  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, content);
}

function makeSandbox(): Sandbox {
  const dir = mkdtempSync(join(tmpdir(), "kalici-099-"));
  sandboxes.push(dir);
  const root = join(dir, "ftp");
  const workspace = join(dir, "ws");
  const bin = join(dir, "bin");
  for (const relative of ["scripts/deploy/cpanel-ftp-directory-lib.sh", "api/bin/cpanel-migration-cron.php", `api/migrations/${TARGET}`]) {
    mkdirSync(dirname(join(workspace, relative)), { recursive: true });
    copyFileSync(resolve(repoRoot, relative), join(workspace, relative));
  }
  for (const [name, body] of [["lftp", FAKE_LFTP], ["git", FAKE_GIT], ["gh", FAKE_GH]] as const) {
    writeFile(join(bin, name), body);
    chmodSync(join(bin, name), 0o755);
  }
  mkdirSync(join(dir, "runner"), { recursive: true });
  return { dir, root, workspace, bin };
}

/** A healthy, idle production control plane at the deployed SHA. */
function seedHealthyRemote(box: Sandbox, listingStyle: "full" | "bare" = "full") {
  const remote = join(box.root, "remote");
  const names = ["status.json", "worker-heartbeat.json", "request.completed.preflight-1-1.json"];
  writeFile(join(box.root, "listing.txt"), names.map((n) => (listingStyle === "full" ? `${CONTROL}/${n}` : n)).join("\n") + "\n");
  writeFile(join(remote, "api/.deploy-sha"), `${DEPLOYED}\n`);
  writeFile(join(remote, "api/bin/cpanel-migration-cron.php"), readFileSync(resolve(repoRoot, "api/bin/cpanel-migration-cron.php"), "utf8"));
  writeFile(join(remote, "api/runtime-build/canonical-migrations.php"), `<?php return [['version' => '099', 'checksum' => '${migrationChecksum()}']];\n`);
  writeFile(join(remote, CONTROL, "status.json"), JSON.stringify({ state: "SUCCEEDED", request_id: "preflight-1-1" }));
  writeFile(join(remote, CONTROL, "worker-heartbeat.json"), JSON.stringify({ updated_at: isoNow(-60), deployed_sha: DEPLOYED }));
}

function setListing(box: Sandbox, entries: string[], eol = "\n") {
  writeFile(join(box.root, "listing.txt"), entries.join(eol) + eol);
}

function uploads(box: Sandbox): string {
  const log = join(box.root, "uploads.log");
  return existsSync(log) ? readFileSync(log, "utf8") : "";
}

function run(box: Sandbox, script: string, env: Record<string, string>) {
  const scriptPath = join(box.dir, "step.sh");
  writeFileSync(scriptPath, script);
  const result = spawnSync(bash as string, [scriptPath], {
    cwd: box.workspace,
    encoding: "utf8",
    env: {
      PATH: `${box.bin}:${process.env.PATH ?? ""}`,
      HOME: box.dir,
      FAKE_FTP_ROOT: box.root,
      FAKE_HEAD: DEPLOYED,
      GITHUB_WORKSPACE: box.workspace,
      GITHUB_REPOSITORY: "akelilker/PersonelMedisa",
      GITHUB_REF: "refs/heads/main",
      RUNNER_TEMP: join(box.dir, "runner"),
      FTP_SERVER: "ftp.invalid",
      FTP_USERNAME: "fake-user",
      FTP_PASSWORD: "fake-password",
      FTP_PORT: "21",
      DEPLOYED_SHA: DEPLOYED,
      PROTECTED_ILKER_USER_ID: "10",
      PROTECTED_SERHAN_USER_ID: "9",
      TARGET_MIGRATION: TARGET,
      POLL_ATTEMPTS: "1",
      POLL_INTERVAL_SECONDS: "0",
      TOTAL_POLL_WINDOW_SECONDS: "0",
      ...env,
    },
  });
  return { status: result.status, out: `${result.stdout ?? ""}\n${result.stderr ?? ""}` };
}

// ---------------------------------------------------------------- PREFLIGHT
const PREFLIGHT_REQUEST_ID = "kalici-preflight-77-1";
function preflightReport(overrides: Record<string, unknown> = {}) {
  return {
    schema_version: "1",
    generated_at: isoNow(-120),
    mode: "READ_ONLY_KALICI_SIL_MIGRATION_PREFLIGHT",
    deployed_sha: DEPLOYED,
    migration_version: "099",
    ledger: { applied_tip: "098", pending_versions: ["099"] },
    protected_ids: { ilker_id: 10, serhan_id: 9, distinct: true },
    orphans: { "ek_odeme_kesinti.created_by": 0, "retention_imha_auditleri.actor_user_id": 0 },
    partial_state: { audit_table: false, koruma_column: false, registry_table: false, fk_constraints: [], present: false },
    blockers: [],
    result: "PASS",
    request_id: PREFLIGHT_REQUEST_ID,
    ...overrides,
  };
}
function seedPreflightWorkerResult(box: Sandbox, report = preflightReport()) {
  const remote = join(box.root, "remote", CONTROL);
  writeFile(join(remote, "status.json.after"), JSON.stringify({ state: "SUCCEEDED", request_id: PREFLIGHT_REQUEST_ID, mode: "KALICI_SIL_MIGRATION_PREFLIGHT" }));
  writeFile(join(remote, "kalici-sil-migration-preflight.json.after"), JSON.stringify(report));
}

const describeIfRunnable = bash && jqAvailable ? describe : describe.skip;

describeIfRunnable("099 KALICI_SIL_MIGRATION_PREFLIGHT diagnostics mode", () => {
  const step = () => runBlock(diagnosticsPath, "Run read-only KALICI_SIL 099 preflight on production");
  const validate = () => runBlock(diagnosticsPath, "Validate explicit request");
  const env = { REQUEST_ID: PREFLIGHT_REQUEST_ID };

  it("uploads exactly one integer-id preflight request and reads back a PASS report", () => {
    const box = makeSandbox();
    seedHealthyRemote(box);
    seedPreflightWorkerResult(box);
    const result = run(box, step(), env);
    expect(result.status, result.out).toBe(0);
    expect(result.out).toContain("KALICI_SIL_GATE_WORKER_IDLE=YES");
    expect(result.out).toContain("KALICI_SIL_PREFLIGHT_RESULT=PASS");
    expect(result.out).toContain("KALICI_SIL_APPLY_READY=YES");
    expect(result.out).toMatch(/KALICI_SIL_PREFLIGHT_REPORT_SHA256=[0-9a-f]{64}/);
    expect(result.out).not.toContain("fake-password");
    const log = uploads(box);
    expect(log.match(/^PUT /gm)?.length).toBe(1);
    expect(log).toContain(`MV ${CONTROL}/request.pending.${PREFLIGHT_REQUEST_ID}.json`);
    const request = JSON.parse(readFileSync(join(box.root, "remote", CONTROL, `request.pending.${PREFLIGHT_REQUEST_ID}.json`), "utf8"));
    expect(request).toMatchObject({ mode: "KALICI_SIL_MIGRATION_PREFLIGHT", deployed_sha: DEPLOYED, protected_ilker_user_id: 10, protected_serhan_user_id: 9 });
    expect(request).not.toHaveProperty("target_version");
  });

  it("also accepts a bare-name listing for an idle worker", () => {
    const box = makeSandbox();
    seedHealthyRemote(box, "bare");
    seedPreflightWorkerResult(box);
    const result = run(box, step(), env);
    expect(result.status, result.out).toBe(0);
  });

  it("does not report APPLY_READY for a BLOCKED report", () => {
    const box = makeSandbox();
    seedHealthyRemote(box);
    seedPreflightWorkerResult(box, preflightReport({ result: "BLOCKED", blockers: ["KALICI_SIL_PARTIAL_STATE"] }));
    const result = run(box, step(), env);
    expect(result.status).not.toBe(0);
    expect(result.out).toContain("KALICI_SIL_APPLY_READY=NO");
    expect(result.out).toContain("KALICI_SIL_PREFLIGHT_BLOCKERS=KALICI_SIL_PARTIAL_STATE");
  });

  const negatives: Array<[string, (box: Sandbox) => void, string, Record<string, string>?]> = [
    ["worker RUNNING (full-path listing)", (box) => writeFile(join(box.root, "remote", CONTROL, "status.json"), JSON.stringify({ state: "RUNNING", request_id: "x" })), "WORKER_BUSY"],
    ["worker RUNNING (bare status.json listing)", (box) => { setListing(box, ["status.json"]); writeFile(join(box.root, "remote", CONTROL, "status.json"), JSON.stringify({ state: "RUNNING" })); }, "WORKER_BUSY"],
    ["worker RUNNING (bare CRLF listing)", (box) => { setListing(box, ["status.json", "worker.lock"], "\r\n"); writeFile(join(box.root, "remote", CONTROL, "status.json"), JSON.stringify({ state: "RUNNING" })); }, "WORKER_BUSY"],
    ["listed status.json cannot be downloaded", (box) => { setListing(box, ["status.json"]); rmSync(join(box.root, "remote", CONTROL, "status.json")); }, "WORKER_STATUS_UNREADABLE"],
    ["listed status.json is not JSON", (box) => writeFile(join(box.root, "remote", CONTROL, "status.json"), "<html>oops"), "WORKER_STATUS_UNREADABLE"],
    ["status.json without a state", (box) => writeFile(join(box.root, "remote", CONTROL, "status.json"), "{}"), "WORKER_STATUS_UNREADABLE"],
    ["pending request present (bare name)", (box) => setListing(box, ["status.json", "request.pending.other.json"]), "MIGRATION_CONTROL_PLANE_BUSY"],
    ["stale processing request", (box) => setListing(box, [`${CONTROL}/request.processing.abc.json`]), "STALE_PROCESSING_REQUEST"],
    ["control-plane listing fails", (box) => writeFile(join(box.root, "fail-list"), "1"), "CONTROL_PLANE_EVIDENCE_UNREADABLE"],
    ["remote deploy SHA differs", (box) => writeFile(join(box.root, "remote", "api/.deploy-sha"), `${OTHER_SHA}\n`), "DEPLOY_SHA_MISMATCH"],
    ["workflow ref is not the deployed SHA", () => undefined, "WORKFLOW_REF_NOT_DEPLOYED_SHA", { FAKE_HEAD: OTHER_SHA }],
    ["remote worker differs", (box) => writeFile(join(box.root, "remote", "api/bin/cpanel-migration-cron.php"), "<?php // other"), "REMOTE_WORKER_PARITY_MISMATCH"],
    ["remote bundle lacks this 099 checksum", (box) => writeFile(join(box.root, "remote", "api/runtime-build/canonical-migrations.php"), "<?php return [];"), "BUNDLE_099_CHECKSUM_MISMATCH"],
  ];
  for (const [label, mutate, reason, extraEnv] of negatives) {
    it(`blocks before any upload: ${label}`, () => {
      const box = makeSandbox();
      seedHealthyRemote(box);
      seedPreflightWorkerResult(box);
      mutate(box);
      const result = run(box, step(), { ...env, ...(extraEnv ?? {}) });
      expect(result.status, result.out).not.toBe(0);
      expect(result.out).toContain(`KALICI_SIL_PREFLIGHT_REASON=${reason}`);
      expect(uploads(box)).toBe("");
    });
  }

  it("validates mode confirmation and protected ids before any FTP work", () => {
    const box = makeSandbox();
    const base = { MODE: "KALICI_SIL_MIGRATION_PREFLIGHT", CONFIRMATION: "KALICI_SIL_MIGRATION_PREFLIGHT" };
    expect(run(box, validate(), base).status).toBe(0);
    expect(run(box, validate(), { ...base, CONFIRMATION: "READ_ONLY_MIGRATION_PREFLIGHT" }).status).not.toBe(0);
    const same = run(box, validate(), { ...base, PROTECTED_SERHAN_USER_ID: "10" });
    expect(same.out).toContain("PREFLIGHT_REASON=PROTECTED_IDS_NOT_DISTINCT");
    for (const bad of ["", "0", "-9", "09", "9 OR 1=1", "abc"]) {
      const invalid = run(box, validate(), { ...base, PROTECTED_ILKER_USER_ID: bad });
      expect(invalid.status, bad).not.toBe(0);
    }
    expect(run(box, validate(), { ...base, DEPLOYED_SHA: "abc" }).out).toContain("DEPLOYED_SHA_INPUT_INVALID");
    expect(uploads(box)).toBe("");
  });
});

// ---------------------------------------------------------------- APPLY
const APPLY_REQUEST_ID = "kalici-apply-88-1";
function applyEnv(box: Sandbox, report: Record<string, unknown>, extra: Record<string, string> = {}) {
  const body = JSON.stringify(report);
  writeFile(join(box.root, "remote", CONTROL, "kalici-sil-migration-preflight.json"), body);
  return {
    REQUEST_ID: APPLY_REQUEST_ID,
    PREFLIGHT_REQUEST_ID,
    PREFLIGHT_REPORT_SHA256: sha256(body),
    CONFIRMATION: "APPLY_099_KALICI_SIL",
    MAX_PREFLIGHT_AGE_SECONDS: "21600",
    MAX_HEARTBEAT_AGE_SECONDS: "1800",
    ...extra,
  };
}
function seedApplyWorkerResult(box: Sandbox, status: Record<string, unknown> = {}, postcheck: Record<string, unknown> = {}) {
  const remote = join(box.root, "remote", CONTROL);
  writeFile(join(remote, "status.json.after"), JSON.stringify({
    state: "SUCCEEDED", request_id: APPLY_REQUEST_ID, mode: "KALICI_SIL_MIGRATION_APPLY", target_version: "099",
    kalici_sil_migration_applied_versions: "099", kalici_sil_migration_postcheck_result: "PASS",
    backup_file: "medisa-pre-kalicisil-099-kalici-apply-88-1-20261010-030000.sql", backup_sha256: "a".repeat(64), backup_bytes: 1234, backup_readback: "VERIFIED",
    ...status,
  }));
  writeFile(join(remote, "kalici-sil-migration-postcheck.json"), JSON.stringify({
    request_id: APPLY_REQUEST_ID, migration_version: "099", fk_missing: [], unexpected_deltas: [], result: "PASS", ...postcheck,
  }));
}

describeIfRunnable("099 KALICI_SIL_MIGRATION_APPLY workflow", () => {
  const gate = () => runBlock(applyPath, "Gate, upload one request, wait, verify postcheck");

  it("applies only behind a protected environment job", () => {
    const workflow = readFileSync(applyPath, "utf8");
    const gateJob = workflow.slice(workflow.indexOf("  environment-gate:"), workflow.indexOf("  request:"));
    const requestJob = workflow.slice(workflow.indexOf("  request:"));
    expect(gateJob).not.toContain("environment:");
    expect(gateJob).not.toContain("secrets.");
    expect(requestJob).toContain("needs: environment-gate");
    expect(requestJob).toContain("environment: kalici-sil-099-apply");
    expect(workflow).toContain('mode: "KALICI_SIL_MIGRATION_APPLY"');
    expect(workflow).not.toContain("APPLY_CANONICAL_MIGRATIONS");
    expect(workflow).not.toMatch(/gh api[^\n]*(-X|--method)\s*(PUT|POST|PATCH|DELETE)/);
  });

  it("passes the full gate, uploads one request and proves success from status + postcheck", () => {
    const box = makeSandbox();
    seedHealthyRemote(box);
    seedApplyWorkerResult(box);
    const result = run(box, gate(), applyEnv(box, preflightReport()));
    expect(result.status, result.out).toBe(0);
    expect(result.out).toContain("KALICI_SIL_APPLY_GATE_RESULT=PASS");
    expect(result.out).toContain("KALICI_SIL_APPLY_RESULT=PASS");
    expect(result.out).not.toContain("fake-password");
    expect(uploads(box).match(/^PUT /gm)?.length).toBe(1);
    const request = JSON.parse(readFileSync(join(box.root, "remote", CONTROL, `request.pending.${APPLY_REQUEST_ID}.json`), "utf8"));
    expect(request).toMatchObject({ mode: "KALICI_SIL_MIGRATION_APPLY", target_version: "099", protected_ilker_user_id: 10, protected_serhan_user_id: 9, deployed_sha: DEPLOYED });
  });

  it("never reports success when the postcheck is not PASS", () => {
    const box = makeSandbox();
    seedHealthyRemote(box);
    seedApplyWorkerResult(box, {}, { result: "BLOCKED", unexpected_deltas: ["KALICI_SIL_FK_MISSING"] });
    const result = run(box, gate(), applyEnv(box, preflightReport()));
    expect(result.status).not.toBe(0);
    expect(result.out).toContain("KALICI_SIL_APPLY_RESULT=UNPROVEN");
  });

  it("never reports success without verified backup evidence", () => {
    const box = makeSandbox();
    seedHealthyRemote(box);
    seedApplyWorkerResult(box, { backup_readback: "MISSING" });
    const result = run(box, gate(), applyEnv(box, preflightReport()));
    expect(result.out).toContain("KALICI_SIL_APPLY_RESULT=UNPROVEN");
  });

  it("does not retry a FAILED worker result", () => {
    const box = makeSandbox();
    seedHealthyRemote(box);
    seedApplyWorkerResult(box, { state: "FAILED", reason: "KALICI_SIL_MIGRATION_APPLY_FAILED", stage: "KALICI_SIL_MIGRATION_APPLY" });
    const result = run(box, gate(), applyEnv(box, preflightReport()));
    expect(result.status).not.toBe(0);
    expect(result.out).toContain("KALICI_SIL_NEXT_STEP=DO_NOT_RETRY");
    expect(uploads(box).match(/^PUT /gm)?.length).toBe(1);
  });

  const negatives: Array<[string, (box: Sandbox) => Record<string, string>, string]> = [
    ["worker RUNNING (bare status.json listing)", (box) => { setListing(box, ["status.json", "worker-heartbeat.json"]); writeFile(join(box.root, "remote", CONTROL, "status.json"), JSON.stringify({ state: "RUNNING" })); return applyEnv(box, preflightReport()); }, "WORKER_BUSY"],
    ["worker RUNNING (full-path listing)", (box) => { writeFile(join(box.root, "remote", CONTROL, "status.json"), JSON.stringify({ state: "RUNNING" })); return applyEnv(box, preflightReport()); }, "WORKER_BUSY"],
    ["status.json listed but unreadable", (box) => { setListing(box, ["status.json"]); rmSync(join(box.root, "remote", CONTROL, "status.json")); return applyEnv(box, preflightReport()); }, "WORKER_STATUS_UNREADABLE"],
    ["status.json not parseable", (box) => { writeFile(join(box.root, "remote", CONTROL, "status.json"), "not-json"); return applyEnv(box, preflightReport()); }, "WORKER_STATUS_UNREADABLE"],
    ["a previous kalici-apply request exists", (box) => { setListing(box, ["status.json", "request.failed.kalici-apply-5-1.json"]); return applyEnv(box, preflightReport()); }, "KALICI_SIL_APPLY_ALREADY_ATTEMPTED"],
    ["preflight report digest mismatch", (box) => ({ ...applyEnv(box, preflightReport()), PREFLIGHT_REPORT_SHA256: "b".repeat(64) }), "PREFLIGHT_REPORT_DIGEST_MISMATCH"],
    ["preflight request id mismatch", (box) => ({ ...applyEnv(box, preflightReport()), PREFLIGHT_REQUEST_ID: "kalici-preflight-1-1" }), "PREFLIGHT_REQUEST_ID_MISMATCH"],
    ["stale preflight report", (box) => applyEnv(box, preflightReport({ generated_at: isoNow(-7 * 3600) })), "PREFLIGHT_STALE"],
    ["BLOCKED preflight report", (box) => applyEnv(box, preflightReport({ result: "BLOCKED" })), "PREFLIGHT_NOT_PASS"],
    ["preflight for other ids", (box) => applyEnv(box, preflightReport({ protected_ids: { ilker_id: 10, serhan_id: 104, distinct: true } })), "PROTECTED_SERHAN_ID_MISMATCH"],
    ["preflight with partial state", (box) => applyEnv(box, preflightReport({ partial_state: { present: true, fk_constraints: ["fk_p099_x"] } })), "PARTIAL_STATE_PRESENT"],
    ["preflight with more than 099 pending", (box) => applyEnv(box, preflightReport({ ledger: { applied_tip: "098", pending_versions: ["099", "100"] } })), "PREFLIGHT_PENDING_NOT_ONLY_099"],
    ["preflight from another deploy", (box) => applyEnv(box, preflightReport({ deployed_sha: OTHER_SHA })), "PREFLIGHT_SHA_MISMATCH"],
    ["remote deploy SHA differs", (box) => { writeFile(join(box.root, "remote", "api/.deploy-sha"), `${OTHER_SHA}\n`); return applyEnv(box, preflightReport()); }, "DEPLOY_SHA_MISMATCH"],
    ["workflow ref is not the deployed SHA", (box) => ({ ...applyEnv(box, preflightReport()), FAKE_HEAD: OTHER_SHA }), "WORKFLOW_REF_NOT_DEPLOYED_SHA"],
    ["stale heartbeat", (box) => { writeFile(join(box.root, "remote", CONTROL, "worker-heartbeat.json"), JSON.stringify({ updated_at: isoNow(-7200), deployed_sha: DEPLOYED })); return applyEnv(box, preflightReport()); }, "HEARTBEAT_STALE"],
    ["missing preflight report", (box) => { const env = applyEnv(box, preflightReport()); rmSync(join(box.root, "remote", CONTROL, "kalici-sil-migration-preflight.json")); return env; }, "CONTROL_PLANE_EVIDENCE_UNREADABLE"],
  ];
  for (const [label, prepare, reason] of negatives) {
    it(`blocks before any upload: ${label}`, () => {
      const box = makeSandbox();
      seedHealthyRemote(box);
      seedApplyWorkerResult(box);
      const env = prepare(box);
      const result = run(box, gate(), env);
      expect(result.status, result.out).not.toBe(0);
      expect(result.out).toContain(`KALICI_SIL_APPLY_GATE_REASON=${reason}`);
      expect(uploads(box)).toBe("");
    });
  }

  describe("environment-gate job", () => {
    const validate = () => runBlock(applyPath, "Validate explicit request");
    const envGate = () => runBlock(applyPath, "Require protected apply environment");
    const good = { CONFIRMATION: "APPLY_099_KALICI_SIL", PREFLIGHT_REQUEST_ID, PREFLIGHT_REPORT_SHA256: "c".repeat(64) };
    const protectedEnv = {
      name: "kalici-sil-099-apply",
      protection_rules: [{ type: "required_reviewers", prevent_self_review: false, reviewers: [{ type: "User", reviewer: { login: "akelilker" } }] }],
      deployment_branch_policy: { protected_branches: false, custom_branch_policies: true },
    };
    function withEnvironment(box: Sandbox, body: unknown) {
      const path = join(box.dir, "environment.json");
      if (body !== undefined) writeFile(path, JSON.stringify(body));
      return { FAKE_GH_ENV: path, APPLY_ENVIRONMENT: "kalici-sil-099-apply" };
    }

    it("rejects a wrong confirmation phrase and malformed pins", () => {
      const box = makeSandbox();
      expect(run(box, validate(), good).status).toBe(0);
      for (const phrase of ["", "APPLY_CANONICAL_MIGRATIONS", "apply_099_kalici_sil", "APPLY_099_KALICI_SIL "]) {
        const result = run(box, validate(), { ...good, CONFIRMATION: phrase });
        expect(result.status, phrase).not.toBe(0);
        expect(result.out).toContain("CONFIRMATION_INVALID");
      }
      expect(run(box, validate(), { ...good, PREFLIGHT_REPORT_SHA256: "xyz" }).status).not.toBe(0);
      expect(run(box, validate(), { ...good, PREFLIGHT_REQUEST_ID: "preflight-1-1" }).status).not.toBe(0);
      expect(run(box, validate(), { ...good, PROTECTED_SERHAN_USER_ID: "10" }).status).not.toBe(0);
    });

    it("passes only for an existing environment with a required reviewer and branch policy", () => {
      const box = makeSandbox();
      const result = run(box, envGate(), withEnvironment(box, protectedEnv));
      expect(result.status, result.out).toBe(0);
      expect(result.out).toContain("KALICI_SIL_ENVIRONMENT_REQUIRED_REVIEWERS=1");
      expect(result.out).toContain("KALICI_SIL_ENVIRONMENT_PREVENT_SELF_REVIEW=false");
    });

    const envNegatives: Array<[string, unknown, string, Record<string, string>?]> = [
      ["missing environment (404)", undefined, "ENVIRONMENT_MISSING_OR_UNREADABLE"],
      ["auto-created unprotected environment", { name: "kalici-sil-099-apply", protection_rules: [], deployment_branch_policy: null }, "ENVIRONMENT_REQUIRED_REVIEWERS_MISSING"],
      ["required_reviewers rule with zero reviewers", { ...protectedEnv, protection_rules: [{ type: "required_reviewers", reviewers: [] }] }, "ENVIRONMENT_REQUIRED_REVIEWERS_MISSING"],
      ["only a wait timer", { ...protectedEnv, protection_rules: [{ type: "wait_timer", wait_timer: 5 }] }, "ENVIRONMENT_REQUIRED_REVIEWERS_MISSING"],
      ["reviewers but any branch may deploy", { ...protectedEnv, deployment_branch_policy: null }, "ENVIRONMENT_BRANCH_POLICY_MISSING"],
      ["unexpected payload", { message: "Not Found" }, "ENVIRONMENT_MISSING_OR_UNREADABLE"],
      ["dispatched from a non-main ref", protectedEnv, "WORKFLOW_REF_NOT_MAIN", { GITHUB_REF: "refs/heads/ops/x" }],
    ];
    for (const [label, body, reason, extra] of envNegatives) {
      it(`fails closed: ${label}`, () => {
        const box = makeSandbox();
        const result = run(box, envGate(), { ...withEnvironment(box, body), ...(extra ?? {}) });
        expect(result.status, result.out).not.toBe(0);
        expect(result.out).toContain(`KALICI_SIL_ENVIRONMENT_GATE_REASON=${reason}`);
      });
    }
  });
});

describe("099 workflow runner prerequisites", () => {
  it("has bash and jq on Linux runners", () => {
    if (process.platform === "linux") {
      expect(bash).not.toBeNull();
      expect(jqAvailable).toBe(true);
    }
  });
});
