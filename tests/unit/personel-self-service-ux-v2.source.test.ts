import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = resolve(__dirname, "../..");

function read(rel: string): string {
  return readFileSync(resolve(root, rel), "utf8");
}

describe("PERSONEL_SELF_SERVICE_PRODUCT_UX_V2 notification + policy owners", () => {
  it("gates salary/SGK reminders and management tamamlamalar away from PERSONEL shell", () => {
    const shell = read("src/components/shell/ShellHeaderActions.tsx");
    expect(shell).toContain('role === "PERSONEL"');
    expect(shell).toContain("isPersonelRole");
    expect(shell).toContain("fetchInboxNotifications");
    expect(shell).toContain('aria-label="Giriş / Çıkış Geçmişim"');
    expect(shell).toContain('useBildirimlerHeaderPreview(canViewBildirimler && !isPersonelRole)');
    expect(shell).toMatch(/if \(isPersonelRole\)[\s\S]*personelInboxItems/);
    expect(shell).toMatch(/uiProfile === "birim_amiri" \|\| isPersonelRole/);
  });

  it("keeps LateEarly 30dk policy and early-exit confirm on canonical PHP owner", () => {
    const late = read("api/src/Services/Attendance/LateEarlyInfoService.php");
    expect(late).toContain("DEFAULT_GEC_TOLERANS_DK = 30");
    expect(late).toContain("DEFAULT_ERKEN_TOLERANS_DK = 30");
    expect(late).toContain("evaluateEarlyExitConfirmation");
    expect(late).toContain("formatDurationHuman");
    expect(late).toContain("Ücret Kesintisi Durumunu Amirinizle Görüşün");
    expect(late).toContain("never invents 08:30/17:40");
    expect(late).not.toMatch(/beklenen_giris_saati'\s*=>\s*'08:30/);
    expect(late).not.toMatch(/'08:30'\s*,/);

    const event = read("api/src/Services/Qr/QrAttendanceEventService.php");
    expect(event).toContain("early_exit_confirmed");
    expect(event).toContain("confirmation_required");
    expect(event).toContain("evaluateEarlyExitConfirmation");

    const correction = read("api/src/Services/Qr/QrAttendanceCorrectionService.php");
    expect(correction).toContain("CORRECTION_WINDOW_CLOSED");
    expect(correction).toContain("AttendanceBusinessDayService::isCorrectionAllowedNow");
    expect(correction).toContain("Düzeltme Talebiniz Amirinize İletildi.");
  });

  it("personel home no longer hosts duplicate bell or employer leakage surfaces", () => {
    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home).not.toContain("personel-notification-bell");
    expect(home).not.toContain("self-missing-info-warning");
    expect(home).not.toContain("SelfServiceQrShortcuts");
    expect(home).not.toContain("Bugünkü Giriş");
    expect(home).toContain("Giriş Saatinizle İlgili Düzeltme Talebi Oluşturulsun mu?");
  });
});
