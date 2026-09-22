import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import {
  getRolePermissions,
  hasRolePermission,
  ROUTE_PERMISSION
} from "../../src/lib/authorization/role-permissions";

function read(path: string): string {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("sube + personel mobile QR puantaj wiring", () => {
  it("grants qr.kiosk.display to SUBE without yonetim-paneli.manage inflation", () => {
    expect(hasRolePermission("SUBE_YONETICISI", "qr.kiosk.display")).toBe(true);
    expect(hasRolePermission("SUBE_YONETICISI", "yonetim-paneli.manage")).toBe(false);
    expect(hasRolePermission("GENEL_YONETICI", "qr.kiosk.display")).toBe(true);
    expect(hasRolePermission("SISTEM_YONETICISI", "qr.kiosk.display")).toBe(true);
    expect(hasRolePermission("PERSONEL", "qr.kiosk.display")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "qr.kiosk.display")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "qr.kiosk.display")).toBe(false);

    const php = read("api/src/Auth/RolePermissions.php");
    const ts = read("src/lib/authorization/role-permissions.ts");
    expect(php).toContain("'qr.kiosk.display'");
    expect(ts).toContain('"qr.kiosk.display"');
    expect(ROUTE_PERMISSION.qrKioskPage).toBe("qr.kiosk.display");
  });

  it("wires QrKioskController to scoped qr.kiosk.display", () => {
    const controller = read("api/src/Controllers/QrKioskController.php");
    expect(controller).toContain("qr.kiosk.display");
    expect(controller).not.toContain("yonetim-paneli.manage");
    expect(controller).toContain("OrgScope::assertRequiredAssignment");
    expect(controller).toContain("SubeScope::resolveScope");
  });

  it("keeps PERSONEL self-service only and denies management list permissions", () => {
    const personel = getRolePermissions("PERSONEL");
    expect(personel).toEqual([
      "self_service.view",
      "self_service.puantaj.view",
      "self_service.yillik_izin.view",
      "self_service.fazla_calisma.view",
      "self_service.qr.scan",
      "self_service.qr.events.view",
      "self_service.attendance.correct"
    ]);
    expect(hasRolePermission("PERSONEL", "personeller.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "puantaj.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "yonetim-paneli.manage")).toBe(false);
  });

  it("denies PERSONEL login when bound personel is PASIF", () => {
    const login = read("api/src/Auth/LoginController.php");
    expect(login).toContain("assertPersonelRoleLoginAllowed");
    expect(login).toContain("PERSONEL_INACTIVE");
    expect(login).toContain("$rol === 'PERSONEL'");
  });

  it("exposes last QR + completeness on /me and mobile home", () => {
    const me = read("api/src/Controllers/MeController.php");
    expect(me).toContain("PersonelCompletenessService::evaluate");
    expect(me).toContain("last_qr_event");
    expect(me).toContain("completeness");

    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home).toContain("self-missing-info-warning");
    expect(home).toContain("self-last-qr-event");
    // QR CTA/link owner'ı paylaşılan component'tir (rol bağımsız karar).
    expect(home).toContain("<SelfServiceQrShortcuts />");
    const shortcuts = read("src/features/self-service/components/SelfServiceQrShortcuts.tsx");
    expect(shortcuts).toContain("self-qr-scan-link");
    expect(shortcuts).toContain('hasPermission("self_service.qr.scan")');
  });

  it("gates manager QR kiosk CTA and live summary by permission", () => {
    const ops = read("src/features/puantaj/components/QrGirisCikisOperationSection.tsx");
    expect(ops).toContain('hasPermission("qr.kiosk.display")');
    expect(ops).toContain("puantaj-qr-live-summary");
    expect(ops).toContain("Henüz giriş yok");

    const hook = read("src/features/puantaj/hooks/useManagerQrAttendance.ts");
    expect(hook).toContain("include_absent: sameDay");

    const service = read("api/src/Services/Qr/QrAttendanceIntervalReadService.php");
    expect(service).toContain("$includeAbsent");
    expect(service).toContain("NO_SCAN");
  });

  it("keeps EXPLICIT GIRIS/CIKIS scan contract with fail-closed UX copy", () => {
    const scan = read("src/features/self-service/pages/PersonelQrScanPage.tsx");
    expect(scan).toContain('submit("GIRIS")');
    expect(scan).toContain('submit("CIKIS")');
    expect(scan).toContain("QR süresi doldu");
    expect(scan).toContain("Bu QR sizin çalışma şubenize ait değil");
    expect(scan).toContain("Bağlantı yok, işlem kaydedilmedi");
    expect(scan).toContain("Giriş kaydedildi");

    const scanner = read("src/features/self-service/qr/qr-scanner.ts");
    expect(scanner).toContain("NotAllowedError");
    expect(scanner).toContain("isSecureContext");
  });
});
