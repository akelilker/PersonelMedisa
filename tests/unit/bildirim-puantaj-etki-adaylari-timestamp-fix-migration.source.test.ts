import { readFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migrationPath = resolve(
  process.cwd(),
  "api/migrations/010_bildirim_puantaj_etki_snapshot_zamanlarini_duzelt.sql"
);
const migration009Path = resolve(
  process.cwd(),
  "api/migrations/009_onayli_bildirim_puantaj_etki_adaylari.sql"
);
const migrationSource = readFileSync(migrationPath, "utf8");

describe("010_bildirim_puantaj_etki_snapshot_zamanlarini_duzelt migration source", () => {
  it("migration file exists", () => {
    expect(migrationSource.length).toBeGreaterThan(0);
  });

  it("sets utf8mb4 and utc timezone", () => {
    expect(migrationSource).toContain("SET NAMES utf8mb4");
    expect(migrationSource).toContain("SET time_zone = '+00:00'");
  });

  it("alters only onayli_bildirim_puantaj_etki_adaylari", () => {
    expect(migrationSource).toMatch(/ALTER TABLE onayli_bildirim_puantaj_etki_adaylari/);
    const alterMatches = migrationSource.match(/ALTER TABLE\s+(\w+)/g) ?? [];
    expect(alterMatches).toHaveLength(1);
    expect(alterMatches[0]).toBe("ALTER TABLE onayli_bildirim_puantaj_etki_adaylari");
  });

  it("modifies bildirim snapshot columns to DATETIME NOT NULL", () => {
    expect(migrationSource).toContain("MODIFY COLUMN bildirim_created_at DATETIME NOT NULL");
    expect(migrationSource).toContain("MODIFY COLUMN bildirim_updated_at DATETIME NOT NULL");
  });

  it("does not add implicit timestamp behavior", () => {
    expect(migrationSource.toUpperCase()).not.toMatch(/DEFAULT CURRENT_TIMESTAMP/i);
    expect(migrationSource.toUpperCase()).not.toMatch(/ON UPDATE CURRENT_TIMESTAMP/i);
    expect(migrationSource).not.toContain("0000-00-00 00:00:00");
  });

  it("does not mutate data or drop objects", () => {
    expect(migrationSource).not.toMatch(/^DROP /m);
    expect(migrationSource).not.toMatch(/^TRUNCATE /m);
    expect(migrationSource).not.toMatch(/^INSERT /m);
    expect(migrationSource).not.toMatch(/^UPDATE /m);
    expect(migrationSource).not.toMatch(/^DELETE /m);
  });

  it("does not modify other columns via CHANGE, ADD or DROP COLUMN", () => {
    for (const pattern of [/\bCHANGE\s+COLUMN\b/i, /\bADD\s+COLUMN\b/i, /\bDROP\s+COLUMN\b/i]) {
      expect(migrationSource).not.toMatch(pattern);
    }

    const modifyMatches = [...migrationSource.matchAll(/MODIFY COLUMN\s+(\w+)/g)].map((match) => match[1]);
    expect(modifyMatches).toEqual(["bildirim_created_at", "bildirim_updated_at"]);
  });

  it("does not change 009 migration source", () => {
    const diff = execFileSync("git", ["diff", "HEAD", "--", migration009Path], {
      encoding: "utf8"
    });
    expect(diff).toBe("");
  });
});
