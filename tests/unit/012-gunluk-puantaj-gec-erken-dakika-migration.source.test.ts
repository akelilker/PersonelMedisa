import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migrationPath = resolve(
  process.cwd(),
  "api/migrations/012_gunluk_puantaj_gec_erken_dakika.sql"
);
const migrationSource = readFileSync(migrationPath, "utf8");

describe("012_gunluk_puantaj_gec_erken_dakika migration source", () => {
  it("adds nullable unsigned dakika columns to gunluk_puantaj", () => {
    expect(migrationSource).toMatch(/ALTER TABLE gunluk_puantaj/);
    expect(migrationSource).toContain("gec_kalma_dakika INT UNSIGNED NULL");
    expect(migrationSource).toContain("erken_cikis_dakika INT UNSIGNED NULL");
  });

  it("adds the same dakika columns to puantaj_aylik_muhur_satirlari", () => {
    expect(migrationSource).toMatch(/ALTER TABLE puantaj_aylik_muhur_satirlari/);
    expect(migrationSource.match(/gec_kalma_dakika INT UNSIGNED NULL/g)?.length).toBe(2);
    expect(migrationSource.match(/erken_cikis_dakika INT UNSIGNED NULL/g)?.length).toBe(2);
  });

  it("is additive and does not drop or rewrite existing data", () => {
    expect(migrationSource).not.toMatch(/^DROP /m);
    expect(migrationSource).not.toMatch(/^DELETE FROM/m);
    expect(migrationSource).not.toMatch(/^TRUNCATE /m);
    expect(migrationSource).not.toMatch(/MODIFY COLUMN/i);
  });

  it("uses utf8mb4 session settings", () => {
    expect(migrationSource).toContain("SET NAMES utf8mb4");
    expect(migrationSource).toContain("SET time_zone = '+00:00'");
  });
});
