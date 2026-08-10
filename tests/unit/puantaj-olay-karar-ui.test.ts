import { describe, expect, it } from "vitest";
import { puantajOlayKararUiHelpers } from "../../src/features/puantaj/components/PuantajOlayKararPanel";
import { getRolePermissions } from "../../src/lib/authorization/role-permissions";

describe("puantaj olay karar UI policy", () => {
  it("hides tolerance for early exit", () => {
    expect(puantajOlayKararUiHelpers.availableActions("ERKEN_CIKIS", 5)).toEqual([
      "KESINTI_UYGULA",
      "OFFICIAL_PROCESS_REQUIRED"
    ]);
    expect(puantajOlayKararUiHelpers.availableActions("ERKEN_CIKIS", 30)).not.toContain("TOLERANS_UYGULA");
  });

  it("hides tolerance for late over 35", () => {
    expect(puantajOlayKararUiHelpers.availableActions("GEC_KALMA", 36)).toEqual([
      "KESINTI_UYGULA",
      "OFFICIAL_PROCESS_REQUIRED"
    ]);
    expect(puantajOlayKararUiHelpers.availableActions("GEC_KALMA", 40)).not.toContain("TOLERANS_UYGULA");
  });

  it("allows tolerance for late <=35 only for decide-capable role surface", () => {
    expect(puantajOlayKararUiHelpers.availableActions("GEC_KALMA", 1)).toContain("TOLERANS_UYGULA");
    expect(puantajOlayKararUiHelpers.availableActions("GEC_KALMA", 20)).toContain("TOLERANS_UYGULA");
    expect(puantajOlayKararUiHelpers.availableActions("GEC_KALMA", 35)).toContain("TOLERANS_UYGULA");
    expect(getRolePermissions("BOLUM_YONETICISI")).toContain("puantaj.olay_karar.decide");
    expect(getRolePermissions("GENEL_YONETICI")).not.toContain("puantaj.olay_karar.decide");
  });
});
