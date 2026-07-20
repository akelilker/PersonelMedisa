import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migrationPath = resolve(
  process.cwd(),
  "api/migrations/017_donem_kapanis_ve_etki_rapor_indexleri.sql"
);
const migrationSource = readFileSync(migrationPath, "utf8");

describe("017_donem_kapanis_ve_etki_rapor_indexleri migration source", () => {
  it("adds only additive indexes for preflight and report queries", () => {
    expect(migrationSource).toMatch(/ALTER TABLE onayli_bildirim_puantaj_etki_adaylari/);
    expect(migrationSource).toContain("idx_obpea_sube_ay_state (sube_id, ay, state)");
    expect(migrationSource).toContain("idx_obpea_sube_ay_conflict (sube_id, ay, conflict_code)");
    expect(migrationSource).toContain("idx_obpea_sube_ay_uygulama (sube_id, ay, uygulama_modu)");
    expect(migrationSource).toContain("idx_gp_personel_tarih_kontrol (personel_id, tarih, kontrol_durumu)");
    expect(migrationSource).toContain("idx_abo_sube_ay_amir (sube_id, ay, birim_amiri_user_id)");
    expect(migrationSource).toContain("idx_gybo_sube_ay_state (sube_id, ay, state)");
  });

  it("does not drop tables or mutate data", () => {
    expect(migrationSource).not.toMatch(/^\s*(?:DROP|DELETE|TRUNCATE|UPDATE|INSERT)\b/im);
    expect(migrationSource).not.toContain("CREATE TABLE");
  });

  it("remains migration 017 in the contiguous sequence", () => {
    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => /^\d{3}_.*\.sql$/.test(name))
      .sort();
    expect(migrations).toContain("017_donem_kapanis_ve_etki_rapor_indexleri.sql");
    expect(migrations.at(-1)).toBe("034_bordro_onay_ve_projection.sql");
  });
});
