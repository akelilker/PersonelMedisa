import { readFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migrationPath = resolve(
  process.cwd(),
  "api/migrations/009_onayli_bildirim_puantaj_etki_adaylari.sql"
);
const migrationSource = readFileSync(migrationPath, "utf8");

const requiredColumns = [
  "genel_yonetici_bildirim_onayi_id INT UNSIGNED NOT NULL",
  "aylik_bildirim_onayi_id INT UNSIGNED NOT NULL",
  "gunluk_bildirim_id INT UNSIGNED NOT NULL",
  "sube_id INT UNSIGNED NOT NULL",
  "birim_amiri_user_id INT UNSIGNED NOT NULL",
  "ay CHAR(7) NOT NULL",
  "personel_id INT UNSIGNED NOT NULL",
  "tarih DATE NOT NULL",
  "bildirim_turu VARCHAR(32) NOT NULL",
  "bildirim_alt_tur VARCHAR(64) NULL",
  "bildirim_dakika INT UNSIGNED NULL",
  "bildirim_aciklama TEXT NULL",
  "bildirim_created_at TIMESTAMP NOT NULL",
  "bildirim_updated_at TIMESTAMP NOT NULL",
  "etki_turu VARCHAR(64) NOT NULL",
  "etki_miktari INT UNSIGNED NULL",
  "etki_birimi VARCHAR(16) NULL",
  "state VARCHAR(32) NOT NULL",
  "conflict_code VARCHAR(64) NULL",
  "conflict_detail JSON NULL",
  "resmi_surec_id INT UNSIGNED NULL",
  "resmi_surec_turu VARCHAR(64) NULL",
  "resmi_surec_alt_tur VARCHAR(64) NULL",
  "ucretli_mi_snapshot TINYINT(1) NULL",
  "mevcut_puantaj_id INT UNSIGNED NULL",
  "source_priority VARCHAR(64) NOT NULL",
  "created_by INT UNSIGNED NOT NULL",
  "created_at TIMESTAMP NOT NULL",
  "updated_at TIMESTAMP NOT NULL"
];

describe("009_onayli_bildirim_puantaj_etki_adaylari migration source", () => {
  it("defines the onayli_bildirim_puantaj_etki_adaylari table", () => {
    expect(migrationSource).toMatch(/CREATE TABLE IF NOT EXISTS onayli_bildirim_puantaj_etki_adaylari/);
  });

  it("uses utf8mb4 charset and unicode collation", () => {
    expect(migrationSource).toContain("SET NAMES utf8mb4");
    expect(migrationSource).toContain("utf8mb4_unicode_ci");
  });

  it("includes canonical explicit columns", () => {
    for (const column of requiredColumns) {
      expect(migrationSource).toContain(column);
    }
  });

  it("enforces unique gy onay + gunluk bildirim pair", () => {
    expect(migrationSource).toContain(
      "UNIQUE KEY uniq_obpea_gybo_gunluk (genel_yonetici_bildirim_onayi_id, gunluk_bildirim_id)"
    );
  });

  it("defines required foreign keys without cascade delete", () => {
    for (const fk of [
      "fk_obpea_gybo FOREIGN KEY (genel_yonetici_bildirim_onayi_id) REFERENCES genel_yonetici_bildirim_onaylari",
      "fk_obpea_aylik_onay FOREIGN KEY (aylik_bildirim_onayi_id) REFERENCES aylik_bildirim_onaylari",
      "fk_obpea_gunluk_bildirim FOREIGN KEY (gunluk_bildirim_id) REFERENCES gunluk_bildirimler",
      "fk_obpea_sube FOREIGN KEY (sube_id) REFERENCES subeler",
      "fk_obpea_birim_amiri FOREIGN KEY (birim_amiri_user_id) REFERENCES users",
      "fk_obpea_personel FOREIGN KEY (personel_id) REFERENCES personeller",
      "fk_obpea_resmi_surec FOREIGN KEY (resmi_surec_id) REFERENCES surecler",
      "fk_obpea_mevcut_puantaj FOREIGN KEY (mevcut_puantaj_id) REFERENCES gunluk_puantaj",
      "fk_obpea_created_by FOREIGN KEY (created_by) REFERENCES users"
    ]) {
      expect(migrationSource).toContain(fk);
    }
    expect(migrationSource.toLowerCase()).not.toContain("on delete cascade");
  });

  it("does not default state to HAZIR or mutate existing tables", () => {
    expect(migrationSource).not.toMatch(/state VARCHAR\(32\) NOT NULL DEFAULT 'HAZIR'/);
    expect(migrationSource).not.toMatch(/ALTER TABLE/);
    expect(migrationSource).not.toMatch(/^UPDATE /m);
    expect(migrationSource).not.toMatch(/^DELETE FROM/m);
    expect(migrationSource).not.toMatch(/^DROP /m);
    expect(migrationSource).not.toMatch(/^TRUNCATE /m);
  });
});
