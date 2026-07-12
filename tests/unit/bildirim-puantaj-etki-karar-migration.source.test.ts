import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migrationPath = resolve(
  process.cwd(),
  "api/migrations/011_bildirim_puantaj_etki_karar_altyapisi.sql"
);
const migrationSource = readFileSync(migrationPath, "utf8");

const REQUIRED_COLUMNS = [
  "karar_veren_user_id INT UNSIGNED NULL",
  "karar_zamani DATETIME NULL",
  "karar_gerekcesi TEXT NULL",
  "uygulanan_puantaj_id INT UNSIGNED NULL",
  "onceki_puantaj_snapshot JSON NULL",
  "sonraki_puantaj_snapshot JSON NULL",
  "uygulama_hash CHAR(64) NULL",
];

const FORBIDDEN_COLUMNS = ["karar_turu", "blok_nedeni"];

describe("S74-C1 migration 011 bildirim puantaj etki karar altyapisi", () => {
  it("adds exactly seven karar audit columns", () => {
    const addColumnMatches = migrationSource.match(/ADD COLUMN/g) ?? [];
    expect(addColumnMatches).toHaveLength(7);
    for (const column of REQUIRED_COLUMNS) {
      expect(migrationSource).toContain(column);
    }
  });

  it("does not add karar_turu or blok_nedeni", () => {
    for (const column of FORBIDDEN_COLUMNS) {
      expect(migrationSource).not.toContain(column);
    }
  });

  it("defines two RESTRICT foreign keys", () => {
    expect(migrationSource).toContain(
      "FOREIGN KEY (karar_veren_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT"
    );
    expect(migrationSource).toContain(
      "FOREIGN KEY (uygulanan_puantaj_id) REFERENCES gunluk_puantaj (id) ON DELETE RESTRICT ON UPDATE RESTRICT"
    );
    const fkMatches = migrationSource.match(/FOREIGN KEY/g) ?? [];
    expect(fkMatches).toHaveLength(2);
  });

  it("defines three karar indexes", () => {
    expect(migrationSource).toContain("idx_obpea_karar_veren (karar_veren_user_id)");
    expect(migrationSource).toContain("idx_obpea_karar_zamani (karar_zamani)");
    expect(migrationSource).toContain("idx_obpea_uygulanan_puantaj (uygulanan_puantaj_id)");
    const keyMatches = migrationSource.match(/ADD KEY/g) ?? [];
    expect(keyMatches).toHaveLength(3);
  });

  it("does not mutate existing data", () => {
    expect(migrationSource).not.toMatch(/^\s*INSERT\b/im);
    expect(migrationSource).not.toMatch(/^\s*UPDATE\b/im);
    expect(migrationSource).not.toMatch(/^\s*DELETE\b/im);
  });

  it("does not alter existing unique or snapshot columns from 009", () => {
    const migration009Path = resolve(
      process.cwd(),
      "api/migrations/009_onayli_bildirim_puantaj_etki_adaylari.sql"
    );
    const migration009Source = readFileSync(migration009Path, "utf8");
    expect(migration009Source).toContain("uniq_obpea_gybo_gunluk");
    expect(migration009Source).toContain("source_snapshot");
    expect(migration009Source).toContain("source_hash");
    expect(migration009Source).toContain("bildirim_created_at");
    expect(migration009Source).toContain("bildirim_updated_at");
    expect(migration009Source).toContain("state");
    expect(migrationSource).not.toContain("DROP");
    expect(migrationSource).not.toContain("MODIFY COLUMN");
  });
});
