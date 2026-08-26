import { describe, expect, it, vi } from "vitest";
import {
  clearPersonelActivationLocationHash,
  extractPersonelActivationTokenFromHash
} from "../../src/features/auth/personel-aktivasyon-token";

describe("personel aktivasyon token fragment helpers", () => {
  it("reads token from #token= fragment", () => {
    expect(extractPersonelActivationTokenFromHash("#token=abc123secure")).toBe("abc123secure");
    expect(extractPersonelActivationTokenFromHash("token=abc123secure")).toBe("abc123secure");
  });

  it("returns null for missing or empty token", () => {
    expect(extractPersonelActivationTokenFromHash("")).toBeNull();
    expect(extractPersonelActivationTokenFromHash("#")).toBeNull();
    expect(extractPersonelActivationTokenFromHash("#other=1")).toBeNull();
    expect(extractPersonelActivationTokenFromHash("#token=")).toBeNull();
    expect(extractPersonelActivationTokenFromHash("#token=%20")).toBeNull();
  });

  it("clears hash via replaceState without touching storage APIs", () => {
    const replaceState = vi.fn();
    clearPersonelActivationLocationHash(replaceState, {
      pathname: "/personel-aktivasyon",
      search: ""
    });
    expect(replaceState).toHaveBeenCalledWith(null, "", "/personel-aktivasyon");
  });

  it("token helper module does not reference persistence APIs", async () => {
    const source = await import("node:fs").then((fs) =>
      fs.readFileSync(
        new URL("../../src/features/auth/personel-aktivasyon-token.ts", import.meta.url),
        "utf8"
      )
    );
    expect(source).not.toMatch(/localStorage|sessionStorage/);
  });
});
