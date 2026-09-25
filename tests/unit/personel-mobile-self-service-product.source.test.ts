import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

function read(path: string): string {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("personel mobile/PWA self-service productization", () => {
  it("locks personel home information architecture (today / kiosk / puantaj / empty)", () => {
    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home).toContain('data-testid="personel-today-attendance-section"');
    expect(home).toContain("Bugünkü Giriş / Çıkış");
    expect(home).toContain("OwnQrAttendanceBoxes");
    expect(home).toContain("QrKioskModelNote");
    expect(home).toContain("QrPuantajExpectationNote");
    expect(home).toContain('data-testid="personel-today-empty"');
    expect(home).toContain('data-testid="personel-incomplete-day-warning"');
    expect(home).toContain('data-testid="self-last-qr-empty"');
    expect(home).toContain('data-testid="personel-qr-closed-notice"');
    expect(home).toContain("personel-unbound-page");
    expect(home).toContain("QR giriş/çıkış");
    expect(home).toContain("<SelfServiceQrShortcuts />");
    // No QR core rewrite / no demo masking of production.
    expect(home).not.toContain("startQrScanner");
    expect(home).not.toMatch(/INSERT\s+INTO\s+gunluk_puantaj/i);
  });

  it("locks shared attendance boxes fail-closed copy and amir parity wiring", () => {
    const boxes = read("src/features/self-service/components/OwnQrAttendanceBoxes.tsx");
    expect(boxes).toContain('data-testid="giris-scan"');
    expect(boxes).toContain('data-testid="cikis-scan"');
    expect(boxes).toContain('data-testid="giris-scan-not-entitled"');
    expect(boxes).toContain('data-testid="cikis-scan-not-entitled"');
    expect(boxes).toContain("QR bu hesap için kapalı");
    expect(boxes).toContain("kiosk QR okut");

    const amir = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(amir).toContain("OwnQrAttendanceBoxes");
    expect(amir).toContain("QrKioskModelNote");
    expect(amir).toContain("QrPuantajExpectationNote");
    expect(amir).toContain("<SelfServiceQrShortcuts");
    expect(amir).toContain("birim-amiri-edit-daily");
    expect(amir).toContain('hasPermission("self_service.qr.scan")');
  });

  it("locks QR scan field copy, camera errors, success CTA zone", () => {
    const scan = read("src/features/self-service/pages/PersonelQrScanPage.tsx");
    expect(scan).toContain("personel-mobile-shell qr-scan-page");
    expect(scan).toContain("QrPuantajExpectationNote");
    expect(scan).toContain("Kiosk ekranındaki yeni kodu tekrar okutun");
    expect(scan).toContain("Kendi şube kiosk kodunu okutun");
    expect(scan).toContain("Şube kiosk ekranındaki QR kodunu okutun.");
    expect(scan).toContain("Giriş kaydedildi");
    expect(scan).toContain("Çıkış kaydedildi");
    expect(scan).toContain('data-testid="qr-scan-cta-zone"');
    expect(scan).toContain('data-testid="qr-scan-success"');
    expect(scan).toContain('data-testid="qr-scan-error"');
    expect(scan).toContain('submit("GIRIS")');
    expect(scan).toContain('submit("CIKIS")');
    expect(scan).toContain("FORBIDDEN");
    expect(scan).toContain("puantaja otomatik yazılmaz");
    expect(scan).toContain("Bu işlem daha önce kaydedilmiş.");
    expect(scan).toContain("Kayıt oluşturulamadı. Tekrar deneyin.");
    expect(scan).not.toContain("(idempotent)");
    expect(scan).not.toContain("candidate / apply");
    expect(scan).not.toMatch(/default:\s*\n\s*return error\.message/);
    expect(scan).not.toContain("self-service-home__header");

    const scanner = read("src/features/self-service/qr/qr-scanner.ts");
    expect(scanner).toContain("Kamera için güvenli bağlantı (HTTPS) gerekir.");
    expect(scanner).toContain("Kamera izni reddedildi. Tarayıcı ayarlarından kamera erişimini açın.");
    expect(scanner).toContain("Bu cihazda kullanılabilir kamera bulunamadı.");
  });

  it("locks QR history empty/list states, Turkish labels, and expectation note", () => {
    const history = read("src/features/self-service/pages/PersonelQrHistoryPage.tsx");
    expect(history).toContain("QrPuantajExpectationNote");
    expect(history).toContain("QR Eşleşmeleri");
    expect(history).toContain("Ham QR Kayıtları");
    expect(history).toContain('data-testid="personel-qr-raw-empty"');
    expect(history).toContain('data-testid="personel-qr-intervals-empty"');
    expect(history).toContain("EmptyState");
    expect(history).toContain("Çıkış eksik");
    expect(history).toContain("Giriş eksik");
    expect(history).toContain("Şube uyuşmazlığı");
    expect(history).toContain("qr-event-badge");
    expect(history).toContain("formatSelfServiceDateTime");
    expect(history).toContain("QR hareketleri yüklenemedi. Tekrar deneyin.");
    expect(history).not.toContain("Henuz QR hareketi yok");
    expect(history).not.toContain("Cikis eksik");
    expect(history).not.toMatch(/\? error\.message/);
  });

  it("locks kiosk model note + CSS owner without parallel style system", () => {
    const note = read("src/features/self-service/components/QrKioskModelNote.tsx");
    expect(note).toContain("qr-kiosk-model-note");
    expect(note).toContain("Şube kiosk QR");
    expect(note).toContain("Kendi kimlik QR");

    const expectation = read("src/features/self-service/components/QrPuantajExpectationNote.tsx");
    expect(expectation).toContain("QR kaydı kontrol sonrası puantaja işlenir.");
    expect(expectation).not.toContain("candidate / apply");
    expect(expectation).not.toContain("aday uygulaması");

    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home).toContain("Özet yüklenemedi. Tekrar deneyin.");
    expect(home).toContain("QR giriş/çıkış bu personel için henüz açık değil");
    expect(home).not.toMatch(/setError\(cause instanceof Error \? cause\.message/);

    const css = read("src/features/self-service/self-service.css");
    expect(css).toContain(".qr-scan-cta-zone");
    expect(css).toContain(".self-service-action--primary");
    expect(css).toContain(".qr-event-badge");
    expect(css).toContain(".pm-box-closed");
    expect(css).toContain("safe-area-inset-bottom");
    expect(css).toContain("min-height: 48px");
    expect(css).toContain(".pm-context-bar");
    expect(css).toContain("36dvh");

    const main = read("src/styles/main.css");
    expect(main).toContain('../features/self-service/self-service.css');
  });

  it("keeps yetkisiz fail-closed route copy consistent with QR entitlement", () => {
    const routes = read("src/app/routes.tsx");
    expect(routes).toContain('data-testid="yetkisiz-page"');
    expect(routes).toContain("QR giriş/çıkış yalnızca bağlı ve uygun personel");
    expect(routes).toContain('requirePermission="self_service.qr.scan"');
    expect(routes).toContain('requirePermission="self_service.qr.events.view"');
  });
});
