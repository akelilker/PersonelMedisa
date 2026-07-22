import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readdirSync, readFileSync } from "node:fs";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelBelgeMigrationMysqlTestRunner.php");
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/038_personel_belge_yonetimi.sql"),
  "utf8"
);

describe("S86 personel belge 038 MariaDB migration", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks additive 038 schema invariants in source", () => {
    expect(migrationSource).toContain("personel_belge_dosya_surumleri");
    expect(migrationSource).toContain("personel_belge_auditleri");
    expect(migrationSource).toContain("ON DELETE RESTRICT");
    expect(migrationSource).not.toContain("ON DELETE CASCADE");
    expect(migrationSource).toContain("uq_pbd_tek_aktif");
    expect(migrationSource).toContain("aktif_surec_key");
    expect(migrationSource).toMatch(/CREATE TABLE IF NOT EXISTS personel_belge_dosya_surumleri/);
    expect(migrationSource).toMatch(/CREATE TABLE IF NOT EXISTS personel_belge_auditleri/);

    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations[0]).toBe("001_initial_schema.sql");
    expect(migrations.at(-1)).toBe("038_personel_belge_yonetimi.sql");
    expect(migrations.some((name) => name.startsWith("039_"))).toBe(false);
  });

  it("applies 001-038, re-applies 038 idempotently, asserts FK/CHECK/empty", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-belge-migration-mysql: OK");
    expect(result.stdout).toContain("[PASS] 038 ikinci apply idempotent");
    expect(result.stdout).toContain("[PASS] surum FK ON DELETE RESTRICT");
    expect(result.stdout).toContain("[PASS] audit FK ON DELETE RESTRICT");
    expect(result.stdout).toContain("[PASS] surum baslangic satiri 0");
    expect(result.stdout).toContain("[PASS] 039 yok");
  });
});
