import type {
  BugunPersonelDurumuPerson,
  BugunPersonelDurumuStatusCounts
} from "../../types/bildirim";

export const BUGUN_STATUS_KEYS = [
  "geldi",
  "gec_geldi",
  "gelmedi",
  "izinli",
  "raporlu",
  "gorevde",
  "erken_cikti",
  "henuz_degerlendirilmedi"
] as const;

export type BugunStatusKey = (typeof BUGUN_STATUS_KEYS)[number];

export const BUGUN_STATUS_TO_DURUM: Record<BugunStatusKey, string> = {
  geldi: "GELDI",
  gec_geldi: "GEC_GELDI",
  gelmedi: "GELMEDI",
  izinli: "IZINLI",
  raporlu: "RAPORLU",
  gorevde: "GOREVDE",
  erken_cikti: "ERKEN_CIKTI",
  henuz_degerlendirilmedi: "HENUZ_DEGERLENDIRILMEDI"
};

export const BUGUN_STATUS_LABEL: Record<BugunStatusKey, string> = {
  geldi: "Geldi",
  gec_geldi: "Geç Geldi",
  gelmedi: "Gelmedi",
  izinli: "İzinli",
  raporlu: "Raporlu",
  gorevde: "Görevde",
  erken_cikti: "Erken çıktı",
  henuz_degerlendirilmedi: "Henüz değerlendirilmedi"
};

export function filterPersonsByStatus(
  persons: BugunPersonelDurumuPerson[],
  statusKey: BugunStatusKey
): BugunPersonelDurumuPerson[] {
  const durum = BUGUN_STATUS_TO_DURUM[statusKey];
  return persons.filter((person) => person.durum.toUpperCase() === durum);
}

export function formatCompletionGlyph(status: string): string {
  const key = status.toUpperCase();
  if (key === "TAMAMLANDI") return "✅";
  if (key === "BEKLENIYOR") return "⏳";
  if (key === "SURESI_GECTI" || key === "GEC_BILDIRILDI") return "⚠";
  return "·";
}

export function emptyStatusCounts(): BugunPersonelDurumuStatusCounts {
  return {
    toplam: 0,
    geldi: 0,
    gec_geldi: 0,
    gelmedi: 0,
    izinli: 0,
    raporlu: 0,
    gorevde: 0,
    erken_cikti: 0,
    henuz_degerlendirilmedi: 0
  };
}

export function countsSatisfyInvariant(counts: BugunPersonelDurumuStatusCounts): boolean {
  const sum =
    counts.geldi +
    counts.gec_geldi +
    counts.gelmedi +
    counts.izinli +
    counts.raporlu +
    counts.gorevde +
    counts.erken_cikti +
    counts.henuz_degerlendirilmedi;
  return sum === counts.toplam;
}
