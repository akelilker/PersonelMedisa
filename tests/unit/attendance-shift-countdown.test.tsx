// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { describe, expect, it, vi } from "vitest";
import {
  formatDurationHuman,
  hhmmToMinutes,
  mesaiBitimineKalanLabel,
  mesaiyeKalanLabel
} from "../../src/features/self-service/attendance-shift-countdown";

describe("attendance-shift-countdown", () => {
  it("formatDurationHuman matches LateEarlyInfoService copy", () => {
    expect(formatDurationHuman(31)).toBe("31dk");
    expect(formatDurationHuman(60)).toBe("1 Saat");
    expect(formatDurationHuman(125)).toBe("2 Saat 5dk");
  });

  it("mesaiyeKalanLabel only within two hours before planned entry", () => {
    const planned = "09:00";
    const atNine = new Date("2026-09-25T09:00:00+03:00");
    expect(mesaiyeKalanLabel(planned, atNine)).toBeNull();

    const atSeven = new Date("2026-09-25T07:00:00+03:00");
    expect(mesaiyeKalanLabel(planned, atSeven)).toBe("2 Saat");

    const atSixThirty = new Date("2026-09-25T06:30:00+03:00");
    expect(mesaiyeKalanLabel(planned, atSixThirty)).toBeNull();
  });

  it("mesaiBitimineKalanLabel counts down to planned exit", () => {
    const atFifteen = new Date("2026-09-25T15:00:00+03:00");
    expect(mesaiBitimineKalanLabel("17:40", atFifteen)).toBe("2 Saat 40dk");
    const atEnd = new Date("2026-09-25T17:40:00+03:00");
    expect(mesaiBitimineKalanLabel("17:40", atEnd)).toBeNull();
  });

  it("hhmmToMinutes rejects invalid values", () => {
    expect(hhmmToMinutes("08:30")).toBe(8 * 60 + 30);
    expect(hhmmToMinutes("")).toBeNull();
    expect(hhmmToMinutes("99:99")).toBeNull();
  });
});

vi.mock("../../src/features/self-service/hooks/useIstanbulMinuteClock", () => ({
  useIstanbulMinuteClock: () => Date.parse("2026-09-25T08:00:00+03:00")
}));

describe("OwnQrAttendanceBoxes countdown render", () => {
  it("shows mesaiye countdown on active GİRİŞ inside two-hour window", async () => {
    const { render, screen } = await import("@testing-library/react");
    const { MemoryRouter } = await import("react-router-dom");
    const { OwnQrAttendanceBoxes } = await import(
      "../../src/features/self-service/components/OwnQrAttendanceBoxes"
    );

    render(
      <MemoryRouter>
        <OwnQrAttendanceBoxes
          today={{
            giris: null,
            cikis: null,
            can_scan_giris: true,
            can_scan_cikis: false,
            planned_shift: { beklenen_giris_saati: "09:00", beklenen_cikis_saati: "17:40" }
          }}
          qrEnabled
          onScanGiris={() => undefined}
          onScanCikis={() => undefined}
        />
      </MemoryRouter>
    );

    expect(screen.getByText("Mesaiye Kalan Süre")).toBeInTheDocument();
    expect(screen.getByTestId("pm-box-countdown-value")).toHaveTextContent("1 Saat");
    expect(screen.getByTestId("cikis-scan")).toBeDisabled();
  });
});
