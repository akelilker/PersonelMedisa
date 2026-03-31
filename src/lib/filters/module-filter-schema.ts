/**
 * Moduller arasi ortak filtre sozlesmesi (hook + rapor motoru).
 * Backend dogrulamasi zorunludur; frontend yalnizca daraltir.
 */
export type ModuleFilterBase = {
  date_range?: { bas: string; bit: string };
  sube_id?: number | null;
  personel_id?: number | null;
  durum?: string | null;
};

export function matchesDateRange(
  valueIso: string | undefined,
  range: ModuleFilterBase["date_range"]
): boolean {
  if (!range?.bas && !range?.bit) {
    return true;
  }
  if (!valueIso) {
    return false;
  }
  const v = valueIso.slice(0, 10);
  if (range.bas && v < range.bas) {
    return false;
  }
  if (range.bit && v > range.bit) {
    return false;
  }
  return true;
}
