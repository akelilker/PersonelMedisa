import type { IdOption } from "../../types/referans";

/** Demo / sık eşleme: API id’leri korunur, yalnızca görünen metin sadeleştirilir. */
const ID_TO_SIMPLE: Record<number, "Aylık" | "Günlük"> = {
  1: "Aylık",
  2: "Günlük"
};

function normalizeForMatch(value: string) {
  return value
    .trim()
    .toLocaleLowerCase("tr-TR")
    .normalize("NFD")
    .replace(/\p{M}/gu, "");
}

/**
 * Ücret tipi için kullanıcı yüzünde yalnızca `Aylık` veya `Günlük` gösterilir;
 * `value` (id) ve ham `ad` bozulmadan API’de kalır.
 */
export function displayUcretTipiLabel(raw: string | null | undefined, id?: number): string {
  if (id !== undefined && ID_TO_SIMPLE[id] !== undefined) {
    return ID_TO_SIMPLE[id];
  }

  const s = (raw ?? "").trim();
  if (!s) {
    return "-";
  }

  const n = normalizeForMatch(s);

  if (
    n.includes("saatlik") ||
    n.includes("gunluk") ||
    n.includes("günlük") ||
    n.includes("yevmiye") ||
    n.includes("daily") ||
    n.includes("hourly")
  ) {
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
