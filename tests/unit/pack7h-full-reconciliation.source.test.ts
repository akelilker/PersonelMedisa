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

  it("keeps overlapping blocker counts distinct and fail-closed", () => {
    const evidence = read("docs/guncel/129-pack7h-full-reconciliation.md");

    expect(evidence).toContain("Missing sicil | 24 | 24");
    expect(evidence).toContain("Unresolved name split | 23 | 23");
    expect(evidence).toContain("Required birth date missing | 5 | 5");
    expect(evidence).toContain("Required phone missing | 26 | 26");
    expect(evidence).toContain("Distinct blocked canonical records | 55 | 55");
    expect(evidence).toContain("VALIDATION_BLOCKED = 68");
    expect(evidence).toContain("IMPORT_READY = NO");
  });

  it("does not permit fuzzy task mapping or production mutation", () => {
    const evidence = read("docs/guncel/129-pack7h-full-reconciliation.md");

    for (const sicil of ["176", "197", "206", "213", "275", "283", "355", "375"]) {
      expect(evidence).toContain(`| ${sicil} |`);
    }
    expect(evidence).toContain("No fuzzy mapping");
    expect(evidence).toContain("PRODUCTION_MUTATED = NO");
    expect(evidence).toContain("IMPORT_APPLY = NO");
    expect(evidence).toContain("EXPECTED_TOTAL_AFTER_IMPORT = 139");
  });
});
