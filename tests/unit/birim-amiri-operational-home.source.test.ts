import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

const ROOT = path.resolve(__dirname, "../..");

function read(rel: string) {
  return readFileSync(path.join(ROOT, rel), "utf8");
}

describe("BIRIM_AMIRI operational home owners", () => {
  it("home index uses self-service owner for BIRIM_AMIRI instead of MainMenu", () => {
    const routes = read("src/app/routes.tsx");
    expect(routes).toContain('session?.user.rol === "BIRIM_AMIRI"');
    expect(routes).toContain("<BirimAmiriOperationalHomePage />");
    expect(routes).not.toContain("Üst Amire Gönder");
    const home = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(home).not.toContain("Üst Amire Gönder");
  });

  it("operational home reuses the canonical QR owner and keeps daily notification entry on /bildirimler", () => {
    const home = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(home).toContain('to="/bildirimler"');
    expect(home).toContain("Günlük Bildirimi Düzenle");
    expect(home).toContain("Günlük Bildirimi Tamamla");
    // QR CTA/link owner'ı paylaşılan component'tir: paralel QR UI yok.
    expect(home).toContain("<SelfServiceQrShortcuts");
    expect(home).not.toContain('data-testid="self-qr-scan-link"');
    // Pilot UX parity: own GİRİŞ/ÇIKIŞ scan CTAs via shared OwnQrAttendanceBoxes (not inline).
    expect(home).toContain("<OwnQrAttendanceBoxes");
    expect(home).toContain("qrEnabled={qrEnabled}");
    expect(home).toContain('hasPermission("self_service.qr.scan")');
    expect(home).not.toContain('data-testid="giris-scan"');
    // Fallback empty state when today is unavailable (no parallel scan UI).
    expect(home).toContain("pm-attendance-grid--readonly");
    expect(home).toContain("Kendi Bilgilerim");
    expect(home).toContain("Birimim");
    expect(home).toContain("birim-amiri-pazar-mesai-prompt");
    expect(home).toContain("birim-amiri-eksik-giris-warning");

    const boxes = read("src/features/self-service/components/OwnQrAttendanceBoxes.tsx");
    expect(boxes).toContain('data-testid="giris-scan"');
    expect(boxes).toContain('data-testid="cikis-scan"');
    expect(boxes).toContain('data-testid="giris-scan-not-entitled"');
  });

  it("QR entitlement owner is role-independent and shared (no role demotion)", () => {
    const shortcuts = read("src/features/self-service/components/SelfServiceQrShortcuts.tsx");
    expect(shortcuts).toContain('hasPermission("self_service.qr.scan")');
    expect(shortcuts).toContain('data-testid="self-qr-scan-link"');
    expect(shortcuts).toContain('data-testid="self-qr-history-link"');
    // Rol kararı component'te yok; karar permission owner'ında.
    expect(shortcuts).not.toContain('"PERSONEL"');

    const routes = read("src/app/routes.tsx");
    expect(routes).toContain("<SelfServiceQrShortcuts");
    expect(routes).toContain('session?.user.rol === "BIRIM_AMIRI"');
    expect(routes).toContain("<BirimAmiriOperationalHomePage />");
  });

  it("backend unit roster uses OrgScope and does not reuse fetchGunlukRoster fallback", () => {
    const service = read("api/src/Services/Bildirim/BirimAmiriGunlukDurumService.php");
    expect(service).toContain("OrgScope::appendPersonelOrgFilter");
    expect(service).not.toContain("bagli_amir_id");

    const controller = read("api/src/Controllers/BildirimlerController.php");
    const durumFn = controller.indexOf("function birimGunlukDurum");
    const nextFn = controller.indexOf("public static function ", durumFn + 10);
    const durumSlice = controller.slice(durumFn, nextFn === -1 ? undefined : nextFn);
    expect(durumSlice).toContain("BirimAmiriGunlukDurumService::build");
    expect(durumSlice).not.toContain("fetchGunlukRoster");
    expect(controller).toContain("function birimGunlukDurum");
    expect(controller).toContain("OrgScope::assertRequiredAssignment");
    expect(controller).toContain("$rol !== 'BIRIM_AMIRI'");

    const router = read("api/src/Router.php");
    expect(router).toContain("/bildirimler/birim-gunluk-durum");
    expect(router).toContain("birimGunlukDurum");
  });

  it("does not add a new daily completion path; header summary owner stays gunluk tamamlamalari", () => {
    const home = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(home).not.toContain("completeGunlukTamamlama");
    const shell = read("src/components/shell/ShellHeaderActions.tsx");
    expect(shell).toContain("formatHeaderGunlukTamamlamaCopy");
    expect(shell).toContain("tamamlama-");
  });

  it("PERSONEL self-service page delegates QR scan CTAs to the shared owner", () => {
    const selfHome = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(selfHome).toContain("<SelfServiceQrShortcuts />");
    expect(selfHome).toContain("<OwnQrAttendanceBoxes");
    expect(selfHome).toContain("qrEnabled={qrEnabled}");
    expect(selfHome).not.toContain('data-testid="giris-scan"');
    const boxes = read("src/features/self-service/components/OwnQrAttendanceBoxes.tsx");
    expect(boxes).toContain("giris-scan");
    expect(boxes).toContain("cikis-scan");
    const shortcuts = read("src/features/self-service/components/SelfServiceQrShortcuts.tsx");
    expect(shortcuts).toContain("self-qr-scan-link");
    expect(shortcuts).toContain("self-qr-history-link");
  });
});
