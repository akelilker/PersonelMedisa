import { beforeAll, describe, expect, it } from "vitest";
import { readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";

const pureRunner = resolve(process.cwd(), "tests/php/S3FQrPuantajDecisionPureTestRunner.php");
const migration058Runner = resolve(
  process.cwd(),
  "tests/php/S3F058QrDecisionLedgerMysqlTestRunner.php"
);
const decisionMysqlRunner = resolve(
  process.cwd(),
  "tests/php/S3FQrPuantajDecisionMysqlTestRunner.php"
);
const s3ePureRunner = resolve(process.cwd(), "tests/php/S3EQrPuantajCandidateTestRunner.php");
const s3ePeriodMysqlRunner = resolve(
  process.cwd(),
  "tests/php/S3EQrPuantajPeriodContextMysqlTestRunner.php"
);

function read(path: string): string {
  return readFileSync(path, "utf8");
}

function runPhpRunner(path: string) {
  return spawnSync("php", [path], {
    cwd: process.cwd(),
    encoding: "utf8",
    env: process.env
  });
}

describe("S3F QR puantaj candidate review / apply", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks migration 058 tip + append-only ledger + 052-057 present", () => {
    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations.at(-1)).toBe("061_serbest_zaman_kullanim_tahsisleri.sql");
    expect(migrations).toContain("058_qr_puantaj_candidate_decision_ledger.sql");

    for (const n of ["052", "053", "054", "055", "056", "057"] as const) {
      expect(migrations.some((m) => m.startsWith(`${n}_`))).toBe(true);
    }

    const sql = read("api/migrations/058_qr_puantaj_candidate_decision_ledger.sql");
    expect(sql).toContain("qr_puantaj_candidate_decision_ledger");
    expect(sql).toContain("uq_qr_pc_decision_user_nonce");
    expect(sql).toContain("ON DELETE RESTRICT");
    expect(sql).toContain("CREATE TABLE IF NOT EXISTS");
    expect(sql).not.toMatch(/^\s*INSERT\s+/im);
    expect(sql).not.toMatch(/\bDROP\s+TABLE\b/i);
    expect(sql).not.toMatch(/\bUPDATE\s+qr_puantaj_candidate_decision_ledger\b/i);
    expect(sql).not.toMatch(/\bDELETE\s+FROM\s+qr_puantaj_candidate_decision_ledger\b/i);
  });

  it("locks decision owners, routes, permissions without new permission keys", () => {
    const hashSvc = read("api/src/Services/Qr/QrPuantajCandidateHashService.php");
    const policy = read("api/src/Services/Qr/QrPuantajCandidateDecisionPolicy.php");
    const ledger = read("api/src/Services/Qr/QrPuantajCandidateDecisionLedgerService.php");
    const apply = read("api/src/Services/Qr/QrPuantajCandidateApplyService.php");
    const decide = read("api/src/Services/Qr/QrPuantajCandidateDecisionService.php");
    const readSvc = read("api/src/Services/Qr/QrPuantajCandidateReadService.php");
    const projection = read("api/src/Services/Qr/QrPuantajCandidateProjectionService.php");
    const puantaj = read("api/src/Controllers/PuantajController.php");
    const router = read("api/src/Router.php");
    const endpoints = read("src/api/endpoints.ts");
    const puantajApi = read("src/api/puantaj.api.ts");
    const ui = read("src/features/puantaj/components/QrPuantajAdayiSection.tsx");
    const permsTs = read("src/lib/authorization/role-permissions.ts");
    const permsPhp = read("api/src/Auth/RolePermissions.php");

    expect(hashSvc).toContain("QR_CANDIDATE_HASH_V2");
    expect(hashSvc).toContain("sube_id");
    expect(hashSvc).toContain("decision_algorithm_version");
    expect(hashSvc).toContain("period_write_locked");
    expect(hashSvc).toContain("muhur_id");
    expect(hashSvc).toContain("qr_matched_seconds");
    expect(hashSvc).toContain("source_sube_ids");
    expect(hashSvc).toMatch(/function compute\(\$personelId,\s*\$subeId/);
    expect(hashSvc).toMatch(/HASH_SCHEMA_VERSION = 'QR_CANDIDATE_HASH_V2'/);
    expect(hashSvc).not.toMatch(/HASH_SCHEMA_VERSION\s*=\s*'QR_CANDIDATE_HASH_V1'/);

    expect(policy).toContain("canonicalMapAsGuardRow");
    expect(policy).toContain("BLOCK_DEPENDENT_FIELDS");
    expect(decide).toContain("findByUserNonce");
    expect(decide).toContain("Post-lock nonce recheck");
    // Source ordering: period lock → FOR UPDATE → post-lock nonce → recompute → stale → apply.
    // Post-lock nonce recheck must precede recompute/stale evaluation.
    const decideFnIdx = decide.indexOf("public static function decide");
    const acquireIdx = decide.indexOf("acquireForDate", decideFnIdx);
    const forUpdateIdx = decide.indexOf("fetchForUpdate", decideFnIdx);
    const postLockFindIdx = decide.indexOf(
      "$lockedNonce = QrPuantajCandidateDecisionLedgerService::findByUserNonce",
      decideFnIdx
    );
    const recomputeIdx = decide.indexOf("self::recomputeSingleCandidate", decideFnIdx);
    const staleIdx = decide.indexOf("BLOCK_STALE", decideFnIdx);
    const applyIdx = decide.indexOf("self::executeApply", decideFnIdx);
    expect(decideFnIdx).toBeGreaterThan(-1);
    expect(acquireIdx).toBeGreaterThan(decideFnIdx);
    expect(forUpdateIdx).toBeGreaterThan(acquireIdx);
    expect(postLockFindIdx).toBeGreaterThan(forUpdateIdx);
    expect(recomputeIdx).toBeGreaterThan(postLockFindIdx);
    expect(staleIdx).toBeGreaterThan(recomputeIdx);
    expect(applyIdx).toBeGreaterThan(staleIdx);
    expect(decide).not.toContain("S3F_RACE_HOLD_MS");
    expect(decide).not.toMatch(/test-only overlap hold/i);
    expect(decide).not.toMatch(/usleep\s*\(/);

    expect(readSvc).toContain("gec_kalma_dakika");
    expect(readSvc).toContain("muhur_id");
    expect(projection).toContain("muhur_id");
    expect(projection).toContain("dependentGuardFields");
    expect(ui).toContain("QR_APPLY_DEPENDENT_FIELDS_REQUIRE_MANUAL_REVIEW");
    expect(ui).toContain("qr-puantaj-aday-dependent-review");
    expect(ui).toContain("Manuel puantaj incelemesi gerekir");
    expect(ui).toContain("newNonce()");
    expect(ui).not.toContain("nonceRef");
    expect(ui).not.toContain("lastNonce");
    expect(policy).toContain("QR_PUANTAJ_DECISION_V1");
    expect(policy).toContain("APPLY_EXISTING");
    expect(policy).toContain("KEEP_CANONICAL");
    expect(policy).toContain("REOPEN_REVIEW");
    expect(ledger).toContain("qr_puantaj_candidate_decision_ledger");
    expect(ledger).not.toMatch(/UPDATE\s+qr_puantaj_candidate_decision_ledger/i);
    expect(ledger).not.toMatch(/DELETE\s+FROM\s+qr_puantaj_candidate_decision_ledger/i);
    expect(apply).toContain("giris_saati");
    expect(apply).toContain("cikis_saati");
    expect(decide).toContain("function decide");
    expect(decide).toContain("createQrPuantajDecisionOnayAuditManifest");

    const retentionAdapter = read(
      "api/src/Services/Retention/RetentionSourceAdapterService.php"
    );
    expect(retentionAdapter).toContain("QR_PUANTAJ_CANDIDATE_DECISION");
    expect(retentionAdapter).toContain("computeQrPuantajDecisionLedgerFingerprint");

    // Production call sites must use the 3-arg V2 signature.
    for (const path of [
      "api/src/Services/Qr/QrPuantajCandidateDecisionService.php",
      "api/src/Services/Qr/QrPuantajCandidateReadService.php",
      "tests/php/S3FQrPuantajDecisionPureTestRunner.php"
    ] as const) {
      const src = read(path);
      expect(src).not.toMatch(/QrPuantajCandidateHashService::compute\(\s*\$[a-zA-Z0-9_]+\s*,\s*\$[a-zA-Z0-9_]+\s*\)/);
      expect(src).toMatch(/QrPuantajCandidateHashService::compute\([^)]+,[^)]+,[^)]+\)/);
    }

    expect(puantaj).toContain("function qrAdaylari");
    expect(puantaj).toContain("function qrAdayKarar");
    expect(puantaj).toContain("function qrAdayKararlar");
    expect(puantaj).toMatch(/qrAdaylari[\s\S]*puantaj\.view/);
    expect(puantaj).toMatch(/qrAdayKarar[\s\S]*puantaj\.update/);
    expect(puantaj).toMatch(/qrAdayKararlar[\s\S]*puantaj\.view/);

    expect(router).toContain("/puantaj/qr-adaylari/");
    expect(router).toContain("/karar");
    expect(router).toContain("/kararlar");
    expect(router).toContain("PuantajController::qrAdayKarar");
    expect(router).toContain("PuantajController::qrAdayKararlar");

    expect(endpoints).toContain("qrAdayKarar:");
    expect(endpoints).toContain("qrAdayKararlar:");
    expect(puantajApi).toContain("postQrPuantajAdayKarar");

    expect(permsTs).not.toMatch(/qr\.candidate|puantaj\.qr\.decide|qr_aday/);
    expect(permsPhp).not.toMatch(/qr\.candidate|puantaj\.qr\.decide|qr_aday/);

    expect(hasRolePermission("GENEL_YONETICI", "puantaj.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "puantaj.update")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.update")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.update")).toBe(false);
    expect(hasRolePermission("IK_SORUMLUSU", "puantaj.update")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "puantaj.update")).toBe(false);
    expect(hasRolePermission("SISTEM_YONETICISI", "puantaj.update")).toBe(false);
    expect(hasRolePermission("PERSONEL", "puantaj.update")).toBe(false);
  });

  it("locks frontend QrPuantajAdayiSection + karar routes + QR eşleşme süresi copy", () => {
    const section = read("src/features/puantaj/components/QrPuantajAdayiSection.tsx");
    const page = read("src/features/puantaj/pages/GunlukPuantajPage.tsx");
    const endpoints = read("src/api/endpoints.ts");

    expect(section).toContain("QrPuantajAdayiSection");
    expect(section).toContain("postQrPuantajAdayKarar");
    expect(section).toContain("QR eşleşme süresi");
    expect(page).toContain("QrPuantajAdayiSection");
    expect(endpoints).toContain("/karar");
    expect(endpoints).toContain("/kararlar");
  });

  it("runs S3F pure decision PHP runner", () => {
    const result = runPhpRunner(pureRunner);
    expect(result.status).toBe(0);
    expect(result.stdout).toContain("S3F pure decision tests OK");
  });

  it("runs S3F 058 MariaDB ledger migration runner", async () => {
    const result = await runPhpMysqlRunner(migration058Runner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("S3F 058 mysql runner OK");
  }, 120_000);

  it("runs S3F decision MariaDB runner", async () => {
    const result = await runPhpMysqlRunner(decisionMysqlRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("S3F decision mysql tests OK");
  }, 180_000);

  it("S3E regression — pure candidate runner still passes", () => {
    const result = runPhpRunner(s3ePureRunner);
    expect(result.status).toBe(0);
    expect(result.stdout).toContain("S3E pure candidate tests OK");
  });

  it("S3E regression — period context MariaDB runner still passes", async () => {
    const result = await runPhpMysqlRunner(s3ePeriodMysqlRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[OK] S3EQrPuantajPeriodContextMysqlTestRunner");
  }, 120_000);
});
