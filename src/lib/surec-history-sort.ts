import type { Surec } from "../types/surec";

function getSortTime(val?: string | null): number {
  if (!val) return 0;
  const t = new Date(val).getTime();
  return isNaN(t) ? 0 : t;
}

export function compareSurecHistoryDescending(a: Surec, b: Surec): number {
  const aEffective = getSortTime(a.effective_date);
  const bEffective = getSortTime(b.effective_date);

  if (aEffective !== bEffective) {
    return bEffective - aEffective; // DESC
  }

  const aStart = getSortTime(a.baslangic_tarihi);
  const bStart = getSortTime(b.baslangic_tarihi);

  if (aStart !== bStart) {
    return bStart - aStart; // DESC
  }

  const aCreated = getSortTime(a.created_at);
  const bCreated = getSortTime(b.created_at);

  if (aCreated !== bCreated) {
    return bCreated - aCreated; // DESC
  }

  return (b.id ?? 0) - (a.id ?? 0);
}

export function sortSurecHistoryDescending(items: Surec[]): Surec[] {
  return [...items].sort(compareSurecHistoryDescending);
}

export function getSurecTimelineSortWeight(surec: Surec): number {
  const e = getSortTime(surec.effective_date);
  const b = getSortTime(surec.baslangic_tarihi);
  const c = getSortTime(surec.created_at);
  const id = surec.id ?? 0;
  return e + b / 1e20 + c / 1e40 + id / 1e60;
}
