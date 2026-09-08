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

  it("plans multi-axis rows once and applies them in one outer transaction", () => {
    const dryRun = read("api/src/Services/Personel/PersonelLifecycleBulkDryRunService.php");
    const apply = read("api/src/Services/Personel/PersonelLifecycleBulkApplyService.php");
    const org = read("api/src/Services/Personel/PersonelOrganizasyonDegisikligiService.php");
    const branch = read("api/src/Services/Personel/PersonelKaliciSubeDegisikligiService.php");

    expect(dryRun).toContain("'mode' => 'MULTI_AXIS'");
    expect(dryRun).toContain("resolveReferenceOrFail");
    expect(dryRun).toContain("resolveManagerOrFail");
    expect(dryRun).toContain("MISSING_MANAGER_PERSONNEL_REFERENCE");
    expect(dryRun).toContain("AMBIGUOUS_MANAGER_PERSONNEL_REFERENCE");
    expect(dryRun).toContain("catch (PersonelValidationException $e)");
    expect(dryRun).not.toContain("'gorev_id' => $payload['gorev_id'] ?? null");
    expect(apply).toContain("assertMultiAxisPreimage");
    expect(apply).toContain("PersonelOrganizasyonDegisikligiService::applyInTransaction");
    expect(apply).toContain("PersonelKaliciSubeDegisikligiService::applyInTransaction");
    expect(apply).toContain("PERSONEL_LIFECYCLE_STALE_PREIMAGE");
    expect(org).toContain("$ownsTransaction = !$pdo->inTransaction()");
    expect(branch).toContain("$ownsTransaction = !$pdo->inTransaction()");
  });

  it("derives dynamic postcheck contract from inventory fingerprint and dry-run analysis", () => {
    const postcheck = read("api/src/Services/Personel/PersonelLifecycleBulkPostcheck.php");
    const dryRun = read("api/src/Services/Personel/PersonelLifecycleBulkDryRunService.php");

    expect(postcheck).toContain("captureInventory");
    expect(postcheck).toContain("inventory_fingerprint");
    expect(postcheck).toContain("validateContract");
    expect(postcheck).toContain("checksumPreimage");
    expect(postcheck).not.toContain("BINDING_CREATE_COUNT");
    expect(postcheck).not.toContain("validateBindingContract");
    expect(dryRun).toContain("PersonelLifecycleBulkPostcheck::captureInventory");
    expect(dryRun).toContain("PersonelLifecycleBulkPostcheck::validateContract");
    expect(dryRun).toContain("preimage_aktif_durum");
  });

  it("extends completeness owner for sube, lokasyon, yonetici, pozisyon and IC SGK", () => {
    const completeness = read("api/src/Services/Personel/PersonelCompletenessService.php");
    expect(completeness).toContain("'key' => 'sube_id'");
    expect(completeness).toContain("'key' => 'calisma_lokasyonu_id'");
    expect(completeness).toContain("'key' => 'bagli_amir_id'");
    expect(completeness).toContain("'key' => 'pozisyon_id'");
    expect(completeness).toContain("'key' => 'sgk_isveren_id'");
    expect(completeness).toContain("pozisyon_id, 0) <= 0");
    expect(completeness).toContain("SGK is IC-only (DIS_KAYNAK must keep NULL payroll employer)");
  });

  it("does not fabricate manager users and avoids TC in resolver paths", () => {
    const resolver = read("api/src/Services/Personel/PersonelLifecycleBulkReferenceResolver.php");
    expect(resolver).toContain("resolveBagliAmirUserId");
    expect(resolver).toContain("resolveBagliAmirUserIds");
    expect(resolver).toContain("$matchedIds");
    expect(resolver).not.toContain("INSERT INTO users");
    expect(resolver).not.toContain("tc_kimlik");
  });

  it("derives migration control-plane state from the canonical source", () => {
    const preflight = read("api/src/Database/MigrationPreflightReport.php");
    expect(preflight).toContain("array_column($bundle['migrations'], 'version')");
    expect(preflight).not.toContain("ROUND_MIGRATIONS");
  });
});
