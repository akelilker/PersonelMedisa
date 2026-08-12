import { beforeAll, describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";
import { getRolePermissions, hasRolePermission } from "../../src/lib/authorization/role-permissions";

const migrationRunner = resolve(
  process.cwd(),
  "tests/php/S3C057QrAttendanceMysqlTestRunner.php"
);
const tokenRunner = resolve(process.cwd(), "tests/php/S3CQrTokenServiceTestRunner.php");

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

describe("S3C dynamic QR attendance foundation", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks migration 057 + permissions + routes + no puantaj write", () => {
    const migrations = readdirSync(resolve("api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations.at(-1)).toBe("057_qr_attendance_events.sql");
    expect(migrations).toContain("056_users_personel_binding.sql");

    for (const n of ["052", "053", "054", "055", "056"] as const) {
      expect(migrations.some((m) => m.startsWith(`${n}_`))).toBe(true);
    }

    const sql = read("api/migrations/057_qr_attendance_events.sql");
    expect(sql).toContain("qr_attendance_events");
    expect(sql).toContain("ENUM('GIRIS', 'CIKIS')");
    expect(sql).toContain("uq_qr_att_user_nonce");
    expect(sql).toContain("uq_qr_att_user_jti_type");
    expect(sql).toContain("fk_qr_att_personel");
    expect(sql).toContain("fk_qr_att_user");
    expect(sql).toContain("fk_qr_att_sube");
    expect(sql).not.toMatch(/^\s*INSERT\s+/im);
    expect(sql).not.toMatch(/\bDROP\s+TABLE\b/i);
    expect(sql).not.toMatch(/gunluk_puantaj/i);
    expect(sql).not.toMatch(/\binterval\b/i);

    const permsPhp = read("api/src/Auth/RolePermissions.php");
    const permsTs = read("src/lib/authorization/role-permissions.ts");
    for (const p of ["self_service.qr.scan", "self_service.qr.events.view"]) {
      expect(permsPhp).toContain(`'${p}'`);
      expect(permsTs).toContain(`"${p}"`);
    }
    const personelPerms = getRolePermissions("PERSONEL");
    expect(personelPerms).toContain("self_service.qr.scan");
    expect(personelPerms).toContain("self_service.qr.events.view");

    const authSmokePerms = getRolePermissions("AUTH_SMOKE_READONLY");
    expect(authSmokePerms).not.toContain("self_service.qr.scan");
    expect(authSmokePerms).not.toContain("self_service.qr.events.view");
    expect(hasRolePermission("AUTH_SMOKE_READONLY", "self_service.qr.scan")).toBe(false);

    const router = read("api/src/Router.php");
    expect(router).toContain("'/me/qr-scan'");
    expect(router).toContain("'/me/qr-hareketleri'");
    expect(router).toContain("'/qr-kiosk/token'");
    expect(router).not.toMatch(/\/me\/qr-hareketleri['"]\s*&&\s*\$method\s*===\s*'PUT'/);
    expect(router).not.toMatch(/\/me\/qr-hareketleri['"]\s*&&\s*\$method\s*===\s*'PATCH'/);
    expect(router).not.toMatch(/\/me\/qr-hareketleri['"]\s*&&\s*\$method\s*===\s*'DELETE'/);
    expect(router).not.toMatch(/\/me\/qr-scan['"]\s*&&\s*\$method\s*===\s*'PUT'/);
    expect(router).not.toMatch(/\/me\/qr-scan['"]\s*&&\s*\$method\s*===\s*'DELETE'/);

    const svc = read("api/src/Services/Qr/QrAttendanceEventService.php");
    expect(svc).toContain("Never writes gunluk_puantaj");
    expect(svc).not.toMatch(/INSERT\s+INTO\s+gunluk_puantaj/i);
    expect(svc).not.toMatch(/UPDATE\s+gunluk_puantaj/i);
    expect(svc).toContain("QR_CROSS_BRANCH_DENIED");

    const retentionAdapter = read("api/src/Services/Retention/RetentionSourceAdapterService.php");
    expect(retentionAdapter).toContain("resolveIseGirisCikisLifecycle");
    expect(retentionAdapter).toContain("computeIseGirisCikisFingerprint");
    const archive = read("api/src/Services/Retention/ArchiveManifestService.php");
    expect(archive).toContain("function computeIseGirisCikisFingerprint");
    expect(archive).toContain("ise_giris_cikis:empty:personel:");
    expect(archive).toContain("FROM qr_attendance_events");
    // PERSONEL_OZLUK fingerprint owner must remain distinct.
    expect(archive).toContain("function computePersonelOzlukFingerprint");

    expect(existsSync(resolve("src/features/self-service/pages/PersonelQrScanPage.tsx"))).toBe(true);
    expect(existsSync(resolve("src/features/self-service/pages/PersonelQrHistoryPage.tsx"))).toBe(true);
    expect(existsSync(resolve("src/api/qr.api.ts"))).toBe(true);
  });

  it("applies 057 on disposable MariaDB with UNIQUE/FK semantics", () => {
    const result = runPhpMysqlRunner(migrationRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("S3C 057 mysql runner OK");
    expect(result.stdout).toContain("[PASS] 057 ikinci apply idempotent");
    expect(result.stdout).toContain("[PASS] two different users same jti allowed");
    expect(result.stdout).toContain("[PASS] same user same jti GIRIS+CIKIS allowed");
    expect(result.stdout).toContain("[PASS] same user+jti+event_type duplicate fails");
    expect(result.stdout).toContain("[PASS] same user+nonce duplicate fails");
  });

  it("QrTokenService mint/verify contract via PHP runner", () => {
    const result = runPhpRunner(tokenRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("S3C QR token service runner OK");
    expect(result.stdout).toContain("[PASS] tampered payload DENY");
    expect(result.stdout).toContain("[PASS] missing secret QR_CONFIG_NOT_READY mint");
  });
});
