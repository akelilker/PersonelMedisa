// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import type { AttendanceTodayResponse } from "../../src/api/attendance-mobile.api";
import {
  OwnQrAttendanceBoxes,
  PASSIVE_CIKIS_AFTER_COMPLETED_PAIR_NOTICE,
  PASSIVE_CIKIS_WITHOUT_GIRIS_NOTICE,
  PASSIVE_GIRIS_WITH_OPEN_SHIFT_NOTICE
} from "../../src/features/self-service/components/OwnQrAttendanceBoxes";

afterEach(() => cleanup());

function baseToday(
  overrides: Partial<AttendanceTodayResponse> = {}
): AttendanceTodayResponse {
  return {
    business_date: "2026-09-19",
    capabilities: { qr_scan: true, attendance_correct: true, coming_soon_message: "" },
    personel: {
      id: 1,
      ad_soyad: "Test",
      sube_ad: "Merkez",
      bolum_ad: "Ops",
      birim_ad: null,
      gorev_ad: null
    },
    giris: null,
    cikis: null,
    can_scan_giris: true,
    can_scan_cikis: false,
    next_action: "GIRIS",
    pending_giris_correction: null,
    pending_cikis_correction: null,
    ...overrides
  } as AttendanceTodayResponse;
}

function renderBoxes(
  today: AttendanceTodayResponse,
  handlers: {
    onScanGiris?: () => void;
    onScanCikis?: () => void;
    onPassiveGirisWithOpenShift?: () => void;
    onPassiveCikisWithoutGiris?: () => void;
    onPassiveCikisAfterCompletedPair?: () => void;
  } = {}
) {
  const onScanGiris = handlers.onScanGiris ?? vi.fn();
  const onScanCikis = handlers.onScanCikis ?? vi.fn();
  const onPassiveGirisWithOpenShift = handlers.onPassiveGirisWithOpenShift ?? vi.fn();
  const onPassiveCikisWithoutGiris = handlers.onPassiveCikisWithoutGiris ?? vi.fn();
  const onPassiveCikisAfterCompletedPair = handlers.onPassiveCikisAfterCompletedPair ?? vi.fn();

  render(
    <MemoryRouter>
      <OwnQrAttendanceBoxes
        today={today}
        qrEnabled
        onScanGiris={onScanGiris}
        onScanCikis={onScanCikis}
        onPassiveGirisWithOpenShift={onPassiveGirisWithOpenShift}
        onPassiveCikisWithoutGiris={onPassiveCikisWithoutGiris}
        onPassiveCikisAfterCompletedPair={onPassiveCikisAfterCompletedPair}
      />
    </MemoryRouter>
  );

  return {
    onScanGiris,
    onScanCikis,
    onPassiveGirisWithOpenShift,
    onPassiveCikisWithoutGiris,
    onPassiveCikisAfterCompletedPair
  };
}

describe("OwnQrAttendanceBoxes passive GİRİŞ/ÇIKIŞ symmetry", () => {
  it("passive ÇIKIŞ without GİRİŞ invokes the without-giriş handler", () => {
    const { onPassiveCikisWithoutGiris } = renderBoxes(baseToday());

    fireEvent.click(screen.getByTestId("cikis-scan"));
    expect(onPassiveCikisWithoutGiris).toHaveBeenCalledTimes(1);
  });

  it("passive GİRİŞ with open shift uses aria-disabled and open-shift handler", () => {
    const { onPassiveGirisWithOpenShift, onScanGiris } = renderBoxes(
      baseToday({
        giris: { id: 11, local_time: "08:12", display_local_time: "08:12" },
        can_scan_giris: false,
        can_scan_cikis: true,
        next_action: "CIKIS"
      })
    );

    const giris = screen.getByTestId("giris-scan");
    expect(giris).toHaveAttribute("aria-disabled", "true");
    expect(giris).not.toBeDisabled();

    fireEvent.click(giris);
    expect(onPassiveGirisWithOpenShift).toHaveBeenCalledTimes(1);
    expect(onScanGiris).not.toHaveBeenCalled();
  });

  it("passive ÇIKIŞ after completed pair invokes completed-pair handler", () => {
    const { onPassiveCikisAfterCompletedPair } = renderBoxes(
      baseToday({
        giris: { id: 11, local_time: "12:05", display_local_time: "12:05" },
        cikis: { id: 12, local_time: "11:20", display_local_time: "11:20" },
        can_scan_giris: true,
        can_scan_cikis: false,
        next_action: "GIRIS"
      })
    );

    fireEvent.click(screen.getByTestId("cikis-scan"));
    expect(onPassiveCikisAfterCompletedPair).toHaveBeenCalledTimes(1);
  });

  it("stale reentry keeps GİRİŞ actionable without passive open-shift notice", () => {
    const { onScanGiris, onPassiveGirisWithOpenShift } = renderBoxes(
      baseToday({
        giris: { id: 11, local_time: "18:00", display_local_time: "18:00" },
        can_scan_giris: true,
        can_scan_cikis: false,
        next_action: "GIRIS"
      })
    );

    const giris = screen.getByTestId("giris-scan");
    expect(giris).toHaveAttribute("aria-disabled", "false");
    fireEvent.click(giris);
    expect(onScanGiris).toHaveBeenCalledTimes(1);
    expect(onPassiveGirisWithOpenShift).not.toHaveBeenCalled();
  });

  it("exports locked passive notice copy", () => {
    expect(PASSIVE_GIRIS_WITH_OPEN_SHIFT_NOTICE).toContain("Zaten Giriş Yaptınız");
    expect(PASSIVE_CIKIS_WITHOUT_GIRIS_NOTICE).toContain("Henüz Giriş Yapmadınız");
    expect(PASSIVE_CIKIS_AFTER_COMPLETED_PAIR_NOTICE).toContain("Çıkış İşleminiz Zaten Tamamlandı");
  });
});
