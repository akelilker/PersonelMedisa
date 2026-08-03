import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const root = process.cwd();

function read(path: string) {
  return readFileSync(resolve(root, path), "utf8");
}

describe("S97-B personel import apply source locks", () => {
  it("keeps apply endpoint and durable idempotency owner", () => {
    const endpoints = read("src/api/endpoints.ts");
    const router = read("api/src/Router.php");
    const apply = read("api/src/Services/Personel/PersonelImportApplyService.php");
    const migration = read("api/migrations/046_personel_import_apply_owner.sql");
    const permissionsPhp = read("api/src/Auth/RolePermissions.php");
    const permissionsTs = read("src/lib/authorization/role-permissions.ts");
    const modal = read("src/features/personeller/components/PersonelImportDryRunModal.tsx");

    expect(endpoints).toContain('importApply: "/personeller/import/apply"');
    expect(router).toContain("/personeller/import/apply");
    expect(apply).toContain("PERSONEL_IMPORT_MANIFEST_CHANGED");
    expect(apply).toContain("PERSONEL_IMPORT_IDEMPOTENCY_CONFLICT");
    expect(apply).toContain("SCHEMA_NOT_READY");
    expect(apply).toContain("claimIdempotencyInsideTx");
    expect(apply).toContain("uq_pir_idempotency_key");
    expect(apply).not.toContain("tc_sha256");
    expect(migration).toContain("personel_import_runs");
    expect(migration).toContain("uq_pir_idempotency_key");
    expect(migration).not.toMatch(/tc_kimlik_no\b(?!_masked)/);
    expect(migration).not.toMatch(/\btc_sha256\b/);
    expect(permissionsPhp).toContain("personeller.import.apply");
    expect(permissionsTs).toContain("personeller.import.apply");
    expect(modal).toContain("Personelleri Sisteme Aktar");
    expect(modal).toContain(
      "Bu işlem yalnız personel ana kayıtlarını oluşturur. Ücret, bordro kapsamı ve SGK statüsü oluşturmaz."
    );
    expect(modal).toContain("PERSONEL_IMPORT_ONAYLIYORUM");
  });

  it("reuses shared create owner and dry-run manifest fields", () => {
    const controller = read("api/src/Controllers/PersonellerController.php");
    const dryRun = read("api/src/Services/Personel/PersonelImportDryRunService.php");
    const create = read("api/src/Services/Personel/PersonelCreateService.php");

    expect(controller).toContain("PersonelCreateService::insertPersonel");
    expect(controller).toContain("importApply");
    expect(controller).toContain("RolePermissions::assert($user, 'personeller.import.apply')");
    expect(dryRun).toContain("manifest_hash");
    expect(dryRun).toContain("source_sha256");
    expect(dryRun).toContain("can_apply");
    expect(dryRun).toContain("SCHEMA_VERSION");
    expect(dryRun).toContain("PARSER_VERSION");
    expect(dryRun).toContain("buildRowHash");
    expect(dryRun).not.toContain("tc_sha256");
    expect(create).toContain("insertPersonel");
    expect(create).not.toContain("PersonelUcretService");
  });
});
