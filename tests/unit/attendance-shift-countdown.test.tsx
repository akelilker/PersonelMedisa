// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

afterEach(() => cleanup());
import {
  formatDurationHuman,
  hhmmToMinutes,
  isGirisSaatiGecti,
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

  it("isGirisSaatiGecti only true after planned entry passed", () => {
    const planned = "09:00";
    expect(isGirisSaatiGecti(planned, new Date("2026-09-25T08:59:00+03:00"))).toBe(false);
    expect(isGirisSaatiGecti(planned, new Date("2026-09-25T09:00:00+03:00"))).toBe(false);
    expect(isGirisSaatiGecti(planned, new Date("2026-09-25T09:01:00+03:00"))).toBe(true);
    expect(isGirisSaatiGecti(null, new Date("2026-09-25T09:01:00+03:00"))).toBe(false);
  });
});

const clock = vi.hoisted(() => ({
  now: Date.parse("2026-09-25T08:00:00+03:00")
}));

vi.mock("../../src/features/self-service/hooks/useIstanbulMinuteClock", () => ({
  useIstanbulMinuteClock: () => clock.now
}));

describe("OwnQrAttendanceBoxes countdown render", () => {
  type TodayArg = {
    giris?: Record<string, unknown> | null;
    cikis?: Record<string, unknown> | null;
    can_scan_giris?: boolean;
    can_scan_cikis?: boolean;
    bugun_calisma_beklentisi?: {
      bekleniyor: boolean | null;
      neden: "IZINLI" | "RAPORLU" | "GELMEDI" | null;
    } | null;
    planned_shift?: { beklenen_giris_saati: string | null; beklenen_cikis_saati: string | null } | null;
  };

  async function setup(today: TodayArg) {
    const { render, screen } = await import("@testing-library/react");
    const { MemoryRouter } = await import("react-router-dom");
    const { OwnQrAttendanceBoxes } = await import(
      "../../src/features/self-service/components/OwnQrAttendanceBoxes"
    );

    const utils = render(
      <MemoryRouter>
        <OwnQrAttendanceBoxes
          today={
            {
              giris: null,
              cikis: null,
              can_scan_giris: true,
              can_scan_cikis: false,
              bugun_calisma_beklentisi: { bekleniyor: true, neden: null },
              ...today
            } as never
          }
          qrEnabled
          onScanGiris={() => undefined}
          onScanCikis={() => undefined}
        />
      </MemoryRouter>
    );

    return { ...utils, screen };
  }

  it("shows mesaiye countdown on active GİRİŞ inside two-hour window", async () => {
    clock.now = Date.parse("2026-09-25T08:00:00+03:00");
    const { screen } = await setup({
      planned_shift: { beklenen_giris_saati: "09:00", beklenen_cikis_saati: "17:40" }
    });

    expect(screen.getByText("Mesaiye Kalan Süre")).toBeInTheDocument();
    expect(screen.getByTestId("pm-box-countdown-value")).toHaveTextContent("1 Saat");
    expect(screen.getByTestId("cikis-scan")).toBeDisabled();
  });

  it("shows 'İşe Geç Kaldınız.' when no entry, planned entry passed, not on leave", async () => {
    clock.now = Date.parse("2026-09-25T09:43:00+03:00");
    const { screen } = await setup({
      planned_shift: { beklenen_giris_saati: "09:00", beklenen_cikis_saati: "17:40" }
    });

    expect(screen.getByTestId("giris-late-warning")).toHaveTextContent("İşe Geç Kaldınız.");
  });

  it("hides late warning when on leave today", async () => {
    clock.now = Date.parse("2026-09-25T09:43:00+03:00");
    const { screen } = await setup({
      bugun_calisma_beklentisi: { bekleniyor: false, neden: "IZINLI" },
      planned_shift: { beklenen_giris_saati: "09:00", beklenen_cikis_saati: "17:40" }
    });

    expect(screen.queryByTestId("giris-late-warning")).not.toBeInTheDocument();
  });

  it("hides late warning when entry already recorded", async () => {
    clock.now = Date.parse("2026-09-25T09:43:00+03:00");
    const { screen } = await setup({
      giris: {
        id: 1,
        event_type: "GIRIS",
        occurred_at: "2026-09-25T06:00:00Z",
        local_time: "09:00",
        display_local_time: "09:00"
      },
      planned_shift: { beklenen_giris_saati: "09:00", beklenen_cikis_saati: "17:40" }
    });

    expect(screen.queryByTestId("giris-late-warning")).not.toBeInTheDocument();
  });

  it.each([
    ["IZINLI"],
    ["RAPORLU"],
    ["GELMEDI"]
  ] as const)("hides late warning and pre-shift countdown when bekleniyor is false (%s)", async (neden) => {
    clock.now = Date.parse("2026-09-25T08:00:00+03:00");
    const countdown = await setup({
      bugun_calisma_beklentisi: { bekleniyor: false, neden },
      planned_shift: { beklenen_giris_saati: "09:00", beklenen_cikis_saati: "17:40" }
    });
    expect(countdown.screen.queryByText("Mesaiye Kalan Süre")).not.toBeInTheDocument();
    expect(countdown.screen.getByTestId("giris-scan")).toBeEnabled();
    cleanup();

    clock.now = Date.parse("2026-09-25T09:43:00+03:00");
    const late = await setup({
      bugun_calisma_beklentisi: { bekleniyor: false, neden },
      planned_shift: { beklenen_giris_saati: "09:00", beklenen_cikis_saati: "17:40" }
    });
    expect(late.screen.queryByTestId("giris-late-warning")).not.toBeInTheDocument();
    expect(late.screen.getByTestId("giris-scan")).toBeEnabled();
  });

  it("hides late warning and pre-shift countdown when bekleniyor is null", async () => {
    clock.now = Date.parse("2026-09-25T09:43:00+03:00");
    const { screen } = await setup({
      bugun_calisma_beklentisi: { bekleniyor: null, neden: null },
      planned_shift: { beklenen_giris_saati: "09:00", beklenen_cikis_saati: "17:40" }
    });
    expect(screen.queryByTestId("giris-late-warning")).not.toBeInTheDocument();
    expect(screen.queryByText("Mesaiye Kalan Süre")).not.toBeInTheDocument();
    expect(screen.getByTestId("giris-scan")).toBeEnabled();
  });

  it("keeps mesai bitimine countdown on an open shift even when work was not expected", async () => {
    clock.now = Date.parse("2026-09-25T15:00:00+03:00");
    const { screen } = await setup({
      giris: {
        id: 1,
        event_type: "GIRIS",
        occurred_at: "2026-09-25T06:00:00Z",
        local_time: "09:00",
        display_local_time: "09:00"
      },
      can_scan_giris: false,
      can_scan_cikis: true,
      bugun_calisma_beklentisi: { bekleniyor: false, neden: "RAPORLU" },
      planned_shift: { beklenen_giris_saati: "09:00", beklenen_cikis_saati: "17:40" }
    });

    expect(screen.getByText("Mesai Bitimine Kalan")).toBeInTheDocument();
    expect(screen.getByTestId("cikis-scan")).toBeEnabled();
    expect(screen.queryByTestId("giris-late-warning")).not.toBeInTheDocument();
  });

  it("hides late warning when there is no planned shift", async () => {
    clock.now = Date.parse("2026-09-25T09:43:00+03:00");
    const { screen } = await setup({ planned_shift: null });

    expect(screen.queryByTestId("giris-late-warning")).not.toBeInTheDocument();
  });
});
