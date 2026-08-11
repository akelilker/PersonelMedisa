import { beforeAll, describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const migrationRunner = resolve(
  process.cwd(),
  "tests/php/S3B056UsersPersonelBindingMysqlTestRunner.php"
);

function read(path: string): string {
  return readFileSync(path, "utf8");
}

describe("S3B users.personel_id binding foundation", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks migration 056 + owners + no QR half-impl + doc renumber", () => {
    const migrations = readdirSync(resolve("api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations).toContain("055_yillik_izin_hak_duzeltmeleri.sql");
    expect(migrations.at(-1)).toBe("056_users_personel_binding.sql");
    expect(migrations).not.toContain("057_");

    const sql = read("api/migrations/056_users_personel_binding.sql");
    expect(sql).toContain("personel_id");
    expect(sql).toContain("uq_users_personel_id");
    expect(sql).toContain("fk_users_personel");
    expect(sql).toContain("ON DELETE RESTRICT");
    expect(sql).toContain("ON UPDATE RESTRICT");
    expect(sql).toContain("user_personel_binding_audit");
    expect(sql).toContain("ENUM('SET', 'CLEAR', 'REPLACE')");
    expect(sql).not.toMatch(/UPDATE\s+users\s+SET\s+personel_id/i);
    expect(sql).not.toMatch(/\bINSERT\s+INTO\s+users\b/i);
    expect(sql).not.toMatch(/\bDROP\s+TABLE\b/i);

    for (const n of ["052", "053", "054", "055"] as const) {
      // Tip files must remain present and untouched by this suite's expectations.
      expect(migrations.some((m) => m.startsWith(`${n}_`))).toBe(true);
    }

    expect(existsSync(resolve("docs/guncel/105-s3a-personel-self-service-qr-foundation-discovery.md"))).toBe(
      true
    );
    expect(existsSync(resolve("docs/guncel/104-s3a-personel-self-service-qr-foundation-discovery.md"))).toBe(
      false
    );
    expect(existsSync(resolve("docs/guncel/104-s2a-annual-leave-entitlement-adjustment-discovery.md"))).toBe(
      true
    );

    const doc = read("docs/guncel/105-s3a-personel-self-service-qr-foundation-discovery.md");
    expect(doc).toMatch(/^# 105 —/m);
    expect(doc).toContain("D1_QR_MODEL = DYNAMIC_SIGNED");
    expect(doc).toContain("D2_EVENT_DIRECTION = EXPLICIT_GIRIS_CIKIS");
    expect(doc).toContain("D3_CROSS_BRANCH = DENY");
    expect(doc).toContain("D4_TERMINATED_SELF_SERVICE = DENY_ALL");
    expect(doc).toContain("REUSE_GIRIS_CIKIS_DUZELTME");
    expect(doc).toContain("AUTHENTICATED_KIOSK");
    expect(doc).toContain("D6_TTL_DEFAULT_SECONDS = 60");

    const permsPhp = read("api/src/Auth/RolePermissions.php");
    const permsTs = read("src/lib/authorization/role-permissions.ts");
    for (const p of [
      "self_service.view",
      "self_service.puantaj.view",
      "self_service.yillik_izin.view",
      "self_service.fazla_calisma.view"
    ]) {
      expect(permsPhp).toContain(`'${p}'`);
      expect(permsTs).toContain(`"${p}"`);
    }
    expect(permsPhp).not.toContain("self_service.qr");
    expect(permsTs).not.toContain("self_service.qr");

    const router = read("api/src/Router.php");
    expect(router).toContain("'/me'");
    expect(router).toContain("'/me/puantaj'");
    expect(router).toContain("'/me/yillik-izin-bakiye'");
    expect(router).toContain("'/me/fazla-calisma'");
    expect(router).not.toMatch(/\/me\/qr|qr-scan|BarcodeDetector/i);

    const me = read("api/src/Controllers/MeController.php");
    expect(me).not.toMatch(/\$request->getJsonBody\(\).*personel_id|getQuery\(\s*['\"]personel_id/);
    expect(me).toContain("SelfPersonelContext::resolveForSelfService");
    expect(me).toContain("YillikIzinBakiyeService::assemble");

    const binding = read("api/src/Services/Auth/UserPersonelBindingService.php");
    expect(binding).toContain("PERSONEL_ALREADY_BOUND");
    expect(binding).toContain("user_personel_binding_audit");
    expect(binding).toContain("'SET'");
    expect(binding).toContain("'CLEAR'");
    expect(binding).toContain("'REPLACE'");

    const ctx = read("api/src/Services/SelfService/SelfPersonelContext.php");
    expect(ctx).toContain("SELF_SERVICE_BINDING_REQUIRED");
    expect(ctx).toContain("SELF_SERVICE_PERSONEL_INACTIVE");

    const shell = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(shell).toContain("personel-self-service-page");
    expect(shell).toContain("personel-unbound-page");
    expect(shell).toContain("personel-inactive-page");
    expect(shell).not.toMatch(/QR|BarcodeDetector|getUserMedia/i);

    const contract = read("src/lib/yonetim/kullanici-api-contract.ts");
    expect(contract).toContain("personel_id: payload.personel_id");
    expect(contract).not.toMatch(/REAL_API_UNSUPPORTED_KULLANICI_FIELDS\s*=\s*\[[^\]]*personel_id/);
  });

  it("applies 056 on disposable MariaDB with UNIQUE NULL + RESTRICT + audit", () => {
    const result = runPhpMysqlRunner(migrationRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-s3b-056-users-personel-binding-mysql: OK");
    expect(result.stdout).toContain("[PASS] 056 ikinci apply idempotent");
    expect(result.stdout).toContain("[PASS] duplicate non-null personel_id forbidden");
    expect(result.stdout).toContain("[PASS] existing users remain NULL (no backfill)");
  });
});
