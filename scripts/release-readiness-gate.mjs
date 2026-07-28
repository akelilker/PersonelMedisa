/**
 * S96 — Release readiness gate (code + ops dependency status).
 *
 * Default: verifies repo/code contracts that unlock final ops acceptance.
 * External ops gates are reported as WAITING unless explicitly acknowledged
 * via env. Never performs production writes.
 *
 * Usage:
 *   npm run release:gate
 *   REQUIRE_OPS_READY=1 npm run release:gate
 *   SMOKE_BASE_URL=https://example.invalid npm run release:gate
 *
 * Ops acknowledgement env (value must be exactly "ready"):
 *   RELEASE_GATE_SGK_OFFICIAL_SOURCE
 *   RELEASE_GATE_UBGT_SEED_APPROVED
 *   RELEASE_GATE_PROD_WRITE_APPROVED
 *   RELEASE_GATE_AUTH_SMOKE_CREDENTIAL
 */

import { spawnSync } from "node:child_process";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const scriptDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(scriptDir, "..");

const failures = [];
const warnings = [];

function ok(label, detail = "") {
  console.log(`[OK] ${label}${detail ? ` — ${detail}` : ""}`);
}

function fail(label, detail) {
  failures.push({ label, detail });
  console.error(`[FAIL] ${label}${detail ? ` — ${detail}` : ""}`);
}

function wait(label, detail) {
  warnings.push({ label, detail });
  console.log(`[WAITING] ${label}${detail ? ` — ${detail}` : ""}`);
}

function readyAck(envName) {
  return (process.env[envName] ?? "").trim().toLowerCase() === "ready";
}

function listSourceFiles(dir) {
  const entries = readdirSync(dir, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const absolute = join(dir, entry.name);
    if (entry.isDirectory()) {
      files.push(...listSourceFiles(absolute));
      continue;
    }
    if (entry.isFile() && /\.(ts|tsx|php|mjs|md)$/.test(entry.name)) {
      files.push(absolute);
    }
  }
  return files;
}

function readRepo(relativePath) {
  return readFileSync(resolve(repoRoot, relativePath), "utf8");
}

function assertContains(relativePath, needle, label) {
  const source = readRepo(relativePath);
  if (!source.includes(needle)) {
    fail(label, `${relativePath} missing: ${needle}`);
    return;
  }
  ok(label);
}

function assertNotMatches(relativePath, pattern, label) {
  const source = readRepo(relativePath);
  if (pattern.test(source)) {
    fail(label, `${relativePath} matched forbidden pattern ${pattern}`);
    return;
  }
  ok(label);
}

