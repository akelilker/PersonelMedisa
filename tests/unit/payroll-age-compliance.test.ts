import { describe, expect, it } from "vitest";

/**
 * Mirrors PayrollComplianceGuard::resolveUnder18 boundary:
 * - 18th birthday exclusive: ref < dob+18y → under_18
 * - on 18th birthday → not under_18
 */
function resolveUnder18(
  dogumTarihi: string | null | undefined,
  referansTarihi: string
): { under_18: boolean; missing_dob: boolean } {
  if (dogumTarihi == null || dogumTarihi.trim() === "") {
    return { under_18: false, missing_dob: true };
  }
  const dob = dogumTarihi.trim();
  const ref = referansTarihi.trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dob) || !/^\d{4}-\d{2}-\d{2}$/.test(ref)) {
    return { under_18: false, missing_dob: true };
  }

  const [y, m, d] = dob.split("-").map(Number);
  const [ry, rm, rd] = ref.split("-").map(Number);
  const eighteenth = new Date(Date.UTC(y + 18, m - 1, d));
  const refUtc = new Date(Date.UTC(ry, rm - 1, rd));

  return {
    under_18: refUtc.getTime() < eighteenth.getTime(),
    missing_dob: false
  };
}

describe("payroll age compliance boundaries", () => {
  it("missing DOB → missing_dob, not under_18", () => {
    expect(resolveUnder18(null, "2026-04-01")).toEqual({
      under_18: false,
      missing_dob: true
    });
    expect(resolveUnder18("", "2026-04-01")).toEqual({
      under_18: false,
      missing_dob: true
    });
  });

  it("day before 18th birthday → under_18", () => {
    expect(resolveUnder18("2008-04-02", "2026-04-01")).toEqual({
      under_18: true,
      missing_dob: false
    });
  });

  it("on 18th birthday → not under_18 (block degil)", () => {
    expect(resolveUnder18("2008-04-01", "2026-04-01")).toEqual({
      under_18: false,
      missing_dob: false
    });
  });

  it("after 18th birthday → not under_18", () => {
    expect(resolveUnder18("2008-03-31", "2026-04-01")).toEqual({
      under_18: false,
      missing_dob: false
    });
  });
});
