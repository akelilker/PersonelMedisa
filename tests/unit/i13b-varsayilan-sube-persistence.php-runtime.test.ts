import { beforeAll, describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const migrationRunner = resolve(
  process.cwd(),
  "tests/php/I13B051VarsayilanSubeMigrationMysqlTestRunner.php"
);
const persistenceRunner = resolve(
  process.cwd(),
  "tests/php/I13BVarsayilanSubePersistenceMysqlTestRunner.php"
);

describe("I13-B varsayilan_sube_id MariaDB acceptance", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks migration 051 + owner source contracts", () => {
    const migration = readFileSync("api/migrations/051_users_varsayilan_sube_id.sql", "utf8");
    expect(migration).toContain("ON DELETE SET NULL");
    expect(migration).toContain("varsayilan_sube_id");
    expect(migration).toContain("idx_users_varsayilan_sube_id");
    expect(migration).toContain("fk_users_varsayilan_sube");
    expect(migration).not.toMatch(/UPDATE\s+users\s+SET\s+varsayilan/i);
    expect(migration).not.toMatch(/\bDROP\s+TABLE\b/i);

    expect(existsSync(resolve("api/src/Database/UsersSchema.php"))).toBe(true);

    const yonetim = readFileSync("api/src/Controllers/YonetimController.php", "utf8");
    expect(yonetim).toContain("SCHEMA_NOT_READY");
    expect(yonetim).toContain("varsayilan_sube_id");

    const login = readFileSync("api/src/Auth/LoginController.php", "utf8");
    expect(login).toContain("UsersSchema");
    expect(login).toContain("resolveInitialActiveSubeId");

    const scope = readFileSync("api/src/Scope/SubeScope.php", "utf8");
    expect(scope).toMatch(/function\s+resolveInitialActiveSubeId\s*\(\s*array\s+\$subeIds\s*,\s*\$preferredSubeId/);

    const migrations = readdirSync(resolve("api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations.at(-1)).toBe("058_qr_puantaj_candidate_decision_ledger.sql");
  });

  it("applies 051 idempotently and asserts FK/ON DELETE SET NULL", () => {
    const result = runPhpMysqlRunner(migrationRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-i13b-051-varsayilan-sube-migration-mysql: OK");
    expect(result.stdout).toContain("[PASS] 051 ikinci apply idempotent");
    expect(result.stdout).toContain("[PASS] FK DELETE_RULE=SET NULL");
  });

  it("persists create/update/login + schema-absent compat", () => {
    const result = runPhpMysqlRunner(persistenceRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-i13b-varsayilan-sube-persistence-mysql: OK");
    expect(result.stdout).toContain("[PASS] F active_sube_id=2");
    expect(result.stdout).toContain("[PASS] I defense active=1 not 2");
    expect(result.stdout).toContain("[PASS] J SCHEMA_NOT_READY");
  });
});
