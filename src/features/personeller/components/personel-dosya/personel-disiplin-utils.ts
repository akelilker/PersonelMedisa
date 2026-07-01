import {
  formatFinansKalemTuruLabel,
  formatFinansStateLabel,
  formatSurecStateLabel,
  formatSurecTuruLabel
} from "../../../../lib/display/enum-display";
import { getSurecTimelineSortWeight } from "../../../../lib/surec-history-sort";
import type { FinansKalem } from "../../../../types/finans";
import type { Surec } from "../../../../types/surec";
import { formatDetailValue, formatIsoDateDetail } from "./personel-dosya-format-utils";

function normalizeSurecTypeToken(value: string | null | undefined) {
  return typeof value === "string" ? value.trim().toUpperCase() : "";
}

export function isDisiplinSurecSignal(surec: Surec) {
  if (surec.state === "IPTAL") {
    return false;
  }

  const haystack = `${normalizeSurecTypeToken(surec.surec_turu)} ${normalizeSurecTypeToken(surec.alt_tur)}`;

  return (
    haystack.includes("GEC_GELDI") ||
    haystack.includes("ERKEN_CIKTI") ||
    haystack.includes("DEVAMSIZ") ||
    haystack.includes("GELMEDI") ||
    haystack.includes("CEZA") ||
    normalizeSurecTypeToken(surec.surec_turu) === "UYARI"
  );
}

export function sortDisiplinSurecSignals(surecler: Surec[]) {
  return [...surecler]
    .filter(isDisiplinSurecSignal)
    .sort((left, right) => getSurecTimelineSortWeight(right) - getSurecTimelineSortWeight(left));
}

function buildDisiplinSurecDateSummary(surec: Surec) {
  const bas = formatIsoDateDetail(surec.baslangic_tarihi);
  const bit = formatIsoDateDetail(surec.bitis_tarihi);
  if (bas !== "-" && bit !== "-" && bas !== bit) {
    return `${bas} – ${bit}`;
  }
  if (bas !== "-") {
    return bas;
  }
  if (bit !== "-") {
    return bit;
  }
  return formatIsoDateDetail(surec.effective_date);
}

export function formatDisiplinSurecSignalSummary(surec: Surec) {
  const dates = buildDisiplinSurecDateSummary(surec);
  const datePart = dates !== "-" ? `Tarih: ${dates}` : null;
  return [datePart, "Devamsızlık veya puantaj sapması."].filter((part): part is string => part !== null).join(" · ");
}

export function formatDisiplinSurecSignalTitle(surec: Surec) {
  const anaBaslik = formatSurecTuruLabel(surec.surec_turu);
  const altBaslik = surec.alt_tur ? formatSurecTuruLabel(surec.alt_tur) : null;
  if (altBaslik && altBaslik !== anaBaslik) {
    return `${anaBaslik} / ${altBaslik}`;
  }
  return anaBaslik;
}

export function formatDisiplinSurecSignalState(surec: Surec) {
  return formatSurecStateLabel(surec.state);
}

export function isCezaFinansKalem(item: FinansKalem) {
  return item.kalem_turu.trim().toUpperCase() === "CEZA";
}

export function sortCezaFinansKalemleri(items: FinansKalem[], personelId?: number) {
  return [...items]
    .filter((item) => isCezaFinansKalem(item) && (personelId == null || item.personel_id === personelId))
    .sort((left, right) => {
      const donemCompare = right.donem.localeCompare(left.donem, "tr");
      if (donemCompare !== 0) {
        return donemCompare;
      }
      return right.id - left.id;
    });
}

export function formatFinansCezaTutar(tutar: number) {
  return new Intl.NumberFormat("tr-TR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(tutar);
}

export function formatFinansCezaKayitSummary(item: FinansKalem) {
  const parts = [
    `Dönem: ${item.donem}`,
    `Tutar: ${formatFinansCezaTutar(item.tutar)}`,
    item.state ? `Durum: ${formatFinansStateLabel(item.state)}` : null,
    formatFinansKalemTuruLabel(item.kalem_turu)
  ].filter((part): part is string => Boolean(part));

  return parts.join(" · ");
}
