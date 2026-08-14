import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const root = process.cwd();

function read(path: string) {
  return readFileSync(resolve(root, path), "utf8");
}

describe("S97-D personel import reference pack source locks", () => {
  it("wires GET references.csv with create permission and no write verbs", () => {
    const router = read("api/src/Router.php");
    const controller = read("api/src/Controllers/PersonellerController.php");
    const endpoints = read("src/api/endpoints.ts");

    expect(endpoints).toContain('importReferences: "/personeller/import/references.csv"');
    expect(router).toContain("/personeller/import/references.csv");
    expect(router).toContain("PersonellerController::importReferencesCsv");
    expect(router).toMatch(
      /personeller\/import\/references\.csv[\s\S]{0,80}method === 'GET'/
    );
    expect(router).not.toMatch(/import\/references\.csv[\s\S]{0,120}method === '(POST|PUT|PATCH|DELETE)'/);
    expect(controller).toContain("importReferencesCsv");
    expect(controller).toContain("RolePermissions::assert($user, 'personeller.create')");
    expect(controller).toContain("PersonelImportReferenceCatalogService::buildExport");
    expect(controller).toContain("PersonelImportReferenceCatalogService::SHA_HEADER");
    expect(controller).toContain("ETag");
    expect(router).not.toMatch(/import\/references\.csv[\s\S]{0,40}POST/);

    const method = controller.slice(
      controller.indexOf("public static function importReferencesCsv"),
      controller.indexOf("public static function importDryRun")
    );
    expect(method.indexOf("RolePermissions::assert")).toBeLessThan(method.indexOf("Connection::get"));
    expect(method.indexOf("RolePermissions::assert")).toBeLessThan(
      method.indexOf("PersonelImportReferenceCatalogService::buildExport")
    );
  });

  it("keeps shared catalog owner and dry-run exact-match contract", () => {
    const catalog = read("api/src/Services/Personel/PersonelImportReferenceCatalogService.php");
    const dryRun = read("api/src/Services/Personel/PersonelImportDryRunService.php");
    const csv = read("api/src/Http/CsvResponse.php");

    expect(catalog).toContain("loadCatalogForDryRun");
    expect(catalog).toContain("resolveExactUnique");
    expect(catalog).toContain("PERSONEL_IMPORT_REFERANS_BELIRSIZ");
    expect(catalog).toContain("TUM_YETKILI_SUBELER");
    expect(catalog).toContain("OPEN_BRANCH_DEPARTMENT");
    expect(catalog).toContain("buildSemicolon");
    expect(catalog).toContain("EXPORT_USABILITY = DRY_RUN_RESOLUTION_RESULT");
    expect(catalog).not.toContain("loadSubeDepartmanPairsStrict");
    expect(catalog).not.toContain("isSubeDepartmanLinked");
    expect(catalog).not.toMatch(/FROM\s+personeller/i);
    expect(catalog).not.toMatch(/\bINSERT\b|\bUPDATE\b|\bDELETE\b/);
    expect(catalog).not.toMatch(/FROM\s+sube_departmanlar/i);
    expect(dryRun).toContain("PersonelImportReferenceCatalogService::loadCatalogForDryRun");
    expect(dryRun).toContain("PersonelImportReferenceCatalogService::resolveExactUnique");
    expect(dryRun).not.toContain("PersonelImportReferenceCatalogService::isSubeDepartmanLinked");
    expect(dryRun).not.toContain("PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI");
    expect(dryRun).not.toMatch(/private static function loadNameIndex/);
    expect(dryRun).not.toMatch(/private static function resolveExactUnique/);
    expect(csv).toContain("buildSemicolon");
    expect(csv).toContain("\\t");
    expect(csv).toContain("ltrim($text, ' ')");
  });

  it("gates UI download without mutating dry-run/apply state", () => {
    const modal = read("src/features/personeller/components/PersonelImportDryRunModal.tsx");
    const api = read("src/api/personeller.api.ts");

    expect(modal).toContain("Geçerli Referansları İndir");
    expect(modal).toContain("personel-import-references-download");
    expect(modal).toContain(
      "CSV’de şube, departman, görev ve personel tipi değerlerini referans dosyasında göründüğü şekilde yazın"
    );
    expect(modal).toContain("downloadPersonelImportReferencesCsv");
    expect(modal).toContain("referencesDownloadGuardRef");
    expect(api).toContain("downloadPersonelImportReferencesCsv");
    expect(api).toContain("personel-import-referanslari.csv");
  });

  it("keeps parent parity hermetic via frozen golden fixture", () => {
    const runner = read("tests/php/S97DPersonelImportReferenceMysqlTestRunner.php");
    const golden = JSON.parse(
      read("tests/fixtures/s97d/personel-import-dry-run-parent-f9fd2af.golden.json")
    );
    const runtime = read("tests/unit/s97d-personel-import-reference-mysql.php-runtime.test.ts");

    expect(runner).not.toMatch(/\bgit\s+show\b/);
    expect(runner).not.toMatch(/\bgit\s+fetch\b/);
    expect(runner).not.toMatch(/\bshell_exec\s*\(/);
    expect(runner).not.toMatch(/(?<![>-])\bexec\s*\(/);
    expect(runner).not.toMatch(/PersonelImportDryRunServiceParent/);
    expect(runner).toContain("personel-import-dry-run-parent-f9fd2af.golden.json");
    expect(runner).toContain("PARENT_PARITY_RUNTIME = HERMETIC");
    expect(runner).toContain("MANIFEST_PARITY_WITH_PARENT = EXACT");
    expect(runner).toContain("candidate_payload");
    expect(runner).toContain("template_sha256");
    expect(runner).not.toMatch(/process\.env\.CI|CI\s*===\s*['\"]true['\"]|skipIf.*CI/i);
    expect(runtime).not.toMatch(/skipIf|describe\.skip|it\.skip/);
    expect(golden.parent_sha).toBe("f9fd2af1390550a18ad4b8c89cd397c9724614d8");
    expect(golden.fixture_version).toBe(1);
    expect(String(golden.provenance)).toMatch(/parent commit/i);
    expect(String(golden.provenance)).toMatch(/not from current HEAD/i);
    expect(golden.input_fixture_id).toBe("s97d-parity-valid-row-v1");
    expect(golden.manifest_hash).toMatch(/^[a-f0-9]{64}$/);
    expect(golden.source_sha256).toMatch(/^[a-f0-9]{64}$/);
    expect(golden.template_sha256).toMatch(/^[a-f0-9]{64}$/);
    expect(golden.candidate_payload_sha256).toMatch(/^[a-f0-9]{64}$/);
  });
});
