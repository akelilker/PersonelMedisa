import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { describe, expect, it } from "vitest";

const root = resolve(__dirname, "../..");

function read(rel: string): string {
  return readFileSync(resolve(root, rel), "utf8");
}

describe("attendance anomaly correction flow", () => {
  it("runs the canonical threshold, dedupe, stale-shift and expectation contract", () => {
    const result = spawnSync("php", [resolve(root, "tests/php/AttendanceAnomalyFlowTestRunner.php")], {
      cwd: root,
      encoding: "utf8"
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[OK] AttendanceAnomalyFlowTestRunner");
  });

  it("keeps one read owner, a web-inaccessible CLI, and the migration worker untouched", () => {
    const cli = read("api/bin/attendance-anomaly-scan.php");
    const worker = read("api/bin/cpanel-migration-cron.php");
    const anomaly = read("api/src/Services/Qr/QrAttendanceUnresolvedAnomalyService.php");
    const today = read("api/src/Services/Qr/QrAttendanceTodayService.php");
    const correction = read("api/src/Services/Qr/QrAttendanceCorrectionService.php");
    const event = read("api/src/Services/Qr/QrAttendanceEventService.php");
    const migration = read("api/migrations/093_attendance_anomaly_notification_dedupe.sql");
    const limit = read("api/src/Services/Payroll/FazlaCalismaYillikLimitService.php");

    expect(cli).toContain("PHP_SAPI !== 'cli'");
    expect(cli).toContain("http_response_code(404)");
    expect(cli).toContain("Run every 5 minutes.");
    expect(cli).toContain("cd /home/karmotor/public_html/personelmedisa && /usr/local/bin/ea-php81 api/bin/attendance-anomaly-scan.php");
    expect(cli).toContain("cd /home/karmotor/public_html/personelmedisa && MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES=5 \"$(command -v php)\" api/bin/attendance-anomaly-scan.php");
    expect(cli).toContain("Do not put MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES on this cron");
    expect(cli).toContain("Cadence is 5 minutes. Expected delay after the threshold is 0-300 seconds.");
    expect(cli).not.toMatch(/\*\/\s*[0-9*]/);
    expect(cli).not.toContain("one minute");
    expect(cli).not.toContain("* * * * * cd /home/karmotor/public_html/personelmedisa");
    expect(cli).toContain("QrAttendanceUnresolvedAnomalyService::scan");
    expect(worker).not.toContain("QrAttendanceUnresolvedAnomalyService");
    expect(anomaly).toContain("AttendanceCorrectionApproverResolver");
    expect(anomaly).not.toContain("resolveOperationalManagerRecipients");
    expect(anomaly).toContain("THRESHOLD_MINUTES = 180");
    expect(anomaly).toContain("NO_EVENT_DAY_FINALIZATION_MINUTES = 30");
    expect(anomaly).toContain("function thresholdMinutes()");
    expect(anomaly).toContain("getenv('MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES')");
    expect(anomaly).toContain("self::thresholdMinutes()");
    expect(anomaly).toContain("buildNoEventFinalization");
    expect(today).toContain("calismaBeklentisiForPersonelDate");
    expect(today).toContain("QrAttendanceUnresolvedAnomalyService::listForPersonel");
    expect(correction).toContain("requireUnresolvedAnomaly");
    expect(correction).toContain("createNoEventDayRequest");
    expect(correction).toContain("NO_EVENT_DAY_SCHEMA_NOT_READY");
    expect(correction).toContain("applyEffectiveTime");
    expect(anomaly).toContain("uq_qacr_pending_day");
    expect(anomaly).toContain("function dayKeySchemaReady");
    const inbox = read("api/src/Services/SelfService/PersonelInboxNotificationService.php");
    expect(inbox).toContain("dayKeySchemaReady");
    expect(correction).not.toContain("INSERT INTO qr_attendance_events");
    expect(event).toContain("openGirisBlocksNextGiris");
    expect(event).toContain("QR_STALE_OPEN_SHIFT");
    const migration094 = read("api/migrations/094_attendance_no_event_day.sql");
    expect(migration094).toContain("pending_day_guard");
    expect(migration094).not.toMatch(/GENERATED ALWAYS AS[\s\S]*DATE_FORMAT/i);
    expect(migration094).toContain("CAST(business_date AS CHAR)");
    expect(migration094).toContain("CAST(anomaly_business_date AS CHAR)");
    const scanPage = read("src/features/self-service/pages/PersonelQrScanPage.tsx");
    expect(scanPage).toContain("QR_STALE_OPEN_SHIFT");
    expect(migration).toContain("uq_pin_anomaly_dedupe");
    expect(limit).toContain("ROLLING_12_MONTH_ACTUAL_DATE_V1");
    expect(limit).toContain("16200");
  });
});
