import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("FINDING A — passive GİRİŞ/ÇIKIŞ feedback symmetry (source)", () => {
  it("OwnQrAttendanceBoxes owns passive notices and aria-disabled GİRİŞ click path", () => {
    const boxes = read("src/features/self-service/components/OwnQrAttendanceBoxes.tsx");
    expect(boxes).toContain("PASSIVE_GIRIS_WITH_OPEN_SHIFT_NOTICE");
    expect(boxes).toContain("PASSIVE_CIKIS_AFTER_COMPLETED_PAIR_NOTICE");
    expect(boxes).toContain("onPassiveGirisWithOpenShift");
    expect(boxes).toContain("onPassiveCikisAfterCompletedPair");
    expect(boxes).toContain('aria-disabled={!girisActionable}');
    expect(boxes).toContain("onPassiveGirisWithOpenShift?.()");
    expect(boxes).toContain('today.next_action === "CIKIS"');
    expect(boxes).toContain('today.next_action === "GIRIS"');
  });

  it("PERSONEL and BIRIM_AMIRI homes wire the same passive callbacks", () => {
    const personel = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(personel).toContain("onPassiveGirisWithOpenShift");
    expect(personel).toContain("onPassiveCikisAfterCompletedPair");
    expect(personel).toContain("PASSIVE_GIRIS_WITH_OPEN_SHIFT_NOTICE");

    const amir = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(amir).toContain("onPassiveGirisWithOpenShift");
    expect(amir).toContain("onPassiveCikisAfterCompletedPair");
    expect(amir).toContain("PASSIVE_CIKIS_AFTER_COMPLETED_PAIR_NOTICE");
    expect(amir).toContain("birim-amiri-passive-notice-modal");
  });
});
