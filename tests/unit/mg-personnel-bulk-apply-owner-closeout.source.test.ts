import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();

function read(path: string): string {
  return readFileSync(resolve(root, path), "utf8");
}

describe("MG personnel bulk apply owner closeout sources", () => {
  it("keeps strict create and adds authorized incomplete-create intent", () => {
    const incomplete = read("api/src/Services/Personel/PersonelIncompleteCreateService.php");
    const canonical = read("api/src/Services/Personel/PersonelCanonicalValidator.php");
    const controller = read("api/src/Controllers/PersonellerController.php");

    expect(incomplete).toContain("eksik_bilgi_ile_olustur");
    expect(incomplete).toContain("GENEL_YONETICI");
    expect(incomplete).toContain("SISTEM_YONETICISI");
    expect(incomplete).toContain("PERSONEL_INCOMPLETE_CREATE_FORBIDDEN");
    expect(canonical).toContain("normalizeAndValidateCreatePayload");
    expect(controller).toContain("PersonelIncompleteCreateService::hasIntent");
    expect(controller).toContain("PersonelIncompleteCreateService::normalizePayload");
  });

  it("completes bulk apply owner with canonical delegation (no 501 org skeleton)", () => {
    const apply = read("api/src/Services/Personel/PersonelLifecycleBulkApplyService.php");
    expect(apply).toContain("PersonelOrganizasyonDegisikligiService::apply");
    expect(apply).toContain("PersonelKaliciSubeDegisikligiService::apply");
    expect(apply).toContain("PersonelIstenAyrilmaService::applyInTransaction");
    expect(apply).toContain("PersonelLifecycleBulkMutationPlanner::planCreatePayload");
    expect(apply).toContain("canonical_ready");
    expect(apply).not.toContain("501, 'NOT_IMPLEMENTED'");
    expect(apply).toContain("DEPENDENCY_BLOCKED");
    expect(apply).toContain("deployed_sha");
  });

  it("enforces binding postcheck contract 14 create + 3 exit -> 153/146", () => {
    const postcheck = read("api/src/Services/Personel/PersonelLifecycleBulkPostcheck.php");
    const dryRun = read("api/src/Services/Personel/PersonelLifecycleBulkDryRunService.php");

    expect(postcheck).toContain("BINDING_CREATE_COUNT = 14");
    expect(postcheck).toContain("BINDING_EXIT_COUNT = 3");
    expect(postcheck).toContain("EXPECTED_TOTAL_AFTER = 153");
    expect(postcheck).toContain("EXPECTED_ACTIVE_AFTER = 146");
    expect(postcheck).toContain("POSTCHECK_CREATE_COUNT_MISMATCH");
    expect(dryRun).toContain("PersonelLifecycleBulkPostcheck::validateBindingContract");
  });

  it("extends completeness owner for sube, lokasyon, yonetici and pozisyon", () => {
    const completeness = read("api/src/Services/Personel/PersonelCompletenessService.php");
    expect(completeness).toContain("'key' => 'sube_id'");
    expect(completeness).toContain("'key' => 'calisma_lokasyonu_id'");
    expect(completeness).toContain("'key' => 'bagli_amir_id'");
    expect(completeness).toContain("'key' => 'pozisyon_id'");
    expect(completeness).toContain("pozisyon_id, 0) <= 0");
  });

  it("does not fabricate manager users and avoids TC in resolver paths", () => {
    const resolver = read("api/src/Services/Personel/PersonelLifecycleBulkReferenceResolver.php");
    expect(resolver).toContain("resolveBagliAmirUserId");
    expect(resolver).not.toContain("INSERT INTO users");
    expect(resolver).not.toContain("tc_kimlik");
  });

  it("keeps migration control plane at repo tip 083 unchanged", () => {
    const preflight = read("api/src/Database/MigrationPreflightReport.php");
    expect(preflight).toContain("'083' => '083_personel_organizasyon_degisiklik_auditleri.sql'");
    expect(preflight).not.toContain("'084'");
  });
});
