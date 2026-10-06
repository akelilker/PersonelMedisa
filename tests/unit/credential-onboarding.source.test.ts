import { createHash } from "node:crypto";
import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

describe("credential onboarding owners (MG-CRED-ONBOARD-001)", () => {
  it("defines migration 069 must_change_password column", () => {
    const sql = read("api/migrations/069_personel_credential_onboarding.sql");
    expect(sql).toContain("must_change_password");
  });

  it("exposes POST /auth/change-password and login must_change_password flag", () => {
    const router = read("api/src/Router.php");
    const login = read("api/src/Auth/LoginController.php");
    const change = read("api/src/Auth/ChangePasswordController.php");
    expect(router).toContain("'/auth/change-password'");
    expect(login).toContain("must_change_password");
    expect(change).toContain("must_change_password = 0");
  });

  it("sets must_change_password on admin password writes when schema present", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    expect(yonetim).toContain("hasMustChangePassword");
    expect(yonetim).toContain("must_change_password = 1");
  });

  it("exposes must_change_password boolean on kullanicilar list/detail map without secrets", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    expect(yonetim).toMatch(/\$selectCols\[\] = 'must_change_password'/);
    expect(yonetim).toMatch(/\$cols\[\] = 'must_change_password'/);
    expect(yonetim).toContain("readStoredMustChangePasswordFromRow");
    expect(yonetim).toContain("\$mapped['must_change_password']");
    expect(yonetim).not.toMatch(/\$mapped\[['\"]password['\"]\]/);
    expect(yonetim).not.toMatch(/\$mapped\[['\"]password_hash['\"]\]/);
    expect(yonetim).not.toMatch(/\$mapped\[['\"]temporary_password['\"]\]/);
    expect(yonetim).not.toMatch(/\$mapped\[['\"]gecici_sifre['\"]\]/);
  });

  it("D: kullanicilar list auth gate remains yonetim-paneli.manage only", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    const kullanicilarFn = yonetim.match(
      /public static function kullanicilar\(Request \$request\)[\s\S]*?public static function kullaniciOlustur/
    )?.[0];
    expect(kullanicilarFn).toBeTruthy();
    expect(kullanicilarFn).toContain("assertKullaniciYonetimi");
    expect(kullanicilarFn).toContain("must_change_password");
    expect(kullanicilarFn).not.toContain("CROSS_BRANCH");
    expect(kullanicilarFn).not.toContain("filterByCallerSube");
    expect(kullanicilarFn).not.toContain("assertSameBranch");

    const assertFn = yonetim.match(
      /private static function assertKullaniciYonetimi\([\s\S]*?\n    \}/
    )?.[0];
    expect(assertFn).toBeTruthy();
    expect(assertFn).toContain("yonetim-paneli.manage");
  });

  it("routes authenticated users with must_change_password to change-password page", () => {
    const route = read("src/router/ProtectedRoute.tsx");
    expect(route).toContain("/change-password");
    expect(route).toContain("must_change_password");
  });

  it("A: AuthMiddleware fail-closed rejects must_change_password users on normal protected endpoints", () => {
    const auth = read("api/src/Auth/AuthMiddleware.php");
    expect(auth).toContain("$allowPasswordChangeRequired = false");
    expect(auth).toContain("PASSWORD_CHANGE_REQUIRED");
    expect(auth).toContain("403");
    expect(auth).toContain("enforceMustChangePasswordIfRequired");
    // Cached user must not bypass enforcement
    expect(auth).toMatch(
      /if \(self::\$user !== null\)[\s\S]*enforceMustChangePasswordIfRequired\(\$required, \$allowPasswordChangeRequired\)/,
    );
    expect(auth).toContain("hasMustChangePassword");
    expect(auth).toContain("'must_change_password'");
  });

  it("B: change-password endpoint uses explicit auth bypass only", () => {
    const change = read("api/src/Auth/ChangePasswordController.php");
    expect(change).toContain("AuthMiddleware::authenticate($request, true, true)");
    const me = read("api/src/Controllers/MeController.php");
    expect(me).toContain("AuthMiddleware::authenticate($request, true)");
    expect(me).not.toContain("AuthMiddleware::authenticate($request, true, true)");
  });

  it("C: change-password rejects wrong current password", () => {
    const change = read("api/src/Auth/ChangePasswordController.php");
    expect(change).toContain("INVALID_CURRENT_PASSWORD");
    expect(change).toContain("PasswordHasher::verify($current");
  });

  it("D: successful password change clears must_change_password flag", () => {
    const change = read("api/src/Auth/ChangePasswordController.php");
    expect(change).toContain("must_change_password = 0");
    expect(change).toContain("markPasswordChanged");
    expect(change).toContain("'must_change_password' => false");
  });

  it("E: flag=0 users keep normal protected endpoint auth (no blanket deny)", () => {
    const auth = read("api/src/Auth/AuthMiddleware.php");
    expect(auth).toMatch(
      /if \(!empty\(self::\$user\['must_change_password'\]\)\)/,
    );
    expect(auth).toMatch(
      /if \(!empty\(self::\$user\['must_change_password'\]\)\)[\s\S]*JsonResponse::error\(403, 'PASSWORD_CHANGE_REQUIRED'/,
    );
  });

  it("F: schema-absent backward compat — probe before column select and enforce", () => {
    const auth = read("api/src/Auth/AuthMiddleware.php");
    expect(auth).toContain("UsersSchema::hasMustChangePassword($pdo)");
    expect(auth).toMatch(
      /if \(\$hasMustChangePassword\)[\s\S]*\$cols\[\] = 'must_change_password'/,
    );
    expect(auth).toMatch(
      /if \(array_key_exists\('must_change_password', \$row\)\)/,
    );
  });

  it("G: PASIF user still denied at authentication (AKTIF gate preserved)", () => {
    const auth = read("api/src/Auth/AuthMiddleware.php");
    expect(auth).toMatch(/\(\$row\['durum'\] \?\? ''\) !== 'AKTIF'/);
    expect(auth).toContain("JsonResponse::unauthorized");
  });

  it("change-password only allows self password change (no user_id override)", () => {
    const change = read("api/src/Auth/ChangePasswordController.php");
    expect(change).toContain('$userId = isset($user[\'id\'])');
    expect(change).not.toMatch(/\$body\[['"]user_id['"]\]/);
    expect(change).not.toMatch(/WHERE id = :id.*\$body/);
  });

  it("migration 069 remains in chain; tip is 097 with bundle/runner parity", () => {
    const migrations = readdirSync(resolve("api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations.at(-29)).toBe("069_personel_credential_onboarding.sql");
    expect(migrations.at(-28)).toBe("070_offline_mutation_idempotency.sql");
    expect(migrations.at(-27)).toBe("071_org_hierarchy_authorization.sql");
    expect(migrations.at(-26)).toBe("072_org_reference_short_codes.sql");
    expect(migrations.at(-25)).toBe("073_test_fixture_personel_archive.sql");
    expect(migrations.at(-24)).toBe("074_qr_attendance_correction_and_inbox.sql");
    expect(migrations.at(-23)).toBe("075_personel_account_activation.sql");
    expect(migrations.at(-22)).toBe("076_dis_kaynak_gecici_gorevlendirme.sql");
    expect(migrations.at(-21)).toBe("077_legacy_role_enum_shrink.sql");
    expect(migrations.at(-20)).toBe("078_personel_sicil_sequence.sql");
    expect(migrations.at(-19)).toBe("079_sirket_sube_hiyerarsisi.sql");
    expect(migrations.at(-18)).toBe("080_organizasyon_audit_owners.sql");
    expect(migrations.at(-17)).toBe("081_ik_personeli_rolu.sql");
    expect(migrations.at(-16)).toBe("082_user_erisim_degisiklik_auditleri.sql");
    expect(migrations.at(-15)).toBe("083_personel_organizasyon_degisiklik_auditleri.sql");
    expect(migrations.at(-14)).toBe("084_gunluk_bildirim_tamamlama_header_summary.sql");
    expect(migrations.at(-13)).toBe("085_gunluk_bildirim_duzeltme_auditleri.sql");
    expect(migrations.at(-12)).toBe("086_personel_historical_exit_date_correction_auditleri.sql");
    expect(migrations.at(-11)).toBe("087_sube_muhasebe_yetkilileri.sql");
    expect(migrations.at(-10)).toBe("088_sube_sorumlu_yoneticiler.sql");
    expect(migrations.at(-9)).toBe("089_personel_legacy_account_activation.sql");
    expect(migrations.at(-8)).toBe("090_sgk_isveren_bildirim_donemi_owner.sql");
    expect(migrations.at(-7)).toBe("091_sgk_isveren_bildirim_donemi_reconcile.sql");
    expect(migrations.at(-6)).toBe("092_personel_self_service_product.sql");
    expect(migrations.at(-5)).toBe("093_attendance_anomaly_notification_dedupe.sql");
    expect(migrations.at(-4)).toBe("094_attendance_no_event_day.sql");
    expect(migrations.at(-3)).toBe("095_personel_cinsiyet.sql");
    expect(migrations.at(-2)).toBe("096_personel_bordro_okumalari.sql");
    expect(migrations.at(-1)).toBe("097_qr_attendance_location_audit.sql");

    const migration069 = read("api/migrations/069_personel_credential_onboarding.sql");
    const checksum069 = createHash("sha256").update(migration069).digest("hex");
    expect(checksum069).toMatch(/^[a-f0-9]{64}$/);
    expect(migration069).toContain("must_change_password");
    expect(migration069).not.toContain("DROP TABLE");

    const generator = read("scripts/generate-canonical-migration-bundle.mjs");
    expect(generator).not.toContain("069_personel_credential_onboarding");
    expect(generator).toContain("readdir(migrationsDirectory)");

    const runner = read("api/src/Database/MigrationRunner.php");
    expect(runner).not.toContain("069");
    expect(runner).not.toContain("068");

    const bundleTest = read("tests/unit/canonical-migration-bundle.source.test.ts");
    expect(bundleTest).toContain("'name' => '069_personel_credential_onboarding.sql'");
    expect(bundleTest).toContain("'name' => '070_offline_mutation_idempotency.sql'");
    expect(bundleTest).toContain("'name' => '071_org_hierarchy_authorization.sql'");
    expect(bundleTest).toContain("'name' => '072_org_reference_short_codes.sql'");
    expect(bundleTest).toContain("'name' => '073_test_fixture_personel_archive.sql'");
    expect(bundleTest).toContain("'name' => '074_qr_attendance_correction_and_inbox.sql'");
    expect(bundleTest).toContain("'name' => '075_personel_account_activation.sql'");
    expect(bundleTest).toContain("'name' => '076_dis_kaynak_gecici_gorevlendirme.sql'");
    expect(bundleTest).toContain("'name' => '077_legacy_role_enum_shrink.sql'");
    expect(bundleTest).toContain("'name' => '078_personel_sicil_sequence.sql'");
    expect(bundleTest).toContain("'name' => '079_sirket_sube_hiyerarsisi.sql'");
    expect(bundleTest).toContain("'name' => '080_organizasyon_audit_owners.sql'");
    expect(bundleTest).toContain("checksum069");
    expect(bundleTest).toContain("checksum070");
    expect(bundleTest).toContain("checksum071");
    expect(bundleTest).toContain("checksum072");
    expect(bundleTest).toContain("checksum073");
    expect(bundleTest).toContain("checksum074");
    expect(bundleTest).toContain("checksum075");
    expect(bundleTest).toContain("checksum076");
    expect(bundleTest).toContain("count($rows) !== 98");
    expect(bundleTest).toContain("rows[96]['version'] !== '096'");
    expect(bundleTest).toContain("rows[97]['version'] !== '097'");
    expect(bundleTest).toContain("'name' => '097_qr_attendance_location_audit.sql'");
    expect(bundleTest).toContain("rows[90]['version'] !== '090'");
    expect(bundleTest).toContain("rows[89]['version'] !== '089'");
    expect(bundleTest).toContain("rows[88]['version'] !== '088'");
    expect(bundleTest).toContain("rows[87]['version'] !== '087'");
    expect(bundleTest).toContain("rows[86]['version'] !== '086'");
    expect(bundleTest).toContain("rows[85]['version'] !== '085'");
    expect(bundleTest).toContain("rows[84]['version'] !== '084'");
    expect(bundleTest).toContain("rows[83]['version'] !== '083'");
    expect(bundleTest).toContain("rows[76]['version'] !== '076'");
    expect(bundleTest).toContain("rows[75]['version'] !== '075'");
    expect(bundleTest).toContain("rows[74]['version'] !== '074'");
    expect(bundleTest).toContain("rows[73]['version'] !== '073'");
    expect(bundleTest).toContain("rows[72]['version'] !== '072'");
    expect(bundleTest).toContain("rows[71]['version'] !== '071'");
    expect(bundleTest).toContain("rows[70]['version'] !== '070'");
  });
});
