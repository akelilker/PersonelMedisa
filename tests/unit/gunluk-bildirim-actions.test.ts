import { describe, expect, it } from "vitest";
import type { AppPermission } from "../../src/lib/authorization/role-permissions";
import {
  canCancelGunlukBildirim,
  canEditGunlukBildirim,
  canRequestCorrectionGunlukBildirim,
  canSubmitGunlukBildirim
} from "../../src/lib/bildirim/gunluk-bildirim-actions";
import type { Bildirim } from "../../src/types/bildirim";

function makeItem(overrides: Partial<Bildirim> = {}): Bildirim {
  return {
    id: 1,
    bildirim_turu: "GEC_GELDI",
    state: "TASLAK",
    created_by: 10,
    ...overrides
  };
}

function hasPermissions(...permissions: AppPermission[]) {
  const allowed = new Set(permissions);
  return (permission: AppPermission) => allowed.has(permission);
}

describe("gunluk-bildirim-actions", () => {
  it("allows owner edit/cancel in TASLAK with update permission", () => {
    const item = makeItem({ state: "TASLAK", created_by: 10 });
    const hasPermission = hasPermissions("gunluk_bildirim.update_own_open");

    expect(canEditGunlukBildirim(item, hasPermission, 10)).toBe(true);
    expect(canCancelGunlukBildirim(item, hasPermission, 10)).toBe(true);
  });

  it("denies non-owner edit/cancel in TASLAK even with update permission", () => {
    const item = makeItem({ state: "TASLAK", created_by: 10 });
    const hasPermission = hasPermissions("gunluk_bildirim.update_own_open");

    expect(canEditGunlukBildirim(item, hasPermission, 11)).toBe(false);
    expect(canCancelGunlukBildirim(item, hasPermission, 11)).toBe(false);
  });

  it("allows owner submit in DUZELTME_ISTENDI with submit permission", () => {
    const item = makeItem({ state: "DUZELTME_ISTENDI", created_by: 10 });
    const hasPermission = hasPermissions("gunluk_bildirim.submit");

    expect(canSubmitGunlukBildirim(item, hasPermission, 10)).toBe(true);
  });

  it("allows request correction in GONDERILDI with correction permission", () => {
    const item = makeItem({ state: "GONDERILDI", created_by: 10 });
    const hasPermission = hasPermissions("gunluk_bildirim.request_correction");

    expect(canRequestCorrectionGunlukBildirim(item, hasPermission)).toBe(true);
  });

  it("denies owner write actions in GONDERILDI", () => {
    const item = makeItem({ state: "GONDERILDI", created_by: 10 });
    const hasPermission = hasPermissions("gunluk_bildirim.update_own_open", "gunluk_bildirim.submit");

    expect(canEditGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canCancelGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canSubmitGunlukBildirim(item, hasPermission, 10)).toBe(false);
  });

  it("denies all owner write actions in IPTAL", () => {
    const item = makeItem({ state: "IPTAL", created_by: 10 });
    const hasPermission = hasPermissions(
      "gunluk_bildirim.update_own_open",
      "gunluk_bildirim.submit",
      "gunluk_bildirim.request_correction"
    );

    expect(canEditGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canCancelGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canSubmitGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canRequestCorrectionGunlukBildirim(item, hasPermission)).toBe(false);
  });

  it("denies all owner write actions in HAFTALIK_MUTABAKATA_ALINDI", () => {
    const item = makeItem({ state: "HAFTALIK_MUTABAKATA_ALINDI", created_by: 10 });
    const hasPermission = hasPermissions(
      "gunluk_bildirim.update_own_open",
      "gunluk_bildirim.submit",
      "gunluk_bildirim.request_correction"
    );

    expect(canEditGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canCancelGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canSubmitGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canRequestCorrectionGunlukBildirim(item, hasPermission)).toBe(false);
  });

  it("denies write actions for unknown state", () => {
    const item = makeItem({ state: "MUHURLENDI", created_by: 10 });
    const hasPermission = hasPermissions(
      "gunluk_bildirim.update_own_open",
      "gunluk_bildirim.submit",
      "gunluk_bildirim.request_correction"
    );

    expect(canEditGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canCancelGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canSubmitGunlukBildirim(item, hasPermission, 10)).toBe(false);
    expect(canRequestCorrectionGunlukBildirim(item, hasPermission)).toBe(false);
  });
});
