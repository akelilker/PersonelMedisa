import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import {
  getRolePermissions,
  hasRolePermission
} from "../../src/lib/authorization/role-permissions";
import { PersonelMobileCapabilityService } from "../../src/features/self-service/personel-mobile-capability";

function read(path: string): string {
  return readFileSync(resolve(process.cwd(), path), "utf8");
}

describe("mobile attendance approval UX source contracts", () => {
  it("locks approval hierarchy owner without SUBE / self-approval", () => {
    const resolver = read("api/src/Services/Attendance/AttendanceCorrectionApproverResolver.php");
    expect(resolver).toContain("BIRIM_AMIRI");
    expect(resolver).toContain("BOLUM_YONETICISI");
    expect(resolver).toContain("GENEL_YONETICI");
    expect(resolver).toContain("id <> :exclude_id");
    expect(resolver).not.toMatch(/return\s*\[[^\]]*SUBE_YONETICISI/);
    expect(resolver).not.toContain("'SUBE_YONETICISI'");
    expect(resolver).toContain("chainForRequesterRole");
  });

  it("grants correction request/decide permissions without amir UI copy", () => {
    expect(hasRolePermission("PERSONEL", "self_service.attendance.correct")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "attendance.correction.decide")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "attendance.correction.decide")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "attendance.correction.decide")).toBe(true);
    expect(hasRolePermission("SUBE_YONETICISI", "attendance.correction.decide")).toBe(false);

    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home.toLowerCase()).not.toContain("amir");
    expect(home).toContain("Yöneticinize");
    expect(home).toContain("PERSONEL YÖN. SİST.");
    expect(home).toContain("ANASAYFA");
    expect(home).toContain("pm-header-accent");
    expect(home).toContain("attendance-box-giris");
    expect(home).toContain("attendance-box-cikis");
    expect(home).toContain("BackgroundlessNoticeModal");
  });

  it("keeps DIS_KAYNAK shell + operational QR/attendance capabilities", () => {
    const svc = read("api/src/Services/SelfService/PersonelMobileCapabilityService.php");
    expect(svc).toContain("DIS_KAYNAK");
    expect(svc).toContain("'qr_scan' => true");
    expect(svc).toContain("'attendance_correct' => true");
    expect(svc).toContain("'shell' => true");
    expect(svc).toContain("'izin_write' => false");
    expect(svc).toContain("ÜCRET VE SGK TAHAKKUKU OLUŞTURMAZ");

    const home = read("src/features/self-service/pages/PersonelSelfServiceHomePage.tsx");
    expect(home).toContain("comingSoon");
    expect(home).toContain("guardOrRun");
  });

  it("preserves original QR event and one-pending correction contract", () => {
    const corr = read("api/src/Services/Qr/QrAttendanceCorrectionService.php");
    expect(corr).toContain("CORRECTION_PENDING_EXISTS");
    expect(corr).toContain("SELF_APPROVAL_FORBIDDEN");
    expect(corr).toContain("CORRECTION_NO_APPROVER");
    expect(corr).toContain("REMINDER_MINUTES = 10");
    expect(corr).toContain("processDueReminders");
    expect(corr).not.toMatch(/UPDATE\s+qr_attendance_events/i);

    const sql = read("api/migrations/074_qr_attendance_correction_and_inbox.sql");
    expect(sql).toContain("qr_attendance_correction_requests");
    expect(sql).toContain("personel_inbox_notifications");
    expect(sql).toContain("popup_consumed_at_utc");
    expect(sql).toContain("reminder_due_at_utc");
    expect(sql).not.toMatch(/\bDROP\b/i);
    expect(sql).not.toMatch(/^\s*INSERT\s+/im);
  });

  it("wires late/early info-only path with Bilgi Amaçlıdır tooltip contract", () => {
    const late = read("api/src/Services/Attendance/LateEarlyInfoService.php");
    expect(late).toContain("FINANCIAL_EFFECT = NONE");
    expect(late).toContain("AUTOMATIC_PUANTAJ_EFFECT = NONE");
    expect(late).toContain("beklenen_giris_saati");
    expect(late).toContain("beklenen_cikis_saati");

    const scan = read("src/features/self-service/pages/PersonelQrScanPage.tsx");
    expect(scan).toContain('infoTooltip="Bilgi Amaçlıdır."');
    expect(scan).not.toContain("Bilgi Amaçlıdır.</");
    expect(scan).toContain("late-early-info-modal");
  });

  it("guards correction apply with period lock + canonical reopen semantics", () => {
    const corr = read("api/src/Services/Qr/QrAttendanceCorrectionService.php");
    expect(corr).toContain("PuantajDonemKilidiService::acquireForDate");
    expect(corr).toContain("PuantajDonemPeriodService::assertCanonicalWriteAllowed");
    expect(corr).toContain("QR_CORRECTION_PERIOD_LOCKED");
    expect(corr).toContain("QR_CORRECTION_NO_PUANTAJ_ROW");
    expect(corr).toContain("muhur_id = NULL");
    expect(corr).toContain("kontrol_durumu = 'BEKLIYOR'");
    expect(corr).toContain("resolvePeriodLockContext");
  });

  it("exposes attendance mobile routes", () => {
    const router = read("api/src/Router.php");
    expect(router).toContain("'/me/attendance/today'");
    expect(router).toContain("'/me/attendance/correction-requests'");
    expect(router).toContain("'/me/inbox-notifications'");
    expect(router).toContain("ack-popup");
    expect(router).toContain("/attendance/correction-requests/");
  });

  it("passes PHP pure hierarchy assertions", () => {
    const runner = resolve(process.cwd(), "tests/php/AttendanceCorrectionApproverPureTestRunner.php");
    const result = spawnSync("php", [runner], {
      cwd: process.cwd(),
      encoding: "utf8",
      env: process.env
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[OK] AttendanceCorrectionApproverPureTestRunner");
  });
});
