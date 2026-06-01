import { describe, expect, it } from "vitest";
import {
  assertRevizyonTransition,
  getRevizyonTransitionError,
  isAllowedRevizyonTransition
} from "../../src/lib/revizyon-talebi/revizyon-state";

describe("revizyon-state", () => {
  it("allows the five valid transitions", () => {
    expect(isAllowedRevizyonTransition("TASLAK", "ONAY_BEKLIYOR")).toBe(true);
    expect(isAllowedRevizyonTransition("TASLAK", "IPTAL")).toBe(true);
    expect(isAllowedRevizyonTransition("ONAY_BEKLIYOR", "ONAYLANDI")).toBe(true);
    expect(isAllowedRevizyonTransition("ONAY_BEKLIYOR", "REDDEDILDI")).toBe(true);
    expect(isAllowedRevizyonTransition("ONAY_BEKLIYOR", "IPTAL")).toBe(true);
  });

  it("rejects transitions from terminal states", () => {
    expect(isAllowedRevizyonTransition("ONAYLANDI", "TASLAK")).toBe(false);
    expect(isAllowedRevizyonTransition("REDDEDILDI", "ONAY_BEKLIYOR")).toBe(false);
    expect(isAllowedRevizyonTransition("IPTAL", "ONAY_BEKLIYOR")).toBe(false);
  });

  it("rejects REDDEDILDI to ONAYLANDI", () => {
    expect(isAllowedRevizyonTransition("REDDEDILDI", "ONAYLANDI")).toBe(false);
    expect(getRevizyonTransitionError("REDDEDILDI", "ONAYLANDI")).toBe("INVALID_STATE_TRANSITION");
  });

  it("returns INVALID_STATE_TRANSITION from assert for invalid transitions", () => {
    expect(assertRevizyonTransition("REDDEDILDI", "ONAYLANDI")).toEqual({
      ok: false,
      code: "INVALID_STATE_TRANSITION"
    });
    expect(assertRevizyonTransition("TASLAK", "ONAY_BEKLIYOR")).toEqual({ ok: true });
  });
});
