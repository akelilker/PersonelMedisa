import type { ApiResponse, PaginatedResult } from "../types/api";
import type {
  IsgBakimKaydi,
  IsgMakineDetail,
  IsgMakineDurum,
  IsgMakineListItem,
  ListIsgMakinelerParams,
  MakineBakimDurumuOzet
} from "../types/isg";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";
import { buildIsgBakimProjectionFromDates } from "../features/isg/isg-bakim-utils";

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

function deriveBakimDurumu(
  durum: IsgMakineDurum,
  sonBakim: string | null,
  bakimPeriyotGun: number | null
): {
  uyariDurumu: MakineBakimDurumuOzet;
  sonrakiBakim: string | null;
  gecikmeGun: number | null;
} {
  const derived = buildIsgBakimProjectionFromDates({
    tarihler: [sonBakim],
    durum,
    bakimPeriyotGun
  });

  return {
    uyariDurumu: derived.uyariDurumu,
    sonrakiBakim: derived.sonrakiBakim,
    gecikmeGun: derived.gecikmeGun > 0 ? derived.gecikmeGun : null
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

function normalizeIsgMakineDetail(data: unknown): IsgMakineDetail {
  const root = toRecord(data);
  if (!root) {
    throw new Error("Makine detayi beklenen formatta degil.");
  }

  const kaynak = toRecord(root.makine) ?? root;
  const referans = toRecord(root.referans_adlari);
  const sources = [kaynak, root];
  const refSources = [referans, root];

  const id = readNumber(sources, "id");
  const ad = readString(sources, "ad", "makine_adi");
  const tip = readString(sources, "tip", "makine_tipi");
  if (id === undefined || !ad || !tip) {
    throw new Error("Makine detayi zorunlu alanlari icermiyor.");
  }

  return {
    id,
    ad,
    tip,
    konum: readString(sources, "konum") ?? null,
    durum: normalizeMakineDurum(pickValue(sources, ["durum", "state"])),
    subeId: readNumber(sources, "sube_id", "subeId") ?? null,
    subeAdi: readString(refSources, "sube", "sube_adi", "subeAdi") ?? null,
    bakimPeriyotGun: readNumber(sources, "bakim_periyot_gun", "bakimPeriyotGun") ?? null
  };
}

function normalizeIsgBakimKaydi(data: unknown): IsgBakimKaydi {
  const root = toRecord(data);
  if (!root) {
    throw new Error("Bakim kaydi beklenen formatta degil.");
  }

  const kaynak = toRecord(root.kayit) ?? root;
  const sources = [kaynak, root];
  const id = readNumber(sources, "id");
  if (id === undefined) {
    throw new Error("Bakim kaydi zorunlu alanlari icermiyor.");
  }

  return {
    id,
    makineId: readNumber(sources, "makine_id", "makineId") ?? null,
    bakimTarihi: normalizeIsoDate(pickValue(sources, ["bakim_tarihi", "bakimTarihi", "tarih"])),
    yapan: readString(sources, "yapan", "yapan_kisi", "olusturan") ?? null,
    notlar: readString(sources, "notlar", "aciklama") ?? null
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

export async function fetchIsgMakineDetail(
  makineId: number,
  subeId?: number
): Promise<IsgMakineDetail> {
  const path = appendQueryParams(endpoints.isg.detail(makineId), {
    sube_id: subeId
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizeIsgMakineDetail(response.data);
}

export async function fetchIsgMakineBakimlari(
  makineId: number,
  subeId?: number,
  page?: number,
  limit?: number
): Promise<PaginatedResult<IsgBakimKaydi>> {
  const path = appendQueryParams(endpoints.isg.bakimlar(makineId), {
    sube_id: subeId,
    page,
    limit
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  const normalized = normalizePaginatedList<unknown>(response, {
    requestedPage: page,
    requestedLimit: limit
  });

  return {
    ...normalized,
    items: normalized.items.map((item) => normalizeIsgBakimKaydi(item))
  };
}
