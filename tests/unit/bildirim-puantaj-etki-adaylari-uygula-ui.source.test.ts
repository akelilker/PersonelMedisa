import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const sectionSource = readFileSync(
  resolve(process.cwd(), "src/features/puantaj/components/BildirimPuantajEtkiAdaylariSection.tsx"),
  "utf8"
);
const hookSource = readFileSync(
  resolve(process.cwd(), "src/hooks/useBildirimPuantajEtkiAdaylari.ts"),
  "utf8"
);
const displaySource = readFileSync(
  resolve(process.cwd(), "src/lib/bildirim-puantaj-etki-aday/display.ts"),
  "utf8"
);

describe("S74-C3-B3 uygula UI source parity", () => {
  it("wires apply permission and confirmation without new button family", () => {
    expect(sectionSource).toContain('hasPermission("puantaj.bildirim_etki.apply")');
    expect(sectionSource).toContain("canApplyBildirimPuantajEtkiAday");
    expect(sectionSource).toContain('data-testid="puantaj-etki-aday-detail-apply"');
    expect(sectionSource).toContain('data-testid="puantaj-etki-aday-apply-modal"');
    expect(sectionSource).toContain("universal-btn-save");
    expect(sectionSource).toContain("AppModal");
    expect(sectionSource).not.toContain("!important");
    expect(sectionSource).not.toMatch(/style=\{\{/);
  });

  it("posts expected_state HAZIR through applyBildirimPuantajEtkiAday", () => {
    expect(hookSource).toContain("applyBildirimPuantajEtkiAday");
    expect(hookSource).toContain('expected_state: "HAZIR"');
    expect(hookSource).toContain("PERIOD_LOCKED");
    expect(hookSource).toContain("PUANTAJ_OLUSTU");
    expect(hookSource).toContain("APPLY_UNSUPPORTED");
    expect(displaySource).toContain("canApplyBildirimPuantajEtkiAday");
    expect(displaySource).toContain('state === "HAZIR"');
  });
});
