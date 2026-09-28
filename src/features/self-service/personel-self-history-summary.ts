import type { SelfServiceFact } from "./components/SelfServiceFactList";
import { formatSelfServiceMinutes } from "./format-self-service-minutes";
import type { MePuantajGun, MePuantajOzet, MeQrHistoryDay } from "../../types/self-service";

export type HistoryMonthAuthoritativeInput = {
  ozet: MePuantajOzet | null;
  /** Period overtime minutes from GET /me/fazla-calisma donem_ozet. Null when that owner has no period. */
  fazlaDonemDakika: number | null;
  /** Day rows from GET /me/puantaj, keyed by date for the day-by-day monthly total. */
  puantajItems: MePuantajGun[];
  /** QR history days used as the GİRİŞ→ÇIKIŞ raw fallback when a day lacks a puantaj net. */
  qrDays: MeQrHistoryDay[];
};

function minutesFact(label: string, minutes: number, testId: string): SelfServiceFact {
  return { label, value: formatSelfServiceMinutes(minutes), testId };
}

/** Raw QR GİRİŞ→ÇIKIŞ duration in minutes. Null when either endpoint is missing. */
function qrRawDurationMinutes(day: MeQrHistoryDay): number | null {
  const giris = day.giris?.occurred_at;
  const cikis = day.cikis?.occurred_at;
  if (!giris || !cikis) {
    return null;
  }
  const start = Date.parse(giris);
  const end = Date.parse(cikis);
  if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
    return null;
  }
  return Math.round((end - start) / 60000);
}

/** One day's authoritative minutes: puantaj net if present, else QR raw duration. */
function resolveDayMinutes(
  day: MePuantajGun | undefined,
  qrDay: MeQrHistoryDay | undefined
): number | null {
  if (day && typeof day.net_calisma_suresi_dakika === "number") {
    return day.net_calisma_suresi_dakika;
  }
  if (qrDay) {
    return qrRawDurationMinutes(qrDay);
  }
  return null;
}

/** Day-by-day monthly total across puantaj rows and QR days. Days without ÇIKIŞ stay out. */
function computeMonthTotalMinutes(items: MePuantajGun[], qrDays: MeQrHistoryDay[]): number {
  const puantajByDate = new Map(items.map((item) => [item.tarih, item]));
  const qrByDate = new Map(qrDays.map((day) => [day.date, day]));
  const dates = new Set<string>([...puantajByDate.keys(), ...qrByDate.keys()]);
  let total = 0;
  for (const date of dates) {
    const minutes = resolveDayMinutes(puantajByDate.get(date), qrByDate.get(date));
    if (typeof minutes === "number") {
      total += minutes;
    }
  }
  return total;
}

/**
 * Monthly history figures copied from self-service owners.
 * The single "Toplam saat (Aylık)" line is computed day by day; the official
 * puantaj total is used only when the month has an amir approval (TAMAMLANDI).
 */
export function buildHistoryMonthSummary(input: HistoryMonthAuthoritativeInput): SelfServiceFact[] {
  const rows: SelfServiceFact[] = [];
  const aylikOnayliMi = Boolean(input.ozet?.aylik_onayli_mi);
  const dayByDay = computeMonthTotalMinutes(input.puantajItems, input.qrDays);

  const officialNet = input.ozet?.net_calisma_dakika_toplam;
  const total = aylikOnayliMi && typeof officialNet === "number" ? officialNet : dayByDay;

  rows.push(
    minutesFact(
      aylikOnayliMi ? "Toplam saat (Aylık)" : "Toplam saat (Aylık) (Onaylı Değil)",
      total,
      "qr-history-monthly-total"
    )
  );
  if (typeof input.fazlaDonemDakika === "number") {
    rows.push(minutesFact("Toplam fazla çalışma", input.fazlaDonemDakika, "qr-history-fazla-total"));
  }
  if (input.ozet) {
    rows.push({
      label: "Çalışılan gün",
      value: `${input.ozet.calisma_gun_adet} gün`,
      testId: "qr-history-workday-count"
    });
    rows.push(minutesFact("Toplam geç kalma", input.ozet.gec_kalma_dakika_toplam, "qr-history-late-total"));
    if (input.ozet.erken_cikis_dakika_toplam > 0 || input.ozet.erken_cikis_adet > 0) {
      rows.push(
        minutesFact("Toplam erken çıkma", input.ozet.erken_cikis_dakika_toplam, "qr-history-early-total")
      );
    }
  }
  return rows;
}

/**
 * Day detail facts. "Süre" uses the same rule (puantaj net → QR raw) and is
 * hidden when there is no authoritative duration (e.g. no ÇIKIŞ).
 */
export function buildHistoryDayWorkFacts(
  day: MePuantajGun | null,
  qrDay: MeQrHistoryDay | null,
  aylikOnayliMi: boolean
): SelfServiceFact[] {
  if (!day && !qrDay) {
    return [];
  }
  const rows: SelfServiceFact[] = [];
  if (day?.giris_saati) {
    rows.push({ label: "Giriş", value: day.giris_saati.slice(0, 5), testId: "qr-history-day-giris" });
  }
  if (day?.cikis_saati) {
    rows.push({ label: "Çıkış", value: day.cikis_saati.slice(0, 5), testId: "qr-history-day-cikis" });
  }
  const sure = resolveDayMinutes(day ?? undefined, qrDay ?? undefined);
  if (typeof sure === "number") {
    const suffix = aylikOnayliMi ? "" : " (Onaylı Değil)";
    rows.push({ label: "Süre", value: `${formatSelfServiceMinutes(sure)}${suffix}`, testId: "qr-history-day-sure" });
  }
  if (day && typeof day.gec_kalma_dakika === "number") {
    rows.push(minutesFact("Geç kalma", day.gec_kalma_dakika, "qr-history-day-late"));
  }
  if (day && typeof day.erken_cikis_dakika === "number") {
    rows.push(minutesFact("Erken çıkma", day.erken_cikis_dakika, "qr-history-day-early"));
  }
  if (day && typeof day.fazla_calisma_dakika === "number") {
    rows.push(minutesFact("Fazla çalışma", day.fazla_calisma_dakika, "qr-history-day-fazla"));
  }
  if (day?.gun_tipi) {
    rows.push({ label: "Durum", value: day.gun_tipi, testId: "qr-history-day-durum" });
  }
  return rows;
}
