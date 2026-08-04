import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const root = process.cwd();

function read(path: string) {
  return readFileSync(resolve(root, path), "utf8");
}

describe("S97 personel import dry-run source locks", () => {
  it("keeps dry-run entry and forbids commit endpoint alias", () => {
    const modal = read("src/features/personeller/components/PersonelImportDryRunModal.tsx");
    const page = read("src/features/personeller/pages/PersonellerPage.tsx");
    const endpoints = read("src/api/endpoints.ts");

    expect(page).toContain("Toplu Personel Hazırlama");
    expect(page).toContain("personeller-import-dry-run-open");
    expect(page).toContain("PersonelImportDryRunModal");
    expect(modal).toContain("Bu aşama yalnız doğrulama yapar. Personel, ücret veya bordro kaydı oluşturmaz.");
    expect(modal).toContain("personel-import-dry-run-run");
    expect(modal).toContain("tc_kimlik_no_masked");
    expect(endpoints).toContain('importDryRun: "/personeller/import/dry-run"');
    expect(endpoints).toContain('importTemplate: "/personeller/import/template.csv"');
    expect(endpoints).toContain('importReferences: "/personeller/import/references.csv"');
    expect(endpoints).not.toContain("/personeller/import/commit");
  });

  it("masks TC helper and rejects wage columns in service", () => {
    const validator = read("api/src/Services/Personel/PersonelCanonicalValidator.php");
    const service = read("api/src/Services/Personel/PersonelImportDryRunService.php");
    const controller = read("api/src/Controllers/PersonellerController.php");
    const exportReport = read("src/reports/export-report.ts");

    expect(validator).toContain("maskTcKimlikNo");
    expect(validator).toContain("substr($digits, 0, 3)");
    expect(service).toContain("PERSONEL_IMPORT_UCRET_KARARI_BEKLENIYOR");
    expect(service).toContain("'maas_tutari'");
    expect(service).toContain("'ucret_modeli'");
    expect(service).toContain("PERSONEL_IMPORT_SATIR_KOLON_UYUMSUZ");
    expect(service).toContain("PersonelImportReferenceCatalogService::loadCatalogForDryRun");
    expect(service).toContain("mb_strtolower");
    expect(controller).toContain("RolePermissions::assert($user, 'personeller.create')");
    expect(exportReport).toMatch(/\[\=\+\\?\-@\]/);
  });

  it("makes emergency-contact columns optional for initial import", () => {
    const service = read("api/src/Services/Personel/PersonelImportDryRunService.php");
    const validator = read("api/src/Services/Personel/PersonelCanonicalValidator.php");
    const migration = read("api/migrations/049_personel_acil_durum_nullable.sql");
    const panel = read("src/features/personeller/components/personel-dosya/PersonelKartPanelGenelBilgiler.tsx");

    expect(service).toContain("'acil_durum_kisi'");
    expect(service).toContain("'acil_durum_telefon'");
    expect(service).toMatch(/OPTIONAL_COLUMNS[\s\S]*acil_durum_kisi[\s\S]*acil_durum_telefon/);
    expect(service).not.toMatch(
      /REQUIRED_COLUMNS\s*=\s*\[[^\]]*acil_durum_kisi[^\]]*acil_durum_telefon/
    );
    expect(validator).toContain("optionalTrimmedString($body, 'acil_durum_kisi')");
    expect(validator).toContain("optionalTrimmedString($body, 'acil_durum_telefon')");
    expect(validator).not.toContain("Acil durum kisi zorunludur.");
    expect(validator).not.toContain("Acil durum telefonu zorunludur.");
    expect(migration).toContain("MODIFY COLUMN acil_durum_kisi VARCHAR(120) NULL");
    expect(migration).toContain("MODIFY COLUMN acil_durum_telefon VARCHAR(32) NULL");
    expect(panel).toContain("Acil durum bilgisi eksik");
  });
});
