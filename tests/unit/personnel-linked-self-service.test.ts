import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import {
  SELF_SERVICE_BASELINE_PERMISSIONS,
  collarAllowsQrSelfService,
  getEffectivePermissions,
  getRolePermissions,
  hasPersonnelLinkedSelfServiceEligibility,
  hasQrSelfServiceEntitlement,
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
    expect(hasUserPermission("PERSONEL", "self_service.view", 158, "Mavi Yaka")).toBe(true);
    expect(hasUserPermission("PERSONEL", "personeller.view", 158, "Mavi Yaka")).toBe(false);
    expect(hasUserPermission("PERSONEL", "puantaj.update", 158, "Mavi Yaka")).toBe(false);
  });

  it("A) PERSONEL + Mavi Yaka: QR allowed (scan + events)", () => {
    expect(hasUserPermission("PERSONEL", "self_service.qr.scan", 158, "Mavi Yaka")).toBe(true);
    expect(hasUserPermission("PERSONEL", "self_service.qr.events.view", 158, "Mavi Yaka")).toBe(true);
    expect(getEffectivePermissions("PERSONEL", 158, "Mavi Yaka")).toContain("self_service.qr.scan");
  });

  it("B) PERSONEL + Beyaz Yaka: QR denied", () => {
    expect(hasUserPermission("PERSONEL", "self_service.qr.scan", 158, "Beyaz Yaka")).toBe(false);
    expect(hasUserPermission("PERSONEL", "self_service.qr.events.view", 158, "Beyaz Yaka")).toBe(false);
    expect(getEffectivePermissions("PERSONEL", 158, "Beyaz Yaka")).not.toContain("self_service.qr.scan");
  });

  it("C) PERSONEL + Diğer: QR denied", () => {
    expect(hasUserPermission("PERSONEL", "self_service.qr.scan", 158, "Diğer")).toBe(false);
    expect(hasUserPermission("PERSONEL", "self_service.qr.events.view", 158, "Diğer")).toBe(false);
  });

  it("D) PERSONEL + null/unknown collar: QR denied (fail-closed)", () => {
    // 'MAVİ YAKA' (Turkish dotted uppercase) stays denied on both sides: PHP
    // mb_strtolower and JS toLowerCase map it to "i" + combining dot, not "i".
    for (const collar of [null, undefined, "", "   ", "Bilinmeyen Statu", "MAVI", "MAVİ YAKA"]) {
      expect(
        hasUserPermission("PERSONEL", "self_service.qr.scan", 158, collar),
        `collar=${JSON.stringify(collar)}`
      ).toBe(false);
    }
  });

  it("D-parity) ASCII case folding matches the canonical collar on both sides", () => {
    expect(hasUserPermission("PERSONEL", "self_service.qr.scan", 158, "MAVI YAKA")).toBe(true);
    expect(collarAllowsQrSelfService("  mavi   yaka  ")).toBe(true);
  });

  it("G) Non-QR own self-service is preserved for every collar", () => {
    const nonQrSelf = [
      "self_service.view",
      "self_service.puantaj.view",
      "self_service.yillik_izin.view",
      "self_service.fazla_calisma.view",
      "self_service.attendance.correct"
    ] as const;
    for (const collar of ["Mavi Yaka", "Beyaz Yaka", "Diğer", null]) {
      for (const permission of nonQrSelf) {
        expect(
          hasUserPermission("PERSONEL", permission, 158, collar),
          `${permission} collar=${JSON.stringify(collar)}`
        ).toBe(true);
      }
    }
  });

  it("H) QR/kart entitlement is role-independent: bound personel + canonical collar", () => {
    // Kanonik kural: QR hakkı uygulama rolüyle verilmez; bağlı personel ve
    // kanonik collar ile verilir. Yönetim rolleri de aynı kapıdan geçer.
    for (const role of [
      "GENEL_YONETICI",
      "IK_SORUMLUSU",
      "SUBE_YONETICISI",
      "BOLUM_YONETICISI",
      "BIRIM_AMIRI"
    ] as const) {
      expect(hasUserPermission(role, "self_service.qr.scan", 173, "Mavi Yaka")).toBe(true);
      expect(hasUserPermission(role, "self_service.qr.events.view", 173, "Mavi Yaka")).toBe(true);
      expect(getEffectivePermissions(role, 173, "Mavi Yaka")).toContain("self_service.qr.scan");
    }
  });

  it("H2) bound Beyaz Yaka manager loses QR but keeps management + non-QR self", () => {
    for (const role of ["BIRIM_AMIRI", "BOLUM_YONETICISI", "SUBE_YONETICISI"] as const) {
      expect(hasUserPermission(role, "self_service.qr.scan", 173, "Beyaz Yaka")).toBe(false);
      expect(hasUserPermission(role, "self_service.qr.events.view", 173, "Beyaz Yaka")).toBe(false);
      expect(getEffectivePermissions(role, 173, "Beyaz Yaka")).not.toContain("self_service.qr.scan");
      // Yönetim yetkisi ve non-QR self-service korunur.
      expect(hasUserPermission(role, "self_service.view", 173, "Beyaz Yaka")).toBe(true);
      expect(getEffectivePermissions(role, 173, "Beyaz Yaka")).toContain("self_service.view");
    }
    expect(hasUserPermission("BOLUM_YONETICISI", "aylik_bolum_onayi.approve", 173, "Beyaz Yaka")).toBe(true);
    expect(hasUserPermission("BIRIM_AMIRI", "puantaj.amir_kontrol", 173, "Beyaz Yaka")).toBe(true);
  });

  it("H3) unbound user never gets QR, even with a canonical collar value", () => {
    for (const role of ["PERSONEL", "BIRIM_AMIRI", "BOLUM_YONETICISI", "GENEL_YONETICI"] as const) {
      for (const binding of [null, undefined, 0, -1]) {
        expect(
          hasUserPermission(role, "self_service.qr.scan", binding, "Mavi Yaka"),
          `${role} binding=${String(binding)}`
        ).toBe(false);
        expect(hasUserPermission(role, "self_service.qr.events.view", binding, "Mavi Yaka")).toBe(false);
      }
      expect(hasQrSelfServiceEntitlement(null, "Mavi Yaka")).toBe(false);
    }
  });

  it("BOLUM_YONETICISI + personel_id: management + self (Sinem/Ismail shape)", () => {
    // Sinem-shape
    expect(hasUserPermission("BOLUM_YONETICISI", "personeller.view", 173)).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "aylik_bolum_onayi.approve", 173)).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.view", 173)).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.qr.scan", 173, "Mavi Yaka")).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.qr.events.view", 173, "Mavi Yaka")).toBe(true);
    // İsmail-shape
    expect(hasUserPermission("BOLUM_YONETICISI", "personeller.view", 120)).toBe(true);
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.qr.scan", 120, "Mavi Yaka")).toBe(true);
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
    expect(hasUserPermission("BOLUM_YONETICISI", "self_service.qr.scan", 0, "Mavi Yaka")).toBe(false);
  });

  it("Salih post-fix simulation: BOLUM + personel_id=158 preserves QR/self", () => {
    const perms = getEffectivePermissions("BOLUM_YONETICISI", 158, "Mavi Yaka");
    expect(perms).toContain("aylik_bolum_onayi.approve");
    expect(perms).toContain("self_service.qr.scan");
    expect(perms).toContain("self_service.qr.events.view");
    expect(perms).toContain("self_service.puantaj.view");
    // Rol düşürülmez: yönetim izinleri hâlâ yerinde.
    const beyaz = getEffectivePermissions("BOLUM_YONETICISI", 158, "Beyaz Yaka");
    expect(beyaz).toContain("aylik_bolum_onayi.approve");
    expect(beyaz).not.toContain("self_service.qr.scan");
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

  it("E/F) FE QR surface owner is shared and the route gate stays permission-based", () => {
    const home = readFileSync(
      resolve(root, "src/features/self-service/pages/PersonelSelfServiceHomePage.tsx"),
      "utf8"
    );
    expect(home).toContain('hasPermission("self_service.qr.scan")');
    // PERSONEL product home: OwnQrAttendanceBoxes only — no shortcut strip / employer notes.
    expect(home).not.toContain("<SelfServiceQrShortcuts");
    expect(home).not.toContain('data-testid="self-qr-scan-link"');
    expect(home).not.toContain('data-testid="self-qr-history-link"');
    expect(home).toContain("<OwnQrAttendanceBoxes");
    expect(home).toContain("qrEnabled={qrEnabled}");
    expect(home).not.toContain('data-testid="giris-scan"');
    expect(home).not.toContain('data-testid="giris-scan-not-entitled"');

    const boxes = readFileSync(
      resolve(root, "src/features/self-service/components/OwnQrAttendanceBoxes.tsx"),
      "utf8"
    );
    expect(boxes).toContain("qrEnabled");
    expect(boxes).toContain("const girisActionable = qrEnabled && today.can_scan_giris;");
    expect(boxes).toContain("const cikisActionable = qrEnabled && today.can_scan_cikis;");
    expect(boxes).toContain('data-testid="giris-scan"');
    expect(boxes).toContain('data-testid="cikis-scan"');
    expect(boxes).toContain('data-testid="giris-scan-not-entitled"');
    expect(boxes).toContain('data-testid="cikis-scan-not-entitled"');

    const shortcuts = readFileSync(
      resolve(root, "src/features/self-service/components/SelfServiceQrShortcuts.tsx"),
      "utf8"
    );
    expect(shortcuts).toContain('hasPermission("self_service.qr.scan")');
    for (const testId of ["self-qr-scan-link", "self-qr-history-link"]) {
      expect(shortcuts).toContain(`data-testid="${testId}"`);
    }

    // Personnel-linked management homes reuse the same owner (no parallel UI).
    const birimHome = readFileSync(
      resolve(root, "src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx"),
      "utf8"
    );
    expect(birimHome).toContain("<SelfServiceQrShortcuts");
    expect(birimHome).toContain("<OwnQrAttendanceBoxes");
    expect(birimHome).toContain("qrEnabled={qrEnabled}");
    const routes = readFileSync(resolve(root, "src/app/routes.tsx"), "utf8");
    expect(routes).toContain("<SelfServiceQrShortcuts");

    // Direct route access is denied by the shared, permission-based route guard.
    const route = readFileSync(resolve(root, "src/router/ProtectedRoute.tsx"), "utf8");
    expect(route).toContain("session.user.personel_tipi_ad");
    expect(route).toContain("personelId, personelTipiAd");
    // QR route guard must never re-introduce a role condition.
    expect(route).not.toMatch(/rol\s*===?\s*"PERSONEL"/);
    const hook = readFileSync(resolve(root, "src/hooks/use-role-access.ts"), "utf8");
    expect(hook).toContain("personelTipiAd");
    const authApi = readFileSync(resolve(root, "src/api/auth.api.ts"), "utf8");
    expect(authApi).toContain("personel_tipi_ad");
  });

  it("BE QR surfaces are role-independent collar-gated (authoritative, not FE-only)", () => {
    const perms = readFileSync(rolePermissionsPhp, "utf8");
    expect(perms).toContain("QR_SELF_SERVICE_COLLAR = 'Mavi Yaka'");
    expect(perms).toContain("personel_tipi_ad");
    // Rol bağımsız tek karar noktası; PERSONEL-only kapı kalmadı.
    expect(perms).toContain("function hasQrSelfServiceEntitlement");
    expect(perms).toContain("return self::hasQrSelfServiceEntitlement($user);");
    expect(perms).not.toMatch(/normalizeRole\([^)]*\)\s*===\s*'PERSONEL'\s*\n\s*&&\s*self::isQrSelfServicePermission/);
    // ucret_tipi is documented as not a collar source and is never read.
    expect(perms).toContain("ucret_tipi` is intentionally NOT a collar source");
    expect(perms).not.toContain("ucret_tipi_id");
    expect(perms).not.toMatch(/p\.ucret_tipi/);

    const ctx = readFileSync(selfContextPhp, "utf8");
    expect(ctx).toContain("LEFT JOIN personel_tipleri pt ON pt.id = p.personel_tipi_id");
    expect(ctx).toContain("function loadCollar");

    const middleware = readFileSync(resolve(root, "api/src/Auth/AuthMiddleware.php"), "utf8");
    expect(middleware).toContain("SelfPersonelContext::loadCollar");
    expect(middleware).toContain("personel_tipi_ad");

    const login = readFileSync(resolve(root, "api/src/Auth/LoginController.php"), "utf8");
    expect(login).toContain("SelfPersonelContext::loadCollar");

    const me = readFileSync(meControllerPhp, "utf8");
    expect(me).toContain("RolePermissions::assert($user, 'self_service.qr.scan')");
    expect(me).toContain("RolePermissions::assert($user, 'self_service.qr.events.view')");
    expect(me).toContain("if (RolePermissions::has($user, 'self_service.qr.events.view'))");

    const today = readFileSync(
      resolve(root, "api/src/Services/Qr/QrAttendanceTodayService.php"),
      "utf8"
    );
    expect(today).toContain("RolePermissions::has($authUser, 'self_service.qr.scan')");
    expect(today).toContain("$caps['qr_scan'] = false;");
  });

  it("I/J) login destination unchanged: PERSONEL → self-service home, manager → main app", () => {
    const routes = readFileSync(resolve(root, "src/app/routes.tsx"), "utf8");
    const start = routes.indexOf("function HomeIndexMainMenu()");
    expect(start).toBeGreaterThan(-1);
    const end = routes.indexOf("\nfunction ", start + 1);
    const block = routes.slice(start, end);
    // PERSONEL keeps the dedicated self-service home.
    expect(block).toContain('session?.user.rol === "PERSONEL"');
    expect(block).toContain("<PersonelSelfServiceHomePage />");
    // Management roles keep the main application (MainMenu), not self-service.
    expect(block).toContain("ctx.showMainMenu ? <MainMenu");
    // The collar decision never changes the login destination.
    const collarIdx = block.indexOf("personel_tipi");
    expect(collarIdx).toBe(-1);
  });

  it("K/L) 219 canonical rule and ilkerA reservation owners are untouched", () => {
    const onboarding = readFileSync(
      resolve(root, "api/src/Services/Auth/PersonelAccountOnboardingService.php"),
      "utf8"
    );
    expect(onboarding).toContain("const PROTECTED_USERNAMES = ['ilkerA']");
    expect(onboarding).toContain("'ad' => 'Doğu Berkan', 'soyad' => 'Atmaca'");
    // Collar work must never add a person-specific authorization rule.
    for (const owner of [rolePermissionsPhp, meControllerPhp, selfContextPhp]) {
      const source = readFileSync(owner, "utf8");
      expect(source, owner).not.toMatch(/\b219\b/);
      expect(source, owner).not.toMatch(/ilkerA/i);
    }
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
