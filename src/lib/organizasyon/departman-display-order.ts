import type { IdOption } from "../../types/referans";

/**
 * Departman picker'ının kanonik business display order owner'ı.
 *
 * Sıralama alfabetik DEĞİLDİR; işletmenin onayladığı gösterim sırasıdır.
 * Katalogda `sort_order` / `sira` / `display_order` kolonu yoktur, bu yüzden
 * sıra tek bu dosyada explicit tutulur (dağınık hardcode yok, production
 * schema/migration bu fazda değişmez).
 *
 * Kontrat:
 * - Bilinen departmanlar verilen sırayla başa gelir.
 * - Bilinmeyen/yeni departmanlar KAYBOLMAZ: bilinen setten sonra stabil
 *   (alfabetik tr) sırada listelenir.
 * - Eşleştirme kanonik ada göre normalize edilir (kısa kod prefix'i, büyük/küçük
 *   harf ve aksan farkı, "Uretim"/"Üretim" gibi katalog yazım farkları).
 */
export const DEPARTMAN_DISPLAY_ORDER = [
  "Yönetim",
  "Finans ve Risk Yönetimi",
  "Mali ve İdari İşler",
  "Pazarlama ve Satış",
  "Üretim",
  "İhracat",
  "Karabük Depo",
  "Giresun Depo",
  "Satış Sonrası Hizmet",
  "İzmir Mağaza"
] as const;

const UNKNOWN_RANK = Number.MAX_SAFE_INTEGER;

/** Referans etiketi "KOD — Ad" olabilir; sıra karşılaştırması ad parçası üzerinden yapılır. */
function readDisplayName(label: string): string {
  const parts = label.split(" — ");

  return (parts.length > 1 ? parts[parts.length - 1]! : label).trim();
}

export function normalizeDepartmanDisplayName(value: string | null | undefined): string {
  return (value ?? "")
    .toLocaleLowerCase("tr-TR")
    .replace(/ı/g, "i")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/\s+/gu, " ")
    .trim();
}

const ORDERED_NAME_KEYS = new Map<string, number>(
  DEPARTMAN_DISPLAY_ORDER.map((name, index) => [normalizeDepartmanDisplayName(name), index])
);

/** Bilinen sıradaki yer; bilinmiyorsa UNKNOWN_RANK. */
export function resolveDepartmanDisplayRank(label: string | null | undefined): number {
  const key = normalizeDepartmanDisplayName(readDisplayName(label ?? ""));

  return ORDERED_NAME_KEYS.get(key) ?? UNKNOWN_RANK;
}

export function compareDepartmanDisplayOrder(left: IdOption, right: IdOption): number {
  const leftRank = resolveDepartmanDisplayRank(left.label);
  const rightRank = resolveDepartmanDisplayRank(right.label);

  if (leftRank !== rightRank) {
    return leftRank - rightRank;
  }

  const byLabel = readDisplayName(left.label).localeCompare(readDisplayName(right.label), "tr");
  if (byLabel !== 0) {
    return byLabel;
  }

  return left.id - right.id;
}

/** Kanonik business order ile yeni dizi döner (girdi dizisi mutasyona uğramaz). */
export function sortDepartmanDisplayOptions(options: IdOption[]): IdOption[] {
  return [...options].sort(compareDepartmanDisplayOrder);
}