function checkNativeDialogs() {
  const srcRoot = resolve(repoRoot, "src");
  const nativeRe = /\b(?:window|globalThis)\s*\.\s*(?:confirm|prompt|alert)\s*\(/g;
  const hits = [];
  for (const filePath of listSourceFiles(srcRoot)) {
    if (!/\.(ts|tsx)$/.test(filePath)) continue;
    const text = readFileSync(filePath, "utf8");
    let match;
    nativeRe.lastIndex = 0;
    while ((match = nativeRe.exec(text)) !== null) {
      const relative = filePath.slice(repoRoot.length + 1).replace(/\\/g, "/");
      hits.push(`${relative}:${match[0]}`);
    }
  }
  if (hits.length > 0) {
    fail("src native dialog = 0", hits.slice(0, 5).join(" | "));
    return;
  }
  ok("src native dialog = 0");
}

function checkCodeContracts() {
  assertContains(
    "src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx",
    'testId="bordro-kesinlestir-action-dialog"',
    "Bordro kesinleştir AppActionDialog"
  );
  assertContains(
    "src/features/raporlar/pages/DonemKapanisMerkeziPage.tsx",
    'testId="donem-kapanis-muhur-action-dialog"',
    "Dönem mühür AppActionDialog"
  );
  assertContains(
    "src/features/yonetim/pages/ResmiTatilTakvimiPage.tsx",
    'data-testid="rtt-cancel-error"',
    "Resmî tatil iptal modal hata yüzeyi"
  );
  assertContains(
    "src/features/personeller/components/personel-dosya/PersonelBelgelerPanel.tsx",
    'data-testid="personel-belge-create-error"',
    "Personel belge create modal hata yüzeyi"
  );
  assertContains(
    "api/src/Controllers/SgkKatalogHazirlikController.php",
    "No seed/write activation",
    "SGK katalog write fail-closed (controller)"
  );
  assertContains(
    "api/src/Services/Payroll/SgkKatalogTamlikService.php",
    "'approve_aktif_mi' => false",
    "SGK approve_aktif_mi=false"
  );
  assertContains(
    "api/src/Controllers/SgkKatalogHazirlikController.php",
    "'seed_var_mi' => false",
    "SGK seed_var_mi=false"
  );
  assertNotMatches(
    "api/src/Router.php",
    /sgk-katalog-hazirlik\/import(?!\/dry-run)/,
    "SGK import write route yok (yalnız dry-run)"
  );

  if (!existsSync(resolve(repoRoot, "scripts/post-deploy-smoke.mjs"))) {
    fail("post-deploy smoke script", "scripts/post-deploy-smoke.mjs missing");
  } else {
    ok("post-deploy smoke script");
  }

  if (!existsSync(resolve(repoRoot, "docs/guncel/95-s96-release-ops-runbook.md"))) {
    fail("ops runbook", "docs/guncel/95-s96-release-ops-runbook.md missing");
  } else {
    ok("ops runbook");
  }

  const pkg = JSON.parse(readRepo("package.json"));
  if (pkg.scripts?.["smoke:live"] !== "node scripts/post-deploy-smoke.mjs") {
    fail("package.json smoke:live", "unexpected smoke:live script");
  } else {
    ok("package.json smoke:live");
  }
  if (pkg.scripts?.["release:gate"] !== "node scripts/release-readiness-gate.mjs") {
    fail("package.json release:gate", "unexpected release:gate script");
  } else {
    ok("package.json release:gate");
  }

  checkNativeDialogs();
}

function checkOpsGates() {
  const gates = [
    {
      env: "RELEASE_GATE_SGK_OFFICIAL_SOURCE",
      label: "SGK katalog resmi kaynak paketi",
      hint: "Resmi birincil kaynak + hash paketi gelince RELEASE_GATE_SGK_OFFICIAL_SOURCE=ready"
    },
    {
      env: "RELEASE_GATE_UBGT_SEED_APPROVED",
      label: "UBGT / resmi tatil seed onayı",
      hint: "Seed onay metni gelince RELEASE_GATE_UBGT_SEED_APPROVED=ready"
    },
    {
      env: "RELEASE_GATE_PROD_WRITE_APPROVED",
      label: "Production write yetkisi",
      hint: "Yazma onayı gelince RELEASE_GATE_PROD_WRITE_APPROVED=ready"
    },
    {
      env: "RELEASE_GATE_AUTH_SMOKE_CREDENTIAL",
      label: "Canlı authenticated smoke hesabı",
      hint: "Güvenli test hesabı hazırsa RELEASE_GATE_AUTH_SMOKE_CREDENTIAL=ready"
    }
  ];

  let readyCount = 0;
  for (const gate of gates) {
    if (readyAck(gate.env)) {
      readyCount += 1;
      ok(gate.label, `${gate.env}=ready`);
    } else {
      wait(gate.label, gate.hint);
    }
  }
  return { readyCount, total: gates.length };
}

function runLiveSmokeIfRequested() {
  const baseUrl = (process.env.SMOKE_BASE_URL ?? "").trim();
  if (!baseUrl) {
    wait("Live read-only smoke", "SMOKE_BASE_URL set edilirse post-deploy-smoke çalışır");
    return;
  }

  const result = spawnSync(process.execPath, [resolve(repoRoot, "scripts/post-deploy-smoke.mjs")], {
    cwd: repoRoot,
    env: process.env,
    encoding: "utf8"
  });

  if (result.status === 0) {
    ok("Live read-only smoke", "post-deploy-smoke OK");
    return;
  }

  fail(
    "Live read-only smoke",
    (result.stderr || result.stdout || `exit ${result.status}`).trim().slice(0, 500)
  );
}

function main() {
  console.log("PersonelMedisa S96 release readiness gate");
  console.log(`Repo: ${repoRoot}`);
  console.log("");

  console.log("## Code / product contracts");
  checkCodeContracts();
  console.log("");

  console.log("## External ops gates");
  const ops = checkOpsGates();
  console.log("");

  console.log("## Optional live checks");
  runLiveSmokeIfRequested();
  console.log("");

  if (failures.length > 0) {
    console.error(`Release gate result: FAIL (${failures.length})`);
    process.exit(1);
  }

  const requireOps = (process.env.REQUIRE_OPS_READY ?? "").trim() === "1";
  if (requireOps && ops.readyCount < ops.total) {
    console.error(
      `Release gate result: OPS_PENDING (${ops.readyCount}/${ops.total} ops gates ready; REQUIRE_OPS_READY=1)`
    );
    process.exit(2);
  }

  if (ops.readyCount === ops.total) {
    console.log("Release gate result: FULL_READY (code + ops acknowledgements)");
  } else {
    console.log(
      `Release gate result: CODE_READY_OPS_PENDING (${ops.readyCount}/${ops.total} ops gates ready)`
    );
  }
  process.exit(0);
}

main();
