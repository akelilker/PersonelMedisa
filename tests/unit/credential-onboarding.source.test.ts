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

  it("migration 069 remains in chain; tip is 070 with bundle/runner parity", () => {
    const migrations = readdirSync(resolve("api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations.at(-3)).toBe("068_sgk_actor_identity_lifecycle_audit.sql");
    expect(migrations.at(-2)).toBe("069_personel_credential_onboarding.sql");
    expect(migrations.at(-1)).toBe("070_offline_mutation_idempotency.sql");

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
    expect(bundleTest).toContain("checksum069");
    expect(bundleTest).toContain("checksum070");
    expect(bundleTest).toContain("count($rows) !== 71");
    expect(bundleTest).toContain("rows[70]['version'] !== '070'");
    expect(bundleTest).toContain("rows[69]['version'] !== '069'");
  });
});
