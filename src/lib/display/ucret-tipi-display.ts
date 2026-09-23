import type { IdOption } from "../../types/referans";

/** Canonical ücret modeli kodları — bordro/SGK tarafındaki adlarla aynı yazım. */
export type UcretTipiModeli = "MAKTU_AYLIK" | "GUNLUK" | "SAATLIK";

/** PHP ReferansController::ucretTipleri — id korunur, görünen etiket katalogla hizalı. */
const ID_TO_MODEL: Record<number, UcretTipiModeli> = {
  1: "MAKTU_AYLIK",
  2: "GUNLUK",
  3: "SAATLIK"
};

const MODEL_TO_LABEL: Record<UcretTipiModeli, string> = {
  MAKTU_AYLIK: "Aylık",
  GUNLUK: "Günlük",
  SAATLIK: "Saatlik"
};

/**
 * `personeller.ucret_tipi_id` → canonical ücret modeli.
 * Backend `SgkPrimGunuService::wageModel` ile aynı eşleme; 1/2/3 dışındaki
 * değerler için `null` döner (BELIRSIZ).
 */
export function resolveUcretTipiModeli(id: number | null | undefined): UcretTipiModeli | null {
  if (id === null || id === undefined) {
    return null;
  }
  return ID_TO_MODEL[id] ?? null;
}

/** Canonical ücret modelinin görünen etiketi (Aylık / Günlük / Saatlik). */
export function displayUcretTipiModeliLabel(model: UcretTipiModeli): string {
  return MODEL_TO_LABEL[model];
}

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
  const model = resolveUcretTipiModeli(id);
  if (model !== null) {
    return MODEL_TO_LABEL[model];
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
