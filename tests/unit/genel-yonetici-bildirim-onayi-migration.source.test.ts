import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migrationPath = resolve(
  process.cwd(),
  "api/migrations/008_genel_yonetici_bildirim_onaylari.sql"
);
const migrationSource = readFileSync(migrationPath, "utf8");

describe("008_genel_yonetici_bildirim_onaylari migration source", () => {
  it("defines the genel_yonetici_bildirim_onaylari table", () => {
    expect(migrationSource).toMatch(/CREATE TABLE IF NOT EXISTS genel_yonetici_bildirim_onaylari/);
  });

  it("uses utf8mb4 charset and unicode collation", () => {
    expect(migrationSource).toContain("SET NAMES utf8mb4");
    expect(migrationSource).toContain("utf8mb4_unicode_ci");
  });

  it("includes required columns and audit fields", () => {
    for (const column of [
      "id INT UNSIGNED",
      "sube_id INT UNSIGNED NOT NULL",
      "birim_amiri_user_id INT UNSIGNED NOT NULL",
      "ay CHAR(7) NOT NULL",
      "aylik_bildirim_onayi_id INT UNSIGNED NOT NULL",
      "state VARCHAR(32) NOT NULL DEFAULT 'TAMAMLANDI'",
      "onaylayan_user_id INT UNSIGNED NOT NULL",
      "onaylandi_at TIMESTAMP NOT NULL",
      "aciklama TEXT NULL",
      "created_at TIMESTAMP NOT NULL",
      "updated_at TIMESTAMP NOT NULL"
    ]) {
      expect(migrationSource).toContain(column);
    }
  });

  it("links aylik_bildirim_onayi_id and enforces unique scope key", () => {
    expect(migrationSource).toContain(
      "CONSTRAINT fk_gybo_aylik_onay FOREIGN KEY (aylik_bildirim_onayi_id) REFERENCES aylik_bildirim_onaylari (id)"
    );
    expect(migrationSource).toContain(
      "UNIQUE KEY uniq_gybo_sube_amir_ay (sube_id, birim_amiri_user_id, ay)"
    );
  });

  it("does not add red/reopen states", () => {
    expect(migrationSource).not.toMatch(/BEKLIYOR|REDDEDILDI|DUZELTME_ISTENDI/);
  });
});
