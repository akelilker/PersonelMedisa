import { describe, expect, it } from "vitest";
import {
  PERSONEL_FIRST_LOGIN_COMPLETE_LABEL,
  PERSONEL_FIRST_LOGIN_PENDING_LABEL,
  countPersonelFirstLoginStatus,
  matchesPersonelFirstLoginFilter,
  resolvePersonelFirstLoginLabel
} from "../../src/lib/yonetim/personel-first-login-status";

describe("personel first-login status visibility", () => {
  it("E: PERSONEL-bound must_change_password=true → İlk Giriş Bekliyor", () => {
    expect(
      resolvePersonelFirstLoginLabel({ personel_id: 12, must_change_password: true })
    ).toBe(PERSONEL_FIRST_LOGIN_PENDING_LABEL);
  });

  it("F: PERSONEL-bound must_change_password=false → İlk Giriş Tamamlandı", () => {
    expect(
      resolvePersonelFirstLoginLabel({ personel_id: 12, must_change_password: false })
    ).toBe(PERSONEL_FIRST_LOGIN_COMPLETE_LABEL);
  });

  it("G: Bekliyor filtresi yalnız pending PERSONEL-bound kayıtları döner", () => {
    const pending = { personel_id: 1, must_change_password: true };
    const completed = { personel_id: 2, must_change_password: false };
    const admin = { personel_id: null, must_change_password: true };

    expect(matchesPersonelFirstLoginFilter(pending, "pending")).toBe(true);
    expect(matchesPersonelFirstLoginFilter(completed, "pending")).toBe(false);
    expect(matchesPersonelFirstLoginFilter(admin, "pending")).toBe(false);
  });

  it("H: Tamamlandı filtresi yalnız completed PERSONEL-bound kayıtları döner", () => {
    const pending = { personel_id: 1, must_change_password: true };
    const completed = { personel_id: 2, must_change_password: false };
    const admin = { personel_id: null, must_change_password: false };

    expect(matchesPersonelFirstLoginFilter(completed, "completed")).toBe(true);
    expect(matchesPersonelFirstLoginFilter(pending, "completed")).toBe(false);
    expect(matchesPersonelFirstLoginFilter(admin, "completed")).toBe(false);
  });

  it("I: non-PERSONEL legacy/admin misleading status almıyor", () => {
    expect(resolvePersonelFirstLoginLabel({ personel_id: null, must_change_password: true })).toBeNull();
    expect(resolvePersonelFirstLoginLabel({ personel_id: null, must_change_password: false })).toBeNull();
    expect(resolvePersonelFirstLoginLabel({ must_change_password: true })).toBeNull();
  });

  it("summary counts only PERSONEL-bound known flags", () => {
    const counts = countPersonelFirstLoginStatus([
      { personel_id: 1, must_change_password: true },
      { personel_id: 2, must_change_password: false },
      { personel_id: 3, must_change_password: true },
      { personel_id: null, must_change_password: true },
      { personel_id: 4 }
    ]);

    expect(counts).toEqual({ pending: 2, completed: 1 });
  });

  it("Tümü filtresi tüm kayıtları geçirir", () => {
    expect(matchesPersonelFirstLoginFilter({ personel_id: null }, "all")).toBe(true);
    expect(matchesPersonelFirstLoginFilter({ personel_id: 1, must_change_password: true }, "all")).toBe(
      true
    );
  });
});
