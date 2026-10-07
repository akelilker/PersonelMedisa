import type {
  BugunPersonelDurumuBranch,
  BugunPersonelDurumuPerson,
  BugunPersonelDurumuStatusCounts,
  BugunPersonelDurumuUnit
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

/** Sunum gruplaması: gelen toplamı (durum motoru değişmez). */
export const BUGUN_GELEN_STATUS_KEYS = ["geldi", "gec_geldi", "erken_cikti"] as const satisfies readonly BugunStatusKey[];

/** Sunum gruplaması: gelmeyen toplamı (durum motoru değişmez). */
export const BUGUN_GELMEYEN_STATUS_KEYS = ["gelmedi", "izinli", "raporlu", "gorevde"] as const satisfies readonly BugunStatusKey[];

export type BugunSummaryGroup = "gelen" | "gelmeyen";

export const BUGUN_SUMMARY_GROUP_LABEL: Record<BugunSummaryGroup, string> = {
  gelen: "Toplam Gelen",
  gelmeyen: "Toplam Gelmeyen"
};

export function statusKeysForSummaryGroup(group: BugunSummaryGroup): readonly BugunStatusKey[] {
  return group === "gelen" ? BUGUN_GELEN_STATUS_KEYS : BUGUN_GELMEYEN_STATUS_KEYS;
}

export function summaryGroupForStatusKey(statusKey: BugunStatusKey): BugunSummaryGroup | null {
  if ((BUGUN_GELEN_STATUS_KEYS as readonly string[]).includes(statusKey)) {
    return "gelen";
  }
  if ((BUGUN_GELMEYEN_STATUS_KEYS as readonly string[]).includes(statusKey)) {
    return "gelmeyen";
  }
  return null;
}

export function sumStatusSlice(
  counts: BugunPersonelDurumuStatusCounts,
  keys: readonly BugunStatusKey[]
): number {
  return keys.reduce((acc, key) => acc + counts[key], 0);
}

export function branchToplamGelen(counts: BugunPersonelDurumuStatusCounts): number {
  return sumStatusSlice(counts, BUGUN_GELEN_STATUS_KEYS);
}

export function branchToplamGelmeyen(counts: BugunPersonelDurumuStatusCounts): number {
  return sumStatusSlice(counts, BUGUN_GELMEYEN_STATUS_KEYS);
}

export function overviewCountsSatisfyEquality(counts: BugunPersonelDurumuStatusCounts): boolean {
  return (
    counts.toplam ===
    branchToplamGelen(counts) + branchToplamGelmeyen(counts) + counts.henuz_degerlendirilmedi
  );
}

export function collectBranchPersonsByStatus(
  branch: BugunPersonelDurumuBranch,
  statusKey: BugunStatusKey
): BugunPersonelDurumuPerson[] {
  const out: BugunPersonelDurumuPerson[] = [];
  for (const unit of branch.units) {
    out.push(...filterPersonsByStatus(unit.personeller, statusKey));
  }
  return out;
}

export function findPersonUnitInBranch(
  branch: BugunPersonelDurumuBranch,
  personelId: number
): BugunPersonelDurumuUnit | null {
  for (const unit of branch.units) {
    if (unit.personeller.some((p) => p.personel_id === personelId)) {
      return unit;
    }
  }
  return null;
}

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
