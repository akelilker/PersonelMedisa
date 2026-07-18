import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migrationPath = resolve(process.cwd(), "api/migrations/016_donem_kapanis_auditleri.sql");
const migrationSource = readFileSync(migrationPath, "utf8");

describe("016_donem_kapanis_auditleri migration source", () => {
  it("defines the append-only close audit table", () => {
    expect(migrationSource).toMatch(/CREATE TABLE IF NOT EXISTS donem_kapanis_auditleri/);
    expect(migrationSource).toContain("ENGINE=InnoDB");
    expect(migrationSource).toContain("UNIQUE KEY uq_dka_idempotency (sube_id, yil, ay, action, request_hash)");
  });

  it("includes canonical audit columns and hash fields", () => {
    for (const column of [
      "sube_id INT UNSIGNED NOT NULL",
      "yil SMALLINT UNSIGNED NOT NULL",
      "ay TINYINT UNSIGNED NOT NULL",
      "action VARCHAR(40) NOT NULL",
      "result_state VARCHAR(40) NOT NULL",
      "muhur_id INT UNSIGNED NULL",
      "blocker_count INT UNSIGNED NOT NULL DEFAULT 0",
      "warning_count INT UNSIGNED NOT NULL DEFAULT 0",
      "preflight_hash CHAR(64) NOT NULL",
      "request_hash CHAR(64) NOT NULL",
      "result_hash CHAR(64) NOT NULL",
      "preflight_snapshot JSON NOT NULL",
      "actor_user_id INT UNSIGNED NOT NULL"
    ]) {
      expect(migrationSource).toContain(column);
    }
  });

  it("defines required foreign keys without cascade delete", () => {
    for (const fk of [
      "fk_dka_sube FOREIGN KEY (sube_id) REFERENCES subeler",
      "fk_dka_muhur FOREIGN KEY (muhur_id) REFERENCES puantaj_aylik_muhurleri",
      "fk_dka_actor FOREIGN KEY (actor_user_id) REFERENCES users"
    ]) {
      expect(migrationSource).toContain(fk);
    }
    expect(migrationSource.toLowerCase()).not.toContain("on delete cascade");
  });

  it("is additive and does not mutate existing business rows", () => {
    expect(migrationSource).not.toMatch(/^\s*(?:DROP|DELETE|TRUNCATE|UPDATE)\b/im);
    expect(migrationSource).not.toMatch(/backfill/i);
  });

  it("is part of the contiguous migration sequence", () => {
    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => /^\d{3}_.*\.sql$/.test(name))
      .sort();
    expect(migrations).toContain("016_donem_kapanis_auditleri.sql");
    expect(migrations.at(-1)).toBe("026_zimmetler.sql");
  });
});
