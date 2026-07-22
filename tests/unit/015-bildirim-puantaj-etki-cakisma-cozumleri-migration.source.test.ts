import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migrationPath = resolve(
  process.cwd(),
  "api/migrations/015_bildirim_puantaj_etki_cakisma_cozumleri.sql"
);
const migrationSource = readFileSync(migrationPath, "utf8");

describe("015_bildirim_puantaj_etki_cakisma_cozumleri migration source", () => {
  it("defines the conflict resolution audit table", () => {
    expect(migrationSource).toMatch(/CREATE TABLE IF NOT EXISTS bildirim_puantaj_etki_cakisma_cozumleri/);
    expect(migrationSource).toContain("ENGINE=InnoDB");
    expect(migrationSource).toContain("UNIQUE KEY uq_bpecc_aday (aday_id)");
    expect(migrationSource).toContain("snapshot_schema VARCHAR(32) NOT NULL DEFAULT 'S75_CONFLICT_RESOLUTION_V1'");
  });

  it("includes canonical columns and hash fields", () => {
    for (const column of [
      "aday_id INT UNSIGNED NOT NULL",
      "puantaj_id INT UNSIGNED NULL",
      "sube_id INT UNSIGNED NOT NULL",
      "personel_id INT UNSIGNED NOT NULL",
      "tarih DATE NOT NULL",
      "conflict_class VARCHAR(32) NOT NULL",
      "karar_turu VARCHAR(64) NOT NULL",
      "gerekce TEXT NOT NULL",
      "expected_puantaj_hash CHAR(64) NOT NULL",
      "request_hash CHAR(64) NOT NULL",
      "onceki_snapshot JSON NOT NULL",
      "sonraki_snapshot JSON NOT NULL",
      "sonuc_hash CHAR(64) NOT NULL",
      "karar_veren_user_id INT UNSIGNED NOT NULL",
      "karar_zamani DATETIME NOT NULL"
    ]) {
      expect(migrationSource).toContain(column);
    }
  });

  it("defines required foreign keys with RESTRICT semantics", () => {
    for (const fk of [
      "fk_bpecc_aday FOREIGN KEY (aday_id) REFERENCES onayli_bildirim_puantaj_etki_adaylari",
      "fk_bpecc_puantaj FOREIGN KEY (puantaj_id) REFERENCES gunluk_puantaj",
      "fk_bpecc_karar_veren FOREIGN KEY (karar_veren_user_id) REFERENCES users",
      "fk_bpecc_sube FOREIGN KEY (sube_id) REFERENCES subeler",
      "fk_bpecc_personel FOREIGN KEY (personel_id) REFERENCES personeller"
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
    expect(migrations.at(-1)).toBe("039_ubgt_gun_kapsami_tatil_takvimi.sql");
  });
});
