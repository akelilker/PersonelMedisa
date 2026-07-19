import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const serviceSource = readFileSync(resolve(root, "api/src/Services/PuantajDonemKilidiService.php"), "utf8");
const adayController = readFileSync(resolve(root, "api/src/Controllers/BildirimPuantajEtkiAdaylariController.php"), "utf8");
const puantajController = readFileSync(resolve(root, "api/src/Controllers/PuantajController.php"), "utf8");
const migration = readFileSync(resolve(root, "api/migrations/014_puantaj_donem_kilitleri.sql"), "utf8");

function methodBlock(source: string, method: string, nextMethod: string) {
  const start = source.indexOf(`public static function ${method}(`);
  const end = source.indexOf(`public static function ${nextMethod}(`, start + 1);
  expect(start).toBeGreaterThanOrEqual(0);
  expect(end).toBeGreaterThan(start);
  return source.slice(start, end);
}

describe("S74-D1/D3R puantaj period lock source contract", () => {
  it("creates one additive guard table keyed by sube, yil and ay", () => {
    expect(migration).toMatch(/CREATE TABLE IF NOT EXISTS puantaj_donem_kilitleri/);
    expect(migration).toMatch(/ENGINE=InnoDB/);
    expect(migration).toMatch(/PRIMARY KEY \(sube_id, yil, ay\)/);
    expect(migration).toMatch(/yil SMALLINT UNSIGNED NOT NULL/);
    expect(migration).toMatch(/ay TINYINT UNSIGNED NOT NULL/);
    expect(migration).toMatch(/CHECK \(yil BETWEEN 2000 AND 2100\)/);
    expect(migration).toMatch(/CHECK \(ay BETWEEN 1 AND 12\)/);
    expect(migration).toMatch(/created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP/);
    expect(migration).toMatch(/updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP/);
    expect(migration).toMatch(/FOREIGN KEY \(sube_id\) REFERENCES subeler \(id\)/);
    expect(migration).not.toMatch(/^\s*(?:DROP|DELETE|TRUNCATE|UPDATE)\b/im);
    expect(migration).not.toMatch(/\b(?:gunluk_puantaj|puantaj_aylik_muhurleri|puantaj_aylik_muhur_satirlari)\b/i);
  });

  it("keeps one 017 migration in the contiguous sequence", () => {
    const migrations = readdirSync(resolve(root, "api/migrations")).filter((name) => /^\d{3}_.*\.sql$/.test(name)).sort();
    expect(migrations.at(-1)).toBe("029_serbest_zaman_events.sql");
    expect(migrations.filter((name) => name.startsWith("017_"))).toHaveLength(1);
  });

  it("requires a caller transaction and locks the canonical tuple row FOR UPDATE", () => {
    expect(serviceSource).toContain("$pdo->inTransaction()");
    expect(serviceSource).toContain("'INSERT'");
    expect(serviceSource).toContain("isDuplicateKey");
    expect(serviceSource).toContain("1062");
    expect(serviceSource).toContain("FOR UPDATE");
    expect(serviceSource).not.toContain("$pdo->commit()");
    expect(serviceSource).not.toContain("$pdo->rollBack()");
  });

  it("acquires period lock before candidate row lock in automatic and manual apply", () => {
    const apply = methodBlock(adayController, "apply", "manualApply");
    const manual = methodBlock(adayController, "manualApply", "detail");
    for (const block of [apply, manual]) {
      expect(block.indexOf("PuantajDonemKilidiService::acquireForDate")).toBeGreaterThan(block.indexOf("$pdo->beginTransaction()"));
      expect(block.indexOf("PuantajDonemKilidiService::isSealed")).toBeGreaterThan(block.indexOf("PuantajDonemKilidiService::acquireForDate"));
      expect(block.indexOf("PuantajDonemKilidiService::isSealed")).toBeLessThan(block.indexOf("fetchAdayById($pdo, $adayId, true)"));
      expect(block.indexOf("PuantajDonemKilidiService::acquireForDate")).toBeLessThan(block.indexOf("fetchAdayById($pdo, $adayId, true)"));
    }
  });

  it("acquires the same period lock before generate source work and monthly seal snapshot", () => {
    const generateStart = adayController.indexOf("public static function generate(");
    const generateEnd = adayController.indexOf("private static function validateDismissExpectedState", generateStart);
    expect(generateStart).toBeGreaterThanOrEqual(0);
    expect(generateEnd).toBeGreaterThan(generateStart);
    const generate = adayController.slice(generateStart, generateEnd);
    const sealStart = puantajController.indexOf("public static function muhurleAylik(");
    const sealEnd = puantajController.indexOf("private static function getConnection", sealStart);
    expect(sealStart).toBeGreaterThanOrEqual(0);
    expect(sealEnd).toBeGreaterThan(sealStart);
    const seal = puantajController.slice(sealStart, sealEnd);
    expect(generate.indexOf("PuantajDonemKilidiService::acquire")).toBeLessThan(generate.indexOf("fetchEligibleSources"));
    expect(generate.indexOf("PuantajDonemKilidiService::isSealed")).toBeLessThan(generate.indexOf("fetchGyById($pdo, $gyId, true)"));
    expect(seal.indexOf("PuantajDonemKilidiService::acquire")).toBeLessThan(seal.indexOf("selectRowsForSeal"));
    expect(seal.indexOf("PuantajDonemKilidiService::acquire")).toBeLessThan(seal.indexOf("INSERT INTO puantaj_aylik_muhurleri"));
  });

  it("serializes direct daily puantaj upsert with the same period lock", () => {
    const upsert = methodBlock(puantajController, "upsert", "muhurleAylik");
    expect(upsert.indexOf("$pdo->beginTransaction()")).toBeGreaterThanOrEqual(0);
    expect(upsert.indexOf("PuantajDonemKilidiService::acquireForDate")).toBeLessThan(upsert.indexOf("PuantajDonemKilidiService::isSealed"));
    expect(upsert.indexOf("PuantajDonemKilidiService::isSealed")).toBeLessThan(upsert.indexOf("findPuantajRow"));
    expect(upsert).toContain("$pdo->commit()");
    expect(upsert).toContain("$pdo->rollBack()");
  });
});
