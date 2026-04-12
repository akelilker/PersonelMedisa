import type { ApiResponse, PaginatedResult } from "../types/api";
import type {
  IsgMakineDurum,
  IsgMakineListItem,
  ListIsgMakinelerParams,
  MakineBakimDurumuOzet
} from "../types/isg";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function readStringValue(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : undefined;
}

function readNumberValue(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string" && value.trim()) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return undefined;
}

function pickValue(sources: Array<Record<string, unknown> | null>, keys: string[]): unknown {
  for (const source of sources) {
    if (!source) {
      continue;
    }

    for (const key of keys) {
      if (key in source) {
        const value = source[key];
        if (value !== undefined) {
          return value;
        }
      }
    }
  }

  return undefined;
}

function readString(sources: Array<Record<string, unknown> | null>, ...keys: string[]): string | undefined {
  return readStringValue(pickValue(sources, keys));
}

function readNumber(sources: Array<Record<string, unknown> | null>, ...keys: string[]): number | undefined {
  return readNumberValue(pickValue(sources, keys));
}

function normalizeMakineDurum(value: unknown): IsgMakineDurum {
  const normalized = typeof value === "string" ? value.trim().toLowerCase() : "";
  if (normalized === "aktif" || normalized === "arizali" || normalized === "pasif") {
    return normalized;
  }

  return "aktif";
}

function normalizeIsoDate(value: unknown): string | null {
  const raw = readStringValue(value);
  if (!raw) {
    return null;
  }

  const parsed = Date.parse(raw);
  if (Number.isNaN(parsed)) {
    return null;
  }

  return new Date(parsed).toISOString();
}

function addDays(isoDate: string, days: number): string | null {
  const parsed = Date.parse(isoDate);
  if (Number.isNaN(parsed) || !Number.isFinite(days)) {
    return null;
  }

  const next = new Date(parsed);
  next.setUTCDate(next.getUTCDate() + days);
  return next.toISOString();
}

function startOfTodayMs(): number {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return today.getTime();
}

function deriveBakimDurumu(
  durum: IsgMakineDurum,
  sonBakim: string | null,
  bakimPeriyotGun: number | null
): {
  uyariDurumu: MakineBakimDurumuOzet;
  sonrakiBakim: string | null;
  gecikmeGun: number | null;
} {
  if (durum === "aktif" && (!sonBakim || !bakimPeriyotGun || bakimPeriyotGun <= 0)) {
    return {
      uyariDurumu: "eksik_veri",
      sonrakiBakim: null,
      gecikmeGun: null
    };
  }

  if (!sonBakim || !bakimPeriyotGun || bakimPeriyotGun <= 0) {
    return {
      uyariDurumu: "guncel",
      sonrakiBakim: null,
      gecikmeGun: null
    };
  }

  const sonrakiBakim = addDays(sonBakim, bakimPeriyotGun);
  if (!sonrakiBakim) {
    return {
      uyariDurumu: "eksik_veri",
      sonrakiBakim: null,
      gecikmeGun: null
    };
  }

  const diffMs = startOfTodayMs() - new Date(sonrakiBakim).getTime();
  if (diffMs > 0) {
    return {
      uyariDurumu: "gecikmis",
      sonrakiBakim,
      gecikmeGun: Math.max(1, Math.ceil(diffMs / 86400000))
    };
  }

  return {
    uyariDurumu: "guncel",
    sonrakiBakim,
    gecikmeGun: null
  };
}

function normalizeIsgMakineListItem(data: unknown): IsgMakineListItem {
  const root = toRecord(data);
  if (!root) {
    throw new Error("Makine listesi yaniti beklenen formatta degil.");
  }

  const kaynak = toRecord(root.makine) ?? root;
  const referans = toRecord(root.referans_adlari);
  const sources = [kaynak, root];
  const refSources = [referans, root];

  const id = readNumber(sources, "id");
  const ad = readString(sources, "ad", "makine_adi");
  const tip = readString(sources, "tip", "makine_tipi");
  if (id === undefined || !ad || !tip) {
    throw new Error("Makine listesi yaniti zorunlu alanlari icermiyor.");
  }

  const durum = normalizeMakineDurum(pickValue(sources, ["durum", "state"]));
  const sonBakim = normalizeIsoDate(pickValue(sources, ["son_bakim", "sonBakim", "bakim_tarihi"]));
  const bakimPeriyotGun = readNumber(sources, "bakim_periyot_gun", "bakimPeriyotGun") ?? null;
  const derived = deriveBakimDurumu(durum, sonBakim, bakimPeriyotGun);

  return {
    id,
    ad,
    tip,
    konum: readString(sources, "konum") ?? null,
    durum,
    subeId: readNumber(sources, "sube_id", "subeId") ?? null,
    subeAdi: readString(refSources, "sube", "sube_adi", "subeAdi") ?? null,
    sonBakim,
    sonrakiBakim: derived.sonrakiBakim,
    bakimPeriyotGun,
    gecikmeGun: derived.gecikmeGun,
    uyariDurumu: derived.uyariDurumu
  };
}

export async function listIsgMakineler(
  params?: ListIsgMakinelerParams
): Promise<PaginatedResult<IsgMakineListItem>> {
  const path = appendQueryParams(endpoints.isg.list, {
    search: params?.search,
    durum: params?.durum && params.durum !== "tum" ? params.durum : undefined,
    tip: params?.tip,
    sube_id: params?.sube_id,
    page: params?.page,
    limit: params?.limit
  });

  const response = await apiRequest<ApiResponse<unknown>>(path);
  const normalized = normalizePaginatedList<unknown>(response, {
    requestedPage: params?.page,
    requestedLimit: params?.limit
  });

  return {
    ...normalized,
    items: normalized.items.map((item) => normalizeIsgMakineListItem(item))
  };
}
