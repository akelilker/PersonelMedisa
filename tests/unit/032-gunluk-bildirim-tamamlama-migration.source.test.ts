import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const migrationPath = resolve(
  process.cwd(),
  "api/migrations/032_gunluk_bildirim_tamamlama_ve_duplicate.sql"
);
const migrationSource = readFileSync(migrationPath, "utf8");
const controllerPath = resolve(process.cwd(), "api/src/Controllers/BildirimlerController.php");
const controllerSource = readFileSync(controllerPath, "utf8");

describe("032_gunluk_bildirim_tamamlama_ve_duplicate migration source", () => {
  it("creates gunluk_bildirim_tamamlamalari with unique sube/amir/tarih", () => {
    expect(migrationSource).toContain("CREATE TABLE IF NOT EXISTS gunluk_bildirim_tamamlamalari");
    expect(migrationSource).toContain("uniq_gbt_sube_amir_tarih");
    expect(migrationSource).toContain("SET NAMES utf8mb4");
  });

  it("adds open_duplicate_key generated column and unique index", () => {
    expect(migrationSource).toContain("open_duplicate_key");
    expect(migrationSource).toContain("uniq_gb_open_duplicate");
    expect(migrationSource).toContain("GENERATED ALWAYS AS");
  });

  it("is additive and does not drop existing data", () => {
    expect(migrationSource).not.toMatch(/^DROP /m);
    expect(migrationSource).not.toMatch(/^DELETE FROM/m);
    expect(migrationSource).not.toMatch(/^TRUNCATE /m);
  });
});

describe("BildirimlerController S81 source", () => {
  it("exposes gunlukOzet and completion endpoints", () => {
    expect(controllerSource).toContain("function gunlukOzet");
    expect(controllerSource).toContain("function gunlukTamamlamaGet");
    expect(controllerSource).toContain("function gunlukTamamlamaCreate");
  });

  it("uses enrichment JOIN without N+1 personel lookups", () => {
    expect(controllerSource).toContain("LEFT JOIN personeller p ON p.id = gb.personel_id");
    expect(controllerSource).toContain("LEFT JOIN departmanlar d ON d.id = gb.departman_id");
    expect(controllerSource).toContain("LEFT JOIN gorevler g ON g.id = p.gorev_id");
    expect(controllerSource).toContain("LEFT JOIN subeler s ON s.id = gb.sube_id");
    expect(controllerSource).toContain("personel_ad_soyad");
  });

  it("guards duplicate create with Turkish conflict message", () => {
    expect(controllerSource).toContain("Bu personel/tarih/olay için açık bildirim zaten var.");
    expect(controllerSource).toContain("Bu personel sizin sorumluluk kapsamınızda değil.");
  });
});
