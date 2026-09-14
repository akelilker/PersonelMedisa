/**
 * Şube (branch) etiketlerinin kanonik adaptive display owner'ı.
 *
 * Kural (ürün kararı):
 * - Kısa ad (`subeler.ad`) global listede benzersizse → yalnız kısa ad.
 *   Örn: `Fabrika`, `Giresun`, `İstanbul` — ama aynı kısa ad birden fazla
 *   şirkette varsa (Medisa Ankara + Karyapı Ankara) → şirket ile disambiguate:
 *   `Medisa Ankara`, `Karyapı Ankara`.
 * - Disambiguate kaynağı backend read model'inin türettiği `tam_ad`'dir
 *   (`SubeReadModel` derives `tam_ad`). Product code bunu yeniden kurmaz:
 *   string prefix kesme ya da elle `${sirket} ${sube}` birleştirme YOKTUR.
 * - `tam_ad` gelmiyorsa (eski payload/mock) kısa ada düşülür; uydurma yapılmaz.
 */

import { normalizeOrgName } from "./sube-display-name";

export type SubeDisplayLabelInput = {
  id: number;
  /** Kısa ad (`subeler.ad`) — yoksa tam ad. */
  ad?: string | null;
  /** Login/read model kısa ad alanı (varsa `ad` yerine tercih edilir). */
  kisaAd?: string | null;
  /** Şirket + şube türetilmiş okuma alanı. */
  tamAd?: string | null;
};

function readLabelValue(value: string | null | undefined): string {
  return (value ?? "").replace(/\s+/gu, " ").trim();
}

/** Aynı kısa adı paylaşan şubeler (karşılaştırma anahtarı). */
export function buildDuplicateShortNameKeys(items: SubeDisplayLabelInput[]): Set<string> {
  const counts = new Map<string, number>();

  for (const item of items) {
    const key = normalizeOrgName(readLabelValue(item.kisaAd) || readLabelValue(item.ad));
    if (!key) {
      continue;
    }
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }

  const duplicates = new Set<string>();
  for (const [key, count] of counts) {
    if (count > 1) {
      duplicates.add(key);
    }
  }

  return duplicates;
}

/** Tek şube için adaptive etiket; `duplicateShortNames` global listeden gelir. */
export function resolveSubeDisplayLabel(
  item: SubeDisplayLabelInput,
  duplicateShortNames: ReadonlySet<string>
): string {
  const shortName = readLabelValue(item.kisaAd) || readLabelValue(item.ad);
  const key = normalizeOrgName(shortName);
  const tamAd = readLabelValue(item.tamAd);

  if (key && duplicateShortNames.has(key) && tamAd) {
    return tamAd;
  }

  return shortName || tamAd;
}

/** Global liste → id bazlı adaptive etiket haritası (tek canonical owner). */
export function resolveSubeDisplayLabels(items: SubeDisplayLabelInput[]): Map<number, string> {
  const duplicates = buildDuplicateShortNameKeys(items);
  const labels = new Map<number, string>();

  for (const item of items) {
    labels.set(item.id, resolveSubeDisplayLabel(item, duplicates));
  }

  return labels;
}
