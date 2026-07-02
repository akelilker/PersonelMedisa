import type { RaporTipi } from "../../types/rapor";

const RAPOR_TIPI_SET = new Set<RaporTipi>([
  "personel-ozet",
  "izin",
  "devamsizlik",
  "tesvik",
  "ceza",
  "ekstra-prim",
  "is-kazasi",
  "bildirim"
]);

export type RaporQueryExtraFilters = {
  muhur_id?: number;
  donem?: string;
};

export type RaporQueryPrefillResult = {
  raporTipi?: RaporTipi;
  baslangicTarihi?: string;
  bitisTarihi?: string;
  extraFilters: RaporQueryExtraFilters;
  shouldAutoRun: boolean;
};

function isValidDateParam(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return false;
  }

  const [year, month, day] = value.split("-").map((part) => Number.parseInt(part, 10));
  const date = new Date(year, month - 1, day);

  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
}

export function parseRaporTipiParam(value: string | null): RaporTipi | undefined {
  if (!value) {
    return undefined;
  }

  return RAPOR_TIPI_SET.has(value as RaporTipi) ? (value as RaporTipi) : undefined;
}

export function parsePositiveIntQueryParam(value: string | null): number | undefined {
  if (!value?.trim()) {
    return undefined;
  }

  const parsed = Number.parseInt(value, 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return undefined;
  }

  return parsed;
}

export function parseDonemQueryParam(value: string | null): string | undefined {
  if (!value?.trim()) {
    return undefined;
  }

  return /^\d{4}-\d{2}$/.test(value) ? value : undefined;
}

export function parseRaporlarQueryPrefill(searchParams: URLSearchParams): RaporQueryPrefillResult {
  const raporTipi = parseRaporTipiParam(searchParams.get("rapor"));
  const baslangicRaw = searchParams.get("baslangic");
  const bitisRaw = searchParams.get("bitis");
  const baslangicTarihi =
    baslangicRaw && isValidDateParam(baslangicRaw) ? baslangicRaw : undefined;
  const bitisTarihi = bitisRaw && isValidDateParam(bitisRaw) ? bitisRaw : undefined;

  const extraFilters: RaporQueryExtraFilters = {};
  const muhurId = parsePositiveIntQueryParam(searchParams.get("muhur_id"));
  if (muhurId !== undefined) {
    extraFilters.muhur_id = muhurId;
  }

  const donem = parseDonemQueryParam(searchParams.get("donem"));
  if (donem !== undefined) {
    extraFilters.donem = donem;
  }

  return {
    raporTipi,
    baslangicTarihi,
    bitisTarihi,
    extraFilters,
    shouldAutoRun: Boolean(raporTipi && baslangicTarihi && bitisTarihi)
  };
}
