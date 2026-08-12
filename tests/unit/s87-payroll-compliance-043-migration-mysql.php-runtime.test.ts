import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readdirSync, readFileSync } from "node:fs";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PayrollCompliance043MigrationMysqlTestRunner.php");
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/043_payroll_compliance_critical_gaps.sql"),
  "utf8"
);

describe("S87 payroll compliance 043 MariaDB migration", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks additive 043 schema invariants in source", () => {
    expect(migrationSource).toContain("talep_tarihi");
    expect(migrationSource).toContain("imzali_talep_belge_id");
    expect(migrationSource).toContain("sisteme_giren_kullanici_id");
    expect(migrationSource).toContain("sisteme_giris_zamani");
    expect(migrationSource).toContain("yillik_fazla_calisma_kilitleri");
    expect(migrationSource).toContain("REFERENCES surecler");
    expect(migrationSource).toContain("ON DELETE RESTRICT");
    expect(migrationSource).not.toMatch(/\bDROP\s+TABLE\b/i);
    expect(migrationSource).not.toMatch(/\bDELETE\s+FROM\b/i);

    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations[0]).toBe("001_initial_schema.sql");
    expect(migrations.at(-1)).toBe("059_retention_physical_destruction_execution.sql");
    expect(migrations).toContain("042_sgk_resmi_kaynakli_kisitli_katalog.sql");
  });

  it("applies 001-043, re-applies 043 idempotently, asserts FK/columns/lock table", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-payroll-compliance-043-migration-mysql: OK");
    expect(result.stdout).toContain("[PASS] 043 ikinci apply idempotent");
    expect(result.stdout).toContain("[PASS] 043 tercih kanit kolonlari");
    expect(result.stdout).toContain("[PASS] fk_fcot_imzali_belge RESTRICT");
    expect(result.stdout).toContain("[PASS] imzali belge FK → surecler");
    expect(result.stdout).toContain("[PASS] yillik_fazla_calisma_kilitleri mevcut");
    expect(result.stdout).toContain("[PASS] 042 SGK gecerlilik_tarih_durumu korunur");
  });
});
