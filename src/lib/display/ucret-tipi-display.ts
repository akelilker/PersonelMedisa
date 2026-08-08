import type { IdOption } from "../../types/referans";

/** PHP ReferansController::ucretTipleri — id korunur, görünen etiket katalogla hizalı. */
const ID_TO_LABEL: Record<number, "Aylık" | "Günlük" | "Saatlik"> = {
  1: "Aylık",
  2: "Günlük",
  3: "Saatlik"
};

function normalizeForMatch(value: string) {
  return value
    .trim()
    .toLocaleLowerCase("tr-TR")
    .normalize("NFD")
    .replace(/\p{M}/gu, "");
}

/**
 * Ücret tipi etiketi: Aylık / Günlük / Saatlik ayrı tutulur.
 * `value` (id) ve ham `ad` API tarafında bozulmaz.
 */
export function displayUcretTipiLabel(raw: string | null | undefined, id?: number): string {
  if (id !== undefined && ID_TO_LABEL[id] !== undefined) {
    return ID_TO_LABEL[id];
  }

  const s = (raw ?? "").trim();
  if (!s) {
    return "-";
  }

  const n = normalizeForMatch(s);

  if (n.includes("saatlik") || n.includes("hourly")) {
    return "Saatlik";
  }

  if (n.includes("gunluk") || n.includes("günlük") || n.includes("yevmiye") || n.includes("daily")) {
    return "Günlük";
  }

  if (
    n.includes("aylik") ||
    n.includes("aylık") ||
    n.includes("maktu") ||
    n.includes("maas") ||
    n.includes("maaş") ||
    n.includes("monthly")
  ) {
    return "Aylık";
  }

  return s;
}

export function mapUcretTipiSelectOptions(options: IdOption[]): Array<{ value: string; label: string }> {
  return options.map((option) => ({
    value: String(option.id),
    label: displayUcretTipiLabel(option.label, option.id)
  }));
}
