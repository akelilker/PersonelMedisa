import { beforeAll, describe, expect, it } from "vitest";
import { readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";

const pureRunner = resolve(process.cwd(), "tests/php/S3EQrPuantajCandidateTestRunner.php");
const periodMysqlRunner = resolve(process.cwd(), "tests/php/S3EQrPuantajPeriodContextMysqlTestRunner.php");
const s3dPureRunner = resolve(process.cwd(), "tests/php/S3DQrIntervalDerivationTestRunner.php");
const s3dRangeRunner = resolve(process.cwd(), "tests/php/S3DQrIntervalRangeMysqlTestRunner.php");
const s3cMigrationRunner = resolve(process.cwd(), "tests/php/S3C057QrAttendanceMysqlTestRunner.php");
const s3cTokenRunner = resolve(process.cwd(), "tests/php/S3CQrTokenServiceTestRunner.php");
const s3cBizRunner = resolve(process.cwd(), "tests/php/S3CQrBusinessDateRangeTestRunner.php");

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

describe("S3E QR puantaj candidate projection", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks candidate owners, route, permission, and no write / no migration 058", () => {
    const projection = read("api/src/Services/Qr/QrPuantajCandidateProjectionService.php");
    const readSvc = read("api/src/Services/Qr/QrPuantajCandidateReadService.php");
    const puantaj = read("api/src/Controllers/PuantajController.php");
    const router = read("api/src/Router.php");
    const endpoints = read("src/api/endpoints.ts");
    const puantajApi = read("src/api/puantaj.api.ts");

    expect(projection).toContain("QR_PUANTAJ_CANDIDATE_V1");
    expect(projection).toContain("READY_SINGLE_INTERVAL");
    expect(projection).toContain("REVIEW_MULTIPLE_INTERVALS");
    expect(projection).toContain("REVIEW_CROSS_MIDNIGHT");
    expect(projection).toContain("REVIEW_ANOMALY");
    expect(projection).toContain("qr_matched_seconds");
    expect(projection).toContain("period_write_locked");
    expect(projection).toContain("canonical_write_block_code");
    expect(projection).not.toMatch(/INSERT\s+INTO\s+gunluk_puantaj/i);
    expect(projection).not.toMatch(/UPDATE\s+gunluk_puantaj/i);

    expect(readSvc).toContain("MAX_RANGE_DAYS_INCLUSIVE = 62");
    expect(readSvc).toContain("resolveCanonicalWriteContext");
    expect(readSvc).toContain("buildPeriodContextByDate");
    expect(readSvc).not.toMatch(/INSERT\s+INTO\s+gunluk_puantaj/i);
    expect(readSvc).not.toMatch(/UPDATE\s+gunluk_puantaj/i);
    expect(readSvc).not.toMatch(/INSERT\s+INTO\s+haftalik_kapanis_revizyon/i);
    expect(readSvc).not.toMatch(/correctionUret/i);

    expect(puantaj).toContain("function qrAdaylari");
    expect(puantaj).toContain("puantaj.view");
    expect(puantaj).toContain("SubeScope::assertPersonelAccess");
    expect(puantaj).toContain("QrPuantajCandidateReadService::listForPersonel");
    expect(puantaj).not.toMatch(/INSERT\s+INTO\s+gunluk_puantaj[\s\S]*qrAdaylari/s);

    expect(router).toContain("/puantaj/qr-adaylari/");
    expect(router).toContain("PuantajController::qrAdaylari");

    expect(endpoints).toContain("qrAdaylari:");
    expect(puantajApi).toContain("fetchQrPuantajAdaylari");

    expect(hasRolePermission("GENEL_YONETICI", "puantaj.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "puantaj.view")).toBe(true);
    expect(hasRolePermission("PERSONEL", "puantaj.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "self_service.qr.events.view")).toBe(true);
  });

  it("does not introduce migration 058", () => {
    const files = readdirSync(resolve(process.cwd(), "api/migrations"));
    expect(files.some((f) => /^058/.test(f))).toBe(false);
  });

  it("runs S3E pure candidate PHP runner", () => {
    const result = runPhpRunner(pureRunner);
    expect(result.status).toBe(0);
    expect(result.stdout).toContain("S3E pure candidate tests OK");
  });

  it("runs S3E MariaDB period context runner", async () => {
    await runPhpMysqlRunner(periodMysqlRunner);
  }, 120_000);

  it("S3D regression — pure derivation unchanged", () => {
    const result = runPhpRunner(s3dPureRunner);
    expect(result.status).toBe(0);
    expect(result.stdout).toContain("[OK] S3DQrIntervalDerivationTestRunner");
  });

  it("S3D regression — MariaDB range runner", async () => {
    await runPhpMysqlRunner(s3dRangeRunner);
  }, 120_000);

  it("S3C regression — 057 schema runner", async () => {
    await runPhpMysqlRunner(s3cMigrationRunner);
  }, 120_000);

  it("S3C regression — token + business date", () => {
    expect(runPhpRunner(s3cTokenRunner).status).toBe(0);
    expect(runPhpRunner(s3cBizRunner).status).toBe(0);
  });
});
