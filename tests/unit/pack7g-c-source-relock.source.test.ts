import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

function read(path: string) {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("Pack7G-C source re-lock and readiness evidence", () => {
  it("preserves predecessor lineage while promoting the successor", () => {
    const evidence = read("docs/guncel/128-pack7g-c-source-relock-import-readiness.md");

    expect(evidence).toContain(
      "C449594165BF27F338D0D295D771CB54F5AA002EE86A2B8B989075498416806F"
    );
    expect(evidence).toContain(
      "50142B64A2CFD982196E6AA25DBF13612B3453CFC783348E0D44659B126027B0"
    );
    expect(evidence).toContain(
      "C6E8476423101E06F34A6CDF7ACB1A566CAF7199A894DB89F8957F6E12A80AE2"
    );
    expect(evidence).toContain("SOURCE_LINEAGE_PRESERVED");
    expect(evidence).toContain("50142B... -> C6E847...");
    expect(evidence).toContain("EXTERNAL_MEMBERSHIP");
    expect(evidence).toContain("13/13");
  });

  it("keeps successor task-code values explicitly current-source provenance", () => {
    const evidence = read("docs/guncel/128-pack7g-c-source-relock-import-readiness.md");

    expect(evidence).toContain("CURRENT_SUCCESSOR_SOURCE_VALUE");
    expect(evidence).toContain("GOREV_KODU_ACCEPTED_FROM_SUCCESSOR = NO");
    expect(evidence).toContain("Aktif görev kataloğunda tam eşleşme yok");
    expect(evidence).toContain(
      "Önceki ham değer mevcut değil; mevcut değer güncel kaynak dosyadan alınmıştır."
    );
    for (const sicil of ["176", "197", "206", "213", "275", "283", "355", "375"]) {
      expect(evidence).toContain(`\`${sicil}\``);
    }
  });

  it("locks fail-closed projection and production restrictions", () => {
    const evidence = read("docs/guncel/128-pack7g-c-source-relock-import-readiness.md");

    expect(evidence).toContain("VALIDATION_BLOCKED");
    expect(evidence).toContain("IMPORT_READY = NO");
    expect(evidence).toContain("PRODUCTION_MUTATED = NO");
    expect(evidence).toContain("CANONICAL_122_IMPORT = NO");
    expect(evidence).toContain("EXTERNAL_13_IMPORT = NO");
    expect(evidence).toContain("IMPORT_APPLY = NO");
    expect(evidence).toContain("EXPECTED_TOTAL_AFTER_IMPORT");
    expect(evidence).toContain("BLANK_TC_NORMALIZED_TO_NULL");
    expect(evidence).toContain("BLANK_SICIL_NORMALIZED_TO_NULL");
  });
});
