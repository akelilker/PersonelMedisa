import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();

function read(path: string): string {
  return readFileSync(resolve(root, path), "utf8");
}

describe("MG personnel bulk dry-run apply parity sources", () => {
  it("routes create planning through shared bulk mutation planner", () => {
    const planner = read("api/src/Services/Personel/PersonelLifecycleBulkMutationPlanner.php");
    const dryRun = read("api/src/Services/Personel/PersonelLifecycleBulkDryRunService.php");
    const apply = read("api/src/Services/Personel/PersonelLifecycleBulkApplyService.php");

    expect(planner).toContain("planCreatePayload");
    expect(planner).toContain("inferMissingDepartmanForCreate");
    expect(planner).toContain("validateCreateReferences");
    expect(planner).toContain("planOrgTargets");
    expect(dryRun).toContain("PersonelLifecycleBulkMutationPlanner::planCreatePayload");
    expect(dryRun).toContain("canonical_ready");
    expect(dryRun).toContain("validation_field");
    expect(apply).toContain("PersonelLifecycleBulkMutationPlanner::planCreatePayload");
    expect(apply).toContain("canonical_ready");
  });

  it("derives departman from bolum when safe and keeps hierarchy fail-closed", () => {
    const org = read("api/src/Services/Personel/PersonelOrgStructureSchema.php");
    expect(org).toContain("inferDepartmanIdFromBolum");
    expect(org).toContain("inferMissingDepartmanForCreate");
    expect(org).toContain("Bolum secildiginde departman_id zorunludur.");
  });

  it("uses shared Turkish-insensitive reference search keys without renaming catalog rows", () => {
    const resolver = read("api/src/Services/Personel/PersonelLifecycleBulkReferenceResolver.php");
    expect(resolver).toContain("searchKey");
    expect(resolver).toContain("asciiFold");
    expect(resolver).not.toContain("UPDATE ");
    expect(resolver).not.toContain("INSERT INTO departmanlar");
  });
});
