import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = resolve(process.cwd());
const read = (relative: string) => readFileSync(resolve(root, relative), "utf8");

const migration = read(
  "api/migrations/086_personel_historical_exit_date_correction_auditleri.sql"
);
const service = read(
  "api/src/Services/Personel/PersonelHistoricalExitDateCorrectionAuditService.php"
);

describe("migration 086: personel_historical_exit_date_correction_auditleri", () => {
  it("creates append-only audit table additively", () => {
    expect(migration).toContain(
      "CREATE TABLE IF NOT EXISTS personel_historical_exit_date_correction_auditleri"
    );
    expect(migration).toContain("operation_type");
    expect(migration).toContain("mutation_id");
    expect(migration).toContain("old_baslangic_tarihi");
    expect(migration).toContain("old_bitis_tarihi");
    expect(migration).toContain("new_baslangic_tarihi");
    expect(migration).toContain("new_bitis_tarihi");
    expect(migration).toContain("old_aciklama");
    expect(migration).toContain("actor_user_id");
    expect(migration).toContain("PACK086_BLOCKER");
    expect(migration).toContain("trg_phedca_no_update");
    expect(migration).toContain("trg_phedca_no_delete");
  });

  it("writes no business data / no drop table", () => {
    const statements = migration.replace(/--[^\n]*\n/g, "\n");
    expect(statements).not.toMatch(/\bINSERT\s+INTO\b/i);
    expect(statements).not.toMatch(/\bUPDATE\s+surecler\b/i);
    expect(statements).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(statements).not.toMatch(/\bDROP\s+TABLE\b/i);
  });
});

describe("historical exit correction audit write owner", () => {
  it("owns transactional append for correction provenance", () => {
    expect(service).toContain("class PersonelHistoricalExitDateCorrectionAuditService");
    expect(service).toContain("appendInTransaction");
    expect(service).toContain("hasTable");
    expect(service).toContain("personel_historical_exit_date_correction_auditleri");
  });
});
