import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

function read(path: string): string {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("personel mobile/PWA self-service productization", () => {
  it("locks simplified personel home (attendance boxes only)", () => {
    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home).toContain('data-testid="personel-today-attendance-section"');
    expect(home).toContain("OwnQrAttendanceBoxes");
    expect(home).not.toContain("QrKioskModelNote");
    expect(home).not.toContain("QrPuantajExpectationNote");
    expect(home).not.toContain('data-testid="personel-today-empty"');
    expect(home).not.toContain('data-testid="personel-incomplete-day-warning"');
    expect(home).not.toContain('data-testid="self-last-qr-empty"');
    expect(home).not.toContain('data-testid="self-missing-info-warning"');
    expect(home).not.toContain("pm-context-bar");
    expect(home).toContain('data-testid="personel-qr-closed-notice"');
    expect(home).toContain("personel-unbound-page");
    expect(home).toContain("QR giriş/çıkış");
    expect(home).not.toContain("<SelfServiceQrShortcuts />");
    expect(home).toContain("AttendanceCorrectionRequestModal");
    expect(home).not.toContain("startQrScanner");
    expect(home).not.toMatch(/INSERT\s+INTO\s+gunluk_puantaj/i);

    const correctModal = read("src/features/self-service/components/AttendanceCorrectionRequestModal.tsx");
    expect(correctModal).toContain("Düzeltme Talebiniz Amirinize İletildi.");
    expect(correctModal).toContain("CORRECTION_WINDOW_CLOSED");
    expect(correctModal).toContain("Giriş Saatinizle İlgili Düzeltme Talebi Oluşturulsun mu?");
  });

  it("locks shared attendance boxes fail-closed copy and amir parity wiring", () => {
    const boxes = read("src/features/self-service/components/OwnQrAttendanceBoxes.tsx");
    expect(boxes).toContain('data-testid="giris-scan"');
    expect(boxes).toContain('data-testid="cikis-scan"');
    expect(boxes).toContain('data-testid="giris-scan-not-entitled"');
    expect(boxes).toContain('data-testid="cikis-scan-not-entitled"');
    expect(boxes).toContain("QR bu hesap için kapalı");
    expect(boxes).toContain("kiosk QR okut");
    expect(boxes).toContain("pm-box-pencil");
    expect(boxes).toContain("correction_allowed === true");
    expect(boxes).toContain("Giriş Saati Düzeltme Talebi");

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
    expect(scan).not.toContain("QrPuantajExpectationNote");
    expect(scan).toContain("QR Kodunun Süresi Doldu. Yeni Kodu Okutun.");
    expect(scan).toContain("Bu QR Kodu Çalışma Yerinizle Eşleşmiyor.");
    expect(scan).toContain("İnternet Bağlantısı Yok. İşlem Kaydedilmedi.");
    expect(scan).not.toContain("Şube kiosk ekranındaki QR kodunu okutun.");
    expect(scan).toContain("Girişiniz kaydedildi");
    expect(scan).toContain("Çıkışınız kaydedildi");
    expect(scan).toContain('data-testid="qr-scan-cta-zone"');
    expect(scan).toContain('data-testid="qr-scan-success"');
    expect(scan).toContain('data-testid="qr-scan-error"');
    expect(scan).toContain('submit("GIRIS")');
    expect(scan).toContain('submit("CIKIS")');
    expect(scan).toContain("FORBIDDEN");
    expect(scan).not.toContain("puantaja otomatik yazılmaz");
    expect(scan).toContain("Bu işlem daha önce kaydedilmiş.");
    expect(scan).toContain("Kayıt oluşturulamadı. Tekrar deneyin.");
    expect(scan).toContain("QR Okut");
    expect(scan).toContain("QR Okutun");
    expect(scan).toContain("early_exit_confirmed");
    expect(scan).toContain("early-exit-confirm-modal");
    expect(scan).toContain('data-testid="qr-scan-late-early-info"');
    expect(scan).not.toContain("late-early-info-modal");
    expect(scan).not.toContain("Giriş / Çıkış Geçmişim");
    expect(scan).toContain("qr-scan-video-wrap--collapsed");
    expect(scan).not.toContain("(idempotent)");
    expect(scan).not.toContain("candidate / apply");
    expect(scan).not.toMatch(/default:\s*\n\s*return error\.message/);
    expect(scan).not.toContain("self-service-home__header");

    const scanner = read("src/features/self-service/qr/qr-scanner.ts");
    expect(scanner).toContain("Kamera için güvenli bağlantı (HTTPS) gerekir.");
    expect(scanner).toContain("Telefon Ayarlarınızdan Kamera Erişimine İzin Verin.");
    expect(scanner).toContain("Bu cihazda kullanılabilir kamera bulunamadı.");
  });

  it("locks QR history calendar UX without technical interval copy", () => {
    const history = read("src/features/self-service/pages/PersonelQrHistoryPage.tsx");
    expect(history).not.toContain("QrPuantajExpectationNote");
    expect(history).not.toContain("fetchMeQrAraliklari");
    expect(history).not.toContain("Ham QR Kayıtları");
    expect(history).not.toContain("QR Eşleşmeleri");
    expect(history).toContain('data-testid="qr-history-calendar"');
    expect(history).toContain('data-testid="qr-history-day-detail"');
    expect(history).toContain("fetchMeQrHareketleri");
    expect(history).toContain("Pzt");
    expect(history).toContain("Giriş Kaydı Bulunamadı.");
    expect(history).toContain("Giriş / Çıkış Geçmişi Yüklenemedi. Tekrar Deneyin.");
    expect(history).toContain("AttendanceCorrectionRequestModal");
    expect(history).toContain("history-giris-correct");
    expect(history).not.toContain('to="/self/qr-okut"');
    expect(history).not.toContain("Henuz QR hareketi yok");
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
    expect(css).toContain(".pm-box-pencil");
    expect(css).toContain(".qr-history-grid");
    expect(css).toContain("safe-area-inset-bottom");
    expect(css).toContain("min-height: 48px");
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
