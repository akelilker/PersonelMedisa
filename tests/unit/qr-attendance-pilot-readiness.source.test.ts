import { describe, expect, it } from "vitest";
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

function read(path: string): string {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("QR attendance pilot readiness contracts", () => {
  it("locks ops pilot checklist without embedding secrets", () => {
    const checklist = "docs/ops/QR_ATTENDANCE_PILOT_READINESS_CHECKLIST.md";
    expect(existsSync(resolve(process.cwd(), checklist))).toBe(true);
    const body = read(checklist);
    expect(body).toContain("qr_signing_secret");
    expect(body).toContain("30–120");
    expect(body).toContain("AUTHENTICATED_KIOSK");
    expect(body).toContain("otomatik `gunluk_puantaj`");
    expect(body).toContain("candidate → review → controlled apply");
    expect(body).toContain("Secret değeri yazma/okuma/loglama yok");
    expect(body).not.toMatch(/qr_signing_secret\s*=\s*['\"][^'\"]{8,}/);
    expect(body).not.toMatch(/CHANGE_ME_REAL_SECRET/);
  });

  it("keeps scan path from auto-inserting gunluk_puantaj", () => {
    const scan = read("api/src/Services/Qr/QrAttendanceEventService.php");
    expect(scan).toContain("Never writes gunluk_puantaj");
    expect(scan).not.toMatch(/INSERT\s+INTO\s+gunluk_puantaj/i);

    const decision = read("api/src/Services/Qr/QrPuantajCandidateDecisionService.php");
    expect(decision).toContain("otomatik olusturma yok");
    expect(decision).toContain("QR apply mevcut gunluk_puantaj satiri gerektirir");
  });

  it("wires shared own attendance boxes + expectation note on personel and amir homes", () => {
    const boxes = read("src/features/self-service/components/OwnQrAttendanceBoxes.tsx");
    expect(boxes).toContain("giris-scan");
    expect(boxes).toContain("cikis-scan");
    expect(boxes).toContain("data-testid=\"giris-scan-not-entitled\"");

    const note = read("src/features/self-service/components/QrPuantajExpectationNote.tsx");
    expect(note).toContain("qr-puantaj-expectation-note");
    expect(note).toContain("puantaja otomatik yazılmaz");

    const personel = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(personel).toContain("OwnQrAttendanceBoxes");
    expect(personel).toContain("QrPuantajExpectationNote");
    expect(personel).toContain('testId="personel-attendance-boxes"');

    const amir = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(amir).toContain("OwnQrAttendanceBoxes");
    expect(amir).toContain("QrPuantajExpectationNote");
    expect(amir).toContain('testId="birim-amiri-own-attendance"');
    expect(amir).toContain('navigate("/self/qr-okut?event=GIRIS")');
    expect(amir).toContain('navigate("/self/qr-okut?event=CIKIS")');
    // Yönetim CTA korunur.
    expect(amir).toContain("birim-amiri-edit-daily");
  });

  it("improves scan-page field copy and camera HTTPS/permission messages", () => {
    const scanPage = read("src/features/self-service/pages/PersonelQrScanPage.tsx");
    expect(scanPage).toContain("QrPuantajExpectationNote");
    expect(scanPage).toContain("Kiosk ekranındaki yeni kodu tekrar okutun");
    expect(scanPage).toContain("Kendi şube kiosk kodunu okutun");
    expect(scanPage).toContain("Kendi kimlik QR");

    const scanner = read("src/features/self-service/qr/qr-scanner.ts");
    expect(scanner).toContain("Kamera için güvenli bağlantı (HTTPS) gerekir.");
    expect(scanner).toContain("Kamera izni reddedildi. Tarayıcı ayarlarından kamera erişimini açın.");
    expect(scanner).toContain("Bu cihazda kullanılabilir kamera bulunamadı.");
    expect(scanner).toContain("BarcodeDetector");
    expect(scanner).toContain("jsqr");
  });
});
