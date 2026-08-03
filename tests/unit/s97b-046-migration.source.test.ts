import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";

const MIGRATION = "api/migrations/046_personel_import_apply_owner.sql";

describe("S97-B migration 046 source locks", () => {
  it("is additive idempotent import-run audit without raw CSV/TC", () => {
    const sql = readFileSync(MIGRATION, "utf8");
    expect(sql).toContain("CREATE TABLE IF NOT EXISTS personel_import_runs");
    expect(sql).toContain("CREATE TABLE IF NOT EXISTS personel_import_run_satirlari");
    expect(sql).toContain("uq_pir_idempotency_key");
    expect(sql).toContain("chk_pir_source_sha256");
    expect(sql).toContain("chk_pir_manifest_hash");
    expect(sql).toContain("tc_kimlik_no_masked");
    expect(sql).not.toMatch(/\braw_csv\b/i);
    expect(sql).not.toMatch(/\btc_kimlik_no\b(?!_masked)/);
    expect(sql).not.toMatch(/\btc_sha256\b/);
    expect(sql).not.toMatch(/\bDROP TABLE\b/i);
    expect(sql).not.toMatch(/\bDELETE FROM personeller\b/i);
  });
});
