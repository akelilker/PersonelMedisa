import { describe, expect, it } from "vitest";
import { spawnSync } from "node:child_process";
import { resolve } from "node:path";
import { readFileSync } from "node:fs";
import {
  formatManagerLocationEventLine,
  formatManagerLocationEvents
} from "../../src/features/puantaj/qr-read-utils";

const runner = resolve(process.cwd(), "tests/php/QrLocationVerificationTestRunner.php");

describe("QR location verification (PHP runtime)", () => {
  it("classifies geofence audit without blocking scan semantics", () => {
    const result = spawnSync("php", [runner], { encoding: "utf8" });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[OK] QrLocationVerificationTestRunner");
  });
});

describe("QR scan client wiring", () => {
  it("captures location only on submit path without staff-facing detail", () => {
    const scanPage = readFileSync("src/features/self-service/pages/PersonelQrScanPage.tsx", "utf8");
    expect(scanPage).toContain("captureQrScanLocation");
    expect(scanPage).toContain("location_capture");
    expect(scanPage).not.toMatch(/Konum Doğrulandı|accuracy_meters|distance_meters/);
  });

  it("formats manager-only location audit lines", () => {
    const line = formatManagerLocationEventLine({
      event_type: "GIRIS",
      occurred_at: "2026-10-05T08:00:00+03:00",
      status_code: "VERIFIED",
      status_label: "Konum Doğrulandı",
      distance_meters: 42,
      accuracy_meters: 18
    });
    expect(line).toContain("Konum Doğrulandı");
    expect(line).toContain("42m");
    expect(formatManagerLocationEvents({ location_events: [] } as never)).toBe("—");
  });
});
