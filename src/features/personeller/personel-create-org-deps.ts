import type { IdOption } from "../../types/referans";

/**
 * SGK işveren options for create: only employers that share the selected
 * şube's company. Unknown company → empty list (fail-closed; BE still validates).
 */
export function filterSgkIsverenOptionsForSube(
  sgkOptions: IdOption[],
  subeOptions: IdOption[],
  subeId: string
): IdOption[] {
  const trimmed = subeId.trim();
  if (!trimmed) {
    return [];
  }
  const sube = subeOptions.find((option) => String(option.id) === trimmed);
  const sirketId = sube?.sirketId ?? null;
  if (sirketId == null || sirketId <= 0) {
    return [];
  }
  return sgkOptions.filter((option) => option.sirketId === sirketId);
}

/** Keep current SGK only when it remains valid for the new şube company. */
export function resolveSgkIsverenAfterSubeChange(
  currentSgkIsverenId: string,
  filteredSgkOptions: IdOption[]
): string {
  const current = currentSgkIsverenId.trim();
  if (!current) {
    return "";
  }
  return filteredSgkOptions.some((option) => String(option.id) === current) ? current : "";
}
