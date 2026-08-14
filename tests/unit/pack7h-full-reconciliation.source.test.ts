import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

function read(path: string) {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("Pack7H full reconciliation source locks", () => {
  it("starts from the merged Pack7G-C deployment and locks trusted source hashes", () => {
    const evidence = read("docs/guncel/129-pack7h-full-reconciliation.md");

    expect(evidence).toContain("1ddbe59780c76cdf6543e0049e61d33d67bfc919");
    expect(evidence).toContain(
      "C449594165BF27F338D0D295D771CB54F5AA002EE86A2B8B989075498416806F"
    );
    expect(evidence).toContain(
      "C6E8476423101E06F34A6CDF7ACB1A566CAF7199A894DB89F8957F6E12A80AE2"
    );
    expect(evidence).toContain(
      "5777457AFF86CD5B6E3F7410121FD2C6E00E96B2B17A58CE6101BFD4C3E49BE1"
    );
    expect(evidence).toContain("PACK7G_C_DEPLOY");
  });

  it("keeps source lineage and continuation provenance", () => {
    const evidence = read("docs/guncel/129-pack7h-full-reconciliation.md");

    expect(evidence).toContain("D0BB5DB62DFE43A3C190E8D17252D98A6B15855C62F980454337CD6DA4DBEB15");
    expect(evidence).toContain("ENRICHED_ARTIFACT_PATH");
    expect(evidence).toContain("source hash/row, strong identity key, reason, and confidence");
    expect(evidence).toContain("No source workbook was modified");
  });

  it("preserves historical continuation counts while asserting the authoritative completion", () => {
    const evidence = read("docs/guncel/129-pack7h-full-reconciliation.md");

    expect(evidence).toContain("MISSING_SICIL_BEFORE = 24");
    expect(evidence).toContain("MISSING_SICIL_AFTER = 4");
    expect(evidence).toContain("CANONICAL_BLOCKED_DISTINCT_AFTER = 41");
    expect(evidence).toContain("VALIDATION_BLOCKED = 54");
    expect(evidence).toContain("MODE = USER_AUTHORITATIVE_COMPLETION");
    expect(evidence).toContain("UNRESOLVED_NAME_SPLIT_AFTER = 0");
    expect(evidence).toContain("NAME_SPLITS_RESOLVED_BY_USER = 23");
    expect(evidence).toContain("CANONICAL_BLOCKED_DISTINCT_AFTER = 26");
    expect(evidence).toContain("VALIDATION_BLOCKED = 58");
    expect(evidence).toContain("IMPORT_READY = NO");
    expect(evidence).toContain("FINAL_STATUS = BLOCKED");
  });

  it("does not permit fuzzy task mapping or production mutation", () => {
    const evidence = read("docs/guncel/129-pack7h-full-reconciliation.md");

    for (const sicil of ["176", "197", "201", "206", "213", "275", "283", "285", "355", "375", "398", "407", "427"]) {
      expect(evidence).toContain(`| ${sicil} |`);
    }
    expect(evidence).toContain("No fuzzy mapping");
    expect(evidence).toContain("No repository catalog row, fuzzy mapping");
    expect(evidence).toContain("EXTERNAL_GOREV_TRUE_BLOCKERS_AFTER_RECONCILIATION = 13");
    expect(evidence).toContain("GOREV_BLOCKER_COUNT_INCONSISTENCY_RESOLVED = YES");
    expect(evidence).toContain("EXACT_LOCATION_REFERENCE = Karabük");
    expect(evidence).toContain("EXACT_SUBE_REFERENCE = BLOCKED");
    expect(evidence).toContain("EXACT_PERSONEL_TIPI_REFERENCE = BLOCKED");
    expect(evidence).toContain("PRODUCTION_MUTATED = NO");
    expect(evidence).toContain("IMPORT_APPLY = NO");
    expect(evidence).toContain("EXPECTED_TOTAL_AFTER_IMPORT = 139");
  });
});
