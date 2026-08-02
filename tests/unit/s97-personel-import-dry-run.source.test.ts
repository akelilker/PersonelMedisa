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
    expect(service).toContain("mb_strtolower");
    expect(controller).toContain("RolePermissions::assert($user, 'personeller.create')");
    expect(exportReport).toMatch(/\[\=\+\\?\-@\]/);
  });
});
