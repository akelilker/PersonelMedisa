import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migrationPath = resolve(
  process.cwd(),
  "api/migrations/013_bildirim_puantaj_etki_manual_apply.sql"
);
const migrationSource = readFileSync(migrationPath, "utf8");

const REQUIRED_COLUMNS = [
  "uygulama_modu VARCHAR(16) NOT NULL DEFAULT 'OTOMATIK'",
  "manuel_karar_turu VARCHAR(64) NULL DEFAULT NULL",
  "manuel_karar_miktari INT UNSIGNED NULL DEFAULT NULL",
];

describe("S74-D1 migration 013 bildirim puantaj etki manual apply", () => {
  it("adds exactly three manual apply columns", () => {
    const addColumnMatches = migrationSource.match(/ADD COLUMN/g) ?? [];
    expect(addColumnMatches).toHaveLength(3);
    for (const column of REQUIRED_COLUMNS) {
      expect(migrationSource).toContain(column);
    }
  });

  it("does not mutate existing data or drop columns", () => {
    expect(migrationSource).not.toMatch(/^\s*INSERT\b/im);
    expect(migrationSource).not.toMatch(/^\s*UPDATE\b/im);
    expect(migrationSource).not.toMatch(/^\s*DELETE\b/im);
    expect(migrationSource).not.toContain("DROP");
  });
});
