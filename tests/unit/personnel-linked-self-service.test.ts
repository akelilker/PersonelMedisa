import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import {
  SELF_SERVICE_BASELINE_PERMISSIONS,
  getEffectivePermissions,
  getRolePermissions,
  hasPersonnelLinkedSelfServiceEligibility,
  hasRolePermission,
  hasUserPermission
} from "../../src/lib/authorization/role-permissions";

const root = process.cwd();
const phpRunner = resolve(root, "tests/php/PersonnelLinkedSelfServicePhpTestRunner.php");
const rolePermissionsPhp = resolve(root, "api/src/Auth/RolePermissions.php");
const meControllerPhp = resolve(root, "api/src/Controllers/MeController.php");
const selfContextPhp = resolve(root, "api/src/Services/SelfService/SelfPersonelContext.php");

describe("personnel-linked self-service authorization", () => {
  it("keeps PERSONEL matrix as the single self-service baseline (no duplicate list in PHP)", () => {
    expect([...SELF_SERVICE_BASELINE_PERMISSIONS]).toEqual([...getRolePermissions("PERSONEL")]);
    const php = readFileSync(rolePermissionsPhp, "utf8");
    expect(php).toContain("function selfServiceBaselinePermissions");
    expect(php).toContain("hasPersonnelLinkedSelfServiceEligibility");
    expect(php).toContain("self::selfServiceBaselinePermissions()");
    expect(php).toContain("return self::$matrix['PERSONEL']");
  });

  it("PERSONEL + personel_id: self YES, management NO", () => {
    expect(hasUserPermission("PERSONEL", "self_service.view", 158)).toBe(true);
    expect(hasUserPermission("PERSONEL", "self_service.qr.scan", 158)).toBe(true);
    expect(hasUserPermission("PERSONEL", "personeller.view", 158)).toBe(false);
    expect(hasUserPermission("PERSONEL", "puantaj.update", 158)).toBe(false);
  });

  it("BOLUM_YONETICISI + personel_id: management + self (Sinem/Ismail shape)", () => {
    // Sinem-shape
    expect(hasUserPermission("BOLUM_YONETICISI", "personeller.view", 173)).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "aylik_bolum_onayi.approve", 173)).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.view", 173)).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.qr.scan", 173)).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.qr.events.view", 173)).toBe(true);
    // İsmail-shape
    expect(hasUserPermission("BOLUM_YONETICISI", "personeller.view", 120)).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.qr.scan", 120)).toBe(true);
  });

  it("BOLUM_YONETICISI + null: management YES, self NO", () => {
    expect(hasUserPermission("BOLUM_YONETICISI", "personeller.view", null)).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.view", null)).toBe(false);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.qr.scan", null)).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "self_service.view")).toBe(false);
  });

  it("GENEL_YONETICI + personel_id / null", () => {
    expect(hasUserPermission("GENEL_YONETICI", "yonetim-paneli.manage", 10)).toBe(true);
    expect(hasUserPermission("GENEL_YONETICI", "self_service.view", 10)).toBe(true);
    expect(hasUserPermission("GENEL_YONETICI", "yonetim-paneli.manage", null)).toBe(true);
    expect(hasUserPermission("GENEL_YONETICI", "self_service.view", null)).toBe(false);
  });

  it("invalid / missing binding is fail-closed for self-service", () => {
    expect(hasPersonnelLinkedSelfServiceEligibility(null)).toBe(false);
    expect(hasPersonnelLinkedSelfServiceEligibility(undefined)).toBe(false);
    expect(hasPersonnelLinkedSelfServiceEligibility(0)).toBe(false);
    expect(hasPersonnelLinkedSelfServiceEligibility(-1)).toBe(false);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.view", 0)).toBe(false);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.view", undefined)).toBe(false);
  });

  it("Salih post-fix simulation: BOLUM + personel_id=158 preserves QR/self", () => {
    const perms = getEffectivePermissions("BOLUM_YONETICISI", 158);
    expect(perms).toContain("aylik_bolum_onayi.approve");
    expect(perms).toContain("self_service.qr.scan");
    expect(perms).toContain("self_service.qr.events.view");
    expect(perms).toContain("self_service.puantaj.view");
  });

  it("legacy unknown role gains only self baseline via binding (no management fail-open)", () => {
    expect(hasUserPermission("SGK_KARAR_ONAY_YETKILISI", "self_service.view", 50)).toBe(true);
    expect(hasUserPermission("SGK_KARAR_ONAY_YETKILISI", "personeller.view", 50)).toBe(false);
    expect(hasUserPermission("SGK_KARAR_ONAY_YETKILISI", "self_service.view", null)).toBe(false);
    expect(getRolePermissions("SGK_KARAR_ONAY_YETKILISI")).toEqual([]);
  });

  it("self-only boundary owners remain SelfPersonelContext (no controller bypass)", () => {
    const me = readFileSync(meControllerPhp, "utf8");
    const ctx = readFileSync(selfContextPhp, "utf8");
    expect(me).toContain("RolePermissions::assert($user, 'self_service.view')");
    expect(me).toContain("SelfPersonelContext::resolveForSelfService");
    expect(me).not.toMatch(/if\s*\(.*manager.*personel_id/i);
    expect(ctx).toContain("users.personel_id");
    expect(ctx).toContain("SELF_SERVICE_BINDING_REQUIRED");
    expect(ctx).toContain("client personel_id is never trusted");
  });

  it("self-service-only role cannot reach the management reference read surface", () => {
    const referans = readFileSync(
      resolve(root, "api/src/Controllers/ReferansController.php"),
      "utf8"
    );
    const guardCall = "self::assertReferenceReadAllowed($user);";

    const helperStart = referans.indexOf(
      "private static function assertReferenceReadAllowed("
    );
    expect(helperStart).toBeGreaterThan(-1);
    const helperEnd = referans.indexOf(
      "\n    private static function listByTable(",
      helperStart
    );
    const helper = referans.slice(helperStart, helperEnd);
    // Role-based fail-closed: the self-service-only role never reads reference data.
    expect(helper).toContain("'PERSONEL'");
    expect(helper).toContain("403");
    expect(helper).toContain("SELF_SERVICE_ONLY_ROLE_FORBIDDEN");

    // Every authenticated reference read entry point carries the guard.
    const readOwners = [
      "public static function sgkIsverenler(",
      "public static function calismaLokasyonlari(",
      "public static function bagliAmirler(",
      "public static function surecTurleri(",
      "public static function ucretTipleri(",
      "public static function primKurallari(",
      "public static function bildirimTurleri(",
      "private static function listByTable(",
      "private static function listHierarchical("
    ];
    for (const owner of readOwners) {
      const start = referans.indexOf(owner);
      expect(start, `${owner} missing`).toBeGreaterThan(-1);
      const rest = referans.slice(start);
      const bounds = [
        rest.indexOf("\n    public static function", 1),
        rest.indexOf("\n    private static function", 1)
      ].filter((index) => index > 0);
      const block = bounds.length > 0 ? rest.slice(0, Math.min(...bounds)) : rest;
      expect(block, `${owner} must deny the self-service-only role`).toContain(guardCall);
    }
    expect(referans.split(guardCall).length - 1).toBe(readOwners.length);
  });

  it("FE permission hydration consumes personel_id (useRoleAccess + ProtectedRoute)", () => {
    const hook = readFileSync(resolve(root, "src/hooks/use-role-access.ts"), "utf8");
    const route = readFileSync(resolve(root, "src/router/ProtectedRoute.tsx"), "utf8");
    const menu = readFileSync(resolve(root, "src/components/main-menu/MainMenu.tsx"), "utf8");
    const routes = readFileSync(resolve(root, "src/app/routes.tsx"), "utf8");
    expect(hook).toContain("hasUserPermission");
    expect(hook).toContain("personel_id");
    expect(route).toContain("hasUserPermission");
    expect(route).toContain("personel_id");
    // Manager MainMenu must not surface self-service; /self stays route-gated.
    expect(menu).not.toContain("menu-self-service");
    expect(menu).not.toContain("self_service.view");
    expect(menu).not.toMatch(/Öz Servis\s*\/\s*QR/i);
    expect(routes).toContain('requirePermission="self_service.view"');
    expect(routes).toContain('path="self"');
  });

  it("PHP matrix runner PASS", () => {
    const result = spawnSync("php", [phpRunner], { encoding: "utf8" });
    if (result.status !== 0) {
      throw new Error(
        `PHP runner failed (status=${result.status}):\n${result.stdout}\n${result.stderr}`
      );
    }
    expect(result.stdout).toContain("ALL_PASS personnel-linked-self-service");
  });
});
