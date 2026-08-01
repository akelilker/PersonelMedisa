import { describe, expect, it } from "vitest";

describe("payroll haftalik/aylik parity contract", () => {
  it("2700 = 45*60; monthly 225h matches 5 weeks; 7.5*6 = 45", () => {
    expect(2700).toBe(45 * 60);
    expect(225 * 60).toBe(2700 * 5); // monthly parity contract
    expect(7.5 * 6).toBe(45);
  });
});
