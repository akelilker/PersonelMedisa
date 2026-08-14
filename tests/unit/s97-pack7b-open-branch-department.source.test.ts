import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const root = process.cwd();

function read(path: string) {
  return readFileSync(resolve(root, path), "utf8");
}

describe("Pack7B open branch-department import contract", () => {
  it("removes import-only şube↔departman pair gate and keeps SubeScope", () => {
    const dryRun = read("api/src/Services/Personel/PersonelImportDryRunService.php");
    const apply = read("api/src/Services/Personel/PersonelImportApplyService.php");
    const catalog = read("api/src/Services/Personel/PersonelImportReferenceCatalogService.php");
    const create = read("api/src/Services/Personel/PersonelCreateService.php");
    const controller = read("api/src/Controllers/PersonellerController.php");
    const subeScope = read("api/src/Scope/SubeScope.php");

    expect(dryRun).toContain("PERSONEL_IMPORT_SUBE_SCOPE_IHLALI");
    expect(dryRun).toContain("SubeScope::allowedSubeIds");
    expect(dryRun).not.toContain("PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI");
    expect(dryRun).not.toContain("isSubeDepartmanLinked");
    expect(apply).toContain("PersonelImportDryRunService::analyze");
    expect(catalog).toContain("OPEN_BRANCH_DEPARTMENT");
    expect(catalog).toContain("TUM_YETKILI_SUBELER");
    expect(catalog).not.toContain("isSubeDepartmanLinked");
    expect(catalog).not.toContain("loadSubeDepartmanPairsStrict");
    expect(catalog).not.toMatch(/FROM\s+sube_departmanlar/i);
    expect(create).not.toMatch(/sube_departmanlar/i);
    expect(controller).toContain("PersonelCreateService::validateCreateReferences");
    expect(controller).toContain("existsActiveRecord($pdo, 'departmanlar'");
    expect(controller).not.toMatch(/FROM\s+sube_departmanlar/i);
    expect(subeScope).toContain("function allowedSubeIds");
  });

  it("keeps Yönetim matrix config and Revizyon department-scope join unchanged", () => {
    const yonetim = read("api/src/Controllers/YonetimController.php");
    const revizyon = read("api/src/Controllers/RevizyonController.php");
    const historical = read("docs/guncel/123-personnel-import-data-readiness.md");

    expect(yonetim).toContain("function replaceSubeDepartmanlar");
    expect(yonetim).toContain("DELETE FROM sube_departmanlar WHERE sube_id = :sube_id");
    expect(yonetim).toContain("INSERT INTO sube_departmanlar (sube_id, departman_id)");
    expect(revizyon).toContain("INNER JOIN sube_departmanlar sd ON sd.sube_id = us.sube_id");
    expect(historical).toContain("PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI");
    expect(historical).toContain("| DRY_RUN_VALID | **40** |");
    expect(historical).toContain("| PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI | 58 |");
  });
});
