import type { SelfServiceFact } from "./components/SelfServiceFactList";
import { formatSelfServiceMinutes } from "./format-self-service-minutes";
import type { MePuantajGun, MePuantajOzet } from "../../types/self-service";

export type HistoryMonthAuthoritativeInput = {
  ozet: MePuantajOzet | null;
  /** Period overtime minutes from GET /me/fazla-calisma donem_ozet. Null when that owner has no period. */
  fazlaDonemDakika: number | null;
};

function minutesFact(label: string, minutes: number, testId: string): SelfServiceFact {
  return { label, value: formatSelfServiceMinutes(minutes), testId };
}

/**
 * Monthly history figures copied from self-service owners.
 * This function does not accept day rows and does not sum them.
 */
export function buildHistoryMonthSummary(input: HistoryMonthAuthoritativeInput): SelfServiceFact[] {
  const rows: SelfServiceFact[] = [];
  const net = input.ozet?.net_calisma_dakika_toplam;
  if (typeof net === "number") {
    rows.push(minutesFact("Toplam çalışılan süre", net, "qr-history-net-total"));
  }
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

/** Day detail from one authoritative puantaj row. Missing minutes stay hidden. */
export function buildHistoryDayWorkFacts(day: MePuantajGun | null): SelfServiceFact[] {
  if (!day) {
    return [];
  }
  const rows: SelfServiceFact[] = [];
  if (day.giris_saati) {
    rows.push({ label: "Giriş", value: day.giris_saati.slice(0, 5), testId: "qr-history-day-giris" });
  }
  if (day.cikis_saati) {
    rows.push({ label: "Çıkış", value: day.cikis_saati.slice(0, 5), testId: "qr-history-day-cikis" });
  }
  if (typeof day.net_calisma_suresi_dakika === "number") {
    rows.push(minutesFact("Net çalışma", day.net_calisma_suresi_dakika, "qr-history-day-net"));
  }
  if (typeof day.gec_kalma_dakika === "number") {
    rows.push(minutesFact("Geç kalma", day.gec_kalma_dakika, "qr-history-day-late"));
  }
  if (typeof day.erken_cikis_dakika === "number") {
    rows.push(minutesFact("Erken çıkma", day.erken_cikis_dakika, "qr-history-day-early"));
  }
  if (typeof day.fazla_calisma_dakika === "number") {
    rows.push(minutesFact("Fazla çalışma", day.fazla_calisma_dakika, "qr-history-day-fazla"));
  }
  if (day.gun_tipi) {
    rows.push({ label: "Durum", value: day.gun_tipi, testId: "qr-history-day-durum" });
  }
  return rows;
}
