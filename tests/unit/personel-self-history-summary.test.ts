import { describe, expect, it } from "vitest";
import { buildHistoryMonthSummary } from "../../src/features/self-service/personel-self-history-summary";
import type { MePuantajGun, MePuantajOzet, MeQrHistoryDay } from "../../src/types/self-service";

const baseOzet: MePuantajOzet = {
  calisma_gun_adet: 0,
  gec_kalma_adet: 0,
  gec_kalma_dakika_toplam: 0,
  erken_cikis_adet: 0,
  erken_cikis_dakika_toplam: 0,
  fazla_calisma_dakika_toplam: 0,
  net_calisma_dakika_toplam: null,
  aylik_onayli_mi: false
};

function qrDay(date: string, giris?: string, cikis?: string): MeQrHistoryDay {
  const girisEvent = giris
    ? { id: 1, event_type: "GIRIS", time: giris.slice(11, 16), occurred_at: giris, status: null }
    : null;
  const cikisEvent = cikis
    ? { id: 2, event_type: "CIKIS", time: cikis.slice(11, 16), occurred_at: cikis, status: null }
    : null;
  return {
    date,
    has_events: Boolean(giris || cikis),
    giris: girisEvent,
    cikis: cikisEvent,
    events: [girisEvent, cikisEvent].filter(Boolean),
    status_lines: []
  };
}

function workdayRow(rows: ReturnType<typeof buildHistoryMonthSummary>) {
  return rows.find((row) => row.testId === "qr-history-workday-count");
}

describe("buildHistoryMonthSummary worked-day QR fallback (FINDING D)", () => {
  it("counts completed QR days when the month is unapproved (same day set as hours)", () => {
    const rows = buildHistoryMonthSummary({
      ozet: baseOzet,
      fazlaDonemDakika: null,
      puantajItems: [],
      qrDays: [
        qrDay("2026-09-25", "2026-09-25T09:00:00+03:00", "2026-09-25T17:00:00+03:00"),
        qrDay("2026-09-26", "2026-09-26T09:00:00+03:00", "2026-09-26T12:00:00+03:00")
      ]
    });
    expect(rows[0].value).toBe("11 saat");
    expect(workdayRow(rows)).toEqual({
      label: "Çalışılan gün (Onaylı Değil)",
      value: "2 gün",
      testId: "qr-history-workday-count"
    });
  });

  it("does not count QR days without ÇIKIŞ toward worked days", () => {
    const rows = buildHistoryMonthSummary({
      ozet: baseOzet,
      fazlaDonemDakika: null,
      puantajItems: [],
      qrDays: [
        qrDay("2026-09-25", "2026-09-25T09:00:00+03:00", "2026-09-25T17:00:00+03:00"),
        qrDay("2026-09-26", "2026-09-26T09:00:00+03:00")
      ]
    });
    expect(rows[0].value).toBe("8 saat");
    expect(workdayRow(rows)?.value).toBe("1 gün");
  });

  it("uses official calisma_gun_adet when the month is approved", () => {
    const rows = buildHistoryMonthSummary({
      ozet: {
        ...baseOzet,
        calisma_gun_adet: 5,
        net_calisma_dakika_toplam: 600,
        aylik_onayli_mi: true
      },
      fazlaDonemDakika: null,
      puantajItems: [],
      qrDays: [qrDay("2026-09-25", "2026-09-25T09:00:00+03:00", "2026-09-25T17:00:00+03:00")]
    });
    expect(rows[0].value).toBe("10 saat");
    expect(workdayRow(rows)).toEqual({
      label: "Çalışılan gün",
      value: "5 gün",
      testId: "qr-history-workday-count"
    });
  });

  it("does not double-count a day that has both puantaj net and QR", () => {
    const day: MePuantajGun = {
      tarih: "2026-09-24",
      gun_tipi: "Normal_Is_Gunu",
      giris_saati: "09:00:00",
      cikis_saati: "17:00:00",
      net_calisma_suresi_dakika: 480,
      gunluk_brut_sure_dakika: 480,
      gec_kalma_dakika: 0,
      erken_cikis_dakika: 0,
      fazla_calisma_dakika: 0
    };
    const rows = buildHistoryMonthSummary({
      ozet: baseOzet,
      fazlaDonemDakika: null,
      puantajItems: [day],
      qrDays: [qrDay("2026-09-24", "2026-09-24T09:00:00+03:00", "2026-09-24T17:00:00+03:00")]
    });
    expect(workdayRow(rows)?.value).toBe("1 gün");
  });
});
