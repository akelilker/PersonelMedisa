import { describe, expect, it } from "vitest";
import { formatBildirimTuruLabel, formatUserRoleLabel } from "../../src/lib/display/enum-display";

describe("enum display labels", () => {
  it("renders exact Turkish daily notification labels", () => {
    expect(formatBildirimTuruLabel("DIGER")).toBe("Diğer");
    expect(formatBildirimTuruLabel("IZINLI")).toBe("İzinli");
    expect(formatBildirimTuruLabel("GOREVDE")).toBe("Görevde");
  });

  it("renders the exact Birim Amiri role label", () => {
    expect(formatUserRoleLabel("BIRIM_AMIRI")).toBe("Birim Amiri Rolü");
  });
});
