import type { Surec } from "../types/surec";

function parseSurecDay(value: string | undefined): number | null {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const parsed = Date.parse(`${trimmed}T00:00:00`);
  return Number.isFinite(parsed) ? parsed : null;
}

function parseSurecInstant(value: string | undefined): number | null {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const parsed = Date.parse(trimmed);
  return Number.isFinite(parsed) ? parsed : null;
}

function orderingTuple(surec: Surec): [number, number, number, number] {
  const effective = parseSurecDay(surec.effective_date) ?? Number.NEGATIVE_INFINITY;
  const baslangic = parseSurecDay(surec.baslangic_tarihi) ?? Number.NEGATIVE_INFINITY;
  const created = parseSurecInstant(surec.created_at) ?? Number.NEGATIVE_INFINITY;
  return [effective, baslangic, created, surec.id];
}

export function compareSurecHistoryDescending(a: Surec, b: Surec): number {
  const getTime = (val?: string | null) => {
    if (!val) return 0;
    const t = new Date(val).getTime();
    return isNaN(t) ? 0 : t;
  };

  const aEffective = getTime(a.effective_date);
  const bEffective = getTime(b.effective_date);

  if (aEffective !== bEffective) {
    return bEffective - aEffective; // DESC
  }

  const aStart = getTime(a.baslangic_tarihi);
  const bStart = getTime(b.baslangic_tarihi);

  if (aStart !== bStart) {
    return bStart - aStart; // DESC
  }

  const aCreated = getTime(a.created_at);
  const bCreated = getTime(b.created_at);

  if (aCreated !== bCreated) {
    return bCreated - aCreated; // DESC
  }

  // tie-breaker (en son güvence)
  return (b.id ?? 0) - (a.id ?? 0);
}

export function sortSurecHistoryDescending(items: Surec[]): Surec[] {
  return [...items].sort(compareSurecHistoryDescending);
}

export function getSurecTimelineSortWeight(surec: Surec): number {
  const [e, b, c, id] = orderingTuple(surec);
  const base = e === Number.NEGATIVE_INFINITY ? 0 : e;
  const tierB = b === Number.NEGATIVE_INFINITY ? 0 : b / 1e12;
  const tierC = c === Number.NEGATIVE_INFINITY ? 0 : c / 1e18;
  return base + tierB + tierC + id / 1e6;
}
