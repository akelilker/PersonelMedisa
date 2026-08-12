import { beforeAll, describe, expect, it } from "vitest";
import { readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";

const pureRunner = resolve(process.cwd(), "tests/php/S3DQrIntervalDerivationTestRunner.php");
const rangeRunner = resolve(process.cwd(), "tests/php/S3DQrIntervalRangeMysqlTestRunner.php");
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

describe("S3D QR interval derivation", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks derivation owners, route, and no migration 058 / no puantaj write", () => {
    const derivation = read(
      "api/src/Services/Qr/QrAttendanceIntervalDerivationService.php"
    );
    const readSvc = read("api/src/Services/Qr/QrAttendanceIntervalReadService.php");
    const me = read("api/src/Controllers/MeController.php");
    const router = read("api/src/Router.php");
    const endpoints = read("src/api/endpoints.ts");
    const qrApi = read("src/api/qr.api.ts");
    const historyUi = read("src/features/self-service/pages/PersonelQrHistoryPage.tsx");

    expect(derivation).toContain("QR_INTERVAL_V1");
    expect(derivation).toContain("function derive");
    expect(derivation).toContain("MISSING_CIKIS");
    expect(derivation).toContain("MISSING_GIRIS");
    expect(derivation).toContain("BRANCH_MISMATCH");
    expect(derivation).toContain("GIRIS_CIKIS_DUZELTME");
    expect(derivation).not.toMatch(/INSERT\s+INTO\s+gunluk_puantaj/i);
    expect(derivation).not.toMatch(/UPDATE\s+gunluk_puantaj/i);
    expect(derivation).not.toMatch(/INSERT\s+INTO/i);

    expect(readSvc).toContain("businessDateRangeToUtc");
    expect(readSvc).toContain("loadEventsWithBoundaryContext");
    expect(readSvc).not.toMatch(/INSERT\s+INTO/i);
    expect(readSvc).not.toMatch(/UPDATE\s+qr_/i);
    expect(readSvc).not.toMatch(/DELETE\s+FROM\s+qr_/i);

    expect(me).toContain("function qrAraliklari");
    expect(me).toContain("self_service.qr.events.view");
    expect(me).toContain("QrAttendanceIntervalReadService::listForSelf");
    expect(me).not.toContain("getQuery('personel_id'");
    expect(me).not.toContain('getQuery("personel_id"');

    expect(router).toContain("'/me/qr-araliklari'");
    expect(router).toContain("MeController::qrAraliklari");
    expect(router).toContain("'/me/qr-hareketleri'");

    expect(endpoints).toContain('qrAraliklari: "/me/qr-araliklari"');
    expect(qrApi).toContain("fetchMeQrAraliklari");
    expect(historyUi).toContain("QR Eslesmeleri");
    expect(historyUi).toContain("fetchMeQrAraliklari");

    expect(hasRolePermission("PERSONEL", "self_service.qr.events.view")).toBe(true);
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "self_service.qr.events.view")).toBe(
      false
    );
  });

  it("does not introduce migration 058", () => {
    const files = readdirSync(resolve(process.cwd(), "api/migrations"));
    expect(files.some((f) => /^058_/.test(f))).toBe(false);
    expect(files.some((f) => /interval/i.test(f))).toBe(false);
  });

  it("runs pure derivation PHP runner", () => {
    const result = runPhpRunner(pureRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[OK] S3DQrIntervalDerivationTestRunner");
  });

  it("runs MariaDB range/boundary PHP runner", () => {
    const result = runPhpMysqlRunner(rangeRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[OK] S3DQrIntervalRangeMysqlTestRunner");
  });

  it("keeps S3C raw capture regressions green", () => {
    const token = runPhpRunner(s3cTokenRunner);
    expect(token.status, token.stderr || token.stdout).toBe(0);
    const biz = runPhpRunner(s3cBizRunner);
    expect(biz.status, biz.stderr || biz.stdout).toBe(0);
    const mig = runPhpMysqlRunner(s3cMigrationRunner);
    expect(mig.status, mig.stderr || mig.stdout).toBe(0);
  });
});
