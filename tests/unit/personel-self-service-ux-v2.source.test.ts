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
    expect(shell).not.toContain('data-testid="header-attendance-history"');
    expect(shell).not.toContain('aria-label="Giriş / Çıkış Geçmişim"');
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
    expect(event).toContain("array $internalOptions = null");
    expect(event).not.toContain("__test_occurred_at");
    expect(event).not.toContain("__skip_late_early");

    const correction = read("api/src/Services/Qr/QrAttendanceCorrectionService.php");
    expect(correction).toContain("CORRECTION_WINDOW_CLOSED");
    expect(correction).toContain("AttendanceBusinessDayService::isCorrectionAllowedNow");
    expect(correction).toContain("Düzeltme Talebiniz Amirinize İletildi.");

    const presentation = read("api/src/Services/Qr/QrAttendancePresentationService.php");
    expect(presentation).toContain("presentDayEvent");
    expect(presentation).toContain("presentTodayBoxEvent");
    expect(presentation).toContain("approvedEffectiveLocalTime");
  });

  it("personel home no longer hosts duplicate bell or employer leakage surfaces", () => {
    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home).not.toContain("personel-notification-bell");
    expect(home).not.toContain("self-missing-info-warning");
    expect(home).not.toContain("SelfServiceQrShortcuts");
    expect(home).not.toContain("Bugünkü Giriş");
    expect(home).toContain("AttendanceCorrectionRequestModal");

    const history = read("src/features/self-service/pages/PersonelQrHistoryPage.tsx");
    expect(history).toContain("AttendanceCorrectionRequestModal");
    expect(history).toContain("history-giris-correct");
    expect(history).toContain("Giriş / Çıkış Geçmişi Yüklenemedi. Tekrar Deneyin.");
    expect(history).toContain("qr-history-event-timeline");
    expect(history).not.toContain('to="/self/qr-okut"');
    expect(history).not.toContain(">QR Okut<");
    expect(history).not.toContain("pm-secondary-nav");
    expect(history).not.toContain(">Özet<");
    expect(history).not.toContain('to="/"');

    const shell = read("src/app/AppShell.tsx");
    expect(shell).toContain("isPersonelShellRole ? null : session?.user.ad_soyad");
    expect(shell).toContain('variant={isPersonelShellRole ? "personel-shell" : "default"}');
    expect(shell).toContain("app-personel-shell");

    const header = read("src/components/shell/ShellHeaderActions.tsx");
    expect(header).toContain('subeControl.kind === "multi" && !isPersonelRole');
    expect(header).toContain("showPersonelHomeLogout");
    expect(header).toContain('aria-label={showPersonelHomeLogout ? "Çıkış" : "Ayar menüsü"}');
    expect(header).toContain('<path d="M10 17l5-5-5-5" />');
    expect(header).toContain("personel-shell-duyurular-link");
    expect(header).toContain("fetchSelfDuyurular");

    const today = read("api/src/Services/Qr/QrAttendanceTodayService.php");
    expect(today).toContain("QrAttendancePresentationService::presentTodayBoxEvent");
    expect(today).toContain("next_action");
    expect(today).toContain("resolveOpenShiftState");
    expect(today).not.toContain("&& $giris === null");
    expect(today).toContain("planned_shift");
    expect(today).toContain("plannedShiftPayload");
    expect(today).not.toContain("mesai_bitimine_kalan_label");
    expect(today).toContain("izinli_bugun");

    const event = read("api/src/Services/Qr/QrAttendanceEventService.php");
    expect(event).toContain("resolveOpenShiftState");
    expect(event).toContain("AFTER_HOURS_REENTRY_INFO");
    expect(event).toContain("Mesai Bitiminden Sonra Tekrar İşyerine Giriş Yapmıştır.");
    expect(event).toContain("'evaluate_early_exit' => false");
    expect(event).toContain("'events' => $events");

    const boxes = read("src/features/self-service/components/OwnQrAttendanceBoxes.tsx");
    expect(boxes).toContain("can_scan_giris");
    expect(boxes).toContain("can_scan_cikis");
    expect(boxes).toContain("useIstanbulMinuteClock");
    expect(boxes).toContain("İşe Geç Kaldınız.");
    expect(boxes).toContain("isGirisSaatiGecti");
    expect(boxes).not.toMatch(/today\.giris \? \(/);

    const notifications = read("src/styles/components/notifications.css");
    expect(notifications).toContain("overflow: visible");
    expect(notifications).toContain("overflow pairing");

    const meController = read("api/src/Controllers/MeController.php");
    expect(meController).toContain("'sicil_no' =>");
    expect(meController).toContain("'ise_giris_tarihi' =>");

    const selfCss = read("src/features/self-service/self-service.css");
    expect(selfCss).toContain(".pm-self-identity--home");
    expect(selfCss).not.toContain(".pm-self-home-info");

    const businessDay = read("api/src/Services/Attendance/AttendanceBusinessDayService.php");
    expect(businessDay).toContain("resolveWorkDay");
    expect(businessDay).toContain("fail closed");
    expect(businessDay).toContain("No Mon–Fri hardcode");
    expect(businessDay).toContain("No 08:30/17:40 invent");
    expect(businessDay).toContain("loadPlannedDay");
    expect(businessDay).not.toMatch(/return\s+\[\s*1\s*,\s*2\s*,\s*3\s*,\s*4\s*,\s*5\s*\]/);
    expect(businessDay).not.toMatch(/loadRecentBeklenenCikis/);
  });
});
