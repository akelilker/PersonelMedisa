import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readdirSync, readFileSync } from "node:fs";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PuantajDonemReopen044MigrationMysqlTestRunner.php");
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/044_puantaj_aylik_muhur_revision_reopen.sql"),
  "utf8"
);

describe("S87 seal revision 044 MariaDB migration", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks additive revision/reopen schema invariants in source", () => {
    expect(migrationSource).toContain("revision_no");
    expect(migrationSource).toContain("aktif_muhur");
    expect(migrationSource).toContain("uq_pam_sube_donem_revision");
    expect(migrationSource).toContain("uq_pam_aktif_muhur");
    expect(migrationSource).toContain("puantaj_donem_reopen_talepleri");
    expect(migrationSource).toContain("puantaj_donem_reopen_auditleri");
    expect(migrationSource).not.toMatch(/\bDROP\s+TABLE\b/i);
    expect(migrationSource).not.toMatch(/\bDELETE\s+FROM\b/i);

    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations[0]).toBe("001_initial_schema.sql");
    expect(migrations.at(-1)).toBe("046_personel_import_apply_owner.sql");
  });

  it("applies tip 044 twice idempotently and preserves revision-1 seals", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("PuantajDonemReopen044MigrationMysqlTestRunner: ALL PASS");
    expect(result.stdout).toContain("[PASS] backfill revision_no=1");
    expect(result.stdout).toContain("[PASS] reopen talepleri table");
  });
});
