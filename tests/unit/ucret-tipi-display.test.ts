import { describe, expect, it } from "vitest";
import {
  displayUcretTipiLabel,
  mapUcretTipiSelectOptions
} from "../../src/lib/display/ucret-tipi-display";

describe("ucret-tipi-display", () => {
  it("keeps Aylık, Günlük and Saatlik as distinct labels by id", () => {
    expect(displayUcretTipiLabel("ignored", 1)).toBe("Aylık");
    expect(displayUcretTipiLabel("ignored", 2)).toBe("Günlük");
    expect(displayUcretTipiLabel("ignored", 3)).toBe("Saatlik");
  });

  it("does not collapse Saatlik into Günlük for string labels", () => {
    expect(displayUcretTipiLabel("Saatlik")).toBe("Saatlik");
    expect(displayUcretTipiLabel("hourly")).toBe("Saatlik");
    expect(displayUcretTipiLabel("Günlük")).toBe("Günlük");
    expect(displayUcretTipiLabel("yevmiye")).toBe("Günlük");
    expect(displayUcretTipiLabel("Aylık")).toBe("Aylık");
  });

  it("maps select options with distinct Saatlik label", () => {
    expect(
      mapUcretTipiSelectOptions([
        { id: 1, label: "Aylık" },
        { id: 2, label: "Günlük" },
        { id: 3, label: "Saatlik" }
      ])
    ).toEqual([
      { value: "1", label: "Aylık" },
      { value: "2", label: "Günlük" },
      { value: "3", label: "Saatlik" }
    ]);
  });
});
