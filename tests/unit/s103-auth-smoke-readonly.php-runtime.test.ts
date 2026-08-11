import { beforeAll, describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/S103AuthSmokeMysqlTestRunner.php");

describe("S103 AUTH_SMOKE_READONLY MariaDB acceptance", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 60_000);

  it("migration 041, authz, smoke-read, login scope, provisioning", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-s103-auth-smoke-mysql: OK");
  });

  it("second run stays green", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-s103-auth-smoke-mysql: OK");
  });
});

describe("S103 source contracts", () => {
  it("migration 041 AUTH_SMOKE_READONLY exists; tip is 043 after S87", () => {
    const migrations = readdirSync(resolve("api/migrations"))
      .filter((n) => n.endsWith(".sql"))
      .sort();
    expect(migrations.at(-1)).toBe("055_yillik_izin_hak_duzeltmeleri.sql");
    expect(migrations).toContain("041_auth_smoke_readonly_role.sql");
    expect(migrations).toContain("051_users_varsayilan_sube_id.sql");
    const sql = readFileSync("api/migrations/041_auth_smoke_readonly_role.sql", "utf8");
    expect(sql).toContain("AUTH_SMOKE_READONLY");
    expect(sql).toContain("PATRON");
    expect(sql).toMatch(/S103 yalnız role enum extension yapar/i);
    expect(sql).not.toMatch(/\bINSERT\b/i);
    expect(sql).not.toMatch(/\bDELETE\b/i);
    expect(sql).not.toMatch(/\bDROP\b/i);
    expect(sql).not.toMatch(/\bTRUNCATE\b/i);
    expect(existsSync(resolve("api/migrations/001_initial_schema.sql"))).toBe(true);
  });

  it("personeller list/detail assert permissions", () => {
    const src = readFileSync("api/src/Controllers/PersonellerController.php", "utf8");
    expect(src).toContain("RolePermissions::assertAny($user, [");
    expect(src).toContain("'personeller.view'");
    expect(src).toContain("'personeller.view.sube'");
    expect(src).toContain("RolePermissions::assert($user, 'personeller.detail.view')");
  });

  it("smoke endpoint and router exist", () => {
    expect(existsSync(resolve("api/src/Auth/AuthSmokeController.php"))).toBe(true);
    const controller = readFileSync("api/src/Auth/AuthSmokeController.php", "utf8");
    expect(controller).toContain("ops.auth_smoke.read");
    expect(controller).toContain("AUTH_SMOKE_SCOPE_INVALID");
    expect(controller).toContain("'scope_count' => 1");
    expect(controller).not.toMatch(/\$data\[['\"]username['\"]\]/);
    expect(controller).not.toContain("'ad_soyad'");
    expect(controller).not.toContain("'token'");
    const router = readFileSync("api/src/Router.php", "utf8");
    expect(router).toContain("/auth/smoke-read");
    expect(router).toContain("AuthSmokeController::smokeRead");
  });

  it("smoke script uses smoke-read not personeller after login", () => {
    const smoke = readFileSync("scripts/post-deploy-smoke.mjs", "utf8");
    expect(smoke).toContain("/api/auth/smoke-read");
    expect(smoke).toContain("no domain writes; no PII read");
    expect(smoke).not.toContain("/api/personeller?page=1&limit=5");
    expect(smoke).toContain("SMOKE_AUTH_USERNAME");
    expect(smoke).toContain("SMOKE_AUTH_PASSWORD");
  });

  it("management UI preserves and locks the technical role while editing", () => {
    const page = readFileSync("src/features/yonetim/pages/YonetimPaneliPage.tsx", "utf8");
    expect(page).toContain('currentRole === "AUTH_SMOKE_READONLY"');
    expect(page).toContain('selectOptions={roleOptions(kullaniciForm.rol)}');
    expect(page).toContain('disabled={kullaniciForm.rol === "AUTH_SMOKE_READONLY"}');
  });
});
