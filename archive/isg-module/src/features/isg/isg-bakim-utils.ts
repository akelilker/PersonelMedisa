import type {
  IsgBakimKaydi,
  IsgMakineDurum,
  MakineBakimDurumuOzet
} from "../../types/isg";

const DAY_MS = 86400000;

type NormalizedDateMeta = {
  iso: string | null;
  dayStartMs: number | null;
  sortMs: number | null;
};

export type IsgBakimProjectionItem<T> = {
  item: T;
  normalizedDate: string | null;
};

export type IsgBakimProjection<T> = {
  items: IsgBakimProjectionItem<T>[];
  sonBakim: string | null;
  sonrakiBakim: string | null;
  gecikmeGun: number;
  uyariDurumu: MakineBakimDurumuOzet;
};

function startOfLocalDay(date: Date): number {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
}

function normalizeDate(value: string | null | undefined): NormalizedDateMeta {
  if (typeof value !== "string") {
    return { iso: null, dayStartMs: null, sortMs: null };
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return { iso: null, dayStartMs: null, sortMs: null };
  }

  const parsed = Date.parse(trimmed);
  if (Number.isNaN(parsed)) {
    return { iso: null, dayStartMs: null, sortMs: null };
  }

  const date = new Date(parsed);
  return {
    iso: date.toISOString(),
    dayStartMs: startOfLocalDay(date),
    sortMs: date.getTime()
  };
}

function buildNextBakim(sonBakimDayStartMs: number, bakimPeriyotGun: number): string {
  return new Date(sonBakimDayStartMs + bakimPeriyotGun * DAY_MS).toISOString();
}

export function buildIsgBakimProjection<T>(params: {
  items: T[];
  durum: IsgMakineDurum;
  bakimPeriyotGun: number | null;
  getDate: (item: T) => string | null | undefined;
  now?: Date;
}): IsgBakimProjection<T> {
  const normalizedItems = params.items.map((item) => {
    const meta = normalizeDate(params.getDate(item));
    return {
      item,
      normalizedDate: meta.iso,
      dayStartMs: meta.dayStartMs,
      sortMs: meta.sortMs
    };
  });

  normalizedItems.sort((left, right) => {
    if (left.sortMs === null && right.sortMs === null) {
      return 0;
    }
    if (left.sortMs === null) {
      return 1;
    }
    if (right.sortMs === null) {
      return -1;
    }
    return right.sortMs - left.sortMs;
  });

  const latestValid = normalizedItems.find((item) => item.dayStartMs !== null) ?? null;
  const bakimPeriyotGun =
    typeof params.bakimPeriyotGun === "number" && params.bakimPeriyotGun > 0 ? params.bakimPeriyotGun : null;

  if (!latestValid) {
    return {
      items: normalizedItems.map((item) => ({ item: item.item, normalizedDate: item.normalizedDate })),
      sonBakim: null,
      sonrakiBakim: null,
      gecikmeGun: 0,
      uyariDurumu: params.durum === "aktif" ? "eksik_veri" : "guncel"
    };
  }

  if (bakimPeriyotGun === null) {
    return {
      items: normalizedItems.map((item) => ({ item: item.item, normalizedDate: item.normalizedDate })),
      sonBakim: latestValid.normalizedDate,
      sonrakiBakim: null,
      gecikmeGun: 0,
      uyariDurumu: params.durum === "aktif" ? "eksik_veri" : "guncel"
    };
  }

  const latestValidDayStartMs = latestValid.dayStartMs;
  if (latestValidDayStartMs === null) {
    return {
      items: normalizedItems.map((item) => ({ item: item.item, normalizedDate: item.normalizedDate })),
      sonBakim: latestValid.normalizedDate,
      sonrakiBakim: null,
      gecikmeGun: 0,
      uyariDurumu: params.durum === "aktif" ? "eksik_veri" : "guncel"
    };
  }

  const todayStartMs = startOfLocalDay(params.now ?? new Date());
  const nextBakimDayStartMs = latestValidDayStartMs + bakimPeriyotGun * DAY_MS;
  const diffMs = todayStartMs - nextBakimDayStartMs;
  const gecikmeGun = diffMs > 0 ? Math.max(0, Math.ceil(diffMs / DAY_MS)) : 0;

  return {
    items: normalizedItems.map((item) => ({ item: item.item, normalizedDate: item.normalizedDate })),
    sonBakim: latestValid.normalizedDate,
    sonrakiBakim: buildNextBakim(latestValidDayStartMs, bakimPeriyotGun),
    gecikmeGun,
    uyariDurumu: gecikmeGun > 0 ? "gecikmis" : "guncel"
  };
}

export function buildIsgBakimProjectionFromDates(params: {
  tarihler: Array<string | null | undefined>;
  durum: IsgMakineDurum;
  bakimPeriyotGun: number | null;
  now?: Date;
}) {
  return buildIsgBakimProjection({
    items: params.tarihler,
    durum: params.durum,
    bakimPeriyotGun: params.bakimPeriyotGun,
    getDate: (item) => item,
    now: params.now
  });
}

export function sortIsgBakimKayitlari(
  kayitlar: IsgBakimKaydi[],
  now?: Date
): IsgBakimProjection<IsgBakimKaydi> {
  return buildIsgBakimProjection({
    items: kayitlar,
    durum: "aktif",
    bakimPeriyotGun: 1,
    getDate: (item) => item.bakimTarihi,
    now
  });
}
