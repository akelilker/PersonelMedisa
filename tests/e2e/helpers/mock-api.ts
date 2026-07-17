import { createHash } from "node:crypto";
import type { Page, Route } from "@playwright/test";
import type { GunlukPuantaj } from "../../../src/types/puantaj";
import { hesaplaAylikSgkPuantajOzetleri } from "../../../src/services/dashboard-rapor-servisi";
import {
  SUBE_DELETE_BLOCKED_ERROR_CODE,
  SUBE_DELETE_BLOCKED_MESSAGE
} from "../../../src/lib/yonetim/sube-delete";
import {
  computeGecerlilikDurumu,
  PERSONEL_BELGE_KAYIT_TIPI_KEYS,
  type PersonelBelgeKayitDurum,
  type PersonelBelgeKayitTipi
} from "../../../src/types/personel-belge-kaydi";
import { hasRolePermission, type AppPermission } from "../../../src/lib/authorization/role-permissions";
import {
  listWeeksIntersectingMonth,
  resolveAylikBildirimOnayApproval,
  resolveAyBounds
} from "../../../src/lib/bildirim/aylik-bildirim-onay";

export type MockUserRole = "GENEL_YONETICI" | "BOLUM_YONETICISI" | "MUHASEBE" | "BIRIM_AMIRI";

type MockApiOptions = {
  belgeReferenceDate?: Date;
};

function isoDateDaysFrom(referenceDate: Date, days: number): string {
  const referenceUtc = Date.UTC(
    referenceDate.getUTCFullYear(),
    referenceDate.getUTCMonth(),
    referenceDate.getUTCDate()
  );
  return new Date(referenceUtc + days * 86_400_000).toISOString().slice(0, 10);
}

type MockAylikOzetRow = {
  ay: string;
  personel_id: number;
  ad_soyad: string;
  sicil_no?: string;
  sube_id: number;
  sube: string;
  departman_id: number;
  bolum: string;
  bagli_amir_adi: string;
  devamsizlik_gun: number;
  gec_kalma_adet: number;
  izinli_gelmedi: number;
  izinsiz_gelmedi: number;
  raporlu: number;
  tesvik_tutari: number;
  ceza_kesinti_tutari: number;
  bolum_onay_durumu: "BOLUM_ONAYINDA" | "BOLUM_ONAYLANDI" | "REVIZE_ISTENDI";
  revize_var_mi: boolean;
  son_islem: string;
  kapanis_durumu: "ACIK" | "KAPANDI";
};

type MockAylikOzetPageState = {
  fixtureAy: string;
  rows: MockAylikOzetRow[];
};

const aylikOzetStateByPage = new WeakMap<Page, MockAylikOzetPageState>();

function currentAylikOzetFixtureAy() {
  return `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, "0")}`;
}

function createInitialAylikOzetRows(fixtureAy: string): MockAylikOzetRow[] {
  return [
    {
      ay: fixtureAy,
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      sicil_no: "P-001",
      sube_id: 1,
      sube: "Merkez",
      departman_id: 3,
      bolum: "Döşeme",
      bagli_amir_adi: "Serhan Köse",
      devamsizlik_gun: 0,
      gec_kalma_adet: 1,
      izinli_gelmedi: 0,
      izinsiz_gelmedi: 0,
      raporlu: 0,
      tesvik_tutari: 1200,
      ceza_kesinti_tutari: 0,
      bolum_onay_durumu: "BOLUM_ONAYINDA",
      revize_var_mi: false,
      son_islem: "Bağlı amir günlük kayıtları hazırladı",
      kapanis_durumu: "ACIK"
    },
    {
      ay: fixtureAy,
      personel_id: 2,
      ad_soyad: "Mehmet Kaya",
      sicil_no: "P-002",
      sube_id: 2,
      sube: "Depolama",
      departman_id: 6,
      bolum: "Depo",
      bagli_amir_adi: "Serhan Köse",
      devamsizlik_gun: 1,
      gec_kalma_adet: 0,
      izinli_gelmedi: 1,
      izinsiz_gelmedi: 1,
      raporlu: 0,
      tesvik_tutari: 0,
      ceza_kesinti_tutari: 450,
      bolum_onay_durumu: "BOLUM_ONAYINDA",
      revize_var_mi: true,
      son_islem: "Bölüm yöneticisi revize istedi",
      kapanis_durumu: "ACIK"
    }
  ];
}

function getAylikOzetPageState(page: Page): MockAylikOzetPageState {
  const existing = aylikOzetStateByPage.get(page);
  if (existing) {
    return existing;
  }

  const fixtureAy = currentAylikOzetFixtureAy();
  const created: MockAylikOzetPageState = {
    fixtureAy,
    rows: createInitialAylikOzetRows(fixtureAy)
  };
  aylikOzetStateByPage.set(page, created);
  return created;
}

function okBody(data: unknown) {
  return JSON.stringify({
    data,
    meta: {},
    errors: []
  });
}

type RaporListMeta = {
  page?: number;
  limit?: number;
  total?: number;
  total_pages?: number;
  has_next_page?: boolean;
  has_prev_page?: boolean;
  kaynak?: "SNAPSHOT" | "LIVE";
  muhur_id?: number | null;
  donem?: string | null;
  effective_sube_id?: number | null;
};

function raporListOkBody(items: Record<string, unknown>[], metaOverrides?: RaporListMeta) {
  const page = metaOverrides?.page ?? 1;
  const limit = metaOverrides?.limit ?? 10;
  const total = metaOverrides?.total ?? items.length;
  const totalPages = metaOverrides?.total_pages ?? Math.max(1, Math.ceil(total / limit));

  return JSON.stringify({
    data: { items },
    meta: {
      page,
      limit,
      total,
      total_pages: totalPages,
      has_next_page: metaOverrides?.has_next_page ?? false,
      ...(metaOverrides?.has_prev_page !== undefined ? { has_prev_page: metaOverrides.has_prev_page } : {}),
      ...(metaOverrides?.kaynak ? { kaynak: metaOverrides.kaynak } : {}),
      ...(metaOverrides?.muhur_id !== undefined ? { muhur_id: metaOverrides.muhur_id } : {}),
      ...(metaOverrides?.donem !== undefined ? { donem: metaOverrides.donem } : {}),
      ...(metaOverrides?.effective_sube_id !== undefined
        ? { effective_sube_id: metaOverrides.effective_sube_id }
        : {})
    },
    errors: []
  });
}

function deriveRaporDonem(baslangic?: string | null, bitis?: string | null): string | null {
  if (!baslangic || !bitis) {
    return null;
  }

  const baslangicAy = baslangic.slice(0, 7);
  const bitisAy = bitis.slice(0, 7);
  if (baslangicAy !== bitisAy) {
    return null;
  }

  return baslangicAy;
}

function resolveReportKaynak(
  baslangic?: string | null,
  bitis?: string | null,
  muhurId?: number | null
): { kaynak: "SNAPSHOT" | "LIVE"; donem: string | null; muhur_id: number | null } {
  if (muhurId !== undefined && muhurId !== null && muhurId > 0) {
    return { kaynak: "SNAPSHOT", donem: null, muhur_id: muhurId };
  }

  const donem = deriveRaporDonem(baslangic, bitis);
  if (donem) {
    return { kaynak: "SNAPSHOT", donem, muhur_id: 101 };
  }

  return { kaynak: "LIVE", donem, muhur_id: null };
}

const PERSONEL_SUBE_BY_ID: Record<number, number> = {
  1: 1,
  2: 2
};

const MUHUR_SEAL_SUBE_BY_ID: Record<number, number> = {
  2: 2,
  101: 1,
  123: 1
};

function personelMatchesRaporScope(personelId: number, subeScope: number | null, allowedSubeIds: number[]): boolean {
  const personelSube = PERSONEL_SUBE_BY_ID[personelId];
  if (personelSube === undefined) {
    return false;
  }

  if (subeScope !== null) {
    return personelSube === subeScope;
  }

  if (allowedSubeIds.length > 0) {
    return allowedSubeIds.includes(personelSube);
  }

  return true;
}

function scopeRaporItems<T extends { personel_id?: unknown }>(
  items: T[],
  subeScope: number | null,
  allowedSubeIds: number[] = []
): T[] {
  return items.filter((item) => {
    const personelId = item.personel_id;
    if (typeof personelId !== "number") {
      return false;
    }
    return personelMatchesRaporScope(personelId, subeScope, allowedSubeIds);
  });
}

function isMuhurSealAccessDenied(muhurId: number, subeScope: number | null, allowedSubeIds: number[]): boolean {
  const sealSubeId = MUHUR_SEAL_SUBE_BY_ID[muhurId];
  if (sealSubeId === undefined) {
    return false;
  }

  if (allowedSubeIds.length > 0 && !allowedSubeIds.includes(sealSubeId)) {
    return true;
  }

  if (subeScope !== null && subeScope !== sealSubeId) {
    return true;
  }

  return false;
}

function finansItemMatchesScope(
  personelId: number,
  subeScope: number | null,
  allowedSubeIds: number[]
): boolean {
  const personelSube = PERSONEL_SUBE_BY_ID[personelId];
  if (personelSube === undefined) {
    return allowedSubeIds.length === 0 && subeScope === null;
  }

  return personelMatchesRaporScope(personelId, subeScope, allowedSubeIds);
}

const PERSONEL_OZET_PAGINATED_ITEMS: Record<string, unknown>[] = [
  {
    personel_id: 1,
    ad_soyad: "Ayşe Yılmaz",
    sicil_no: "S-001",
    aktif_durum: "AKTIF",
    departman_id: 3,
    net_calisma_dakika: 510,
    sgk_prim_gun: 30
  },
  {
    personel_id: 2,
    ad_soyad: "Mehmet Kaya",
    sicil_no: "S-002",
    aktif_durum: "AKTIF",
    departman_id: 6,
    net_calisma_dakika: 480,
    sgk_prim_gun: 28
  }
];

function filterRaporItemsBySubeScope(
  items: Record<string, unknown>[],
  subeScope: number | null,
  allowedSubeIds: number[] = []
): Record<string, unknown>[] {
  return scopeRaporItems(items, subeScope, allowedSubeIds);
}

function personelOzetPaginatedBody(
  pageNumber: number,
  pageLimit: number,
  departmanId?: number,
  subeScope: number | null = null,
  allowedSubeIds: number[] = [],
  options?: {
    personelId?: number;
    baslangicTarihi?: string | null;
    bitisTarihi?: string | null;
    muhurId?: number | null;
  }
) {
  let scopedItems = scopeRaporItems(PERSONEL_OZET_PAGINATED_ITEMS, subeScope, allowedSubeIds);

  let filtered =
    departmanId === undefined
      ? scopedItems
      : scopedItems.filter((item) => item.departman_id === departmanId);

  if (options?.personelId !== undefined) {
    filtered = filtered.filter((item) => item.personel_id === options.personelId);
  }

  const sourceMeta = resolveReportKaynak(
    options?.baslangicTarihi,
    options?.bitisTarihi,
    options?.muhurId
  );

  if (departmanId === undefined && subeScope === null && allowedSubeIds.length === 0 && options?.personelId === undefined) {
    const total = PERSONEL_OZET_PAGINATED_ITEMS.length;
    const totalPages = 2;
    const items =
      pageNumber === 2
        ? [PERSONEL_OZET_PAGINATED_ITEMS[1]]
        : pageNumber === 1
          ? [PERSONEL_OZET_PAGINATED_ITEMS[0]]
          : [];

    return raporListOkBody(items, {
      page: pageNumber,
      limit: pageLimit,
      total,
      total_pages: totalPages,
      has_next_page: pageNumber < totalPages,
      has_prev_page: pageNumber > 1,
      kaynak: sourceMeta.kaynak,
      muhur_id: sourceMeta.muhur_id,
      donem: sourceMeta.donem,
      effective_sube_id: subeScope
    });
  }

  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / pageLimit));
  const start = (pageNumber - 1) * pageLimit;
  const items = filtered.slice(start, start + pageLimit);

  return raporListOkBody(items, {
    page: pageNumber,
    limit: pageLimit,
    total,
    total_pages: totalPages,
    has_next_page: pageNumber < totalPages,
    has_prev_page: pageNumber > 1,
    kaynak: sourceMeta.kaynak,
    muhur_id: sourceMeta.muhur_id,
    donem: sourceMeta.donem,
    effective_sube_id: subeScope
  });
}

const DEVAMSIZLIK_PAGINATED_ITEMS: Record<string, unknown>[] = [
  {
    personel_id: 1,
    ad_soyad: "Ayşe Yılmaz",
    departman_id: 3,
    baslangic_tarihi: "2026-04-10",
    bitis_tarihi: "2026-04-10",
    alt_tur: "IZINSIZ",
    state: "MUHURLENDI"
  },
  {
    personel_id: 2,
    ad_soyad: "Mehmet Kaya",
    departman_id: 4,
    baslangic_tarihi: "2026-04-12",
    bitis_tarihi: "2026-04-12",
    alt_tur: "IZINSIZ",
    state: "MUHURLENDI"
  }
];

function devamsizlikPaginatedBody(
  pageNumber: number,
  pageLimit: number,
  departmanId?: number,
  subeScope: number | null = null,
  allowedSubeIds: number[] = [],
  options?: {
    personelId?: number;
    baslangicTarihi?: string | null;
    bitisTarihi?: string | null;
    muhurId?: number | null;
  }
) {
  let scopedItems = scopeRaporItems(DEVAMSIZLIK_PAGINATED_ITEMS, subeScope, allowedSubeIds);

  let filtered =
    departmanId === undefined
      ? scopedItems
      : scopedItems.filter((item) => item.departman_id === departmanId);

  if (options?.personelId !== undefined) {
    filtered = filtered.filter((item) => item.personel_id === options.personelId);
  }

  const sourceMeta = resolveReportKaynak(
    options?.baslangicTarihi,
    options?.bitisTarihi,
    options?.muhurId
  );

  if (
    departmanId === undefined &&
    subeScope === null &&
    allowedSubeIds.length === 0 &&
    options?.personelId === undefined &&
    !options?.baslangicTarihi &&
    !options?.bitisTarihi
  ) {
    const items = [DEVAMSIZLIK_PAGINATED_ITEMS[0]];
    return raporListOkBody(items, {
      page: 1,
      limit: pageLimit,
      total: 1,
      total_pages: 1,
      has_next_page: false,
      has_prev_page: false,
      kaynak: "LIVE",
      muhur_id: null,
      donem: null,
      effective_sube_id: subeScope
    });
  }

  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / pageLimit));
  const start = (pageNumber - 1) * pageLimit;
  const items = filtered.slice(start, start + pageLimit);

  return raporListOkBody(items, {
    page: pageNumber,
    limit: pageLimit,
    total,
    total_pages: totalPages,
    has_next_page: pageNumber < totalPages,
    has_prev_page: pageNumber > 1,
    kaynak: sourceMeta.kaynak,
    muhur_id: sourceMeta.muhur_id,
    donem: sourceMeta.donem,
    effective_sube_id: subeScope
  });
}

const IZIN_PAGINATED_ITEMS: Record<string, unknown>[] = [
  {
    personel_id: 1,
    ad_soyad: "Ayşe Yılmaz",
    departman_id: 3,
    baslangic_tarihi: "2026-04-03",
    bitis_tarihi: "2026-04-03",
    alt_tur: "YILLIK_IZIN",
    ucretli_mi: true,
    state: "MUHURLENDI"
  },
  {
    personel_id: 2,
    ad_soyad: "Mehmet Kaya",
    departman_id: 4,
    baslangic_tarihi: "2026-04-08",
    bitis_tarihi: "2026-04-08",
    alt_tur: "UCRETLI_IZIN",
    ucretli_mi: true,
    state: "MUHURLENDI"
  }
];

function izinPaginatedBody(
  pageNumber: number,
  pageLimit: number,
  departmanId?: number,
  subeScope: number | null = null,
  allowedSubeIds: number[] = [],
  options?: {
    personelId?: number;
    baslangicTarihi?: string | null;
    bitisTarihi?: string | null;
    muhurId?: number | null;
  }
) {
  let scopedItems = scopeRaporItems(IZIN_PAGINATED_ITEMS, subeScope, allowedSubeIds);

  let filtered =
    departmanId === undefined
      ? scopedItems
      : scopedItems.filter((item) => item.departman_id === departmanId);

  if (options?.personelId !== undefined) {
    filtered = filtered.filter((item) => item.personel_id === options.personelId);
  }

  const sourceMeta = resolveReportKaynak(
    options?.baslangicTarihi,
    options?.bitisTarihi,
    options?.muhurId
  );

  if (
    departmanId === undefined &&
    subeScope === null &&
    allowedSubeIds.length === 0 &&
    options?.personelId === undefined &&
    !options?.baslangicTarihi &&
    !options?.bitisTarihi
  ) {
    const items = [IZIN_PAGINATED_ITEMS[0]];
    return raporListOkBody(items, {
      page: 1,
      limit: pageLimit,
      total: 1,
      total_pages: 1,
      has_next_page: false,
      has_prev_page: false,
      kaynak: "LIVE",
      muhur_id: null,
      donem: null,
      effective_sube_id: subeScope
    });
  }

  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / pageLimit));
  const start = (pageNumber - 1) * pageLimit;
  const items = filtered.slice(start, start + pageLimit);

  return raporListOkBody(items, {
    page: pageNumber,
    limit: pageLimit,
    total,
    total_pages: totalPages,
    has_next_page: pageNumber < totalPages,
    has_prev_page: pageNumber > 1,
    kaynak: sourceMeta.kaynak,
    muhur_id: sourceMeta.muhur_id,
    donem: sourceMeta.donem,
    effective_sube_id: subeScope
  });
}

const IS_KAZASI_PAGINATED_ITEMS: Record<string, unknown>[] = [
  {
    personel_id: 1,
    ad_soyad: "Ayşe Yılmaz",
    departman_id: 3,
    baslangic_tarihi: "2026-04-12",
    bitis_tarihi: "2026-04-14",
    aciklama: "Hafif yaralanma",
    state: "AKTIF"
  },
  {
    personel_id: 2,
    ad_soyad: "Mehmet Kaya",
    departman_id: 4,
    baslangic_tarihi: "2026-04-18",
    bitis_tarihi: "2026-04-20",
    aciklama: "Is kazasi kaydi",
    state: "AKTIF"
  }
];

function isKazasiPaginatedBody(
  pageNumber: number,
  pageLimit: number,
  departmanId?: number,
  subeScope: number | null = null,
  allowedSubeIds: number[] = [],
  options?: {
    personelId?: number;
    baslangicTarihi?: string | null;
    bitisTarihi?: string | null;
  }
) {
  let scopedItems = scopeRaporItems(IS_KAZASI_PAGINATED_ITEMS, subeScope, allowedSubeIds);

  let filtered =
    departmanId === undefined
      ? scopedItems
      : scopedItems.filter((item) => item.departman_id === departmanId);

  if (options?.personelId !== undefined) {
    filtered = filtered.filter((item) => item.personel_id === options.personelId);
  }

  if (
    departmanId === undefined &&
    subeScope === null &&
    allowedSubeIds.length === 0 &&
    options?.personelId === undefined &&
    !options?.baslangicTarihi &&
    !options?.bitisTarihi
  ) {
    const items = [IS_KAZASI_PAGINATED_ITEMS[0]];
    return raporListOkBody(items, {
      page: 1,
      limit: pageLimit,
      total: 1,
      total_pages: 1,
      has_next_page: false,
      has_prev_page: false,
      kaynak: "SUREC",
      muhur_id: null,
      donem: null,
      effective_sube_id: subeScope
    });
  }

  const donem =
    options?.baslangicTarihi && /^\d{4}-\d{2}/.test(options.baslangicTarihi)
      ? options.baslangicTarihi.slice(0, 7)
      : null;

  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / pageLimit));
  const start = (pageNumber - 1) * pageLimit;
  const items = filtered.slice(start, start + pageLimit);

  return raporListOkBody(items, {
    page: pageNumber,
    limit: pageLimit,
    total,
    total_pages: totalPages,
    has_next_page: pageNumber < totalPages,
    has_prev_page: pageNumber > 1,
    kaynak: "SUREC",
    muhur_id: null,
    donem,
    effective_sube_id: subeScope
  });
}

const BILDIRIM_PAGINATED_ITEMS: Record<string, unknown>[] = [
  {
    tarih: "2026-04-11",
    departman_id: 3,
    personel_id: 1,
    ad_soyad: "Ayşe Yılmaz",
    bildirim_turu: "IZINSIZ_GELMEDI",
    aciklama: "Habersiz devamsizlik",
    state: "MUHURLENDI"
  },
  {
    tarih: "2026-04-16",
    departman_id: 4,
    personel_id: 2,
    ad_soyad: "Mehmet Kaya",
    bildirim_turu: "GEC_GELDI",
    aciklama: "Gec geldi bildirimi",
    state: "MUHURLENDI"
  }
];

function bildirimPaginatedBody(
  pageNumber: number,
  pageLimit: number,
  departmanId?: number,
  subeScope: number | null = null,
  allowedSubeIds: number[] = [],
  options?: {
    personelId?: number;
    baslangicTarihi?: string | null;
    bitisTarihi?: string | null;
    muhurId?: number | null;
  }
) {
  let scopedItems = scopeRaporItems(BILDIRIM_PAGINATED_ITEMS, subeScope, allowedSubeIds);

  let filtered =
    departmanId === undefined
      ? scopedItems
      : scopedItems.filter((item) => item.departman_id === departmanId);

  if (options?.personelId !== undefined) {
    filtered = filtered.filter((item) => item.personel_id === options.personelId);
  }

  const sourceMeta = resolveReportKaynak(
    options?.baslangicTarihi,
    options?.bitisTarihi,
    options?.muhurId
  );

  if (
    departmanId === undefined &&
    subeScope === null &&
    allowedSubeIds.length === 0 &&
    options?.personelId === undefined &&
    !options?.baslangicTarihi &&
    !options?.bitisTarihi
  ) {
    const items = [BILDIRIM_PAGINATED_ITEMS[0]];
    return raporListOkBody(items, {
      page: 1,
      limit: pageLimit,
      total: 1,
      total_pages: 1,
      has_next_page: false,
      has_prev_page: false,
      kaynak: "LIVE",
      muhur_id: null,
      donem: null,
      effective_sube_id: subeScope
    });
  }

  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / pageLimit));
  const start = (pageNumber - 1) * pageLimit;
  const items = filtered.slice(start, start + pageLimit);

  return raporListOkBody(items, {
    page: pageNumber,
    limit: pageLimit,
    total,
    total_pages: totalPages,
    has_next_page: pageNumber < totalPages,
    has_prev_page: pageNumber > 1,
    kaynak: sourceMeta.kaynak,
    muhur_id: sourceMeta.muhur_id,
    donem: sourceMeta.donem,
    effective_sube_id: subeScope
  });
}

const TESVIK_PAGINATED_ITEMS: Record<string, unknown>[] = [
  {
    personel_id: 1,
    ad_soyad: "Ayşe Yılmaz",
    departman_id: 3,
    donem: "2026-04",
    gun_sayisi: 22,
    toplam_tutar: 1500,
    state: "AKTIF"
  },
  {
    personel_id: 2,
    ad_soyad: "Mehmet Kaya",
    departman_id: 4,
    donem: "2026-04",
    gun_sayisi: 20,
    toplam_tutar: 900,
    state: "AKTIF"
  }
];

function tesvikPaginatedBody(
  pageNumber: number,
  pageLimit: number,
  departmanId?: number,
  subeScope: number | null = null,
  allowedSubeIds: number[] = [],
  options?: {
    personelId?: number;
    baslangicTarihi?: string | null;
    bitisTarihi?: string | null;
  }
) {
  let scopedItems = scopeRaporItems(TESVIK_PAGINATED_ITEMS, subeScope, allowedSubeIds);

  let filtered =
    departmanId === undefined
      ? scopedItems
      : scopedItems.filter((item) => item.departman_id === departmanId);

  if (options?.personelId !== undefined) {
    filtered = filtered.filter((item) => item.personel_id === options.personelId);
  }

  if (
    departmanId === undefined &&
    subeScope === null &&
    allowedSubeIds.length === 0 &&
    options?.personelId === undefined &&
    !options?.baslangicTarihi &&
    !options?.bitisTarihi
  ) {
    const items = [TESVIK_PAGINATED_ITEMS[0]];
    return raporListOkBody(items, {
      page: 1,
      limit: pageLimit,
      total: 1,
      total_pages: 1,
      has_next_page: false,
      has_prev_page: false,
      kaynak: "FINANS",
      muhur_id: null,
      donem: null,
      effective_sube_id: subeScope
    });
  }

  const donem =
    options?.baslangicTarihi && /^\d{4}-\d{2}/.test(options.baslangicTarihi)
      ? options.baslangicTarihi.slice(0, 7)
      : null;

  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / pageLimit));
  const start = (pageNumber - 1) * pageLimit;
  const items = filtered.slice(start, start + pageLimit);

  return raporListOkBody(items, {
    page: pageNumber,
    limit: pageLimit,
    total,
    total_pages: totalPages,
    has_next_page: pageNumber < totalPages,
    has_prev_page: pageNumber > 1,
    kaynak: "FINANS",
    muhur_id: null,
    donem,
    effective_sube_id: subeScope
  });
}

const CEZA_PAGINATED_ITEMS: Record<string, unknown>[] = [
  {
    personel_id: 1,
    ad_soyad: "Ayşe Yılmaz",
    departman_id: 3,
    donem: "2026-04",
    tutar: 500,
    aciklama: "Gec kalma",
    state: "AKTIF"
  },
  {
    personel_id: 2,
    ad_soyad: "Mehmet Kaya",
    departman_id: 4,
    donem: "2026-04",
    tutar: 350,
    aciklama: "Devamsizlik cezasi",
    state: "AKTIF"
  }
];

function cezaPaginatedBody(
  pageNumber: number,
  pageLimit: number,
  departmanId?: number,
  subeScope: number | null = null,
  allowedSubeIds: number[] = [],
  options?: {
    personelId?: number;
    baslangicTarihi?: string | null;
    bitisTarihi?: string | null;
  }
) {
  let scopedItems = scopeRaporItems(CEZA_PAGINATED_ITEMS, subeScope, allowedSubeIds);

  let filtered =
    departmanId === undefined
      ? scopedItems
      : scopedItems.filter((item) => item.departman_id === departmanId);

  if (options?.personelId !== undefined) {
    filtered = filtered.filter((item) => item.personel_id === options.personelId);
  }

  if (
    departmanId === undefined &&
    subeScope === null &&
    allowedSubeIds.length === 0 &&
    options?.personelId === undefined &&
    !options?.baslangicTarihi &&
    !options?.bitisTarihi
  ) {
    const items = [CEZA_PAGINATED_ITEMS[0]];
    return raporListOkBody(items, {
      page: 1,
      limit: pageLimit,
      total: 1,
      total_pages: 1,
      has_next_page: false,
      has_prev_page: false,
      kaynak: "FINANS",
      muhur_id: null,
      donem: null,
      effective_sube_id: subeScope
    });
  }

  const donem =
    options?.baslangicTarihi && /^\d{4}-\d{2}/.test(options.baslangicTarihi)
      ? options.baslangicTarihi.slice(0, 7)
      : null;

  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / pageLimit));
  const start = (pageNumber - 1) * pageLimit;
  const items = filtered.slice(start, start + pageLimit);

  return raporListOkBody(items, {
    page: pageNumber,
    limit: pageLimit,
    total,
    total_pages: totalPages,
    has_next_page: pageNumber < totalPages,
    has_prev_page: pageNumber > 1,
    kaynak: "FINANS",
    muhur_id: null,
    donem,
    effective_sube_id: subeScope
  });
}

const EKSTRA_PRIM_PAGINATED_ITEMS: Record<string, unknown>[] = [
  {
    personel_id: 1,
    ad_soyad: "Ayşe Yılmaz",
    departman_id: 3,
    donem: "2026-04",
    tutar: 800,
    aciklama: "Performans primi",
    state: "AKTIF"
  },
  {
    personel_id: 2,
    ad_soyad: "Mehmet Kaya",
    departman_id: 4,
    donem: "2026-04",
    tutar: 600,
    aciklama: "Ekstra prim odeme",
    state: "AKTIF"
  }
];

function ekstraPrimPaginatedBody(
  pageNumber: number,
  pageLimit: number,
  departmanId?: number,
  subeScope: number | null = null,
  allowedSubeIds: number[] = [],
  options?: {
    personelId?: number;
    baslangicTarihi?: string | null;
    bitisTarihi?: string | null;
  }
) {
  let scopedItems = scopeRaporItems(EKSTRA_PRIM_PAGINATED_ITEMS, subeScope, allowedSubeIds);

  let filtered =
    departmanId === undefined
      ? scopedItems
      : scopedItems.filter((item) => item.departman_id === departmanId);

  if (options?.personelId !== undefined) {
    filtered = filtered.filter((item) => item.personel_id === options.personelId);
  }

  if (
    departmanId === undefined &&
    subeScope === null &&
    allowedSubeIds.length === 0 &&
    options?.personelId === undefined &&
    !options?.baslangicTarihi &&
    !options?.bitisTarihi
  ) {
    const items = [EKSTRA_PRIM_PAGINATED_ITEMS[0]];
    return raporListOkBody(items, {
      page: 1,
      limit: pageLimit,
      total: 1,
      total_pages: 1,
      has_next_page: false,
      has_prev_page: false,
      kaynak: "FINANS",
      muhur_id: null,
      donem: null,
      effective_sube_id: subeScope
    });
  }

  const donem =
    options?.baslangicTarihi && /^\d{4}-\d{2}/.test(options.baslangicTarihi)
      ? options.baslangicTarihi.slice(0, 7)
      : null;

  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / pageLimit));
  const start = (pageNumber - 1) * pageLimit;
  const items = filtered.slice(start, start + pageLimit);

  return raporListOkBody(items, {
    page: pageNumber,
    limit: pageLimit,
    total,
    total_pages: totalPages,
    has_next_page: pageNumber < totalPages,
    has_prev_page: pageNumber > 1,
    kaynak: "FINANS",
    muhur_id: null,
    donem,
    effective_sube_id: subeScope
  });
}

const RAPOR_MOCK_ITEMS: Record<string, Record<string, unknown>[]> = {
  "/api/raporlar/personel-ozet": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      sicil_no: "S-001",
      aktif_durum: "AKTIF",
      net_calisma_dakika: 510,
      sgk_prim_gun: 30
    }
  ],
  "/api/raporlar/izin": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      baslangic_tarihi: "2026-04-01",
      bitis_tarihi: "2026-04-05",
      alt_tur: "YILLIK_IZIN",
      ucretli_mi: true,
      state: "AKTIF"
    }
  ],
  "/api/raporlar/devamsizlik": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      baslangic_tarihi: "2026-04-10",
      bitis_tarihi: "2026-04-10",
      alt_tur: "IZINSIZ",
      state: "AKTIF"
    }
  ],
  "/api/raporlar/tesvik": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      donem: "2026-04",
      gun_sayisi: 22,
      toplam_tutar: 1500,
      state: "AKTIF"
    }
  ],
  "/api/raporlar/ceza": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      donem: "2026-04",
      tutar: 500,
      aciklama: "Gec kalma",
      state: "AKTIF"
    }
  ],
  "/api/raporlar/ekstra-prim": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      donem: "2026-04",
      tutar: 800,
      aciklama: "Performans primi",
      state: "AKTIF"
    }
  ],
  "/api/raporlar/is-kazasi": [
    {
      personel_id: 1,
      ad_soyad: "Ayşe Yılmaz",
      baslangic_tarihi: "2026-03-15",
      bitis_tarihi: "2026-03-20",
      aciklama: "Hafif yaralanma",
      state: "AKTIF"
    }
  ],
  "/api/raporlar/bildirim": [
    {
      tarih: "2026-04-11",
      departman_id: 3,
      personel_id: 2,
      ad_soyad: "Mehmet Kaya",
      bildirim_turu: "IZINSIZ_GELMEDI",
      aciklama: "Habersiz devamsizlik",
      state: "AKTIF"
    }
  ]
};

function errorBody(code: string, message: string, field?: string) {
  return JSON.stringify({
    data: null,
    meta: {},
    errors: [{ code, ...(field ? { field } : {}), message }]
  });
}

const MOCK_BILDIRIM_ALLOWED_TURLER = [
  "GELMEDI",
  "GEC_GELDI",
  "ERKEN_CIKTI",
  "IZINLI",
  "RAPORLU",
  "GOREVDE",
  "DIGER"
] as const;

const MOCK_BILDIRIM_EDITABLE_STATES = ["TASLAK", "DUZELTME_ISTENDI"] as const;

const MOCK_BILDIRIM_LEGACY_TUR_MAP: Record<string, string> = {
  DEVAMSIZLIK: "GELMEDI",
  IZINLI_GELMEDI: "IZINLI",
  IZINSIZ_GELMEDI: "GELMEDI",
  GEC_CIKTI: "ERKEN_CIKTI"
};

type MockBildirimRecord = {
  id: number;
  tarih: string;
  departman_id: number;
  personel_id: number;
  sube_id?: number;
  bildirim_turu: string;
  aciklama?: string;
  state: string;
  okundu_mi?: boolean;
  created_by?: number;
  updated_by?: number;
  submitted_at?: string | null;
  correction_requested_by?: number | null;
  correction_reason?: string | null;
  haftalik_mutabakat_id?: number | null;
};

type MockHaftalikMutabakat = {
  id: number;
  sube_id: number;
  birim_amiri_user_id: number;
  hafta_baslangic: string;
  hafta_bitis: string;
  state: "TAMAMLANDI";
  onaylayan_user_id: number;
  onaylandi_at: string;
  created_at: string;
  updated_at: string;
};

function normalizeMockBildirimTuru(value: string | null | undefined): string | null {
  if (!value) {
    return null;
  }

  const upper = value.toUpperCase();
  const mapped = MOCK_BILDIRIM_LEGACY_TUR_MAP[upper] ?? upper;
  return MOCK_BILDIRIM_ALLOWED_TURLER.includes(mapped as (typeof MOCK_BILDIRIM_ALLOWED_TURLER)[number])
    ? mapped
    : null;
}

function isMockBildirimEditableState(state: string): boolean {
  return (MOCK_BILDIRIM_EDITABLE_STATES as readonly string[]).includes(state.toUpperCase());
}

type MockAylikBildirimOnay = {
  id: number;
  sube_id: number;
  birim_amiri_user_id: number;
  ay: string;
  ay_baslangic: string;
  ay_bitis: string;
  state: "TAMAMLANDI";
  onaylayan_user_id: number;
  onaylandi_at: string;
  aciklama: string | null;
  created_at: string;
  updated_at: string;
};

type MockGyBildirimOnay = {
  id: number;
  sube_id: number;
  birim_amiri_user_id: number;
  ay: string;
  aylik_bildirim_onayi_id: number;
  state: "TAMAMLANDI";
  onaylayan_user_id: number;
  onaylandi_at: string;
  aciklama: string | null;
  created_at: string;
  updated_at: string;
};

type MockBildirimPageState = {
  items: MockBildirimRecord[];
  nextId: number;
  mutabakatlar: MockHaftalikMutabakat[];
  nextMutabakatId: number;
  aylikOnaylar: MockAylikBildirimOnay[];
  nextAylikOnayId: number;
  gyOnaylar: MockGyBildirimOnay[];
  nextGyOnayId: number;
};

const bildirimStateByPage = new WeakMap<Page, MockBildirimPageState>();

function createInitialBildirimler(): MockBildirimRecord[] {
  return [
    {
      id: 701,
      tarih: "2026-04-09",
      departman_id: 3,
      personel_id: 1,
      sube_id: 1,
      bildirim_turu: "GEC_GELDI",
      aciklama: "Mevcut bildirim",
      state: "GONDERILDI",
      okundu_mi: false,
      created_by: 1,
      updated_by: 1
    },
    {
      id: 1,
      tarih: "2026-07-09",
      departman_id: 3,
      personel_id: 1,
      sube_id: 1,
      bildirim_turu: "GEC_GELDI",
      aciklama: "Temmuz bildirimi",
      state: "HAFTALIK_MUTABAKATA_ALINDI",
      okundu_mi: false,
      created_by: 1,
      updated_by: 1,
      haftalik_mutabakat_id: 100
    }
  ];
}

function createSeededJulyAylikOnayState(): Pick<
  MockBildirimPageState,
  "mutabakatlar" | "nextMutabakatId" | "aylikOnaylar" | "nextAylikOnayId"
> {
  const seededAt = "2026-07-11T10:00:00.000Z";
  return {
    mutabakatlar: [
      {
        id: 100,
        sube_id: 1,
        birim_amiri_user_id: 1,
        hafta_baslangic: "2026-07-06",
        hafta_bitis: "2026-07-12",
        state: "TAMAMLANDI",
        onaylayan_user_id: 1,
        onaylandi_at: seededAt,
        created_at: seededAt,
        updated_at: seededAt
      }
    ],
    nextMutabakatId: 0,
    aylikOnaylar: [
      {
        id: 1,
        sube_id: 1,
        birim_amiri_user_id: 1,
        ay: "2026-07",
        ay_baslangic: "2026-07-01",
        ay_bitis: "2026-07-31",
        state: "TAMAMLANDI",
        onaylayan_user_id: 1,
        onaylandi_at: seededAt,
        aciklama: null,
        created_at: seededAt,
        updated_at: seededAt
      }
    ],
    nextAylikOnayId: 1
  };
}

function getBildirimPageState(page: Page): MockBildirimPageState {
  const existing = bildirimStateByPage.get(page);
  if (existing) {
    return existing;
  }

  const created: MockBildirimPageState = {
    items: createInitialBildirimler(),
    nextId: 800,
    ...createSeededJulyAylikOnayState(),
    gyOnaylar: [],
    nextGyOnayId: 0
  };
  bildirimStateByPage.set(page, created);
  return created;
}

function normalizeMockSubeIdsWithVarsayilan(subeIds: number[], varsayilanSubeId: number | null | undefined) {
  if (varsayilanSubeId == null || subeIds.length === 0) {
    return subeIds;
  }

  const others = subeIds.filter((id) => id !== varsayilanSubeId);
  return [varsayilanSubeId, ...others];
}

function assertMockVarsayilanSubeInScope(
  varsayilanSubeId: number | null | undefined,
  subeIds: number[] | undefined
) {
  if (varsayilanSubeId == null) {
    return null;
  }

  const scope = subeIds ?? [];
  if (!scope.includes(varsayilanSubeId)) {
    return "Varsayilan sube yetki verilen subeler icinde olmalidir.";
  }

  return null;
}

function surecListOkBody(items: unknown[], metaOverrides?: RaporListMeta) {
  const page = metaOverrides?.page ?? 1;
  const limit = metaOverrides?.limit ?? 10;
  const total = metaOverrides?.total ?? items.length;
  const totalPages = metaOverrides?.total_pages ?? Math.max(1, Math.ceil(total / limit));

  return JSON.stringify({
    data: { items },
    meta: {
      page,
      limit,
      total,
      total_pages: totalPages
    },
    errors: []
  });
}

async function fulfillJson(route: Route, status: number, body: string) {
  await route.fulfill({
    status,
    contentType: "application/json",
    body
  });
}

const SUBE_SCOPE_MISMATCH_MESSAGE = "Bu kayıt aktif şube bağlamında görüntülenemiyor.";
const PERSONEL_CREATE_SUBE_SCOPE_MISMATCH_MESSAGE = "Bu kayit aktif sube baglaminda goruntulenemiyor.";
const PERSONEL_CREATE_SUBE_UNAUTHORIZED_MESSAGE = "Secili sube icin yetkiniz yok.";
const DUPLICATE_TC_KIMLIK_NO_MESSAGE = "Bu T.C. Kimlik No ile kayıt açılamaz.";
const SUPPORTED_SUREC_TURLERI = new Set([
  "IZIN",
  "DEVAMSIZLIK",
  "TESVIK",
  "RAPOR",
  "IS_KAZASI",
  "DISIPLIN",
  "BELGE",
  "POZISYON_DEGISTI",
  "ISTEN_AYRILMA",
  "GOREV_DEGISIKLIGI",
  "UCRET_DEGISIKLIGI",
  "ORG_DEGISIKLIK",
  "BAGLI_AMIR_ATANDI",
  "BAGLI_AMIR_DEGISTI",
  "BAGLI_AMIR_ATAMASI_KALDIRILDI",
  "BIRIM_AMIRI_ATANDI",
  "BIRIM_AMIRI_ATAMASI_KALDIRILDI",
  "SUBE_YETKISI_DEGISTI"
]);

function getRequestSubeScope(request: { headers(): { [key: string]: string } }, url: URL): number | null {
  const querySubeId = url.searchParams.get("sube_id");
  const headers = request.headers();
  const headerSubeId = headers["x-active-sube-id"] ?? headers["X-Active-Sube-Id"];
  const raw = querySubeId || headerSubeId;

  if (raw === null || raw === undefined || raw.trim() === "") {
    return null;
  }

  const parsed = Number.parseInt(raw, 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

function parseStrictPositiveIntParam(value: string | null): number | null {
  if (value === null || value.trim() === "") {
    return null;
  }

  const trimmed = value.trim();
  const parsed = Number.parseInt(trimmed, 10);
  return Number.isInteger(parsed) && parsed > 0 && String(parsed) === trimmed ? parsed : null;
}

function parsePayloadPositiveInt(value: unknown): number | null {
  if (typeof value === "number" && Number.isInteger(value) && value > 0) {
    return value;
  }

  if (typeof value === "string") {
    return parseStrictPositiveIntParam(value);
  }

  return null;
}

function isValidDateString(value: unknown): value is string {
  if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return false;
  }

  const parsed = Date.parse(`${value}T00:00:00`);
  return Number.isFinite(parsed);
}

function resolveMockIlkIkiGunFirmaOderMi(
  surecTuru: string,
  altTur: string | undefined,
  payload: Record<string, unknown>
): boolean | null {
  if (surecTuru === "RAPOR" && altTur === "Raporlu_Hastalik") {
    if (payload.ilk_iki_gun_firma_oder_mi !== undefined && payload.ilk_iki_gun_firma_oder_mi !== null) {
      return Boolean(payload.ilk_iki_gun_firma_oder_mi);
    }
    return false;
  }

  return null;
}

export async function mockApi(page: Page, role: MockUserRole, options: MockApiOptions = {}) {
  const bildirimPageState = getBildirimPageState(page);
  const bildirimler = bildirimPageState.items;
  const bagliAmirReferanslari: Array<{ id: number; ad: string; sube_id: number; departman_id: number }> = [
    { id: 9, ad: "Demo Amir", sube_id: 1, departman_id: 3 },
    { id: 10, ad: "İkinci Amir", sube_id: 2, departman_id: 6 }
  ];

  const personeller: Array<{
    id: number;
    tc_kimlik_no: string;
    ad: string;
    soyad: string;
    aktif_durum: "AKTIF" | "PASIF";
    sube_id?: number;
    telefon?: string;
    dogum_tarihi?: string;
    dogum_yeri?: string;
    kan_grubu?: string;
    sicil_no?: string;
    ise_giris_tarihi?: string;
    acil_durum_kisi?: string;
    acil_durum_telefon?: string;
    departman_id?: number;
    gorev_id?: number;
    personel_tipi_id?: number;
    bagli_amir_id?: number;
    sube_adi?: string;
    departman_adi?: string;
    gorev_adi?: string;
    personel_tipi_adi?: string;
    bagli_amir_adi?: string;
    ucret_tipi_id?: number;
    ucret_tipi_adi?: string;
    maas_tutari?: number;
    net_maas_tutari?: number;
    prim_kurali_id?: number;
    prim_kurali_adi?: string;
  }> = [
    {
      id: 1,
      tc_kimlik_no: "12345678901",
      ad: "Ayşe",
      soyad: "Yılmaz",
      aktif_durum: "AKTIF",
      sube_id: 1,
      telefon: "05550000000",
      dogum_tarihi: "1992-03-14",
      dogum_yeri: "İstanbul",
      kan_grubu: "A Rh+",
      sicil_no: "P-001",
      ise_giris_tarihi: "2023-02-01",
      acil_durum_kisi: "Fatma Yılmaz",
      acil_durum_telefon: "05553334455",
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 1,
      bagli_amir_id: 9,
      sube_adi: "Merkez",
      departman_adi: "Döşeme",
      gorev_adi: "Genel Müdür",
      personel_tipi_adi: "Tam Zamanlı",
      bagli_amir_adi: "Demo Amir",
      ucret_tipi_id: 1,
      ucret_tipi_adi: "Aylık",
      maas_tutari: 35000,
      prim_kurali_id: 7,
      prim_kurali_adi: "7 No'lu Prim Kuralı"
    },
    {
      id: 2,
      tc_kimlik_no: "23456789012",
      ad: "Mehmet",
      soyad: "Kaya",
      aktif_durum: "AKTIF",
      sube_id: 2,
      telefon: "05551111111",
      dogum_tarihi: "1989-11-02",
      dogum_yeri: "Ankara",
      kan_grubu: "0 Rh+",
      sicil_no: "P-002",
      ise_giris_tarihi: "2024-07-15",
      acil_durum_kisi: "Zeynep Kaya",
      acil_durum_telefon: "05556667788",
      departman_id: 6,
      gorev_id: 2,
      personel_tipi_id: 2,
      bagli_amir_id: 9,
      sube_adi: "Depolama",
      departman_adi: "Depo",
      gorev_adi: "Üretim Müdürü",
      personel_tipi_adi: "Yarı Zamanlı",
      bagli_amir_adi: "Demo Amir",
      ucret_tipi_id: 2,
      ucret_tipi_adi: "Saatlik",
      maas_tutari: 25000,
      prim_kurali_id: 8,
      prim_kurali_adi: "8 No'lu Prim Kuralı"
    },
    {
      id: 3,
      tc_kimlik_no: "34567890123",
      ad: "Pasif",
      soyad: "Ornek",
      aktif_durum: "PASIF",
      sube_id: 1,
      telefon: "05552222222",
      dogum_tarihi: "1990-01-01",
      dogum_yeri: "İzmir",
      kan_grubu: "B Rh+",
      sicil_no: "P-003",
      ise_giris_tarihi: "2022-01-10",
      acil_durum_kisi: "Yakın Kişi",
      acil_durum_telefon: "05559998877",
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 1,
      bagli_amir_id: 9,
      sube_adi: "Merkez",
      departman_adi: "Döşeme",
      gorev_adi: "Genel Müdür",
      personel_tipi_adi: "Tam Zamanlı",
      bagli_amir_adi: "Demo Amir",
      ucret_tipi_id: 1,
      ucret_tipi_adi: "Aylık",
      maas_tutari: 30000,
      prim_kurali_id: 7,
      prim_kurali_adi: "7 No'lu Prim Kuralı"
    },
    {
      id: 4,
      tc_kimlik_no: "45678901234",
      ad: "Maas",
      soyad: "Eksik",
      aktif_durum: "AKTIF",
      sube_id: 1,
      telefon: "05323334444",
      dogum_tarihi: "1988-08-08",
      dogum_yeri: "Bursa",
      kan_grubu: "AB Rh+",
      sicil_no: "P-004",
      ise_giris_tarihi: "2025-01-01",
      acil_durum_kisi: "Acil Kisi",
      acil_durum_telefon: "05325556677",
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 1,
      bagli_amir_id: 9,
      sube_adi: "Merkez",
      departman_adi: "Döşeme",
      gorev_adi: "Genel Müdür",
      personel_tipi_adi: "Tam Zamanlı",
      bagli_amir_adi: "Demo Amir",
      ucret_tipi_id: 1,
      ucret_tipi_adi: "Aylık"
    },
    {
      id: 5,
      tc_kimlik_no: "56789012345",
      ad: "Ucuncu",
      soyad: "Sube",
      aktif_durum: "AKTIF",
      sube_id: 99,
      telefon: "05328889900",
      dogum_tarihi: "1993-05-20",
      dogum_yeri: "Antalya",
      kan_grubu: "A Rh-",
      sicil_no: "P-005",
      ise_giris_tarihi: "2025-06-01",
      acil_durum_kisi: "Acil Kisi",
      acil_durum_telefon: "05321112233",
      departman_id: 6,
      gorev_id: 2,
      personel_tipi_id: 1,
      bagli_amir_id: 10,
      sube_adi: "Pasif Şube",
      departman_adi: "Depo",
      gorev_adi: "Uretim Müdürü",
      personel_tipi_adi: "Tam Zamanlı",
      bagli_amir_adi: "İkinci Amir",
      ucret_tipi_id: 1,
      ucret_tipi_adi: "Aylık",
      maas_tutari: 22000,
      prim_kurali_id: 8,
      prim_kurali_adi: "8 No'lu Prim Kuralı"
    }
  ];

  const surecler: Array<{
    id: number;
    personel_id: number;
    surec_turu: string;
    alt_tur?: string;
    baslangic_tarihi: string;
    bitis_tarihi?: string;
    effective_date?: string;
    created_at?: string;
    ucretli_mi?: boolean;
    ilk_iki_gun_firma_oder_mi?: boolean | null;
    aciklama?: string;
    state: string;
  }> = [
    {
      id: 501,
      personel_id: 1,
      surec_turu: "IZIN",
      alt_tur: "YILLIK_IZIN",
      baslangic_tarihi: "2026-04-10",
      bitis_tarihi: "2026-04-11",
      effective_date: "2026-04-10",
      created_at: "2026-04-10T10:00:00.000Z",
      ucretli_mi: true,
      aciklama: "Mevcut surec",
      state: "AKTIF"
    },
    {
      id: 502,
      personel_id: 1,
      surec_turu: "DEVAMSIZLIK",
      baslangic_tarihi: "2026-03-08",
      aciklama: "Demo devamsizlik sinyali",
      state: "AKTIF"
    },
    {
      id: 504,
      personel_id: 1,
      surec_turu: "BELGE",
      alt_tur: "SERTIFIKA",
      baslangic_tarihi: "2024-03-01",
      aciklama: JSON.stringify({
        _personel_belge_kaydi: true,
        kayit_tipi: "SERTIFIKA",
        ad: "Forklift Operatör Belgesi",
        baslangic_tarihi: "2024-03-01",
        bitis_tarihi: "2027-03-01"
      }),
      state: "AKTIF"
    },
    {
      id: 505,
      personel_id: 1,
      surec_turu: "BELGE",
      alt_tur: "SERTIFIKA",
      baslangic_tarihi: "2026-06-28",
      aciklama: JSON.stringify({
        _personel_belge_kaydi: true,
        kayit_tipi: "SERTIFIKA",
        ad: "S32 Forklift Belgesi",
        baslangic_tarihi: "2026-06-28"
      }),
      created_at: "2026-06-28T10:00:00.000Z",
      state: "AKTIF"
    },
    {
      id: 503,
      personel_id: 2,
      surec_turu: "IZIN",
      alt_tur: "UCRETSIZ_IZIN",
      baslangic_tarihi: "2026-04-15",
      bitis_tarihi: "2026-04-15",
      effective_date: "2026-04-15",
      created_at: "2026-04-15T10:00:00.000Z",
      ucretli_mi: false,
      aciklama: "Sube 2 scope kontrol sureci",
      state: "AKTIF"
    }
  ];

  const DEMO_BELGE_TURLERI = ["KIMLIK", "ADRES_BEYANI", "IS_GIRIS_EVRAKLARI", "BANKA_IBAN"] as const;
  const belgeDurumByPersonelId = new Map<number, Record<string, "VAR" | "YOK">>();

  const zimmetler: Array<{
    id: number;
    personel_id: number;
    urun_turu: string;
    teslim_tarihi: string;
    teslim_eden?: string;
    aciklama?: string;
    teslim_durumu: string;
    zimmet_durumu: string;
    iade_tarihi?: string;
  }> = [
    {
      id: 551,
      personel_id: 1,
      urun_turu: "KASK",
      teslim_tarihi: "2026-03-01",
      teslim_eden: "IK Gorevlisi",
      aciklama: "Seri No: KSK-001",
      teslim_durumu: "YENI",
      zimmet_durumu: "AKTIF"
    },
    {
      id: 552,
      personel_id: 1,
      urun_turu: "KULAKLIK",
      teslim_tarihi: "2026-01-15",
      teslim_eden: "Bağlı Amir",
      aciklama: "Önceki vardiyadan teslim alındı",
      teslim_durumu: "IKINCI_EL",
      zimmet_durumu: "IADE_EDILDI",
      iade_tarihi: "2026-02-20"
    }
  ];

  const personelBelgeKayitlari: Array<{
    id: number;
    personel_id: number;
    kayit_tipi: PersonelBelgeKayitTipi;
    ad: string;
    veren_kurum?: string | null;
    belge_no?: string | null;
    baslangic_tarihi?: string | null;
    bitis_tarihi?: string | null;
    durum: PersonelBelgeKayitDurum;
    ek_ref?: string | null;
    aciklama?: string | null;
    created_at?: string;
    updated_at?: string;
  }> = [
    {
      id: 901,
      personel_id: 1,
      kayit_tipi: "SERTIFIKA",
      ad: "Forklift Operatör Belgesi",
      veren_kurum: "Medisa Eğitim Merkezi",
      belge_no: "FRK-2024-001",
      baslangic_tarihi: "2024-03-01",
      bitis_tarihi: options.belgeReferenceDate
        ? isoDateDaysFrom(options.belgeReferenceDate, 31)
        : "2027-03-01",
      durum: "AKTIF",
      created_at: "2024-03-01T10:00:00.000Z"
    },
    {
      id: 902,
      personel_id: 1,
      kayit_tipi: "EHLIYET",
      ad: "B Sınıfı Ehliyet",
      veren_kurum: "İstanbul İl Emniyet",
      belge_no: "TR-987654",
      baslangic_tarihi: "2018-05-10",
      bitis_tarihi: options.belgeReferenceDate
        ? isoDateDaysFrom(options.belgeReferenceDate, 30)
        : "2026-07-15",
      durum: "AKTIF",
      created_at: "2018-05-10T10:00:00.000Z"
    },
    ...(options.belgeReferenceDate
      ? [
          {
            id: 9901,
            personel_id: 1,
            kayit_tipi: "SERTIFIKA" as const,
            ad: "Sınırdan Bir Gün Önce Belgesi",
            bitis_tarihi: isoDateDaysFrom(options.belgeReferenceDate, 29),
            durum: "AKTIF" as const
          },
          {
            id: 9902,
            personel_id: 1,
            kayit_tipi: "SERTIFIKA" as const,
            ad: "Süresi Dolmuş Belge",
            bitis_tarihi: isoDateDaysFrom(options.belgeReferenceDate, -1),
            durum: "AKTIF" as const
          }
        ]
      : []),
    {
      id: 903,
      personel_id: 1,
      kayit_tipi: "SERTFIKA",
      ad: "S34 İptal Sertifika",
      veren_kurum: "Medisa Eğitim Merkezi",
      belge_no: "S34-001",
      baslangic_tarihi: "2023-01-01",
      bitis_tarihi: "2025-01-01",
      durum: "IPTAL",
      aciklama: "Eski kayıt iptal edildi",
      created_at: "2023-01-01T10:00:00.000Z",
      updated_at: "2024-06-15T10:00:00.000Z"
    }
  ];


  const finansKalemleri: Array<{
    id: number;
    personel_id: number;
    donem: string;
    kalem_turu: string;
    tutar: number;
    aciklama?: string;
    state: string;
  }> = [
    {
      id: 901,
      personel_id: 1,
      donem: "2026-04",
      kalem_turu: "AVANS",
      tutar: 2500,
      aciklama: "Mevcut finans kalemi",
      state: "AKTIF"
    },
    {
      id: 902,
      personel_id: 2,
      donem: "2026-04",
      kalem_turu: "PRIM",
      tutar: 1200,
      aciklama: "Sube 2 finans kalemi",
      state: "AKTIF"
    },
    {
      id: 903,
      personel_id: 1,
      donem: "2026-03",
      kalem_turu: "PRIM",
      tutar: 1800,
      aciklama: "Farkli donem finans kaydi",
      state: "AKTIF"
    },
    {
      id: 904,
      personel_id: 1,
      donem: "2026-04",
      kalem_turu: "PRIM",
      tutar: 900,
      aciklama: "Iptal finans kaydi",
      state: "IPTAL"
    }
  ];

  const maasSnapshots: Array<Record<string, any>> = [];
  const maasAudits: Array<Record<string, any>> = [];
  let maasNextId = 0;
  let maasNextAuditId = 0;

  const puantajKayitlari: GunlukPuantaj[] = [
    {
      personel_id: 1,
      tarih: "2026-04-09",
      gun_tipi: "Normal_Is_Gunu",
      hareket_durumu: "Geldi",
      hesap_etkisi: "Tam_Yevmiye_Ver",
      giris_saati: "08:30",
      cikis_saati: "18:00",
      gercek_mola_dakika: 60,
      hesaplanan_mola_dakika: 60,
      net_calisma_suresi_dakika: 510,
      gunluk_brut_sure_dakika: 570,
      hafta_tatili_hak_kazandi_mi: true,
      state: "HESAPLANDI",
      compliance_uyarilari: []
    },
    {
      personel_id: 1,
      tarih: "2026-04-10",
      gun_tipi: "Normal_Is_Gunu",
      hareket_durumu: "Gec_Geldi",
      dayanak: "Ucretli_Izinli",
      hesap_etkisi: "Tam_Yevmiye_Ver",
      giris_saati: "09:15",
      cikis_saati: "18:00",
      gercek_mola_dakika: 60,
      hesaplanan_mola_dakika: 60,
      net_calisma_suresi_dakika: 465,
      gunluk_brut_sure_dakika: 525,
      hafta_tatili_hak_kazandi_mi: true,
      state: "HESAPLANDI",
      compliance_uyarilari: []
    },
    {
      personel_id: 2,
      tarih: "2026-04-09",
      gun_tipi: "Normal_Is_Gunu",
      hareket_durumu: "Gelmedi",
      dayanak: "Yok_Izinsiz",
      hesap_etkisi: "Yevmiye_Kes",
      hafta_tatili_hak_kazandi_mi: false,
      state: "HESAPLANDI",
      compliance_uyarilari: []
    }
  ];

  type MockPuantajEtkiAday = {
    id: number;
    genel_yonetici_bildirim_onayi_id: number;
    aylik_bildirim_onayi_id: number;
    gunluk_bildirim_id: number;
    personel_id: number;
    sube_id: number;
    birim_amiri_user_id: number;
    ay: string;
    tarih: string;
    bildirim_turu: string;
    bildirim_alt_tur: string | null;
    bildirim_dakika: number | null;
    bildirim_aciklama: string | null;
    bildirim_created_at: string;
    bildirim_updated_at: string;
    etki_turu: string;
    etki_miktari: number | null;
    etki_birimi: string | null;
    state: "HAZIR" | "INCELEME_GEREKLI" | "UYGULANDI" | "YOK_SAYILDI";
    conflict_code: string | null;
    conflict_detail: Record<string, unknown> | null;
    resmi_surec_id: number | null;
    resmi_surec_turu: string | null;
    resmi_surec_alt_tur: string | null;
    ucretli_mi_snapshot: boolean | null;
    mevcut_puantaj_id: number | null;
    source_priority: string;
    created_by: number;
    source_snapshot: Record<string, unknown> | null;
    source_hash: string | null;
    projection_version: string | null;
    created_at: string;
    updated_at: string;
    karar_veren_user_id: number | null;
    karar_zamani: string | null;
    karar_gerekcesi: string | null;
    uygulanan_puantaj_id: number | null;
    onceki_puantaj_snapshot: Record<string, unknown> | null;
    sonraki_puantaj_snapshot: Record<string, unknown> | null;
    uygulama_hash: string | null;
    uygulama_modu: "OTOMATIK" | "MANUEL";
    manuel_karar_turu: string | null;
    manuel_karar_miktari: number | null;
  };

  const puantajEtkiAdaylari: MockPuantajEtkiAday[] = [
    {
      id: 1,
      genel_yonetici_bildirim_onayi_id: 10,
      aylik_bildirim_onayi_id: 2,
      gunluk_bildirim_id: 101,
      personel_id: 1,
      sube_id: 1,
      birim_amiri_user_id: 1,
      ay: "2026-06",
      tarih: "2026-06-03",
      bildirim_turu: "GEC_KALMA",
      bildirim_alt_tur: null,
      bildirim_dakika: 15,
      bildirim_aciklama: "Sabah gecikme bildirimi",
      bildirim_created_at: "2026-06-03 08:45:00",
      bildirim_updated_at: "2026-06-03 08:45:00",
      etki_turu: "GEC_KALMA_DK",
      etki_miktari: 15,
      etki_birimi: "DK",
      state: "HAZIR",
      conflict_code: null,
      conflict_detail: null,
      resmi_surec_id: null,
      resmi_surec_turu: null,
      resmi_surec_alt_tur: null,
      ucretli_mi_snapshot: null,
      mevcut_puantaj_id: null,
      source_priority: "BILDIRIM",
      created_by: 1,
      source_snapshot: { aciklama: "Sabah gecikme bildirimi", ad_soyad: "Ali Demir" },
      source_hash: "hash-1",
      projection_version: "v1",
      created_at: "2026-06-10 10:00:00",
      updated_at: "2026-06-10 10:00:00",
      karar_veren_user_id: null,
      karar_zamani: null,
      karar_gerekcesi: null,
      uygulanan_puantaj_id: null,
      onceki_puantaj_snapshot: null,
      sonraki_puantaj_snapshot: null,
      uygulama_hash: null,
      uygulama_modu: "OTOMATIK",
      manuel_karar_turu: null,
      manuel_karar_miktari: null
    },
    {
      id: 2,
      genel_yonetici_bildirim_onayi_id: 11,
      aylik_bildirim_onayi_id: 3,
      gunluk_bildirim_id: 202,
      personel_id: 2,
      sube_id: 2,
      birim_amiri_user_id: 4,
      ay: "2026-06",
      tarih: "2026-06-04",
      bildirim_turu: "GELMEDI",
      bildirim_alt_tur: null,
      bildirim_dakika: null,
      bildirim_aciklama: "Sube 2 gelmedi bildirimi",
      bildirim_created_at: "2026-06-04 09:00:00",
      bildirim_updated_at: "2026-06-04 09:00:00",
      etki_turu: "DEVAMSIZLIK_GUN",
      etki_miktari: null,
      etki_birimi: null,
      state: "INCELEME_GEREKLI",
      conflict_code: "MEVCUT_PUANTAJ_VAR",
      conflict_detail: { message: "Sube 2 mevcut puantaj cakismasi." },
      resmi_surec_id: null,
      resmi_surec_turu: null,
      resmi_surec_alt_tur: null,
      ucretli_mi_snapshot: null,
      mevcut_puantaj_id: 55,
      source_priority: "BILDIRIM",
      created_by: 1,
      source_snapshot: { aciklama: "Sube 2 gelmedi bildirimi" },
      source_hash: "hash-2",
      projection_version: "v1",
      created_at: "2026-06-10 10:05:00",
      updated_at: "2026-06-10 10:05:00",
      karar_veren_user_id: null,
      karar_zamani: null,
      karar_gerekcesi: null,
      uygulanan_puantaj_id: null,
      onceki_puantaj_snapshot: null,
      sonraki_puantaj_snapshot: null,
      uygulama_hash: null,
      uygulama_modu: "OTOMATIK",
      manuel_karar_turu: null,
      manuel_karar_miktari: null
    },
    {
      id: 3,
      genel_yonetici_bildirim_onayi_id: 10,
      aylik_bildirim_onayi_id: 2,
      gunluk_bildirim_id: 103,
      personel_id: 1,
      sube_id: 1,
      birim_amiri_user_id: 1,
      ay: "2026-06",
      tarih: "2026-06-04",
      bildirim_turu: "GELMEDI",
      bildirim_alt_tur: null,
      bildirim_dakika: null,
      bildirim_aciklama: "Gelmedi bildirimi",
      bildirim_created_at: "2026-06-04 09:00:00",
      bildirim_updated_at: "2026-06-04 09:00:00",
      etki_turu: "DEVAMSIZLIK_GUN",
      etki_miktari: null,
      etki_birimi: null,
      state: "INCELEME_GEREKLI",
      conflict_code: "MEVCUT_PUANTAJ_VAR",
      conflict_detail: { message: "İlgili gün için mevcut puantaj kaydı bulunuyor." },
      resmi_surec_id: null,
      resmi_surec_turu: null,
      resmi_surec_alt_tur: null,
      ucretli_mi_snapshot: null,
      mevcut_puantaj_id: 55,
      source_priority: "BILDIRIM",
      created_by: 1,
      source_snapshot: { aciklama: "Gelmedi bildirimi" },
      source_hash: "hash-2",
      projection_version: "v1",
      created_at: "2026-06-10 10:05:00",
      updated_at: "2026-06-10 10:05:00",
      karar_veren_user_id: null,
      karar_zamani: null,
      karar_gerekcesi: null,
      uygulanan_puantaj_id: null,
      onceki_puantaj_snapshot: null,
      sonraki_puantaj_snapshot: null,
      uygulama_hash: null,
      uygulama_modu: "OTOMATIK",
      manuel_karar_turu: null,
      manuel_karar_miktari: null
    },
    {
      id: 4,
      genel_yonetici_bildirim_onayi_id: 10,
      aylik_bildirim_onayi_id: 2,
      gunluk_bildirim_id: 104,
      personel_id: 1,
      sube_id: 1,
      birim_amiri_user_id: 1,
      ay: "2026-06",
      tarih: "2026-06-05",
      bildirim_turu: "ERKEN_CIKIS",
      bildirim_alt_tur: null,
      bildirim_dakika: 30,
      bildirim_aciklama: null,
      bildirim_created_at: "2026-06-05 16:30:00",
      bildirim_updated_at: "2026-06-05 16:30:00",
      etki_turu: "ERKEN_CIKIS_DK",
      etki_miktari: 30,
      etki_birimi: "DK",
      state: "UYGULANDI",
      conflict_code: null,
      conflict_detail: null,
      resmi_surec_id: null,
      resmi_surec_turu: null,
      resmi_surec_alt_tur: null,
      ucretli_mi_snapshot: null,
      mevcut_puantaj_id: null,
      source_priority: "BILDIRIM",
      created_by: 1,
      source_snapshot: null,
      source_hash: "hash-3",
      projection_version: "v1",
      created_at: "2026-06-10 10:10:00",
      updated_at: "2026-06-11 11:00:00",
      karar_veren_user_id: 5,
      karar_zamani: "2026-06-11 11:00:00",
      karar_gerekcesi: "Otomatik uygulandi",
      uygulanan_puantaj_id: 77,
      onceki_puantaj_snapshot: null,
      sonraki_puantaj_snapshot: null,
      uygulama_hash: "apply-hash-3",
      uygulama_modu: "OTOMATIK",
      manuel_karar_turu: null,
      manuel_karar_miktari: null
    },
    {
      id: 5,
      genel_yonetici_bildirim_onayi_id: 10,
      aylik_bildirim_onayi_id: 2,
      gunluk_bildirim_id: 105,
      personel_id: 1,
      sube_id: 1,
      birim_amiri_user_id: 1,
      ay: "2026-06",
      tarih: "2026-06-06",
      bildirim_turu: "DIGER",
      bildirim_alt_tur: null,
      bildirim_dakika: null,
      bildirim_aciklama: "Diger bildirim",
      bildirim_created_at: "2026-06-06 12:00:00",
      bildirim_updated_at: "2026-06-06 12:00:00",
      etki_turu: "MANUEL",
      etki_miktari: null,
      etki_birimi: null,
      state: "YOK_SAYILDI",
      conflict_code: "DIGER_MANUEL_INCELEME",
      conflict_detail: { message: "Manuel inceleme gerekiyor." },
      resmi_surec_id: null,
      resmi_surec_turu: null,
      resmi_surec_alt_tur: null,
      ucretli_mi_snapshot: null,
      mevcut_puantaj_id: null,
      source_priority: "BILDIRIM",
      created_by: 1,
      source_snapshot: { aciklama: "Diger bildirim" },
      source_hash: "hash-4",
      projection_version: "v1",
      created_at: "2026-06-10 10:15:00",
      updated_at: "2026-06-12 09:00:00",
      karar_veren_user_id: 5,
      karar_zamani: "2026-06-12 09:00:00",
      karar_gerekcesi: "Mevcut puantaj kaydıyla çakıştığı için yok sayıldı.",
      uygulanan_puantaj_id: null,
      onceki_puantaj_snapshot: null,
      sonraki_puantaj_snapshot: null,
      uygulama_hash: null,
      uygulama_modu: "OTOMATIK",
      manuel_karar_turu: null,
      manuel_karar_miktari: null
    },
    {
      id: 6,
      genel_yonetici_bildirim_onayi_id: 10,
      aylik_bildirim_onayi_id: 2,
      gunluk_bildirim_id: 106,
      personel_id: 1,
      sube_id: 1,
      birim_amiri_user_id: 1,
      ay: "2026-06",
      tarih: "2026-06-07",
      bildirim_turu: "DIGER",
      bildirim_alt_tur: null,
      bildirim_dakika: null,
      bildirim_aciklama: "Gorevde calisma aciklamasi",
      bildirim_created_at: "2026-06-07 10:00:00",
      bildirim_updated_at: "2026-06-07 10:00:00",
      etki_turu: "MANUEL",
      etki_miktari: null,
      etki_birimi: null,
      state: "INCELEME_GEREKLI",
      conflict_code: "DIGER_MANUEL_INCELEME",
      conflict_detail: { message: "Manuel inceleme gerekiyor." },
      resmi_surec_id: null,
      resmi_surec_turu: null,
      resmi_surec_alt_tur: null,
      ucretli_mi_snapshot: null,
      mevcut_puantaj_id: null,
      source_priority: "BILDIRIM",
      created_by: 1,
      source_snapshot: { aciklama: "Gorevde calisma aciklamasi", ad_soyad: "Ali Demir" },
      source_hash: "hash-6",
      projection_version: "v1",
      created_at: "2026-06-10 10:20:00",
      updated_at: "2026-06-10 10:20:00",
      karar_veren_user_id: null,
      karar_zamani: null,
      karar_gerekcesi: null,
      uygulanan_puantaj_id: null,
      onceki_puantaj_snapshot: null,
      sonraki_puantaj_snapshot: null,
      uygulama_hash: null,
      uygulama_modu: "OTOMATIK",
      manuel_karar_turu: null,
      manuel_karar_miktari: null
    },
    {
      id: 7,
      genel_yonetici_bildirim_onayi_id: 10,
      aylik_bildirim_onayi_id: 2,
      gunluk_bildirim_id: 107,
      personel_id: 1,
      sube_id: 1,
      birim_amiri_user_id: 1,
      ay: "2026-06",
      tarih: "2026-06-08",
      bildirim_turu: "GELMEDI",
      bildirim_alt_tur: null,
      bildirim_dakika: null,
      bildirim_aciklama: "Resmi surec cakismasi",
      bildirim_created_at: "2026-06-08 09:00:00",
      bildirim_updated_at: "2026-06-08 09:00:00",
      etki_turu: "DEVAMSIZLIK_GUN",
      etki_miktari: null,
      etki_birimi: null,
      state: "INCELEME_GEREKLI",
      conflict_code: "MEVCUT_PUANTAJ_VAR",
      conflict_detail: { message: "Resmi surec dayanakli puantaj cakismasi." },
      resmi_surec_id: null,
      resmi_surec_turu: null,
      resmi_surec_alt_tur: null,
      ucretli_mi_snapshot: null,
      mevcut_puantaj_id: 57,
      source_priority: "BILDIRIM",
      created_by: 1,
      source_snapshot: { aciklama: "Resmi surec cakismasi" },
      source_hash: "hash-7",
      projection_version: "v1",
      created_at: "2026-06-10 10:25:00",
      updated_at: "2026-06-10 10:25:00",
      karar_veren_user_id: null,
      karar_zamani: null,
      karar_gerekcesi: null,
      uygulanan_puantaj_id: null,
      onceki_puantaj_snapshot: null,
      sonraki_puantaj_snapshot: null,
      uygulama_hash: null,
      uygulama_modu: "OTOMATIK",
      manuel_karar_turu: null,
      manuel_karar_miktari: null
    },
    {
      id: 8,
      genel_yonetici_bildirim_onayi_id: 10,
      aylik_bildirim_onayi_id: 2,
      gunluk_bildirim_id: 108,
      personel_id: 1,
      sube_id: 1,
      birim_amiri_user_id: 1,
      ay: "2026-06",
      tarih: "2026-06-09",
      bildirim_turu: "GELMEDI",
      bildirim_alt_tur: null,
      bildirim_dakika: null,
      bildirim_aciklama: "Muhurlu puantaj cakismasi",
      bildirim_created_at: "2026-06-09 09:00:00",
      bildirim_updated_at: "2026-06-09 09:00:00",
      etki_turu: "DEVAMSIZLIK_GUN",
      etki_miktari: null,
      etki_birimi: null,
      state: "INCELEME_GEREKLI",
      conflict_code: "MEVCUT_PUANTAJ_VAR",
      conflict_detail: { message: "Muhurlu puantaj kaydi ile cakisma." },
      resmi_surec_id: null,
      resmi_surec_turu: null,
      resmi_surec_alt_tur: null,
      ucretli_mi_snapshot: null,
      mevcut_puantaj_id: 58,
      source_priority: "BILDIRIM",
      created_by: 1,
      source_snapshot: { aciklama: "Muhurlu puantaj cakismasi" },
      source_hash: "hash-8",
      projection_version: "v1",
      created_at: "2026-06-10 10:30:00",
      updated_at: "2026-06-10 10:30:00",
      karar_veren_user_id: null,
      karar_zamani: null,
      karar_gerekcesi: null,
      uygulanan_puantaj_id: null,
      onceki_puantaj_snapshot: null,
      sonraki_puantaj_snapshot: null,
      uygulama_hash: null,
      uygulama_modu: "OTOMATIK",
      manuel_karar_turu: null,
      manuel_karar_miktari: null
    }
  ];

  type MockConflictPuantajRow = {
    id: number;
    personel_id: number;
    tarih: string;
    state: string;
    gun_tipi: string | null;
    hareket_durumu: string | null;
    dayanak: string | null;
    durumu_bildirdi_mi: number;
    durum_bildirim_aciklamasi: string | null;
    hesap_etkisi: string | null;
    beklenen_giris_saati: string | null;
    beklenen_cikis_saati: string | null;
    giris_saati: string | null;
    cikis_saati: string | null;
    gec_kalma_dakika: number | null;
    erken_cikis_dakika: number | null;
    gercek_mola_dakika: number | null;
    hesaplanan_mola_dakika: number | null;
    net_calisma_suresi_dakika: number | null;
    gunluk_brut_sure_dakika: number | null;
    hafta_tatili_hak_kazandi_mi: number | null;
    kontrol_durumu: string;
    kaynak: string | null;
    aciklama: string | null;
    muhur_id: number | null;
    updated_at: string;
  };

  const mockConflictPuantajByKey = new Map<string, MockConflictPuantajRow>([
    [
      "1:2026-06-04",
      {
        id: 55,
        personel_id: 1,
        tarih: "2026-06-04",
        state: "ACIK",
        gun_tipi: null,
        hareket_durumu: "Geldi",
        dayanak: "Yok_Izinsiz",
        durumu_bildirdi_mi: 0,
        durum_bildirim_aciklamasi: null,
        hesap_etkisi: "Tam_Yevmiye_Ver",
        beklenen_giris_saati: "08:00",
        beklenen_cikis_saati: "17:00",
        giris_saati: "08:30",
        cikis_saati: "17:30",
        gec_kalma_dakika: null,
        erken_cikis_dakika: null,
        gercek_mola_dakika: 60,
        hesaplanan_mola_dakika: 60,
        net_calisma_suresi_dakika: 480,
        gunluk_brut_sure_dakika: 540,
        hafta_tatili_hak_kazandi_mi: 0,
        kontrol_durumu: "BEKLIYOR",
        kaynak: "MANUEL",
        aciklama: null,
        muhur_id: null,
        updated_at: "2026-06-10 08:00:00"
      }
    ],
    [
      "2:2026-06-04",
      {
        id: 56,
        personel_id: 2,
        tarih: "2026-06-04",
        state: "ACIK",
        gun_tipi: null,
        hareket_durumu: "Geldi",
        dayanak: "Yok_Izinsiz",
        durumu_bildirdi_mi: 0,
        durum_bildirim_aciklamasi: null,
        hesap_etkisi: "Tam_Yevmiye_Ver",
        beklenen_giris_saati: "08:00",
        beklenen_cikis_saati: "17:00",
        giris_saati: "08:30",
        cikis_saati: "17:30",
        gec_kalma_dakika: null,
        erken_cikis_dakika: null,
        gercek_mola_dakika: 60,
        hesaplanan_mola_dakika: 60,
        net_calisma_suresi_dakika: 480,
        gunluk_brut_sure_dakika: 540,
        hafta_tatili_hak_kazandi_mi: 0,
        kontrol_durumu: "BEKLIYOR",
        kaynak: "BILDIRIM_ETKI_ADAYI",
        aciklama: null,
        muhur_id: null,
        updated_at: "2026-06-10 08:00:00"
      }
    ],
    [
      "1:2026-06-08",
      {
        id: 57,
        personel_id: 1,
        tarih: "2026-06-08",
        state: "ACIK",
        gun_tipi: null,
        hareket_durumu: "Gelmedi",
        dayanak: "Yillik_Izin",
        durumu_bildirdi_mi: 0,
        durum_bildirim_aciklamasi: null,
        hesap_etkisi: "Yillik_Izin",
        beklenen_giris_saati: null,
        beklenen_cikis_saati: null,
        giris_saati: null,
        cikis_saati: null,
        gec_kalma_dakika: null,
        erken_cikis_dakika: null,
        gercek_mola_dakika: null,
        hesaplanan_mola_dakika: null,
        net_calisma_suresi_dakika: null,
        gunluk_brut_sure_dakika: null,
        hafta_tatili_hak_kazandi_mi: null,
        kontrol_durumu: "BEKLIYOR",
        kaynak: "MANUEL",
        aciklama: null,
        muhur_id: null,
        updated_at: "2026-06-10 08:00:00"
      }
    ],
    [
      "1:2026-06-09",
      {
        id: 58,
        personel_id: 1,
        tarih: "2026-06-09",
        state: "MUHURLENDI",
        gun_tipi: null,
        hareket_durumu: "Geldi",
        dayanak: "Yok_Izinsiz",
        durumu_bildirdi_mi: 0,
        durum_bildirim_aciklamasi: null,
        hesap_etkisi: "Tam_Yevmiye_Ver",
        beklenen_giris_saati: "08:00",
        beklenen_cikis_saati: "17:00",
        giris_saati: "08:30",
        cikis_saati: "17:30",
        gec_kalma_dakika: null,
        erken_cikis_dakika: null,
        gercek_mola_dakika: 60,
        hesaplanan_mola_dakika: 60,
        net_calisma_suresi_dakika: 480,
        gunluk_brut_sure_dakika: 540,
        hafta_tatili_hak_kazandi_mi: 0,
        kontrol_durumu: "BEKLIYOR",
        kaynak: "MANUEL",
        aciklama: null,
        muhur_id: 1,
        updated_at: "2026-06-10 08:00:00"
      }
    ]
  ]);

  const mockConflictResolutions = new Map<
    number,
    {
      id: number;
      aday_id: number;
      puantaj_id: number | null;
      conflict_class: string;
      karar_turu: string;
      gerekce: string;
      request_hash: string;
      sonuc_hash: string;
      karar_veren_user_id: number;
      karar_zamani: string;
    }
  >();

  function sortKeysRecursive(value: unknown): unknown {
    if (Array.isArray(value)) {
      return value.map((entry) => sortKeysRecursive(entry));
    }
    if (value && typeof value === "object") {
      const sorted: Record<string, unknown> = {};
      for (const key of Object.keys(value as Record<string, unknown>).sort()) {
        sorted[key] = sortKeysRecursive((value as Record<string, unknown>)[key]);
      }
      return sorted;
    }
    return value;
  }

  function canonicalJson(data: unknown): string {
    return JSON.stringify(sortKeysRecursive(data));
  }

  function mockPuantajConcurrencyPayload(row: MockConflictPuantajRow) {
    return {
      id: row.id,
      personel_id: row.personel_id,
      tarih: row.tarih,
      state: row.state,
      gun_tipi: row.gun_tipi,
      hareket_durumu: row.hareket_durumu,
      dayanak: row.dayanak,
      durumu_bildirdi_mi: Boolean(row.durumu_bildirdi_mi),
      durum_bildirim_aciklamasi: row.durum_bildirim_aciklamasi,
      hesap_etkisi: row.hesap_etkisi,
      beklenen_giris_saati: row.beklenen_giris_saati,
      beklenen_cikis_saati: row.beklenen_cikis_saati,
      giris_saati: row.giris_saati,
      cikis_saati: row.cikis_saati,
      gec_kalma_dakika: row.gec_kalma_dakika,
      erken_cikis_dakika: row.erken_cikis_dakika,
      gercek_mola_dakika: row.gercek_mola_dakika,
      hesaplanan_mola_dakika: row.hesaplanan_mola_dakika,
      net_calisma_suresi_dakika: row.net_calisma_suresi_dakika,
      gunluk_brut_sure_dakika: row.gunluk_brut_sure_dakika,
      hafta_tatili_hak_kazandi_mi: row.hafta_tatili_hak_kazandi_mi === null ? null : Boolean(row.hafta_tatili_hak_kazandi_mi),
      kontrol_durumu: row.kontrol_durumu || "BEKLIYOR",
      kaynak: row.kaynak,
      aciklama: row.aciklama,
      muhur_id: row.muhur_id,
      updated_at: row.updated_at
    };
  }

  function computeMockPuantajHash(row: MockConflictPuantajRow): string {
    return createHash("sha256").update(canonicalJson(mockPuantajConcurrencyPayload(row))).digest("hex");
  }

  function getMockConflictPuantaj(item: MockPuantajEtkiAday): MockConflictPuantajRow | null {
    return mockConflictPuantajByKey.get(`${item.personel_id}:${item.tarih}`) ?? null;
  }

  function classifyMockConflict(item: MockPuantajEtkiAday, puantaj: MockConflictPuantajRow) {
    if (puantaj.state === "MUHURLENDI" || (puantaj.muhur_id ?? 0) > 0) {
      return {
        class: "MUHURLU_PUANTAJ",
        default_karar: "MEVCUT_PUANTAJI_KORU",
        revise_allowed: false,
        risk: "KRITIK"
      };
    }
    if (item.state === "UYGULANDI" && item.uygulanan_puantaj_id === puantaj.id) {
      return {
        class: "AYNI_ADAY_PUANTAJI",
        default_karar: "ADAY_ETKISIYLE_REVIZE_ET",
        revise_allowed: true,
        risk: "DUSUK"
      };
    }
    const kaynak = (puantaj.kaynak ?? "").toUpperCase();
    if (kaynak === "BILDIRIM_ETKI_ADAYI" || kaynak === "BILDIRIM_ETKI_REVIZYON") {
      return {
        class: "BASKA_ADAY_KAYNAGI",
        default_karar: "MEVCUT_PUANTAJI_KORU",
        revise_allowed: true,
        risk: "ORTA"
      };
    }
    if (["Yillik_Izin", "Ucretli_Izinli", "Raporlu_Hastalik", "Raporlu_Is_Kazasi"].includes(puantaj.dayanak ?? "")) {
      return {
        class: "RESMI_SUREC_DAYANAK",
        default_karar: "MEVCUT_PUANTAJI_KORU",
        revise_allowed: false,
        risk: "YUKSEK"
      };
    }
    if (puantaj.kontrol_durumu === "AMIR_KONTROL_ETTI") {
      return {
        class: "AMIR_KONTROL_EDILMIS",
        default_karar: "MEVCUT_PUANTAJI_KORU",
        revise_allowed: true,
        risk: "ORTA"
      };
    }
    if (kaynak === "MANUEL") {
      return {
        class: "MANUEL_KAYNAK",
        default_karar: "MEVCUT_PUANTAJI_KORU",
        revise_allowed: true,
        risk: "YUKSEK"
      };
    }
    if (!kaynak) {
      return {
        class: "LEGACY_BELIRSIZ",
        default_karar: "MEVCUT_PUANTAJI_KORU",
        revise_allowed: true,
        risk: "YUKSEK"
      };
    }
    return {
      class: "MANUEL_KAYNAK",
      default_karar: "MEVCUT_PUANTAJI_KORU",
      revise_allowed: true,
      risk: "YUKSEK"
    };
  }

  function buildMockRevizePreview(item: MockPuantajEtkiAday, puantaj: MockConflictPuantajRow) {
    if (item.etki_turu !== "DEVAMSIZLIK_GUN") {
      return null;
    }
    return {
      hareket_durumu: "Gelmedi",
      dayanak: "Yok_Izinsiz",
      hesap_etkisi: "Yevmiye_Kes",
      gec_kalma_dakika: null,
      erken_cikis_dakika: null,
      kontrol_durumu: "BEKLIYOR",
      kaynak: "BILDIRIM_ETKI_REVIZYON"
    };
  }

  function computeMockConflictRequestHash(input: {
    aday_id: number;
    expected_state: string;
    karar_turu: string;
    gerekce: string;
    expected_puantaj_id: number;
    expected_puantaj_hash: string;
  }) {
    return createHash("sha256")
      .update(
        canonicalJson({
          schema_version: "S75_CONFLICT_RESOLUTION_V1",
          aday_id: input.aday_id,
          expected_state: input.expected_state,
          karar_turu: input.karar_turu,
          gerekce: input.gerekce,
          expected_puantaj_id: input.expected_puantaj_id,
          expected_puantaj_hash: input.expected_puantaj_hash.toLowerCase()
        })
      )
      .digest("hex");
  }

  function mapMockPuantajEtkiListRow(item: MockPuantajEtkiAday) {
    return {
      id: item.id,
      genel_yonetici_bildirim_onayi_id: item.genel_yonetici_bildirim_onayi_id,
      gunluk_bildirim_id: item.gunluk_bildirim_id,
      personel_id: item.personel_id,
      sube_id: item.sube_id,
      birim_amiri_user_id: item.birim_amiri_user_id,
      ay: item.ay,
      tarih: item.tarih,
      bildirim_turu: item.bildirim_turu,
      etki_turu: item.etki_turu,
      etki_miktari: item.etki_miktari,
      etki_birimi: item.etki_birimi,
      state: item.state,
      conflict_code: item.conflict_code,
      source_priority: item.source_priority,
      created_at: item.created_at,
      karar_veren_user_id: item.karar_veren_user_id,
      karar_zamani: item.karar_zamani,
      uygulanan_puantaj_id: item.uygulanan_puantaj_id,
      uygulama_modu: item.uygulama_modu,
      manuel_karar_turu: item.manuel_karar_turu,
      manuel_karar_miktari: item.manuel_karar_miktari
    };
  }

  function mapMockPuantajEtkiDetailRow(item: MockPuantajEtkiAday) {
    const base = {
      ...mapMockPuantajEtkiListRow(item),
      aylik_bildirim_onayi_id: item.aylik_bildirim_onayi_id,
      bildirim_alt_tur: item.bildirim_alt_tur,
      bildirim_dakika: item.bildirim_dakika,
      bildirim_aciklama: item.bildirim_aciklama,
      bildirim_created_at: item.bildirim_created_at,
      bildirim_updated_at: item.bildirim_updated_at,
      conflict_detail: item.conflict_detail,
      resmi_surec_id: item.resmi_surec_id,
      resmi_surec_turu: item.resmi_surec_turu,
      resmi_surec_alt_tur: item.resmi_surec_alt_tur,
      ucretli_mi_snapshot: item.ucretli_mi_snapshot,
      mevcut_puantaj_id: item.mevcut_puantaj_id,
      source_snapshot: item.source_snapshot,
      source_hash: item.source_hash,
      projection_version: item.projection_version,
      updated_at: item.updated_at,
      karar_gerekcesi: item.karar_gerekcesi,
      onceki_puantaj_snapshot: item.onceki_puantaj_snapshot,
      sonraki_puantaj_snapshot: item.sonraki_puantaj_snapshot,
      uygulama_hash: item.uygulama_hash
    };
    const puantaj = getMockConflictPuantaj(item);
    if (!puantaj) {
      return {
        ...base,
        mevcut_puantaj: null,
        current_puantaj_hash: null,
        conflict_class: null,
        conflict_default_karar: null,
        conflict_revise_allowed: false,
        conflict_risk: null,
        revize_onizleme: null,
        cakisma_cozum: mockConflictResolutions.get(item.id) ?? null
      };
    }
    const classification = classifyMockConflict(item, puantaj);
    return {
      ...base,
      mevcut_puantaj: mockPuantajConcurrencyPayload(puantaj),
      current_puantaj_hash: computeMockPuantajHash(puantaj),
      conflict_class: classification.class,
      conflict_default_karar: classification.default_karar,
      conflict_revise_allowed: classification.revise_allowed,
      conflict_risk: classification.risk,
      revize_onizleme: buildMockRevizePreview(item, puantaj),
      cakisma_cozum: mockConflictResolutions.get(item.id) ?? null
    };
  }

  function countMockPuantajEtkiAdaylari(items: MockPuantajEtkiAday[]) {
    const counts = {
      toplam: items.length,
      hazir: 0,
      inceleme_gerekli: 0,
      uygulandi: 0,
      yok_sayildi: 0
    };
    for (const item of items) {
      if (item.state === "HAZIR") counts.hazir += 1;
      if (item.state === "INCELEME_GEREKLI") counts.inceleme_gerekli += 1;
      if (item.state === "UYGULANDI") counts.uygulandi += 1;
      if (item.state === "YOK_SAYILDI") counts.yok_sayildi += 1;
    }
    return counts;
  }

  const departmanOptions: Array<{ id: number; ad: string }> = [
    { id: 1, ad: "Muhasebe" },
    { id: 2, ad: "Finans" },
    { id: 3, ad: "Döşeme" },
    { id: 4, ad: "Panel" },
    { id: 5, ad: "Hammadde Depo" },
    { id: 6, ad: "Depo" },
    { id: 7, ad: "E-ticaret Depo" },
    { id: 8, ad: "Yönetim Personeli" },
    { id: 9, ad: "Dış Ticaret" },
    { id: 10, ad: "İdari İşler" },
    { id: 11, ad: "Pazarlama" },
    { id: 12, ad: "Ar-Ge" }
  ];

  const ucretTipiReferans: Array<{ id: number; ad: string }> = [
    { id: 1, ad: "Aylık" },
    { id: 2, ad: "Saatlik" }
  ];

  const primKuraliReferans: Array<{ id: number; ad: string }> = [
    { id: 7, ad: "7 No'lu Prim Kuralı" },
    { id: 8, ad: "8 No'lu Prim Kuralı" }
  ];

  const subeler: Array<{
    id: number;
    kod: string;
    ad: string;
    departman_ids: number[];
    departman_adlari: string[];
    durum: "AKTIF" | "PASIF";
  }> = [
    {
      id: 1,
      kod: "MRK",
      ad: "Merkez",
      departman_ids: [1, 3],
      departman_adlari: ["Muhasebe", "Döşeme"],
      durum: "AKTIF"
    },
    {
      id: 2,
      kod: "DPL",
      ad: "Depolama",
      departman_ids: [1],
      departman_adlari: ["Depo"],
      durum: "AKTIF"
    },
    {
      id: 99,
      kod: "PSF",
      ad: "Pasif Şube",
      departman_ids: [1],
      departman_adlari: ["Muhasebe"],
      durum: "PASIF"
    }
  ];

  const makineler: Array<{
    id: number;
    ad: string;
    tip: string;
    konum?: string | null;
    durum: "aktif" | "arizali" | "pasif";
    sube_id: number;
    son_bakim?: string | null;
    bakim_periyot_gun?: number | null;
  }> = [
    {
      id: 1101,
      ad: "Kesim Robotu",
      tip: "Kesim",
      konum: "Atolye A",
      durum: "aktif",
      sube_id: 1,
      son_bakim: "2026-04-01",
      bakim_periyot_gun: 30
    },
    {
      id: 1102,
      ad: "Forklift 02",
      tip: "Tasima",
      konum: "Depo Giris",
      durum: "aktif",
      sube_id: 2,
      son_bakim: "2026-02-10",
      bakim_periyot_gun: 30
    },
    {
      id: 1103,
      ad: "Pres Hatti",
      tip: "Pres",
      konum: "Atolye B",
      durum: "aktif",
      sube_id: 1,
      son_bakim: null,
      bakim_periyot_gun: 45
    }
  ];

  const bakimKayitlari: Array<{
    id: number;
    makine_id: number;
    bakim_tarihi?: string | null;
    yapan?: string | null;
    notlar?: string | null;
  }> = [
    {
      id: 2101,
      makine_id: 1101,
      bakim_tarihi: "2026-03-01",
      yapan: "Bakim Ekibi A",
      notlar: "Aylık kontrol"
    },
    {
      id: 2102,
      makine_id: 1101,
      bakim_tarihi: "2026-04-01",
      yapan: "Bakim Ekibi B",
      notlar: "Rutin mekanik bakim"
    },
    {
      id: 2104,
      makine_id: 1102,
      bakim_tarihi: "2026-02-10",
      yapan: "Servis",
      notlar: "Fren ayari"
    },
    {
      id: 2103,
      makine_id: 1102,
      bakim_tarihi: "2026-01-05",
      yapan: "Servis",
      notlar: "Yag degisimi"
    },
    {
      id: 2105,
      makine_id: 1103,
      bakim_tarihi: "gecersiz-tarih",
      yapan: "Kayit Hatasi",
      notlar: "Tarih dogrulanamadi"
    }
  ];

  const yonetimKullanicilari: Array<{
    id: number;
    username: string;
    ad_soyad: string;
    telefon?: string;
    kullanici_tipi: "IC_PERSONEL" | "HARICI";
    rol: MockUserRole;
    personel_id: number | null;
    sube_ids: number[];
    varsayilan_sube_id: number | null;
    durum: "AKTIF" | "PASIF";
    notlar?: string;
  }> = [
    {
      id: 1,
      username: "genel_yonetici",
      ad_soyad: "İlker Akel",
      telefon: "05550000001",
      kullanici_tipi: "HARICI",
      rol: "GENEL_YONETICI",
      personel_id: null,
      sube_ids: [],
      varsayilan_sube_id: null,
      durum: "AKTIF",
      notlar: "Tüm yapıyı yönetir"
    },
    {
      id: 2,
      username: "bolum_yoneticisi",
      ad_soyad: "Adnan Bulut",
      telefon: "05550000002",
      kullanici_tipi: "HARICI",
      rol: "BOLUM_YONETICISI",
      personel_id: null,
      sube_ids: [2],
      varsayilan_sube_id: 2,
      durum: "AKTIF",
      notlar: "Depolama kapsamında bölüm onayı verir"
    },
    {
      id: 3,
      username: "birim_amiri",
      ad_soyad: "Serhan Köse",
      telefon: "05550000003",
      kullanici_tipi: "IC_PERSONEL",
      rol: "BIRIM_AMIRI",
      personel_id: 1,
      sube_ids: [1],
      varsayilan_sube_id: 1,
      durum: "AKTIF",
      notlar: "Günlük kayıtları girer"
    }
  ];

  /** Playwright süreci ile tarayıcı aynı makinede; UI ilk yüklemede `new Date()` ayını kullanır. */
  const aylikOzetPageState = getAylikOzetPageState(page);
  const aylikOzetFixtureAy = aylikOzetPageState.fixtureAy;
  const aylikOzetRows = aylikOzetPageState.rows;

  let surecIdCounter = 600;
let zimmetIdCounter = 560;
let personelBelgeKaydiIdCounter = 903;
  let finansIdCounter = 950;
  let kullaniciIdCounter = 3;
  let subeIdCounter = 2;
  let departmanIdCounter = 12;
  let personelIdCounter = 5;
  let personelUcretIdCounter = 1;
  let mevzuatParametreIdCounter = 1;
  type MockPersonelUcret = {
    id: number;
    personel_id: number;
    ucret_tutari: number;
    ucret_turu: "BRUT" | "NET";
    para_birimi: string;
    gecerlilik_baslangic: string;
    gecerlilik_bitis: string | null;
    state: "AKTIF" | "IPTAL";
    kaynak: "MANUEL" | "PERSONEL_KAYDI_MIGRASYON" | "SISTEM";
    aciklama?: string | null;
  };
  type MockMevzuatParametresi = {
    id: number;
    parametre_kodu: string;
    deger_tipi: "SAYISAL" | "METIN";
    sayisal_deger: number | null;
    metin_deger: string | null;
    gecerlilik_baslangic: string;
    gecerlilik_bitis: string | null;
    birim?: string | null;
    aciklama?: string | null;
    state: "AKTIF" | "IPTAL";
  };
  const personelUcretleri: MockPersonelUcret[] = [];
  const mevzuatParametreleri: MockMevzuatParametresi[] = [];

  function rangesOverlapInclusive(
    startA: string,
    endA: string | null,
    startB: string,
    endB: string | null
  ) {
    const aEnd = endA ?? "9999-12-31";
    const bEnd = endB ?? "9999-12-31";
    return startA <= bEnd && startB <= aEnd;
  }

  function encodePersonelBelgeKaydiSurecMetadata(payload: {
    kayit_tipi: string;
    ad: string;
    veren_kurum?: string | null;
    belge_no?: string | null;
    baslangic_tarihi?: string | null;
    bitis_tarihi?: string | null;
    ek_ref?: string | null;
    aciklama?: string | null;
  }) {
    return JSON.stringify({
      _personel_belge_kaydi: true,
      ...payload
    });
  }

  function serializePersonelBelgeKaydi(record: (typeof personelBelgeKayitlari)[number]) {
    const bitisTarihi = record.bitis_tarihi ?? null;
    return {
      id: record.id,
      personel_id: record.personel_id,
      kayit_tipi: record.kayit_tipi,
      ad: record.ad,
      veren_kurum: record.veren_kurum ?? null,
      belge_no: record.belge_no ?? null,
      baslangic_tarihi: record.baslangic_tarihi ?? null,
      bitis_tarihi: bitisTarihi,
      durum: record.durum,
      gecerlilik_durumu: computeGecerlilikDurumu(bitisTarihi, options.belgeReferenceDate),
      ek_ref: record.ek_ref ?? null,
      aciklama: record.aciklama ?? null,
      created_at: record.created_at ?? null,
      updated_at: record.updated_at ?? null
    };
  }

  function assertBelgePersonelScope(request: { headers(): { [key: string]: string } }, url: URL, personelId: number) {
    const personel = personeller.find((item) => item.id === personelId);
    if (!personel) {
      return { ok: false as const, personel: undefined };
    }

    const subeScope = getRequestSubeScope(request, url);
    if (personel.sube_id !== undefined && subeScope !== null && personel.sube_id !== subeScope) {
      return { ok: false as const, personel, status: 403, body: errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE) };
    }

    if (personel.sube_id !== undefined && mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(personel.sube_id)) {
      return {
        ok: false as const,
        personel,
        status: 403,
        body: errorBody("FORBIDDEN", PERSONEL_CREATE_SUBE_UNAUTHORIZED_MESSAGE)
      };
    }

    return { ok: true as const, personel };
  }

  function getDepartmanLabel(id: number) {
    return departmanOptions.find((item) => item.id === id)?.ad ?? `Departman ${id}`;
  }

  function getSubeLabel(id: number | undefined) {
    return subeler.find((item) => item.id === id)?.ad;
  }

  function getGorevLabel(id: number | undefined) {
    return gorevAdlari.find((item) => item.id === id)?.ad;
  }

  function normalizeSubePayload(payload: { kod: string; ad: string; departman_ids?: number[]; durum: "AKTIF" | "PASIF" }) {
    const departmanIds = payload.departman_ids ?? [];
    return {
      kod: payload.kod,
      ad: payload.ad,
      departman_ids: departmanIds,
      departman_adlari: departmanIds.map((id) => getDepartmanLabel(id)),
      durum: payload.durum
    };
  }

  function getPuantajRowsForPersonel(personelId: number) {
    return puantajKayitlari.filter((kayit) => kayit.personel_id === personelId);
  }

  function buildPersonelDetail(personel: (typeof personeller)[number]) {
    const sgkOzeti = hesaplaAylikSgkPuantajOzetleri(getPuantajRowsForPersonel(personel.id))[0] ?? null;

    return {
      ana_kart: {
        id: personel.id,
        tc_kimlik_no: personel.tc_kimlik_no,
        ad: personel.ad,
        soyad: personel.soyad,
        aktif_durum: personel.aktif_durum,
        sube_id: personel.sube_id,
        telefon: personel.telefon,
        dogum_tarihi: personel.dogum_tarihi,
        dogum_yeri: personel.dogum_yeri,
        kan_grubu: personel.kan_grubu,
        sicil_no: personel.sicil_no,
        ise_giris_tarihi: personel.ise_giris_tarihi,
        acil_durum_kisi: personel.acil_durum_kisi,
        acil_durum_telefon: personel.acil_durum_telefon,
        departman_id: personel.departman_id,
        gorev_id: personel.gorev_id,
        personel_tipi_id: personel.personel_tipi_id,
        bagli_amir_id: personel.bagli_amir_id,
        ucret_tipi_id: personel.ucret_tipi_id,
        ucret_tipi_adi: personel.ucret_tipi_adi,
        maas_tutari: personel.maas_tutari,
        net_maas_tutari: personel.net_maas_tutari ?? personel.maas_tutari,
        prim_kurali_id: personel.prim_kurali_id,
        prim_kurali_adi: personel.prim_kurali_adi
      },
      sistem_ozeti: {
        hizmet_suresi: personel.id === 1 ? "3 yil 2 ay" : "1 yil 8 ay",
        toplam_izin_hakki: personel.id === 1 ? 14 : 10,
        kullanilan_izin: personel.id === 1 ? 4 : 2,
        kalan_izin: personel.id === 1 ? 10 : 8,
        sgk_donem: sgkOzeti?.donem,
        sgk_prim_gun: sgkOzeti?.sgk_prim_gun,
        sgk_eksik_gun_sayisi: sgkOzeti?.eksik_gun_sayisi,
        sgk_eksik_gun_nedeni_kodu: sgkOzeti?.eksik_gun_nedeni_kodu ?? null,
        sgk_ayin_takvim_gun_sayisi: sgkOzeti?.ayin_takvim_gun_sayisi,
        sgk_hesaplama_modu: sgkOzeti?.hesaplama_modu
      },
      pasiflik_durumu: {
        aktif_durum: personel.aktif_durum,
        etiket: personel.aktif_durum === "PASIF" ? "İşten Ayrıldı" : null
      },
      referans_adlari: {
        sube: personel.sube_adi,
        departman: personel.departman_adi,
        gorev: personel.gorev_adi,
        personel_tipi: personel.personel_tipi_adi,
        bagli_amir: personel.bagli_amir_adi
      }
    };
  }

  const gorevAdlari: Array<{ id: number; ad: string }> = [
    { id: 1, ad: "Genel Müdür" },
    { id: 2, ad: "Üretim Müdürü" },
    { id: 3, ad: "Finans Müdürü" },
    { id: 4, ad: "Fabrika Müdürü" },
    { id: 5, ad: "İdari İşler Müdürü" },
    { id: 6, ad: "Pazarlama Müdürü" },
    { id: 7, ad: "Dış Ticaret Müdürü" },
    { id: 8, ad: "Satış Destek Personeli" },
    { id: 9, ad: "Dış Ticaret Personeli" },
    { id: 10, ad: "Satış Personeli" },
    { id: 11, ad: "Temizlik Personeli" },
    { id: 12, ad: "Güvenlik Personeli" },
    { id: 13, ad: "Satış Sonrası (SSH)" },
    { id: 14, ad: "Şoför" },
    { id: 15, ad: "Aşçı" }
  ];

  function normalizeLifecycleSnapshot(p: (typeof personeller)[number]) {
    const n = (v: number | undefined | null) =>
      v === undefined || v === null || !Number.isFinite(v) ? null : v;
    const nm = (v: number | undefined | null) =>
      v === undefined || v === null || !Number.isFinite(v) ? null : v;
    return {
      departman_id: n(p.departman_id),
      gorev_id: n(p.gorev_id),
      bagli_amir_id: n(p.bagli_amir_id),
      personel_tipi_id: n(p.personel_tipi_id),
      ucret_tipi_id: n(p.ucret_tipi_id),
      maas_tutari: nm(p.net_maas_tutari ?? p.maas_tutari),
      prim_kurali_id: n(p.prim_kurali_id)
    };
  }

  function lifecycleSnapshotsEqual(
    a: ReturnType<typeof normalizeLifecycleSnapshot>,
    b: ReturnType<typeof normalizeLifecycleSnapshot>
  ) {
    return (
      a.departman_id === b.departman_id &&
      a.gorev_id === b.gorev_id &&
      a.bagli_amir_id === b.bagli_amir_id &&
      a.personel_tipi_id === b.personel_tipi_id &&
      a.ucret_tipi_id === b.ucret_tipi_id &&
      a.maas_tutari === b.maas_tutari &&
      a.prim_kurali_id === b.prim_kurali_id
    );
  }

  function mergePersonelFromPutPayload(
    base: (typeof personeller)[number],
    payload: Record<string, unknown>
  ) {
    const next: (typeof personeller)[number] = { ...base };
    if (typeof payload.ad === "string") next.ad = payload.ad.trim();
    if (typeof payload.soyad === "string") next.soyad = payload.soyad.trim();
    if (typeof payload.telefon === "string") next.telefon = payload.telefon.trim();

    const setId = (
      key:
        | "sube_id"
        | "departman_id"
        | "gorev_id"
        | "bagli_amir_id"
        | "personel_tipi_id"
        | "prim_kurali_id"
        | "ucret_tipi_id"
    ) => {
      if (!(key in payload)) return;
      const v = payload[key];
      if (v === null) {
        next[key] = undefined;
        return;
      }
      if (typeof v === "number" && Number.isFinite(v)) {
        next[key] = v;
        return;
      }
      if (typeof v === "string" && v.trim()) {
        const parsed = Number.parseInt(v.trim(), 10);
        if (Number.isFinite(parsed)) next[key] = parsed;
      }
    };
    setId("sube_id");
    setId("departman_id");
    setId("gorev_id");
    setId("bagli_amir_id");
    setId("personel_tipi_id");
    setId("prim_kurali_id");
    setId("ucret_tipi_id");
    if ("net_maas_tutari" in payload || "maas_tutari" in payload) {
      const rawNet = payload.net_maas_tutari;
      const rawMaas = payload.maas_tutari;
      const resolved =
        rawNet === null || rawNet === undefined
          ? rawMaas === null || rawMaas === undefined
            ? undefined
            : typeof rawMaas === "number"
              ? rawMaas
              : Number.parseFloat(String(rawMaas))
          : typeof rawNet === "number"
            ? rawNet
            : Number.parseFloat(String(rawNet));
      if (resolved === null || resolved === undefined || !Number.isFinite(resolved)) {
        next.maas_tutari = undefined;
        next.net_maas_tutari = undefined;
      } else {
        next.maas_tutari = resolved;
        next.net_maas_tutari = resolved;
      }
    }
    return next;
  }

  function syncPersonelReferansAdlari(target: (typeof personeller)[number]) {
    if (target.departman_id !== undefined) {
      target.departman_adi = getDepartmanLabel(target.departman_id);
    } else {
      target.departman_adi = undefined;
    }
    if (target.gorev_id !== undefined) {
      target.gorev_adi = gorevAdlari.find((g) => g.id === target.gorev_id)?.ad ?? target.gorev_adi;
    } else {
      target.gorev_adi = undefined;
    }
    if (target.personel_tipi_id !== undefined) {
      target.personel_tipi_adi =
        target.personel_tipi_id === 1
          ? "Tam Zamanlı"
          : target.personel_tipi_id === 2
            ? "Yarı Zamanlı"
            : target.personel_tipi_adi;
    } else {
      target.personel_tipi_adi = undefined;
    }
    if (target.ucret_tipi_id !== undefined) {
      target.ucret_tipi_adi =
        ucretTipiReferans.find((x) => x.id === target.ucret_tipi_id)?.ad ?? target.ucret_tipi_adi;
    } else {
      target.ucret_tipi_adi = undefined;
    }
    if (target.prim_kurali_id !== undefined) {
      target.prim_kurali_adi =
        primKuraliReferans.find((x) => x.id === target.prim_kurali_id)?.ad ?? target.prim_kurali_adi;
    } else {
      target.prim_kurali_adi = undefined;
    }
    if (target.bagli_amir_id !== undefined) {
      target.bagli_amir_adi =
        bagliAmirReferanslari.find((item) => item.id === target.bagli_amir_id)?.ad ?? target.bagli_amir_adi;
    } else {
      target.bagli_amir_adi = undefined;
    }
  }

  function buildAylikOzetResponse(searchUrl: URL) {
    const ay = urlValue(searchUrl.searchParams.get("ay")) ?? aylikOzetFixtureAy;
    const subeId = numberValue(searchUrl.searchParams.get("sube_id"));
    const departmanId = numberValue(searchUrl.searchParams.get("departman_id"));
    const sadeceRevizeli = searchUrl.searchParams.get("sadece_revizeli") === "true";

    const items = aylikOzetRows.filter((item) => {
      if (item.ay !== ay) {
        return false;
      }
      if (Number.isFinite(subeId) && item.sube_id !== subeId) {
        return false;
      }
      if (!Number.isFinite(subeId) && mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(item.sube_id)) {
        return false;
      }
      if (Number.isFinite(departmanId) && item.departman_id !== departmanId) {
        return false;
      }
      if (sadeceRevizeli && !item.revize_var_mi) {
        return false;
      }
      return true;
    });

    const pendingBolumOnayi = items.filter((item) => item.bolum_onay_durumu === "BOLUM_ONAYINDA").length;

    const state = (() => {
      if (items.length === 0) {
        return "BOLUM_ONAYINDA";
      }
      if (items.every((item) => item.kapanis_durumu === "KAPANDI")) {
        return "KAPANDI";
      }
      if (items.some((item) => item.bolum_onay_durumu === "REVIZE_ISTENDI")) {
        return "REVIZE_ISTENDI";
      }
      if (pendingBolumOnayi === 0) {
        return "BOLUM_ONAYLANDI";
      }
      return "BOLUM_ONAYINDA";
    })();

    return {
      ay,
      state,
      summary: {
        toplam_personel: items.length,
        toplam_devamsizlik_gun: items.reduce((acc, item) => acc + item.devamsizlik_gun, 0),
        toplam_gec_kalma: items.reduce((acc, item) => acc + item.gec_kalma_adet, 0),
        toplam_izinli_gelmedi: items.reduce((acc, item) => acc + item.izinli_gelmedi, 0),
        toplam_izinsiz_gelmedi: items.reduce((acc, item) => acc + item.izinsiz_gelmedi, 0),
        toplam_raporlu: items.reduce((acc, item) => acc + item.raporlu, 0),
        toplam_tesvik_tutari: items.reduce((acc, item) => acc + item.tesvik_tutari, 0),
        toplam_ceza_kesinti_tutari: items.reduce((acc, item) => acc + item.ceza_kesinti_tutari, 0)
      },
      items,
      pending_bolum_onayi: pendingBolumOnayi
    };
  }

  function urlValue(value: string | null) {
    return value && value.trim() ? value : null;
  }

  function numberValue(value: string | null) {
    const parsed = Number.parseInt(value ?? "", 10);
    return Number.isFinite(parsed) ? parsed : null;
  }

  const mockUserSubeIds =
    role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : role === "BOLUM_YONETICISI" ? [2] : [];
  const mockUserId = 1;

  function resolveMockBildirimSubeId(personelId: number): number | undefined {
    const personel = personeller.find((item) => item.id === personelId);
    return typeof personel?.sube_id === "number" ? personel.sube_id : undefined;
  }

  function assertMockBildirimOwnership(bildirim: MockBildirimRecord): boolean {
    return (bildirim.created_by ?? 0) === mockUserId;
  }

  function resolveMockMutabakatWeek(value: unknown): { start: string; end: string } | null {
    if (typeof value !== "string" || !isValidDateString(value)) return null;
    const date = new Date(`${value}T00:00:00Z`);
    if (date.getUTCDay() !== 1) return null;
    date.setUTCDate(date.getUTCDate() + 6);
    return { start: value, end: date.toISOString().slice(0, 10) };
  }

  function mockMutabakatCounts(subeId: number, userId: number, start: string, end: string) {
    const rows = bildirimler.filter(
      (item) => item.sube_id === subeId && item.created_by === userId && item.tarih >= start && item.tarih <= end
    );
    const count = (state: string) => rows.filter((item) => item.state.toUpperCase() === state).length;
    return {
      toplam: rows.length,
      taslak: count("TASLAK"),
      gonderildi: count("GONDERILDI"),
      duzeltme_istendi: count("DUZELTME_ISTENDI"),
      haftalik_mutabakata_alindi: count("HAFTALIK_MUTABAKATA_ALINDI"),
      iptal: count("IPTAL")
    };
  }

  function mockMutabakatBlockReason(counts: ReturnType<typeof mockMutabakatCounts>, existing?: MockHaftalikMutabakat) {
    if (existing) return "Bu hafta icin mutabakat zaten mevcut.";
    if (counts.taslak > 0) return "Haftada taslak bildirim bulunuyor.";
    if (counts.duzeltme_istendi > 0) return "Haftada duzeltme bekleyen bildirim bulunuyor.";
    if (counts.haftalik_mutabakata_alindi > 0) return "Haftadaki bildirimler daha once mutabakata alinmis.";
    if (counts.gonderildi < 1) return "Mutabakata alinacak gonderilmis bildirim bulunamadi.";
    return null;
  }

  function buildMockAylikOnayContext(subeId: number, amirId: number, ay: string) {
    const bounds = resolveAyBounds(ay);
    if (!bounds) {
      return null;
    }
    const { ay_baslangic: ayBaslangic, ay_bitis: ayBitis } = bounds;
    const rows = bildirimler.filter(
      (item) =>
        item.sube_id === subeId &&
        item.created_by === amirId &&
        item.tarih >= ayBaslangic &&
        item.tarih <= ayBitis
    );
    const weeks = listWeeksIntersectingMonth(ayBaslangic, ayBitis);
    const counts = {
      toplam_bildirim: 0,
      mutabakata_alinan: 0,
      mutabakatli_hafta: 0,
      eksik_hafta: 0,
      taslak: 0,
      duzeltme_istendi: 0,
      gonderildi: 0
    };
    const stateMap: Record<string, keyof typeof counts> = {
      TASLAK: "taslak",
      GONDERILDI: "gonderildi",
      DUZELTME_ISTENDI: "duzeltme_istendi",
      HAFTALIK_MUTABAKATA_ALINDI: "mutabakata_alinan"
    };
    rows.forEach((row) => {
      const state = row.state.toUpperCase();
      if (state === "IPTAL") {
        return;
      }
      counts.toplam_bildirim += 1;
      const key = stateMap[state];
      if (key) {
        counts[key] += 1;
      }
    });

    const haftalar = weeks.map((week) => {
      const weekRows = rows.filter(
        (row) =>
          row.tarih >= week.hafta_baslangic &&
          row.tarih <= week.hafta_bitis &&
          row.state.toUpperCase() !== "IPTAL"
      );
      const mutabakat = bildirimPageState.mutabakatlar.find(
        (item) =>
          item.sube_id === subeId &&
          item.birim_amiri_user_id === amirId &&
          item.hafta_baslangic === week.hafta_baslangic
      );
      const bildirimSayisi = weekRows.length;
      const mutabakataAlinan = weekRows.filter(
        (row) => row.state.toUpperCase() === "HAFTALIK_MUTABAKATA_ALINDI"
      ).length;
      const eksikMi = bildirimSayisi > 0 && !mutabakat;
      if (eksikMi) {
        counts.eksik_hafta += 1;
      } else if (mutabakat) {
        counts.mutabakatli_hafta += 1;
      }
      return {
        hafta_baslangic: week.hafta_baslangic,
        hafta_bitis: week.hafta_bitis,
        mutabakat_id: mutabakat?.id ?? null,
        state: mutabakat?.state ?? null,
        bildirim_sayisi: bildirimSayisi,
        mutabakata_alinan_sayisi: mutabakataAlinan,
        eksik_mi: eksikMi,
        blok_nedeni: eksikMi ? "Haftalik mutabakat eksik." : null
      };
    });

    return { ayBaslangic, ayBitis, counts, haftalar };
  }

  function resolveGyBildirimOnayApproval(input: {
    existingGy?: MockGyBildirimOnay;
    aylikOnay?: MockAylikBildirimOnay;
    counts: {
      eksik_hafta: number;
      taslak: number;
      duzeltme_istendi: number;
      gonderildi: number;
      mutabakata_alinan: number;
      toplam_bildirim: number;
    };
  }) {
    if (input.existingGy) {
      return { onay_verilebilir_mi: false, blok_nedeni: "ZATEN_ONAYLANDI" as const };
    }
    if (!input.aylikOnay) {
      return { onay_verilebilir_mi: false, blok_nedeni: "AYLIK_BILDIRIM_ONAYI_GEREKLI" as const };
    }
    if (input.aylikOnay.state !== "TAMAMLANDI") {
      return { onay_verilebilir_mi: false, blok_nedeni: "AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI" as const };
    }
    if (input.counts.eksik_hafta > 0) {
      return { onay_verilebilir_mi: false, blok_nedeni: "EKSIK_HAFTA_VAR" as const };
    }
    if (
      input.counts.taslak > 0 ||
      input.counts.duzeltme_istendi > 0 ||
      input.counts.gonderildi > 0 ||
      input.counts.mutabakata_alinan < 1 ||
      input.counts.toplam_bildirim < 1
    ) {
      return { onay_verilebilir_mi: false, blok_nedeni: "AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI" as const };
    }
    return { onay_verilebilir_mi: true, blok_nedeni: null };
  }

  async function denyUnlessRolePermission(route: Route, permission: AppPermission) {
    if (!hasRolePermission(role, permission)) {
      await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu islem icin yetkiniz yok."));
      return true;
    }
    return false;
  }

  async function denyUnlessAnyRolePermission(route: Route, permissions: AppPermission[]) {
    if (!permissions.some((permission) => hasRolePermission(role, permission))) {
      await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu islem icin yetkiniz yok."));
      return true;
    }
    return false;
  }

  function isAmirKontrolOnlyPayload(payload: Record<string, unknown>): boolean {
    const keys = Object.keys(payload);
    return keys.length === 1 && keys[0] === "kontrol_durumu" && payload.kontrol_durumu === "AMIR_KONTROL_ETTI";
  }

  async function denyUnlessPuantajUpsertPermission(route: Route, payload: Record<string, unknown>) {
    if (isAmirKontrolOnlyPayload(payload)) {
      return denyUnlessAnyRolePermission(route, ["puantaj.amir_kontrol", "puantaj.update"]);
    }

    return denyUnlessRolePermission(route, "puantaj.update");
  }

  await page.route(
    (testUrl) => {
      try {
        return new URL(testUrl).pathname.startsWith("/api/");
      } catch {
        return false;
      }
    },
    async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    const method = request.method();

    if (path === "/api/auth/login" && method === "POST") {
      await fulfillJson(
        route,
        200,
        okBody({
          token: "mock-token",
          ui_profile: role === "BIRIM_AMIRI" ? "birim_amiri" : "yonetim",
          sube_list: subeler
            .filter((item) => item.durum === "AKTIF" && (mockUserSubeIds.length === 0 || mockUserSubeIds.includes(item.id)))
            .map((item) => ({ id: item.id, ad: item.ad })),
          user: {
            id: 1,
            ad_soyad: "Mock Kullanıcı",
            rol: role,
            sube_ids: mockUserSubeIds
          }
        })
      );
      return;
    }

    if (path === "/api/personeller" && method === "GET") {
      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const search = (url.searchParams.get("search") ?? "").toLowerCase();
      const departmanId = Number.parseInt(url.searchParams.get("departman_id") ?? "", 10);
      const personelTipiId = Number.parseInt(url.searchParams.get("personel_tipi_id") ?? "", 10);
      const aktiflik = url.searchParams.get("aktiflik") ?? "tum";
      const subeScope = getRequestSubeScope(request, url);

      if (subeScope !== null && mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeScope)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", PERSONEL_CREATE_SUBE_UNAUTHORIZED_MESSAGE));
        return;
      }

      const personelMatchesListScope = (item: (typeof personeller)[number]) => {
        if (typeof item.sube_id !== "number") {
          return false;
        }

        if (subeScope !== null) {
          return item.sube_id === subeScope;
        }

        if (mockUserSubeIds.length > 0) {
          return mockUserSubeIds.includes(item.sube_id);
        }

        return true;
      };

      const filtered = personeller.filter((item) => {
        if (!personelMatchesListScope(item)) {
          return false;
        }
        if (aktiflik === "aktif" && item.aktif_durum !== "AKTIF") {
          return false;
        }
        if (aktiflik === "pasif" && item.aktif_durum !== "PASIF") {
          return false;
        }
        if (Number.isFinite(departmanId) && item.departman_id !== departmanId) {
          return false;
        }
        if (Number.isFinite(personelTipiId) && item.personel_tipi_id !== personelTipiId) {
          return false;
        }
        if (!search) {
          return true;
        }

        const fullText = `${item.ad} ${item.soyad} ${item.tc_kimlik_no}`.toLowerCase();
        return fullText.includes(search);
      });

      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit);

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path === "/api/personeller" && method === "POST") {
      const payload = request.postDataJSON() as Record<string, unknown>;
      const tcKimlikNo = String(payload.tc_kimlik_no ?? "").trim();
      const subeId =
        typeof payload.sube_id === "number"
          ? payload.sube_id
          : Number.parseInt(String(payload.sube_id ?? ""), 10);
      const subeScope = getRequestSubeScope(request, url);

      const duplicateTc = personeller.some((personel) => String(personel.tc_kimlik_no).trim() === tcKimlikNo);
      if (duplicateTc) {
        await fulfillJson(
          route,
          409,
          errorBody("DUPLICATE_TC_KIMLIK_NO", DUPLICATE_TC_KIMLIK_NO_MESSAGE, "tc_kimlik_no")
        );
        return;
      }

      if (Number.isFinite(subeId) && subeId > 0 && subeScope !== null && subeId !== subeScope) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", PERSONEL_CREATE_SUBE_SCOPE_MISMATCH_MESSAGE));
        return;
      }

      if (!Number.isFinite(subeId) || subeId <= 0) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", "Şube seçilmelidir."));
        return;
      }

      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", PERSONEL_CREATE_SUBE_UNAUTHORIZED_MESSAGE));
        return;
      }

      const parseId = (value: unknown) => {
        if (typeof value === "number" && Number.isFinite(value)) {
          return value;
        }
        if (typeof value === "string" && value.trim()) {
          const parsed = Number.parseInt(value, 10);
          if (Number.isFinite(parsed)) {
            return parsed;
          }
        }
        return undefined;
      };

      const parseMaas = (value: unknown) => {
        if (value === undefined || value === null || value === "") {
          return undefined;
        }
        if (typeof value === "number" && Number.isFinite(value)) {
          return value;
        }
        if (typeof value === "string" && value.trim()) {
          const parsed = Number.parseFloat(value.replace(",", "."));
          return Number.isFinite(parsed) ? parsed : undefined;
        }
        return undefined;
      };

      const departmanId = parseId(payload.departman_id);
      const gorevId = parseId(payload.gorev_id);
      const personelTipiId = parseId(payload.personel_tipi_id);
      const ucretTipiId = parseId(payload.ucret_tipi_id);
      const nextId = ++personelIdCounter;

      const created = {
        id: nextId,
        tc_kimlik_no: tcKimlikNo,
        ad: String(payload.ad ?? "Yeni"),
        soyad: String(payload.soyad ?? "Personel"),
        aktif_durum: (payload.aktif_durum === "PASIF" ? "PASIF" : "AKTIF") as "AKTIF" | "PASIF",
        sube_id: subeId,
        telefon: typeof payload.telefon === "string" ? payload.telefon : undefined,
        dogum_tarihi: typeof payload.dogum_tarihi === "string" ? payload.dogum_tarihi : undefined,
        dogum_yeri: typeof payload.dogum_yeri === "string" ? payload.dogum_yeri : undefined,
        kan_grubu: typeof payload.kan_grubu === "string" ? payload.kan_grubu : undefined,
        sicil_no: typeof payload.sicil_no === "string" ? payload.sicil_no : undefined,
        ise_giris_tarihi: typeof payload.ise_giris_tarihi === "string" ? payload.ise_giris_tarihi : undefined,
        acil_durum_kisi: typeof payload.acil_durum_kisi === "string" ? payload.acil_durum_kisi : undefined,
        acil_durum_telefon:
          typeof payload.acil_durum_telefon === "string" ? payload.acil_durum_telefon : undefined,
        departman_id: departmanId,
        gorev_id: gorevId,
        personel_tipi_id: personelTipiId,
        bagli_amir_id: parseId(payload.bagli_amir_id),
        ucret_tipi_id: ucretTipiId,
        ucret_tipi_adi: ucretTipiId === 1 ? "Aylık" : ucretTipiId === 2 ? "Saatlik" : undefined,
        maas_tutari: parseMaas(payload.net_maas_tutari) ?? parseMaas(payload.maas_tutari),
        net_maas_tutari: parseMaas(payload.net_maas_tutari) ?? parseMaas(payload.maas_tutari),
        sube_adi: getSubeLabel(subeId),
        departman_adi: departmanId ? getDepartmanLabel(departmanId) : undefined,
        gorev_adi: gorevId ? getGorevLabel(gorevId) : undefined,
        personel_tipi_adi:
          personelTipiId === 1 ? "Tam Zamanlı" : personelTipiId === 2 ? "Yarı Zamanlı" : undefined
      };

      personeller.unshift(created);
      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path === "/api/isg/makineler" && method === "GET") {
      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const search = (url.searchParams.get("search") ?? "").toLowerCase();
      const durum = (url.searchParams.get("durum") ?? "tum").toLowerCase();
      const tip = (url.searchParams.get("tip") ?? "").toLowerCase();
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);

      const filtered = makineler.filter((item) => {
        if (Number.isFinite(subeId) && item.sube_id !== subeId) {
          return false;
        }
        if (durum !== "tum" && item.durum !== durum) {
          return false;
        }
        if (tip && !item.tip.toLowerCase().includes(tip)) {
          return false;
        }
        if (!search) {
          return true;
        }

        const fullText = `${item.ad} ${item.tip} ${item.konum ?? ""}`.toLowerCase();
        return fullText.includes(search);
      });

      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit).map((item) => ({
        ...item,
        referans_adlari: {
          sube: subeler.find((sube) => sube.id === item.sube_id)?.ad ?? "-"
        }
      }));

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path.match(/^\/api\/isg\/makineler\/\d+$/) && method === "GET") {
      const makineId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);
      const makine = makineler.find((item) => item.id === makineId);

      if (!makine || (Number.isFinite(subeId) && makine.sube_id !== subeId)) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Makine bulunamadi."));
        return;
      }

      await fulfillJson(
        route,
        200,
        okBody({
          ...makine,
          referans_adlari: {
            sube: subeler.find((sube) => sube.id === makine.sube_id)?.ad ?? "-"
          }
        })
      );
      return;
    }

    if (path.match(/^\/api\/isg\/makineler\/\d+\/bakimlar$/) && method === "GET") {
      const makineId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);
      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const makine = makineler.find((item) => item.id === makineId);

      if (!makine || (Number.isFinite(subeId) && makine.sube_id !== subeId)) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bakim gecmisi bulunamadi."));
        return;
      }

      const filtered = bakimKayitlari.filter((item) => item.makine_id === makineId);
      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit);

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path.match(/^\/api\/personeller\/\d+$/) && method === "PUT") {
      const personelId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const personel = personeller.find((item) => item.id === personelId);
      if (!personel) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }

      if (role === "BIRIM_AMIRI") {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }

      const subeScope = getRequestSubeScope(request, url);
      if (personel.sube_id !== undefined && subeScope !== null && personel.sube_id !== subeScope) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE));
        return;
      }
      if (personel.sube_id !== undefined && mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(personel.sube_id)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", PERSONEL_CREATE_SUBE_UNAUTHORIZED_MESSAGE));
        return;
      }

      const payload = (request.postDataJSON() ?? {}) as Record<string, unknown>;

      let validatedTcKimlikNo: string | undefined;
      if ("tc_kimlik_no" in payload) {
        const tcKimlikNo = String(payload.tc_kimlik_no ?? "").trim();
        if (!/^\d{11}$/.test(tcKimlikNo)) {
          await fulfillJson(
            route,
            422,
            errorBody("VALIDATION_ERROR", "T.C. Kimlik No 11 hane olmalidir.", "tc_kimlik_no")
          );
          return;
        }
        const duplicateTc = personeller.some(
          (item) => item.id !== personelId && String(item.tc_kimlik_no).trim() === tcKimlikNo
        );
        if (duplicateTc) {
          await fulfillJson(
            route,
            409,
            errorBody("DUPLICATE_TC_KIMLIK_NO", DUPLICATE_TC_KIMLIK_NO_MESSAGE, "tc_kimlik_no")
          );
          return;
        }
        validatedTcKimlikNo = tcKimlikNo;
      }

      const readNullablePositiveInt = (field: string) => {
        if (!(field in payload)) return undefined;
        const value = payload[field];
        if (value === null || value === "") return null;
        const parsed =
          typeof value === "number"
            ? value
            : typeof value === "string" && value.trim()
              ? Number.parseInt(value.trim(), 10)
              : NaN;
        return Number.isInteger(parsed) && parsed > 0 ? parsed : NaN;
      };

      for (const field of ["departman_id", "gorev_id", "bagli_amir_id", "personel_tipi_id", "ucret_tipi_id", "prim_kurali_id"]) {
        const parsed = readNullablePositiveInt(field);
        if (typeof parsed === "number" && Number.isNaN(parsed)) {
          await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Gecersiz deger.", field));
          return;
        }
      }

      if ("sube_id" in payload) {
        const parsedSubeId = readNullablePositiveInt("sube_id");
        if (parsedSubeId === null || (typeof parsedSubeId === "number" && Number.isNaN(parsedSubeId))) {
          await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Sube secilmelidir.", "sube_id"));
          return;
        }
        if (parsedSubeId !== personel.sube_id) {
          await fulfillJson(route, 403, errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE));
          return;
        }
      }

      if ("aktif_durum" in payload && payload.aktif_durum !== personel.aktif_durum) {
        await fulfillJson(
          route,
          422,
          errorBody("VALIDATION_ERROR", "Aktif durum bu endpoint ile degistirilemez.", "aktif_durum")
        );
        return;
      }

      const hasLifecycleKeys =
        "departman_id" in payload ||
        "gorev_id" in payload ||
        "bagli_amir_id" in payload ||
        "personel_tipi_id" in payload ||
        "ucret_tipi_id" in payload ||
        "net_maas_tutari" in payload ||
        "maas_tutari" in payload ||
        "prim_kurali_id" in payload;

      if (!hasLifecycleKeys) {
        if (typeof payload.ad === "string") personel.ad = payload.ad.trim();
        if (typeof payload.soyad === "string") personel.soyad = payload.soyad.trim();
        if (typeof payload.telefon === "string") personel.telefon = payload.telefon.trim();
        if (validatedTcKimlikNo !== undefined) personel.tc_kimlik_no = validatedTcKimlikNo;
        await fulfillJson(route, 200, okBody(buildPersonelDetail(personel)));
        return;
      }

      const merged = mergePersonelFromPutPayload(personel, payload);
      if (typeof payload.ad === "string") merged.ad = payload.ad.trim();
      if (typeof payload.soyad === "string") merged.soyad = payload.soyad.trim();
      if (typeof payload.telefon === "string") merged.telefon = payload.telefon.trim();

      const beforeSnap = normalizeLifecycleSnapshot(personel);
      const afterSnap = normalizeLifecycleSnapshot(merged);
      const hasLifecycleDiff = !lifecycleSnapshotsEqual(beforeSnap, afterSnap);

      if (!hasLifecycleDiff) {
        personel.ad = merged.ad;
        personel.soyad = merged.soyad;
        personel.telefon = merged.telefon;
        await fulfillJson(route, 200, okBody(buildPersonelDetail(personel)));
        return;
      }

      const effectiveRaw = payload.effective_date;
      const effective =
        typeof effectiveRaw === "string" && effectiveRaw.trim() ? effectiveRaw.trim() : "";
      if (!effective) {
        await fulfillJson(
          route,
          400,
          errorBody("VALIDATION_ERROR", "Gecerlilik tarihi zorunludur.")
        );
        return;
      }

      Object.assign(personel, merged);
      syncPersonelReferansAdlari(personel);

      const createdAt = new Date().toISOString();
      surecler.unshift({
        id: ++surecIdCounter,
        personel_id: personelId,
        surec_turu: "ORG_DEGISIKLIK",
        baslangic_tarihi: effective,
        effective_date: effective,
        created_at: createdAt,
        state: "TAMAMLANDI",
        aciklama: "Mock otomatik org gecmis kaydi"
      });

      await fulfillJson(route, 200, okBody(buildPersonelDetail(personel)));
      return;
    }

    if (path.match(/^\/api\/personeller\/\d+$/) && method === "GET") {
      const personelId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const personel = personeller.find((item) => item.id === personelId);
      const bagliAmir = bagliAmirReferanslari.find((item) => item.id === personelId);
      const subeScope = getRequestSubeScope(request, url);

      if (!personel && !bagliAmir) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }

      if (personel && subeScope !== null && personel.sube_id !== subeScope) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE));
        return;
      }

      if (!personel && bagliAmir) {
        if (subeScope !== null && bagliAmir.sube_id !== subeScope) {
          await fulfillJson(route, 403, errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE));
          return;
        }
        await fulfillJson(
          route,
          200,
          okBody({
            ana_kart: {
              id: bagliAmir.id,
              tc_kimlik_no: "00000000000",
              ad: bagliAmir.ad,
              soyad: "",
              aktif_durum: "AKTIF",
              sube_id: bagliAmir.sube_id,
              departman_id: bagliAmir.departman_id
            },
            sistem_ozeti: {
              hizmet_suresi: "-",
              toplam_izin_hakki: 0,
              kullanilan_izin: 0,
              kalan_izin: 0
            },
            pasiflik_durumu: {
              aktif_durum: "AKTIF",
              etiket: null
            },
            referans_adlari: {
              sube: subeler.find((sube) => sube.id === bagliAmir.sube_id)?.ad ?? "-",
              departman: getDepartmanLabel(bagliAmir.departman_id),
              gorev: "Birim Amiri",
              personel_tipi: "-",
              bagli_amir: null
            }
          })
        );
        return;
      }

      await fulfillJson(route, 200, okBody(buildPersonelDetail(personel)));
      return;
    }

    const personelUcretAktifMatch = path.match(/^\/api\/personeller\/(\d+)\/ucretler\/aktif$/);
    if (personelUcretAktifMatch && method === "GET") {
      if (await denyUnlessRolePermission(route, "personeller.ucret.view")) return;
      const personelId = Number.parseInt(personelUcretAktifMatch[1] ?? "0", 10);
      const personel = personeller.find((item) => item.id === personelId);
      if (!personel) {
        await fulfillJson(route, 404, errorBody("SALARY_RECORD_NOT_FOUND", "Personel bulunamadi."));
        return;
      }
      const subeScope = getRequestSubeScope(request, url);
      if (subeScope !== null && personel.sube_id !== subeScope) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE));
        return;
      }
      const tarih = url.searchParams.get("tarih") ?? new Date().toISOString().slice(0, 10);
      const matches = personelUcretleri.filter(
        (item) =>
          item.personel_id === personelId &&
          item.state === "AKTIF" &&
          item.gecerlilik_baslangic <= tarih &&
          (item.gecerlilik_bitis === null || tarih <= item.gecerlilik_bitis)
      );
      if (matches.length > 1) {
        await fulfillJson(route, 409, errorBody("SALARY_OVERLAP_DATA_ERROR", "Ucret gecmisinde cakisan kayitlar var."));
        return;
      }
      if (matches[0]) {
        await fulfillJson(route, 200, okBody(matches[0]));
        return;
      }
      await fulfillJson(route, 404, errorBody("SALARY_MISSING", "Belirtilen tarihte gecerli ucret kaydi yok."));
      return;
    }

    const personelUcretListMatch = path.match(/^\/api\/personeller\/(\d+)\/ucretler$/);
    if (personelUcretListMatch && (method === "GET" || method === "POST")) {
      const personelId = Number.parseInt(personelUcretListMatch[1] ?? "0", 10);
      const personel = personeller.find((item) => item.id === personelId);
      if (!personel) {
        await fulfillJson(route, 404, errorBody("SALARY_RECORD_NOT_FOUND", "Personel bulunamadi."));
        return;
      }
      const subeScope = getRequestSubeScope(request, url);
      if (subeScope !== null && personel.sube_id !== subeScope) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE));
        return;
      }

      if (method === "GET") {
        if (await denyUnlessRolePermission(route, "personeller.ucret.view")) return;
        const items = personelUcretleri
          .filter((item) => item.personel_id === personelId)
          .sort((a, b) => b.gecerlilik_baslangic.localeCompare(a.gecerlilik_baslangic) || b.id - a.id);
        await fulfillJson(route, 200, okBody({ items }));
        return;
      }

      if (await denyUnlessRolePermission(route, "personeller.ucret.manage")) return;
      const payload = request.postDataJSON() as Record<string, unknown>;
      const tutar = Number(payload.ucret_tutari);
      const baslangic = String(payload.gecerlilik_baslangic ?? "");
      const bitis =
        payload.gecerlilik_bitis === null || payload.gecerlilik_bitis === undefined || payload.gecerlilik_bitis === ""
          ? null
          : String(payload.gecerlilik_bitis);
      if (!Number.isFinite(tutar) || tutar <= 0 || !/^\d{4}-\d{2}-\d{2}$/.test(baslangic)) {
        await fulfillJson(route, 400, errorBody("SALARY_AMOUNT_INVALID", "Ucret tutari veya tarih gecersiz."));
        return;
      }
      const open = personelUcretleri.find(
        (item) => item.personel_id === personelId && item.state === "AKTIF" && item.gecerlilik_bitis === null
      );
      if (open && open.gecerlilik_baslangic < baslangic) {
        const closeDate = new Date(`${baslangic}T00:00:00Z`);
        closeDate.setUTCDate(closeDate.getUTCDate() - 1);
        open.gecerlilik_bitis = closeDate.toISOString().slice(0, 10);
      }
      const overlap = personelUcretleri.some(
        (item) =>
          item.personel_id === personelId &&
          item.state === "AKTIF" &&
          rangesOverlapInclusive(baslangic, bitis, item.gecerlilik_baslangic, item.gecerlilik_bitis)
      );
      if (overlap) {
        await fulfillJson(
          route,
          409,
          errorBody(
            "SALARY_DATE_OVERLAP",
            "Bu personel için seçilen tarih aralığında başka bir ücret kaydı bulunmaktadır."
          )
        );
        return;
      }
      const created: MockPersonelUcret = {
        id: ++personelUcretIdCounter,
        personel_id: personelId,
        ucret_tutari: tutar,
        ucret_turu: payload.ucret_turu === "BRUT" ? "BRUT" : "NET",
        para_birimi: String(payload.para_birimi ?? "TRY"),
        gecerlilik_baslangic: baslangic,
        gecerlilik_bitis: bitis,
        state: "AKTIF",
        kaynak: "MANUEL",
        aciklama: typeof payload.aciklama === "string" ? payload.aciklama : null
      };
      personelUcretleri.push(created);
      personel.maas_tutari = tutar;
      personel.net_maas_tutari = tutar;
      await fulfillJson(route, 201, okBody(created));
      return;
    }

    const personelUcretCancelMatch = path.match(/^\/api\/personeller\/(\d+)\/ucretler\/(\d+)\/iptal$/);
    if (personelUcretCancelMatch && method === "POST") {
      if (await denyUnlessRolePermission(route, "personeller.ucret.manage")) return;
      const personelId = Number.parseInt(personelUcretCancelMatch[1] ?? "0", 10);
      const ucretId = Number.parseInt(personelUcretCancelMatch[2] ?? "0", 10);
      const record = personelUcretleri.find((item) => item.id === ucretId && item.personel_id === personelId);
      if (!record) {
        await fulfillJson(route, 404, errorBody("SALARY_RECORD_NOT_FOUND", "Ucret kaydi bulunamadi."));
        return;
      }
      record.state = "IPTAL";
      await fulfillJson(route, 200, okBody(record));
      return;
    }

    if (path === "/api/mevzuat-parametreleri" && method === "GET") {
      if (await denyUnlessRolePermission(route, "mevzuat_parametreleri.view")) return;
      await fulfillJson(route, 200, okBody({ items: [...mevzuatParametreleri] }));
      return;
    }

    if (path === "/api/mevzuat-parametreleri" && method === "POST") {
      if (await denyUnlessRolePermission(route, "mevzuat_parametreleri.manage")) return;
      const payload = request.postDataJSON() as Record<string, unknown>;
      const kod = String(payload.parametre_kodu ?? "").trim().toUpperCase();
      const baslangic = String(payload.gecerlilik_baslangic ?? "");
      const bitis =
        payload.gecerlilik_bitis === null || payload.gecerlilik_bitis === undefined || payload.gecerlilik_bitis === ""
          ? null
          : String(payload.gecerlilik_bitis);
      const degerTipi = payload.deger_tipi === "METIN" ? "METIN" : "SAYISAL";
      const overlap = mevzuatParametreleri.some(
        (item) =>
          item.parametre_kodu === kod &&
          item.state === "AKTIF" &&
          rangesOverlapInclusive(baslangic, bitis, item.gecerlilik_baslangic, item.gecerlilik_bitis)
      );
      if (overlap) {
        await fulfillJson(route, 409, errorBody("LEGAL_PARAMETER_OVERLAP", "Mevzuat parametresi tarih araligi cakisiyor."));
        return;
      }
      const created: MockMevzuatParametresi = {
        id: ++mevzuatParametreIdCounter,
        parametre_kodu: kod,
        deger_tipi: degerTipi,
        sayisal_deger: degerTipi === "SAYISAL" ? Number(payload.sayisal_deger) : null,
        metin_deger: degerTipi === "METIN" ? String(payload.metin_deger ?? "") : null,
        gecerlilik_baslangic: baslangic,
        gecerlilik_bitis: bitis,
        birim: typeof payload.birim === "string" ? payload.birim : null,
        aciklama: typeof payload.aciklama === "string" ? payload.aciklama : null,
        state: "AKTIF"
      };
      mevzuatParametreleri.push(created);
      await fulfillJson(route, 201, okBody(created));
      return;
    }

    const mevzuatCancelMatch = path.match(/^\/api\/mevzuat-parametreleri\/(\d+)\/iptal$/);
    if (mevzuatCancelMatch && method === "POST") {
      if (await denyUnlessRolePermission(route, "mevzuat_parametreleri.manage")) return;
      const id = Number.parseInt(mevzuatCancelMatch[1] ?? "0", 10);
      const record = mevzuatParametreleri.find((item) => item.id === id);
      if (!record) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Parametre bulunamadi."));
        return;
      }
      record.state = "IPTAL";
      await fulfillJson(route, 200, okBody(record));
      return;
    }

    if (path === "/api/surecler" && method === "GET") {
      const hasPersonelId = url.searchParams.has("personel_id");
      const personelId = hasPersonelId ? parseStrictPositiveIntParam(url.searchParams.get("personel_id")) : null;
      const surecTuru = url.searchParams.get("surec_turu");
      const state = url.searchParams.get("state");
      const baslangicTarihi = url.searchParams.get("baslangic_tarihi");
      const bitisTarihi = url.searchParams.get("bitis_tarihi");
      const subeScope = getRequestSubeScope(request, url);

      if (hasPersonelId && personelId === null) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Personel secimi gecersiz.", "personel_id"));
        return;
      }

      if (subeScope !== null && mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeScope)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", PERSONEL_CREATE_SUBE_UNAUTHORIZED_MESSAGE));
        return;
      }

      const personelInScope = (personel: (typeof personeller)[number] | undefined) => {
        if (!personel || typeof personel.sube_id !== "number") {
          return false;
        }

        if (subeScope !== null) {
          return personel.sube_id === subeScope;
        }

        return mockUserSubeIds.length === 0 || mockUserSubeIds.includes(personel.sube_id);
      };

      if (personelId !== null) {
        const linkedPersonel = personeller.find((personel) => personel.id === personelId);
        if (!linkedPersonel) {
          await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
          return;
        }

        if (!personelInScope(linkedPersonel)) {
          await fulfillJson(route, 403, errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE));
          return;
        }
      }

      const filtered = surecler.filter((item) => {
        if (personelId !== null && item.personel_id !== personelId) {
          return false;
        }
        if (surecTuru && item.surec_turu !== surecTuru) {
          return false;
        }
        if (state && item.state !== state) {
          return false;
        }
        if (baslangicTarihi && item.baslangic_tarihi !== baslangicTarihi) {
          return false;
        }
        if (bitisTarihi && item.bitis_tarihi !== bitisTarihi) {
          return false;
        }
        if (!personelInScope(personeller.find((personel) => personel.id === item.personel_id))) {
          return false;
        }
        return true;
      });

      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "20", 10) || 20;
      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit);

      await fulfillJson(
        route,
        200,
        surecListOkBody(items, {
          page: pageNumber,
          limit: pageLimit,
          total: filtered.length
        })
      );
      return;
    }

    if (path === "/api/surecler" && method === "POST") {
      const payload = request.postDataJSON() as Record<string, unknown>;
      const personelId = parsePayloadPositiveInt(payload.personel_id);
      const surecTuru =
        typeof payload.surec_turu === "string" ? payload.surec_turu.trim().toUpperCase() : "";
      const baslangicTarihi = typeof payload.baslangic_tarihi === "string" ? payload.baslangic_tarihi : "";
      const bitisTarihi =
        typeof payload.bitis_tarihi === "string" && payload.bitis_tarihi.trim() !== ""
          ? payload.bitis_tarihi
          : undefined;
      const subeScope = getRequestSubeScope(request, url);

      if (personelId === null) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Personel seçilmelidir.", "personel_id"));
        return;
      }

      if (!surecTuru) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Süreç türü seçilmelidir.", "surec_turu"));
        return;
      }

      if (!SUPPORTED_SUREC_TURLERI.has(surecTuru)) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Süreç türü geçerli değil.", "surec_turu"));
        return;
      }

      if (!isValidDateString(baslangicTarihi)) {
        await fulfillJson(
          route,
          422,
          errorBody("VALIDATION_ERROR", "Başlangıç tarihi geçerli olmalıdır.", "baslangic_tarihi")
        );
        return;
      }

      if (bitisTarihi !== undefined && !isValidDateString(bitisTarihi)) {
        await fulfillJson(
          route,
          422,
          errorBody("VALIDATION_ERROR", "Bitiş tarihi geçerli olmalıdır.", "bitis_tarihi")
        );
        return;
      }

      if (subeScope !== null && mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeScope)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", PERSONEL_CREATE_SUBE_UNAUTHORIZED_MESSAGE));
        return;
      }

      const linkedPersonel = personeller.find((personel) => personel.id === personelId);
      if (!linkedPersonel) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Personel bulunamadı.", "personel_id"));
        return;
      }

      if (linkedPersonel.sube_id !== undefined) {
        if (subeScope !== null && linkedPersonel.sube_id !== subeScope) {
          await fulfillJson(route, 403, errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE));
          return;
        }
        if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(linkedPersonel.sube_id)) {
          await fulfillJson(route, 403, errorBody("FORBIDDEN", PERSONEL_CREATE_SUBE_UNAUTHORIZED_MESSAGE));
          return;
        }
      }

      if (linkedPersonel.aktif_durum === "PASIF") {
        await fulfillJson(
          route,
          422,
          errorBody("VALIDATION_ERROR", "Pasif personele süreç kaydı eklenemez.", "personel_id")
        );
        return;
      }

      if (surecTuru === "POZISYON_DEGISTI") {
        const mockOrgIndex = surecler.findIndex(
          (item) =>
            item.personel_id === personelId &&
            item.surec_turu === "ORG_DEGISIKLIK" &&
            item.baslangic_tarihi === baslangicTarihi &&
            item.aciklama === "Mock otomatik org gecmis kaydi"
        );
        if (mockOrgIndex >= 0) {
          surecler.splice(mockOrgIndex, 1);
        }
      }

      const created = {
        id: ++surecIdCounter,
        personel_id: personelId,
        surec_turu: surecTuru,
        alt_tur: typeof payload.alt_tur === "string" && payload.alt_tur.trim() ? payload.alt_tur.trim() : undefined,
        baslangic_tarihi: baslangicTarihi,
        bitis_tarihi: bitisTarihi,
        ucretli_mi: Boolean(payload.ucretli_mi),
        ilk_iki_gun_firma_oder_mi: resolveMockIlkIkiGunFirmaOderMi(
          surecTuru,
          typeof payload.alt_tur === "string" && payload.alt_tur.trim() ? payload.alt_tur.trim() : undefined,
          payload
        ),
        aciklama:
          typeof payload.aciklama === "string" && payload.aciklama.trim() ? payload.aciklama.trim() : undefined,
        created_at: new Date().toISOString(),
        state: "AKTIF"
      };
      surecler.unshift(created);

      if (created.surec_turu === "ISTEN_AYRILMA") {
        const targetPersonel = personeller.find((item) => item.id === created.personel_id);
        if (targetPersonel) {
          targetPersonel.aktif_durum = "PASIF";
        }
      }

      await fulfillJson(route, 201, okBody(created));
      return;
    }

    if (path === "/api/zimmetler" && method === "GET") {
      const personelId = Number.parseInt(url.searchParams.get("personel_id") ?? "", 10);
      const zimmetDurumu = url.searchParams.get("zimmet_durumu");
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);

      const filtered = zimmetler.filter((item) => {
        if (Number.isFinite(personelId) && item.personel_id !== personelId) {
          return false;
        }
        if (zimmetDurumu && item.zimmet_durumu !== zimmetDurumu) {
          return false;
        }
        if (Number.isFinite(subeId)) {
          const linkedPersonel = personeller.find((personel) => personel.id === item.personel_id);
          if (!linkedPersonel || linkedPersonel.sube_id !== subeId) {
            return false;
          }
        }
        return true;
      });

      await fulfillJson(route, 200, okBody({ items: filtered }));
      return;
    }

    if (path === "/api/zimmetler" && method === "POST") {
      const payload = request.postDataJSON() as {
        personel_id: number;
        urun_turu: string;
        teslim_tarihi: string;
        teslim_eden?: string;
        aciklama?: string;
        teslim_durumu: string;
      };

      const created = {
        id: ++zimmetIdCounter,
        personel_id: payload.personel_id,
        urun_turu: payload.urun_turu,
        teslim_tarihi: payload.teslim_tarihi,
        teslim_eden: payload.teslim_eden,
        aciklama: payload.aciklama,
        teslim_durumu: payload.teslim_durumu,
        zimmet_durumu: "AKTIF"
      };
      zimmetler.unshift(created);

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    const belgeKayitlariListMatch = path.match(/^\/api\/personeller\/(\d+)\/belge-kayitlari$/);
    if (belgeKayitlariListMatch && method === "GET") {
      const pid = Number.parseInt(belgeKayitlariListMatch[1] ?? "0", 10);
      const scopeResult = assertBelgePersonelScope(request, url, pid);
      if (!scopeResult.personel) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }
      if (!scopeResult.ok) {
        await fulfillJson(route, scopeResult.status, scopeResult.body);
        return;
      }

      const stateFilter = url.searchParams.get("state");
      const filtered = personelBelgeKayitlari.filter((item) => {
        if (item.personel_id !== pid) {
          return false;
        }
        if (stateFilter && stateFilter !== "tum" && item.durum !== stateFilter) {
          return false;
        }
        return true;
      });

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items: filtered.map(serializePersonelBelgeKaydi) },
          meta: { page: 1, limit: 50, total: filtered.length, total_pages: 1 },
          errors: []
        })
      );
      return;
    }

    if (belgeKayitlariListMatch && method === "POST") {
      const pid = Number.parseInt(belgeKayitlariListMatch[1] ?? "0", 10);
      const scopeResult = assertBelgePersonelScope(request, url, pid);
      if (!scopeResult.personel) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }
      if (!scopeResult.ok) {
        await fulfillJson(route, scopeResult.status, scopeResult.body);
        return;
      }
      if (scopeResult.personel.aktif_durum === "PASIF") {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Pasif personele belge kaydı eklenemez.", "personel_id"));
        return;
      }

      const payload = request.postDataJSON() as {
        kayit_tipi?: string;
        ad?: string;
        veren_kurum?: string | null;
        belge_no?: string | null;
        baslangic_tarihi?: string | null;
        bitis_tarihi?: string | null;
        ek_ref?: string | null;
        aciklama?: string | null;
      };

      const kayitTipi = payload.kayit_tipi?.trim();
      const ad = payload.ad?.trim();
      if (!kayitTipi || !(PERSONEL_BELGE_KAYIT_TIPI_KEYS as readonly string[]).includes(kayitTipi) || !ad) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "kayit_tipi ve ad zorunludur."));
        return;
      }
      if (payload.baslangic_tarihi && !isValidDateString(payload.baslangic_tarihi)) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Gecerli bir tarih olmalidir.", "baslangic_tarihi"));
        return;
      }
      if (payload.bitis_tarihi && !isValidDateString(payload.bitis_tarihi)) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Gecerli bir tarih olmalidir.", "bitis_tarihi"));
        return;
      }

      const now = new Date().toISOString();
      const created = {
        id: ++personelBelgeKaydiIdCounter,
        personel_id: pid,
        kayit_tipi: kayitTipi as PersonelBelgeKayitTipi,
        ad,
        veren_kurum: payload.veren_kurum?.trim() || null,
        belge_no: payload.belge_no?.trim() || null,
        baslangic_tarihi: payload.baslangic_tarihi?.trim() || null,
        bitis_tarihi: payload.bitis_tarihi?.trim() || null,
        durum: "AKTIF" as const,
        ek_ref: payload.ek_ref?.trim() || null,
        aciklama: payload.aciklama?.trim() || null,
        created_at: now,
        updated_at: now
      };
      personelBelgeKayitlari.unshift(created);
      surecler.unshift({
        id: created.id,
        personel_id: pid,
        surec_turu: "BELGE",
        alt_tur: kayitTipi,
        baslangic_tarihi: created.baslangic_tarihi ?? now.slice(0, 10),
        bitis_tarihi: created.bitis_tarihi ?? undefined,
        aciklama: encodePersonelBelgeKaydiSurecMetadata({
          kayit_tipi: kayitTipi,
          ad,
          veren_kurum: created.veren_kurum,
          belge_no: created.belge_no,
          baslangic_tarihi: created.baslangic_tarihi,
          bitis_tarihi: created.bitis_tarihi,
          ek_ref: created.ek_ref,
          aciklama: created.aciklama
        }),
        created_at: now,
        state: "AKTIF"
      });
      await fulfillJson(route, 201, okBody(serializePersonelBelgeKaydi(created)));
      return;
    }

    const belgeKayitCancelMatch = path.match(/^\/api\/belge-kayitlari\/(\d+)\/iptal$/);
    if (belgeKayitCancelMatch && method === "POST") {
      const id = Number.parseInt(belgeKayitCancelMatch[1] ?? "0", 10);
      const kayit = personelBelgeKayitlari.find((item) => item.id === id);
      if (!kayit) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Belge kaydi bulunamadi."));
        return;
      }
      const scopeResult = assertBelgePersonelScope(request, url, kayit.personel_id);
      if (!scopeResult.ok) {
        await fulfillJson(route, scopeResult.status, scopeResult.body);
        return;
      }
      kayit.durum = "IPTAL";
      kayit.updated_at = new Date().toISOString();
      await fulfillJson(route, 200, okBody(serializePersonelBelgeKaydi(kayit)));
      return;
    }

    const belgeDurumMatch = path.match(/^\/api\/personeller\/(\d+)\/belge-durumu$/);
    if (belgeDurumMatch && method === "GET") {
      const pid = Number.parseInt(belgeDurumMatch[1] ?? "0", 10);
      const scopeResult = assertBelgePersonelScope(request, url, pid);
      if (!scopeResult.personel) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }
      if (!scopeResult.ok) {
        await fulfillJson(route, scopeResult.status, scopeResult.body);
        return;
      }
      const stored = belgeDurumByPersonelId.get(pid) ?? {};
      const items = DEMO_BELGE_TURLERI.map((belge_turu) => ({
        belge_turu,
        durum: (stored[belge_turu] as "VAR" | "YOK" | undefined) ?? "YOK"
      }));
      await fulfillJson(route, 200, okBody({ items }));
      return;
    }

    if (belgeDurumMatch && method === "PUT") {
      const pid = Number.parseInt(belgeDurumMatch[1] ?? "0", 10);
      const scopeResult = assertBelgePersonelScope(request, url, pid);
      if (!scopeResult.personel) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Personel bulunamadi."));
        return;
      }
      if (!scopeResult.ok) {
        await fulfillJson(route, scopeResult.status, scopeResult.body);
        return;
      }
      if (scopeResult.personel.aktif_durum === "PASIF") {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Pasif personelin belge durumu güncellenemez.", "personel_id"));
        return;
      }
      const payload = (request.postDataJSON() ?? {}) as {
        items?: Array<{ belge_turu?: string; durum?: string }>;
      };
      const stored = belgeDurumByPersonelId.get(pid) ?? {};
      const incoming: Record<string, "VAR" | "YOK"> = {};
      for (const row of payload.items ?? []) {
        const tur = row.belge_turu?.trim();
        const durum = row.durum;
        if (!tur || (durum !== "VAR" && durum !== "YOK")) {
          continue;
        }
        if (!(DEMO_BELGE_TURLERI as readonly string[]).includes(tur)) {
          continue;
        }
        incoming[tur] = durum;
      }
      const next: Record<string, "VAR" | "YOK"> = { ...stored };
      for (const belge_turu of DEMO_BELGE_TURLERI) {
        next[belge_turu] = incoming[belge_turu] ?? stored[belge_turu] ?? "YOK";
      }
      belgeDurumByPersonelId.set(pid, next);
      const items = DEMO_BELGE_TURLERI.map((belge_turu) => ({
        belge_turu,
        durum: next[belge_turu] ?? "YOK"
      }));
      await fulfillJson(route, 200, okBody({ items }));
      return;
    }

    if (path === "/api/personeller/1" && method === "GET") {
      await fulfillJson(
        route,
        200,
        okBody({
          ana_kart: {
            id: 1,
            tc_kimlik_no: "12345678901",
            ad: "Ayşe",
            soyad: "Yılmaz",
            aktif_durum: "AKTIF",
            sube_id: 1,
            telefon: "05550000000",
            dogum_tarihi: "1992-03-14",
            dogum_yeri: "İstanbul",
            kan_grubu: "A Rh+",
            sicil_no: "P-001",
            ise_giris_tarihi: "2023-02-01",
            acil_durum_kisi: "Fatma Yılmaz",
            acil_durum_telefon: "05553334455",
            departman_id: 3,
            gorev_id: 1,
            personel_tipi_id: 1,
            bagli_amir_id: 9
          },
          sistem_ozeti: {
            hizmet_suresi: "3 yil 2 ay",
            toplam_izin_hakki: 14,
            kullanilan_izin: 4,
            kalan_izin: 10
          },
          pasiflik_durumu: {
            aktif_durum: "AKTIF",
            etiket: null
          },
          referans_adlari: {
            sube: "Merkez",
            departman: "Döşeme",
            gorev: "Genel Müdür",
            personel_tipi: "Tam Zamanlı",
            bagli_amir: "Demo Amir"
          }
        })
      );
      return;
    }

    if (path === "/api/surecler" && method === "GET") {
      await fulfillJson(route, 200, okBody({ items: surecler }));
      return;
    }

    if (path === "/api/surecler" && method === "POST") {
      const payload = request.postDataJSON() as {
        personel_id: number;
        surec_turu: string;
        alt_tur?: string;
        baslangic_tarihi: string;
        bitis_tarihi: string;
        ucretli_mi?: boolean;
        ilk_iki_gun_firma_oder_mi?: boolean;
        aciklama?: string;
      };

      const surecTuru = payload.surec_turu.trim().toUpperCase();
      const altTur = payload.alt_tur?.trim() || undefined;
      const created = {
        id: ++surecIdCounter,
        personel_id: payload.personel_id,
        surec_turu: surecTuru,
        alt_tur: altTur,
        baslangic_tarihi: payload.baslangic_tarihi,
        bitis_tarihi: payload.bitis_tarihi,
        ucretli_mi: payload.ucretli_mi,
        ilk_iki_gun_firma_oder_mi: resolveMockIlkIkiGunFirmaOderMi(surecTuru, altTur, payload),
        aciklama: payload.aciklama,
        state: "AKTIF"
      };
      surecler.unshift(created);

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/surecler\/\d+$/) && method === "GET") {
      const surecId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const surec = surecler.find((item) => item.id === surecId);
      if (!surec) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Surec bulunamadi."));
        return;
      }

      await fulfillJson(route, 200, okBody(surec));
      return;
    }

    if (path.match(/^\/api\/surecler\/\d+$/) && method === "PUT") {
      const surecId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const surec = surecler.find((item) => item.id === surecId);
      if (!surec) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Surec bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<typeof surec>;
      Object.assign(surec, payload);

      await fulfillJson(route, 200, okBody(surec));
      return;
    }

    if (path.match(/^\/api\/surecler\/\d+\/iptal$/) && method === "POST") {
      const surecId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const surec = surecler.find((item) => item.id === surecId);
      if (!surec) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Surec bulunamadi."));
        return;
      }

      surec.state = "IPTAL";
      await fulfillJson(route, 200, okBody({ id: surec.id, state: surec.state }));
      return;
    }

    if (path === "/api/bildirimler/birim-amiri-secenekleri" && method === "GET") {
      if (await denyUnlessRolePermission(route, "bildirimler.view")) return;
      const subeId = getRequestSubeScope(request, url);
      if (!subeId) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Aktif sube secimi zorunludur.", "sube_id"));
        return;
      }
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu sube icin yetkiniz bulunmuyor."));
        return;
      }
      const candidates = [
        { user_id: 1, ad_soyad: "Merkez Birim Amiri", sube_id: 1 },
        { user_id: 4, ad_soyad: "Depolama Birim Amiri", sube_id: 2 }
      ].filter((item) => item.sube_id === subeId);
      await fulfillJson(route, 200, okBody({ items: candidates }));
      return;
    }

    if (path === "/api/haftalik-bildirim-mutabakatlari/ozet" && method === "GET") {
      if (await denyUnlessRolePermission(route, "haftalik_mutabakat.view")) return;
      const week = resolveMockMutabakatWeek(url.searchParams.get("hafta_baslangic"));
      if (!week) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Hafta baslangici Pazartesi olmalidir.", "hafta_baslangic"));
        return;
      }
      const subeId = getRequestSubeScope(request, url) ?? mockUserSubeIds[0] ?? 1;
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Haftalik mutabakat icin aktif sube secilmelidir."));
        return;
      }
      const amirId = role === "BIRIM_AMIRI" ? mockUserId : Number.parseInt(url.searchParams.get("birim_amiri_user_id") ?? "", 10) || null;
      const expectedAmirId = subeId === 1 ? 1 : subeId === 2 ? 4 : null;
      if (!amirId) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Birim amiri secimi zorunludur.", "birim_amiri_user_id"));
        return;
      }
      if (amirId !== expectedAmirId) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Secilen birim amiri aktif sube ile eslesmiyor."));
        return;
      }
      const existing = bildirimPageState.mutabakatlar.find(
        (item) => item.sube_id === subeId && item.birim_amiri_user_id === amirId && item.hafta_baslangic === week.start
      );
      const counts = mockMutabakatCounts(subeId, amirId, week.start, week.end);
      const reason = mockMutabakatBlockReason(counts, existing);
      await fulfillJson(route, 200, okBody({
        hafta_baslangic: week.start, hafta_bitis: week.end, sube_id: subeId,
        birim_amiri_user_id: amirId, counts, onaylanabilir_mi: reason === null,
        blok_nedeni: reason, mevcut_mutabakat_id: existing?.id ?? null
      }));
      return;
    }

    if (path === "/api/haftalik-bildirim-mutabakatlari" && method === "POST") {
      if (await denyUnlessRolePermission(route, "haftalik_mutabakat.approve")) return;
      if (role !== "BIRIM_AMIRI") {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Yalnizca birim amiri kendi haftasini onaylayabilir."));
        return;
      }
      const payload = request.postDataJSON() as { hafta_baslangic?: string };
      const week = resolveMockMutabakatWeek(payload.hafta_baslangic);
      if (!week) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Hafta baslangici Pazartesi olmalidir.", "hafta_baslangic"));
        return;
      }
      const subeId = getRequestSubeScope(request, url) ?? mockUserSubeIds[0] ?? null;
      if (!subeId || !mockUserSubeIds.includes(subeId)) {
        await fulfillJson(route, subeId ? 403 : 422, errorBody(subeId ? "FORBIDDEN" : "VALIDATION_ERROR", "Haftalik mutabakat icin aktif sube secilmelidir."));
        return;
      }
      const existing = bildirimPageState.mutabakatlar.find(
        (item) => item.sube_id === subeId && item.birim_amiri_user_id === mockUserId && item.hafta_baslangic === week.start
      );
      const counts = mockMutabakatCounts(subeId, mockUserId, week.start, week.end);
      const reason = mockMutabakatBlockReason(counts, existing);
      if (reason) {
        await fulfillJson(route, 409, errorBody("CONFLICT", reason));
        return;
      }
      const now = new Date().toISOString();
      const mutabakat: MockHaftalikMutabakat = {
        id: ++bildirimPageState.nextMutabakatId, sube_id: subeId, birim_amiri_user_id: mockUserId,
        hafta_baslangic: week.start, hafta_bitis: week.end, state: "TAMAMLANDI",
        onaylayan_user_id: mockUserId, onaylandi_at: now, created_at: now, updated_at: now
      };
      bildirimPageState.mutabakatlar.push(mutabakat);
      const linked = bildirimler.filter(
        (item) => item.sube_id === subeId && item.created_by === mockUserId &&
          item.tarih >= week.start && item.tarih <= week.end && item.state === "GONDERILDI"
      );
      linked.forEach((item) => {
        item.state = "HAFTALIK_MUTABAKATA_ALINDI";
        item.haftalik_mutabakat_id = mutabakat.id;
        item.updated_by = mockUserId;
      });
      await fulfillJson(route, 201, okBody({
        mutabakat, gunluk_bildirimler: linked,
        counts: { toplam: linked.length, baglanan: linked.length }, baglanan_kayit_sayisi: linked.length
      }));
      return;
    }

    if (path.match(/^\/api\/haftalik-bildirim-mutabakatlari\/\d+$/) && method === "GET") {
      if (await denyUnlessRolePermission(route, "haftalik_mutabakat.view")) return;
      const id = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const mutabakat = bildirimPageState.mutabakatlar.find((item) => item.id === id);
      if (!mutabakat) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Haftalik mutabakat bulunamadi."));
        return;
      }
      const scope = getRequestSubeScope(request, url);
      if ((mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(mutabakat.sube_id)) || (scope && scope !== mutabakat.sube_id)
        || (role === "BIRIM_AMIRI" && mutabakat.birim_amiri_user_id !== mockUserId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }
      const linked = bildirimler.filter((item) => item.haftalik_mutabakat_id === id);
      await fulfillJson(route, 200, okBody({ mutabakat, gunluk_bildirimler: linked, counts: { toplam: linked.length, baglanan: linked.length } }));
      return;
    }

    if (path === "/api/aylik-bildirim-onaylari/ozet" && method === "GET") {
      if (await denyUnlessRolePermission(route, "aylik_bildirim_onayi.view")) return;
      const ay = url.searchParams.get("ay") ?? "";
      if (!resolveAyBounds(ay)) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Ay parametresi YYYY-MM formatinda olmalidir.", "ay"));
        return;
      }
      const subeId = getRequestSubeScope(request, url) ?? mockUserSubeIds[0] ?? 1;
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Aylik bildirim onayi icin aktif sube secilmelidir."));
        return;
      }
      const amirId = role === "BIRIM_AMIRI" ? mockUserId : Number.parseInt(url.searchParams.get("birim_amiri_user_id") ?? "", 10) || null;
      const expectedAmirId = subeId === 1 ? 1 : subeId === 2 ? 4 : null;
      if (amirId !== null && amirId !== expectedAmirId) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Secilen birim amiri aktif sube ile eslesmiyor."));
        return;
      }
      const existing = amirId
        ? bildirimPageState.aylikOnaylar.find(
            (item) => item.sube_id === subeId && item.birim_amiri_user_id === amirId && item.ay === ay
          )
        : undefined;
      const context = amirId ? buildMockAylikOnayContext(subeId, amirId, ay) : null;
      const approval = context
        ? resolveAylikBildirimOnayApproval({
            counts: context.counts,
            mevcutOnayId: existing?.id ?? null,
            eksikHaftaSayisi: context.counts.eksik_hafta
          })
        : { onaylanabilir_mi: false, blok_nedeni: "Birim amiri secimi zorunludur." };
      const bounds = resolveAyBounds(ay);
      await fulfillJson(route, 200, okBody({
        ay,
        ay_baslangic: context?.ayBaslangic ?? bounds?.ay_baslangic,
        ay_bitis: context?.ayBitis ?? bounds?.ay_bitis,
        sube_id: subeId,
        birim_amiri_user_id: amirId,
        haftalar: context?.haftalar ?? [],
        counts: context?.counts ?? {
          toplam_bildirim: 0, mutabakata_alinan: 0, mutabakatli_hafta: 0, eksik_hafta: 0,
          taslak: 0, duzeltme_istendi: 0, gonderildi: 0
        },
        onaylanabilir_mi: approval.onaylanabilir_mi,
        blok_nedeni: approval.blok_nedeni,
        mevcut_onay_id: existing?.id ?? null
      }));
      return;
    }

    if (path === "/api/aylik-bildirim-onaylari" && method === "POST") {
      if (await denyUnlessRolePermission(route, "aylik_bildirim_onayi.approve")) return;
      if (role !== "BIRIM_AMIRI") {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Yalnizca birim amiri kendi ayini onaylayabilir."));
        return;
      }
      const payload = request.postDataJSON() as { ay?: string; aciklama?: string };
      const ay = payload.ay ?? "";
      if (!resolveAyBounds(ay)) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Ay parametresi YYYY-MM formatinda olmalidir.", "ay"));
        return;
      }
      const subeId = getRequestSubeScope(request, url) ?? mockUserSubeIds[0] ?? null;
      if (!subeId || !mockUserSubeIds.includes(subeId)) {
        await fulfillJson(route, subeId ? 403 : 422, errorBody(subeId ? "FORBIDDEN" : "VALIDATION_ERROR", "Aylik bildirim onayi icin aktif sube secilmelidir."));
        return;
      }
      const existing = bildirimPageState.aylikOnaylar.find(
        (item) => item.sube_id === subeId && item.birim_amiri_user_id === mockUserId && item.ay === ay
      );
      const context = buildMockAylikOnayContext(subeId, mockUserId, ay);
      if (!context) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Ay parametresi YYYY-MM formatinda olmalidir.", "ay"));
        return;
      }
      const approval = resolveAylikBildirimOnayApproval({
        counts: context.counts,
        mevcutOnayId: existing?.id ?? null,
        eksikHaftaSayisi: context.counts.eksik_hafta
      });
      if (!approval.onaylanabilir_mi) {
        await fulfillJson(route, 409, errorBody("CONFLICT", approval.blok_nedeni ?? "Aylik bildirim onayi olusturulamadi."));
        return;
      }
      const now = new Date().toISOString();
      const onay: MockAylikBildirimOnay = {
        id: ++bildirimPageState.nextAylikOnayId,
        sube_id: subeId,
        birim_amiri_user_id: mockUserId,
        ay,
        ay_baslangic: context.ayBaslangic,
        ay_bitis: context.ayBitis,
        state: "TAMAMLANDI",
        onaylayan_user_id: mockUserId,
        onaylandi_at: now,
        aciklama: payload.aciklama?.trim() ? payload.aciklama.trim() : null,
        created_at: now,
        updated_at: now
      };
      bildirimPageState.aylikOnaylar.push(onay);
      const mutabakatlar = context.haftalar
        .map((week) =>
          bildirimPageState.mutabakatlar.find(
            (item) =>
              item.sube_id === subeId &&
              item.birim_amiri_user_id === mockUserId &&
              item.hafta_baslangic === week.hafta_baslangic
          )
        )
        .filter((item): item is MockHaftalikMutabakat => item !== undefined);
      await fulfillJson(route, 201, okBody({ onay, haftalar: context.haftalar, haftalik_mutabakatlar: mutabakatlar, counts: context.counts }));
      return;
    }

    if (path.match(/^\/api\/aylik-bildirim-onaylari\/\d+$/) && method === "GET") {
      if (await denyUnlessRolePermission(route, "aylik_bildirim_onayi.view")) return;
      const id = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const onay = bildirimPageState.aylikOnaylar.find((item) => item.id === id);
      if (!onay) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Aylik bildirim onayi bulunamadi."));
        return;
      }
      const scope = getRequestSubeScope(request, url);
      if ((mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(onay.sube_id)) || (scope && scope !== onay.sube_id)
        || (role === "BIRIM_AMIRI" && onay.birim_amiri_user_id !== mockUserId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }
      const context = buildMockAylikOnayContext(onay.sube_id, onay.birim_amiri_user_id, onay.ay);
      const mutabakatlar = (context?.haftalar ?? [])
        .map((week) =>
          bildirimPageState.mutabakatlar.find(
            (item) =>
              item.sube_id === onay.sube_id &&
              item.birim_amiri_user_id === onay.birim_amiri_user_id &&
              item.hafta_baslangic === week.hafta_baslangic
          )
        )
        .filter((item): item is MockHaftalikMutabakat => item !== undefined);
      await fulfillJson(route, 200, okBody({
        onay,
        haftalar: context?.haftalar ?? [],
        haftalik_mutabakatlar: mutabakatlar,
        counts: context?.counts ?? {
          toplam_bildirim: 0, mutabakata_alinan: 0, mutabakatli_hafta: 0, eksik_hafta: 0,
          taslak: 0, duzeltme_istendi: 0, gonderildi: 0
        }
      }));
      return;
    }

    if (path === "/api/genel-yonetici-bildirim-onaylari/ozet" && method === "GET") {
      if (await denyUnlessRolePermission(route, "genel_yonetici_bildirim_onayi.view")) return;
      const ay = url.searchParams.get("ay") ?? "";
      if (!resolveAyBounds(ay)) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Ay parametresi YYYY-MM formatinda olmalidir.", "ay"));
        return;
      }
      const subeId = getRequestSubeScope(request, url);
      if (!subeId) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Genel yonetici bildirim onayi icin aktif sube secilmelidir.", "sube_id"));
        return;
      }
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Secili sube icin yetkiniz yok."));
        return;
      }
      const amirId = Number.parseInt(url.searchParams.get("birim_amiri_user_id") ?? "", 10) || null;
      if (!amirId) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Birim amiri secimi zorunludur.", "birim_amiri_user_id"));
        return;
      }
      const expectedAmirId = subeId === 1 ? 1 : subeId === 2 ? 4 : null;
      if (expectedAmirId !== null && amirId !== expectedAmirId) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Secili birim amiri bu sube icin yetkili degil."));
        return;
      }
      const aylikOnay = bildirimPageState.aylikOnaylar.find(
        (item) => item.sube_id === subeId && item.birim_amiri_user_id === amirId && item.ay === ay
      );
      const existingGy = bildirimPageState.gyOnaylar.find(
        (item) => item.sube_id === subeId && item.birim_amiri_user_id === amirId && item.ay === ay
      );
      const context = buildMockAylikOnayContext(subeId, amirId, ay);
      const approval = resolveGyBildirimOnayApproval({
        existingGy,
        aylikOnay,
        counts: context?.counts ?? {
          toplam_bildirim: 0,
          mutabakata_alinan: 0,
          eksik_hafta: 0,
          taslak: 0,
          duzeltme_istendi: 0,
          gonderildi: 0
        }
      });
      const bounds = resolveAyBounds(ay);
      await fulfillJson(route, 200, okBody({
        ay,
        ay_baslangic: context?.ayBaslangic ?? bounds?.ay_baslangic,
        ay_bitis: context?.ayBitis ?? bounds?.ay_bitis,
        sube_id: subeId,
        birim_amiri_user_id: amirId,
        counts: {
          toplam_bildirim: context?.counts.toplam_bildirim ?? 0,
          mutabakata_alinan: context?.counts.mutabakata_alinan ?? 0,
          eksik_hafta: context?.counts.eksik_hafta ?? 0
        },
        aylik_bildirim_onayi: aylikOnay
          ? { id: aylikOnay.id, state: aylikOnay.state, onaylandi_at: aylikOnay.onaylandi_at }
          : null,
        genel_yonetici_bildirim_onayi: existingGy
          ? {
              id: existingGy.id,
              state: existingGy.state,
              onaylayan_user_id: existingGy.onaylayan_user_id,
              onaylandi_at: existingGy.onaylandi_at,
              aciklama: existingGy.aciklama
            }
          : null,
        onay_verilebilir_mi: approval.onay_verilebilir_mi,
        blok_nedeni: approval.blok_nedeni
      }));
      return;
    }

    if (path === "/api/genel-yonetici-bildirim-onaylari" && method === "POST") {
      if (await denyUnlessRolePermission(route, "genel_yonetici_bildirim_onayi.approve")) return;
      const payload = request.postDataJSON() as {
        ay?: string;
        birim_amiri_user_id?: number;
        aciklama?: string;
      };
      const ay = payload.ay ?? "";
      if (!resolveAyBounds(ay)) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Ay parametresi YYYY-MM formatinda olmalidir.", "ay"));
        return;
      }
      const subeId = getRequestSubeScope(request, url);
      if (!subeId) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Genel yonetici bildirim onayi icin aktif sube secilmelidir.", "sube_id"));
        return;
      }
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Secili sube icin yetkiniz yok."));
        return;
      }
      const amirId = Number.parseInt(String(payload.birim_amiri_user_id ?? ""), 10) || null;
      if (!amirId) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Birim amiri secimi zorunludur.", "birim_amiri_user_id"));
        return;
      }
      const expectedAmirId = subeId === 1 ? 1 : subeId === 2 ? 4 : null;
      if (expectedAmirId !== null && amirId !== expectedAmirId) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Secili birim amiri bu sube icin yetkili degil."));
        return;
      }
      const existingGy = bildirimPageState.gyOnaylar.find(
        (item) => item.sube_id === subeId && item.birim_amiri_user_id === amirId && item.ay === ay
      );
      if (existingGy) {
        await fulfillJson(route, 409, errorBody("GENEL_YONETICI_BILDIRIM_ONAYI_MEVCUT", "Bu ay icin genel yonetici ust onayi zaten mevcut."));
        return;
      }
      const aylikOnay = bildirimPageState.aylikOnaylar.find(
        (item) => item.sube_id === subeId && item.birim_amiri_user_id === amirId && item.ay === ay
      );
      if (!aylikOnay) {
        await fulfillJson(route, 422, errorBody("AYLIK_BILDIRIM_ONAYI_GEREKLI", "Aylik bildirim onayi bulunamadi."));
        return;
      }
      const context = buildMockAylikOnayContext(subeId, amirId, ay);
      const approval = resolveGyBildirimOnayApproval({
        aylikOnay,
        counts: context?.counts ?? {
          toplam_bildirim: 0,
          mutabakata_alinan: 0,
          eksik_hafta: 0,
          taslak: 0,
          duzeltme_istendi: 0,
          gonderildi: 0
        }
      });
      if (!approval.onay_verilebilir_mi) {
        const code = approval.blok_nedeni ?? "AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI";
        const status = code === "GENEL_YONETICI_BILDIRIM_ONAYI_MEVCUT" ? 409 : 422;
        await fulfillJson(route, status, errorBody(code, "Genel yonetici bildirim onayi olusturulamadi."));
        return;
      }
      const now = new Date().toISOString();
      const onay: MockGyBildirimOnay = {
        id: ++bildirimPageState.nextGyOnayId,
        sube_id: subeId,
        birim_amiri_user_id: amirId,
        ay,
        aylik_bildirim_onayi_id: aylikOnay.id,
        state: "TAMAMLANDI",
        onaylayan_user_id: mockUserId,
        onaylandi_at: now,
        aciklama: payload.aciklama?.trim() ? payload.aciklama.trim() : null,
        created_at: now,
        updated_at: now
      };
      bildirimPageState.gyOnaylar.push(onay);
      await fulfillJson(route, 201, okBody(onay));
      return;
    }

    if (path.match(/^\/api\/genel-yonetici-bildirim-onaylari\/\d+$/) && method === "GET") {
      if (await denyUnlessRolePermission(route, "genel_yonetici_bildirim_onayi.view")) return;
      const id = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const onay = bildirimPageState.gyOnaylar.find((item) => item.id === id);
      if (!onay) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Genel yonetici bildirim onayi bulunamadi."));
        return;
      }
      const scope = getRequestSubeScope(request, url);
      if ((mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(onay.sube_id)) || (scope && scope !== onay.sube_id)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }
      await fulfillJson(route, 200, okBody(onay));
      return;
    }

    if (path === "/api/bildirimler" && method === "GET") {
      if (await denyUnlessRolePermission(route, "bildirimler.view")) {
        return;
      }

      const pageNumber = Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1;
      const pageLimit = Number.parseInt(url.searchParams.get("limit") ?? "10", 10) || 10;
      const tarih = url.searchParams.get("tarih");
      const baslangicTarihi = url.searchParams.get("baslangic_tarihi");
      const bitisTarihi = url.searchParams.get("bitis_tarihi");
      const personelId = Number.parseInt(url.searchParams.get("personel_id") ?? "", 10);
      const bildirimTuru = normalizeMockBildirimTuru(url.searchParams.get("bildirim_turu"));
      const stateFilter = url.searchParams.get("state")?.toUpperCase() ?? null;

      const filtered = bildirimler.filter((item) => {
        if (tarih && item.tarih !== tarih) {
          return false;
        }
        if (!tarih && baslangicTarihi && bitisTarihi) {
          if (!item.tarih || item.tarih < baslangicTarihi || item.tarih > bitisTarihi) {
            return false;
          }
        }
        if (Number.isFinite(personelId) && item.personel_id !== personelId) {
          return false;
        }
        if (bildirimTuru && item.bildirim_turu !== bildirimTuru) {
          return false;
        }
        if (stateFilter && item.state.toUpperCase() !== stateFilter) {
          return false;
        }
        return true;
      });

      const start = (pageNumber - 1) * pageLimit;
      const items = filtered.slice(start, start + pageLimit);

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: { items },
          meta: {
            page: pageNumber,
            limit: pageLimit,
            total: filtered.length,
            total_pages: Math.max(1, Math.ceil(filtered.length / pageLimit))
          },
          errors: []
        })
      );
      return;
    }

    if (path === "/api/bildirimler" && method === "POST") {
      if (await denyUnlessRolePermission(route, "gunluk_bildirim.create")) {
        return;
      }

      const payload = request.postDataJSON() as {
        tarih: string;
        departman_id: number;
        personel_id: number;
        bildirim_turu: string;
        aciklama?: string;
      };

      const bildirimTuru = normalizeMockBildirimTuru(payload.bildirim_turu);
      if (!bildirimTuru) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Bildirim turu gecerli degil.", "bildirim_turu"));
        return;
      }

      if (bildirimTuru === "DIGER" && !(payload.aciklama ?? "").trim()) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "DIGER turu icin aciklama zorunludur.", "aciklama"));
        return;
      }

      const created: MockBildirimRecord = {
        id: ++bildirimPageState.nextId,
        tarih: payload.tarih,
        departman_id: payload.departman_id,
        personel_id: payload.personel_id,
        sube_id: resolveMockBildirimSubeId(payload.personel_id),
        bildirim_turu: bildirimTuru,
        aciklama: payload.aciklama,
        state: "TASLAK",
        created_by: mockUserId,
        updated_by: mockUserId
      };
      bildirimler.unshift(created);

      await fulfillJson(route, 201, okBody(created));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+$/) && method === "GET") {
      if (await denyUnlessRolePermission(route, "bildirimler.view")) {
        return;
      }

      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      await fulfillJson(route, 200, okBody(bildirim));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+$/) && method === "PUT") {
      if (await denyUnlessRolePermission(route, "gunluk_bildirim.update_own_open")) {
        return;
      }

      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      if (!assertMockBildirimOwnership(bildirim)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu islem icin yetkiniz yok."));
        return;
      }

      const state = bildirim.state.toUpperCase();
      if (["GONDERILDI", "HAFTALIK_MUTABAKATA_ALINDI", "IPTAL"].includes(state) || !isMockBildirimEditableState(state)) {
        await fulfillJson(route, 409, errorBody("CONFLICT", "Bu durumdaki bildirim guncellenemez."));
        return;
      }

      const payload = request.postDataJSON() as Partial<MockBildirimRecord>;
      if (payload.bildirim_turu !== undefined) {
        const nextTur = normalizeMockBildirimTuru(payload.bildirim_turu);
        if (!nextTur) {
          await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Bildirim turu gecerli degil.", "bildirim_turu"));
          return;
        }
        bildirim.bildirim_turu = nextTur;
      }

      if (payload.aciklama !== undefined) {
        bildirim.aciklama = payload.aciklama;
      }

      if (bildirim.bildirim_turu === "DIGER" && !(bildirim.aciklama ?? "").trim()) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "DIGER turu icin aciklama zorunludur.", "aciklama"));
        return;
      }

      bildirim.updated_by = mockUserId;
      await fulfillJson(route, 200, okBody(bildirim));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+\/submit$/) && method === "POST") {
      if (await denyUnlessRolePermission(route, "gunluk_bildirim.submit")) {
        return;
      }

      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      if (!assertMockBildirimOwnership(bildirim)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu islem icin yetkiniz yok."));
        return;
      }

      const state = bildirim.state.toUpperCase();
      if (state === "GONDERILDI") {
        await fulfillJson(route, 200, okBody(bildirim));
        return;
      }
      if (state === "IPTAL") {
        await fulfillJson(route, 409, errorBody("CONFLICT", "Iptal edilmis bildirim gonderilemez."));
        return;
      }
      if (!isMockBildirimEditableState(state)) {
        await fulfillJson(route, 409, errorBody("CONFLICT", "Bu durumdaki bildirim gonderilemez."));
        return;
      }

      bildirim.state = "GONDERILDI";
      bildirim.submitted_at = new Date().toISOString();
      bildirim.updated_by = mockUserId;
      await fulfillJson(route, 200, okBody(bildirim));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+\/request-correction$/) && method === "POST") {
      if (await denyUnlessRolePermission(route, "gunluk_bildirim.request_correction")) {
        return;
      }

      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as { correction_reason?: string };
      const reason = (payload.correction_reason ?? "").trim();
      if (!reason) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Duzeltme nedeni zorunludur.", "correction_reason"));
        return;
      }

      if (bildirim.state.toUpperCase() !== "GONDERILDI") {
        await fulfillJson(route, 409, errorBody("CONFLICT", "Yalnizca gonderilmis bildirimler icin duzeltme istenebilir."));
        return;
      }

      bildirim.state = "DUZELTME_ISTENDI";
      bildirim.correction_requested_by = mockUserId;
      bildirim.correction_reason = reason;
      bildirim.updated_by = mockUserId;
      await fulfillJson(route, 200, okBody(bildirim));
      return;
    }

    if (path.match(/^\/api\/bildirimler\/\d+\/iptal$/) && method === "POST") {
      if (await denyUnlessRolePermission(route, "gunluk_bildirim.update_own_open")) {
        return;
      }

      const bildirimId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const bildirim = bildirimler.find((item) => item.id === bildirimId);
      if (!bildirim) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Bildirim bulunamadi."));
        return;
      }

      if (!assertMockBildirimOwnership(bildirim)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu islem icin yetkiniz yok."));
        return;
      }

      const state = bildirim.state.toUpperCase();
      if (state === "IPTAL") {
        await fulfillJson(route, 200, okBody(bildirim));
        return;
      }
      if (!isMockBildirimEditableState(state)) {
        await fulfillJson(route, 409, errorBody("CONFLICT", "Bu durumdaki bildirim iptal edilemez."));
        return;
      }

      bildirim.state = "IPTAL";
      bildirim.updated_by = mockUserId;
      await fulfillJson(route, 200, okBody(bildirim));
      return;
    }

    if (path === "/api/referans/departmanlar" && method === "POST") {
      const payload = request.postDataJSON() as { ad?: string };
      const ad = (payload.ad ?? "").trim();
      if (!ad) {
        await fulfillJson(route, 400, errorBody("DEPARTMAN_NAME_REQUIRED", "Departman adı zorunludur."));
        return;
      }

      const existing = departmanOptions.find((item) => item.ad.toLocaleLowerCase("tr-TR") === ad.toLocaleLowerCase("tr-TR"));
      if (existing) {
        await fulfillJson(route, 200, okBody(existing));
        return;
      }

      const created = {
        id: ++departmanIdCounter,
        ad
      };
      departmanOptions.push(created);
      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.startsWith("/api/referans/") && method === "GET") {
      if (path === "/api/referans/departmanlar") {
        await fulfillJson(route, 200, okBody(departmanOptions));
        return;
      }

      if (path === "/api/referans/gorevler") {
        await fulfillJson(route, 200, okBody(gorevAdlari));
        return;
      }

      if (path === "/api/referans/personel-tipleri") {
        await fulfillJson(
          route,
          200,
          okBody([
            { id: 1, ad: "Tam Zamanlı" },
            { id: 2, ad: "Yarı Zamanlı" }
          ])
        );
        return;
      }

      if (path === "/api/referans/surec-turleri") {
        await fulfillJson(
          route,
          200,
          okBody([
            { key: "IZIN", label: "İzin" },
            { key: "RAPOR", label: "Rapor" },
            { key: "IS_KAZASI", label: "İş Kazası" },
            { key: "DEVAMSIZLIK", label: "Devamsızlık" },
            { key: "TESVIK", label: "Teşvik" },
            { key: "BELGE", label: "Belge / Sertifika" },
            { key: "ISTEN_AYRILMA", label: "İşten Ayrılma" }
          ])
        );
        return;
      }

      if (path === "/api/referans/bagli-amirler") {
        await fulfillJson(route, 200, okBody(bagliAmirReferanslari.map(({ id, ad }) => ({ id, ad }))));
        return;
      }

      if (path === "/api/referans/bildirim-turleri") {
        await fulfillJson(
          route,
          200,
          okBody([
            { key: "GEC_GELDI", label: "Geç Geldi" },
            { key: "GELMEDI", label: "Gelmedi" },
            { key: "IZINLI_GELMEDI", label: "İzinli Gelmedi" },
            { key: "IZINSIZ_GELMEDI", label: "İzinsiz Gelmedi" },
            { key: "DEVAMSIZLIK", label: "Devamsızlık" },
            { key: "RAPORLU", label: "Raporlu" },
            { key: "DIGER", label: "Diğer" }
          ])
        );
        return;
      }

      if (path === "/api/referans/ucret-tipleri") {
        await fulfillJson(route, 200, okBody(ucretTipiReferans));
        return;
      }

      if (path === "/api/referans/prim-kurallari") {
        await fulfillJson(route, 200, okBody(primKuraliReferans));
        return;
      }

      await fulfillJson(route, 200, okBody([]));
      return;
    }

    if (path.startsWith("/api/gunluk-puantaj/") && method === "GET") {
      if (await denyUnlessRolePermission(route, "puantaj.view")) {
        return;
      }

      const segments = path.split("/");
      const personelId = Number.parseInt(segments[3] ?? "0", 10);
      const tarih = decodeURIComponent(segments[4] ?? "");
      const subeScope = getRequestSubeScope(request, url);
      const personel = personeller.find((item) => item.id === personelId);

      if (personel && subeScope !== null && personel.sube_id !== subeScope) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE));
        return;
      }

      const mevcutKayit =
        puantajKayitlari.find((item) => item.personel_id === personelId && item.tarih === tarih) ?? null;

      await fulfillJson(
        route,
        200,
        okBody(
          mevcutKayit ?? {
            personel_id: personelId,
            tarih,
            gun_tipi: "Normal_Is_Gunu",
            hareket_durumu: "Geldi",
            hesap_etkisi: "Tam_Yevmiye_Ver",
            giris_saati: "08:30",
            cikis_saati: "18:00",
            gercek_mola_dakika: 60,
            hesaplanan_mola_dakika: 60,
            net_calisma_suresi_dakika: 510,
            gunluk_brut_sure_dakika: 570,
            hafta_tatili_hak_kazandi_mi: true,
            state: "HESAPLANDI",
            compliance_uyarilari: []
          }
        )
      );
      return;
    }

    if (path.startsWith("/api/gunluk-puantaj/") && method === "PUT") {
      const segments = path.split("/");
      const personelId = Number.parseInt(segments[3] ?? "0", 10);
      const tarih = decodeURIComponent(segments[4] ?? "");
      const subeScope = getRequestSubeScope(request, url);
      const personel = personeller.find((item) => item.id === personelId);

      if (personel && subeScope !== null && personel.sube_id !== subeScope) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", SUBE_SCOPE_MISMATCH_MESSAGE));
        return;
      }

      const payload = request.postDataJSON() as {
        gun_tipi?: GunlukPuantaj["gun_tipi"];
        hareket_durumu?: GunlukPuantaj["hareket_durumu"];
        dayanak?: GunlukPuantaj["dayanak"];
        durumu_bildirdi_mi?: boolean | null;
        durum_bildirim_aciklamasi?: string | null;
        beklenen_giris_saati?: string;
        beklenen_cikis_saati?: string;
        giris_saati?: string;
        cikis_saati?: string;
        gercek_mola_dakika?: number;
        kontrol_durumu?: GunlukPuantaj["kontrol_durumu"];
      };

      if (await denyUnlessPuantajUpsertPermission(route, payload)) {
        return;
      }

      const mevcutIndex = puantajKayitlari.findIndex((item) => item.personel_id === personelId && item.tarih === tarih);
      const oncekiKayit =
        mevcutIndex >= 0
          ? puantajKayitlari[mevcutIndex]
          : {
              personel_id: personelId,
              tarih,
              gun_tipi: "Normal_Is_Gunu" as const,
              hareket_durumu: "Geldi" as const,
              hesap_etkisi: "Tam_Yevmiye_Ver" as const,
              hafta_tatili_hak_kazandi_mi: true,
              state: "HESAPLANDI",
              kontrol_durumu: "BEKLIYOR" as const,
              compliance_uyarilari: []
            };
      const updated = {
        ...oncekiKayit,
        gun_tipi: payload.gun_tipi ?? oncekiKayit.gun_tipi ?? "Normal_Is_Gunu",
        hareket_durumu: payload.hareket_durumu ?? oncekiKayit.hareket_durumu ?? "Geldi",
        dayanak: payload.dayanak ?? oncekiKayit.dayanak,
        durumu_bildirdi_mi:
          "durumu_bildirdi_mi" in payload
            ? payload.durumu_bildirdi_mi ?? undefined
            : oncekiKayit.durumu_bildirdi_mi,
        durum_bildirim_aciklamasi:
          payload.durumu_bildirdi_mi === true
            ? payload.durum_bildirim_aciklamasi ?? undefined
            : "durumu_bildirdi_mi" in payload
              ? undefined
              : oncekiKayit.durum_bildirim_aciklamasi,
        beklenen_giris_saati: payload.beklenen_giris_saati ?? oncekiKayit.beklenen_giris_saati,
        beklenen_cikis_saati: payload.beklenen_cikis_saati ?? oncekiKayit.beklenen_cikis_saati,
        giris_saati: payload.giris_saati ?? oncekiKayit.giris_saati ?? "08:30",
        cikis_saati: payload.cikis_saati ?? oncekiKayit.cikis_saati ?? "18:00",
        gercek_mola_dakika: payload.gercek_mola_dakika ?? oncekiKayit.gercek_mola_dakika ?? 60,
        hesaplanan_mola_dakika: payload.gercek_mola_dakika ?? oncekiKayit.hesaplanan_mola_dakika ?? 60,
        net_calisma_suresi_dakika: oncekiKayit.net_calisma_suresi_dakika ?? 510,
        gunluk_brut_sure_dakika: oncekiKayit.gunluk_brut_sure_dakika ?? 570,
        kontrol_durumu: payload.kontrol_durumu ?? oncekiKayit.kontrol_durumu ?? "BEKLIYOR"
      };

      if (mevcutIndex >= 0) {
        puantajKayitlari[mevcutIndex] = updated;
      } else {
        puantajKayitlari.push(updated);
      }

      await fulfillJson(
        route,
        200,
        okBody(updated)
      );
      return;
    }

    if (path === "/api/puantaj/bildirim-etki-adaylari/ozet" && method === "GET") {
      if (await denyUnlessRolePermission(route, "puantaj.bildirim_etki.view")) {
        return;
      }
      const gyId = Number.parseInt(url.searchParams.get("genel_yonetici_bildirim_onayi_id") ?? "", 10);
      if (!gyId) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Genel yonetici bildirim onayi secilmelidir.", "genel_yonetici_bildirim_onayi_id"));
        return;
      }
      const subeScope = getRequestSubeScope(request, url);
      const scopedItems = puantajEtkiAdaylari.filter((item) => item.genel_yonetici_bildirim_onayi_id === gyId);
      if (subeScope !== null) {
        const allowed = scopedItems.every((item) => item.sube_id === subeScope);
        if (!allowed && scopedItems.length > 0) {
          await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
          return;
        }
      }
      const visible = subeScope === null ? scopedItems : scopedItems.filter((item) => item.sube_id === subeScope);
      const sample = visible[0] ?? scopedItems[0];
      await fulfillJson(route, 200, okBody({
        context: {
          genel_yonetici_bildirim_onayi_id: gyId,
          ay: sample?.ay ?? "2026-06",
          ay_baslangic: "2026-06-01",
          ay_bitis: "2026-06-30",
          sube_id: sample?.sube_id ?? subeScope ?? 1,
          birim_amiri_user_id: sample?.birim_amiri_user_id ?? 1,
          aylik_bildirim_onayi_id: sample?.aylik_bildirim_onayi_id ?? 2,
          onaylandi_at: "2026-06-09 12:00:00"
        },
        genel_yonetici_bildirim_onayi: {
          id: gyId,
          state: "TAMAMLANDI",
          onaylandi_at: "2026-06-09 12:00:00"
        },
        kaynak_bildirim_sayisi: 4,
        aday_sayilari: countMockPuantajEtkiAdaylari(visible),
        muhur_durumu: "ACIK",
        hazirlanabilir_mi: false,
        blok_nedeni: null
      }));
      return;
    }

    if (path === "/api/puantaj/bildirim-etki-adaylari" && method === "GET") {
      if (await denyUnlessRolePermission(route, "puantaj.bildirim_etki.view")) {
        return;
      }
      const ay = url.searchParams.get("ay") ?? "";
      if (!/^\d{4}-\d{2}$/.test(ay)) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Ay parametresi YYYY-MM formatinda olmalidir.", "ay"));
        return;
      }
      const amirId = Number.parseInt(url.searchParams.get("birim_amiri_user_id") ?? "", 10);
      if (!amirId) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Birim amiri secimi zorunludur.", "birim_amiri_user_id"));
        return;
      }
      const subeScope = getRequestSubeScope(request, url) ?? mockUserSubeIds[0] ?? 1;
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeScope)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }
      const expectedAmirId = subeScope === 1 ? 1 : subeScope === 2 ? 4 : null;
      if (expectedAmirId !== null && amirId !== expectedAmirId) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Secilen birim amiri aktif sube ile eslesmiyor."));
        return;
      }
      let items = puantajEtkiAdaylari.filter(
        (item) => item.ay === ay && item.sube_id === subeScope && item.birim_amiri_user_id === amirId
      );
      const personelId = Number.parseInt(url.searchParams.get("personel_id") ?? "", 10);
      if (Number.isFinite(personelId)) {
        items = items.filter((item) => item.personel_id === personelId);
      }
      const state = (url.searchParams.get("state") ?? "").trim().toUpperCase();
      if (state) {
        items = items.filter((item) => item.state === state);
      }
      const page = Math.max(1, Number.parseInt(url.searchParams.get("page") ?? "1", 10) || 1);
      const limit = Math.max(1, Number.parseInt(url.searchParams.get("limit") ?? "20", 10) || 20);
      const total = items.length;
      const offset = (page - 1) * limit;
      const pageItems = items.slice(offset, offset + limit).map((item) => mapMockPuantajEtkiListRow(item));
      await fulfillJson(route, 200, okBody({ items: pageItems }, {
        page,
        limit,
        total,
        total_pages: Math.max(1, Math.ceil(total / limit)),
        has_next_page: page * limit < total,
        has_prev_page: page > 1
      }));
      return;
    }

    if (path.match(/^\/api\/puantaj\/bildirim-etki-adaylari\/\d+$/) && method === "GET") {
      if (await denyUnlessRolePermission(route, "puantaj.bildirim_etki.view")) {
        return;
      }
      const adayId = Number.parseInt(path.split("/")[4] ?? "", 10);
      const item = puantajEtkiAdaylari.find((entry) => entry.id === adayId);
      if (!item) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Puantaj etki adayi bulunamadi."));
        return;
      }
      const subeScope = getRequestSubeScope(request, url);
      if (subeScope !== null && subeScope !== item.sube_id) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(item.sube_id)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }
      await fulfillJson(route, 200, okBody(mapMockPuantajEtkiDetailRow(item)));
      return;
    }

    if (path.match(/^\/api\/puantaj\/bildirim-etki-adaylari\/\d+\/cakisma-coz$/) && method === "POST") {
      const authHeader = request.headers()["authorization"] ?? request.headers()["Authorization"] ?? "";
      if (!authHeader.startsWith("Bearer ")) {
        await fulfillJson(route, 401, errorBody("UNAUTHORIZED", "Oturum gerekli."));
        return;
      }
      if (await denyUnlessRolePermission(route, "puantaj.bildirim_etki.resolve_conflict")) {
        return;
      }

      const adayId = Number.parseInt(path.split("/")[4] ?? "", 10);
      if (!adayId) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Puantaj etki adayi bulunamadi."));
        return;
      }

      const itemIndex = puantajEtkiAdaylari.findIndex((entry) => entry.id === adayId);
      const item = itemIndex >= 0 ? puantajEtkiAdaylari[itemIndex] : null;
      if (!item) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Puantaj etki adayi bulunamadi."));
        return;
      }

      const adaySubeId = item.sube_id;
      const subeScope = getRequestSubeScope(request, url);
      if (subeScope !== null && subeScope !== adaySubeId) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(adaySubeId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }

      const payload = request.postDataJSON() as Record<string, unknown>;
      const allowedKeys = new Set([
        "expected_state",
        "karar_turu",
        "gerekce",
        "expected_puantaj_id",
        "expected_puantaj_hash"
      ]);
      for (const key of Object.keys(payload)) {
        if (!allowedKeys.has(key)) {
          await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Desteklenmeyen istek alani.", key));
          return;
        }
      }

      const expectedState = typeof payload.expected_state === "string" ? payload.expected_state.trim() : "";
      const kararTuru = typeof payload.karar_turu === "string" ? payload.karar_turu.trim() : "";
      const gerekce = typeof payload.gerekce === "string" ? payload.gerekce.trim() : "";
      const expectedPuantajId = Number(payload.expected_puantaj_id ?? 0);
      const expectedPuantajHash = typeof payload.expected_puantaj_hash === "string" ? payload.expected_puantaj_hash.trim().toLowerCase() : "";

      if (expectedState !== "HAZIR" && expectedState !== "INCELEME_GEREKLI") {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Beklenen durum gecersiz.", "expected_state"));
        return;
      }
      if (kararTuru !== "MEVCUT_PUANTAJI_KORU" && kararTuru !== "ADAY_ETKISIYLE_REVIZE_ET") {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Desteklenmeyen karar turu.", "karar_turu"));
        return;
      }
      if (gerekce.length < 5 || [...gerekce].length > 500) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Gerekce 5-500 karakter olmalidir.", "gerekce"));
        return;
      }
      if (!/^[a-f0-9]{64}$/.test(expectedPuantajHash)) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Puantaj hash gecersiz.", "expected_puantaj_hash"));
        return;
      }

      if (gerekce === "E2E puantaj stale tetikleyici") {
        await fulfillJson(route, 409, errorBody("PUANTAJ_STALE", "Mevcut puantaj kaydi degismis. Listeyi yenileyip tekrar deneyin."));
        return;
      }

      const puantaj = getMockConflictPuantaj(item);
      if (!puantaj) {
        await fulfillJson(route, 409, errorBody("PUANTAJ_ARTIK_YOK", "Mevcut puantaj kaydi bulunamadi."));
        return;
      }

      const classification = classifyMockConflict(item, puantaj);
      const requestHash = computeMockConflictRequestHash({
        aday_id: adayId,
        expected_state: expectedState,
        karar_turu: kararTuru,
        gerekce,
        expected_puantaj_id: expectedPuantajId,
        expected_puantaj_hash: expectedPuantajHash
      });
      const existingResolution = mockConflictResolutions.get(adayId);
      if (existingResolution) {
        if (existingResolution.request_hash === requestHash) {
          await fulfillJson(route, 200, okBody({
            aday: mapMockPuantajEtkiDetailRow(item),
            puantaj: mockPuantajConcurrencyPayload(puantaj),
            conflict_class: existingResolution.conflict_class,
            karar_turu: existingResolution.karar_turu,
            cakisma_cozum: existingResolution,
            onceki_ozet: null,
            sonraki_ozet: null,
            idempotent: true
          }));
          return;
        }
        await fulfillJson(route, 409, errorBody("REVISION_DECISION_CONFLICT", "Bu aday icin daha once farkli bir cakisma karari verilmis."));
        return;
      }

      if (item.state !== "HAZIR" && item.state !== "INCELEME_GEREKLI") {
        await fulfillJson(route, 409, errorBody("STATE_CONFLICT", "Puantaj etki adayi cakisma cozumu icin uygun degil."));
        return;
      }
      if (expectedState !== item.state) {
        await fulfillJson(route, 409, errorBody("STATE_STALE", "Puantaj etki adayi durumu degismis. Listeyi yenileyip tekrar deneyin."));
        return;
      }
      if (expectedPuantajId !== puantaj.id) {
        await fulfillJson(route, 409, errorBody("PUANTAJ_STALE", "Mevcut puantaj kaydi degismis. Listeyi yenileyip tekrar deneyin."));
        return;
      }
      if (expectedPuantajHash !== computeMockPuantajHash(puantaj)) {
        await fulfillJson(route, 409, errorBody("PUANTAJ_STALE", "Mevcut puantaj kaydi degismis. Listeyi yenileyip tekrar deneyin."));
        return;
      }

      if (classification.class === "MUHURLU_PUANTAJ") {
        await fulfillJson(route, 409, errorBody("PERIOD_LOCKED", "Muhurlu puantaj kaydi revize edilemez."));
        return;
      }
      if (kararTuru === "ADAY_ETKISIYLE_REVIZE_ET" && classification.class === "RESMI_SUREC_DAYANAK") {
        await fulfillJson(route, 409, errorBody("PUANTAJ_SOURCE_PROTECTED", "Resmi surec dayanakli puantaj bildirim etkisiyle revize edilemez."));
        return;
      }

      const kararZamani = "2026-07-12 16:00:00";
      const resolution = {
        id: adayId + 1000,
        aday_id: adayId,
        puantaj_id: puantaj.id,
        conflict_class: classification.class,
        karar_turu: kararTuru,
        gerekce,
        request_hash: requestHash,
        sonuc_hash: `sonuc-${requestHash.slice(0, 16)}`,
        karar_veren_user_id: mockUserId,
        karar_zamani: kararZamani
      };
      mockConflictResolutions.set(adayId, resolution);

      if (kararTuru === "MEVCUT_PUANTAJI_KORU") {
        puantajEtkiAdaylari[itemIndex] = {
          ...item,
          state: "YOK_SAYILDI",
          uygulama_modu: "CAKISMA_COZUM",
          karar_veren_user_id: mockUserId,
          karar_zamani: kararZamani,
          karar_gerekcesi: gerekce,
          uygulanan_puantaj_id: null,
          updated_at: kararZamani
        };
      } else {
        const revised: MockConflictPuantajRow = {
          ...puantaj,
          hareket_durumu: "Gelmedi",
          dayanak: "Yok_Izinsiz",
          hesap_etkisi: "Yevmiye_Kes",
          gec_kalma_dakika: null,
          erken_cikis_dakika: null,
          net_calisma_suresi_dakika: null,
          gunluk_brut_sure_dakika: null,
          hesaplanan_mola_dakika: null,
          hafta_tatili_hak_kazandi_mi: null,
          kontrol_durumu: "BEKLIYOR",
          kaynak: "BILDIRIM_ETKI_REVIZYON",
          durumu_bildirdi_mi: 1,
          durum_bildirim_aciklamasi: item.bildirim_aciklama,
          updated_at: kararZamani
        };
        mockConflictPuantajByKey.set(`${item.personel_id}:${item.tarih}`, revised);
        puantajEtkiAdaylari[itemIndex] = {
          ...item,
          state: "UYGULANDI",
          uygulama_modu: "CAKISMA_COZUM",
          karar_veren_user_id: mockUserId,
          karar_zamani: kararZamani,
          karar_gerekcesi: gerekce,
          uygulanan_puantaj_id: puantaj.id,
          uygulama_hash: resolution.sonuc_hash,
          updated_at: kararZamani
        };
      }

      const updatedItem = puantajEtkiAdaylari[itemIndex];
      const updatedPuantaj = getMockConflictPuantaj(updatedItem)!;
      await fulfillJson(route, 200, okBody({
        aday: mapMockPuantajEtkiDetailRow(updatedItem),
        puantaj: mockPuantajConcurrencyPayload(updatedPuantaj),
        conflict_class: classification.class,
        karar_turu: kararTuru,
        cakisma_cozum: resolution,
        onceki_ozet: { puantaj: mockPuantajConcurrencyPayload(puantaj) },
        sonraki_ozet: { puantaj: mockPuantajConcurrencyPayload(updatedPuantaj) },
        idempotent: false
      }));
      return;
    }

    if (path.match(/^\/api\/puantaj\/bildirim-etki-adaylari\/\d+\/yok-say$/) && method === "POST") {
      const authHeader = request.headers()["authorization"] ?? request.headers()["Authorization"] ?? "";
      if (!authHeader.startsWith("Bearer ")) {
        await fulfillJson(route, 401, errorBody("UNAUTHORIZED", "Oturum gerekli."));
        return;
      }
      if (await denyUnlessRolePermission(route, "puantaj.bildirim_etki.dismiss")) {
        return;
      }

      const adayId = Number.parseInt(path.split("/")[4] ?? "", 10);
      if (!adayId) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Puantaj etki adayi bulunamadi."));
        return;
      }

      const itemIndex = puantajEtkiAdaylari.findIndex((entry) => entry.id === adayId);
      const item = itemIndex >= 0 ? puantajEtkiAdaylari[itemIndex] : null;
      if (!item) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Puantaj etki adayi bulunamadi."));
        return;
      }

      const adaySubeId = item.sube_id;
      const subeScope = getRequestSubeScope(request, url);
      if (subeScope !== null && subeScope !== adaySubeId) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(adaySubeId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }

      const payload = request.postDataJSON() as { expected_state?: string; gerekce?: string };
      const gerekce = typeof payload.gerekce === "string" ? payload.gerekce.trim() : "";
      if (!payload.expected_state || gerekce.length < 5) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Yok sayma gerekcesi en az 5 karakter olmalidir.", "gerekce"));
        return;
      }
      if ([...gerekce].length > 500) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Yok sayma gerekcesi en fazla 500 karakter olabilir.", "gerekce"));
        return;
      }

      if (gerekce === "E2E state stale tetikleyici") {
        await fulfillJson(
          route,
          409,
          errorBody("STATE_STALE", "Puantaj etki adayi durumu degismis. Listeyi yenileyip tekrar deneyin.")
        );
        return;
      }

      if (item.state === "YOK_SAYILDI") {
        if ((item.karar_gerekcesi ?? "").trim() === gerekce) {
          await fulfillJson(route, 200, okBody({
            id: adayId,
            state: "YOK_SAYILDI",
            karar_veren_user_id: item.karar_veren_user_id,
            karar_zamani: item.karar_zamani,
            karar_gerekcesi: item.karar_gerekcesi,
            uygulanan_puantaj_id: item.uygulanan_puantaj_id,
            idempotent: true
          }));
          return;
        }
        await fulfillJson(route, 409, errorBody("STATE_CONFLICT", "Puantaj etki adayi daha once farkli bir gerekceyle yok sayilmis."));
        return;
      }

      if (item.state === "UYGULANDI") {
        await fulfillJson(route, 409, errorBody("STATE_CONFLICT", "Uygulanmis puantaj etki adayi yok sayilamaz."));
        return;
      }

      if (item.state !== "HAZIR" && item.state !== "INCELEME_GEREKLI") {
        await fulfillJson(route, 409, errorBody("STATE_CONFLICT", "Puantaj etki adayi yok sayilamaz."));
        return;
      }

      if (payload.expected_state !== item.state) {
        await fulfillJson(route, 409, errorBody("STATE_STALE", "Puantaj etki adayi durumu degismis. Listeyi yenileyip tekrar deneyin."));
        return;
      }

      const updated = {
        ...item,
        state: "YOK_SAYILDI" as const,
        karar_veren_user_id: mockUserId,
        karar_zamani: "2026-07-12 15:30:00",
        karar_gerekcesi: gerekce,
        updated_at: "2026-07-12 15:30:00"
      };
      puantajEtkiAdaylari[itemIndex] = updated;

      await fulfillJson(
        route,
        200,
        okBody({
          id: adayId,
          state: "YOK_SAYILDI",
          karar_veren_user_id: mockUserId,
          karar_zamani: "2026-07-12 15:30:00",
          karar_gerekcesi: gerekce,
          uygulanan_puantaj_id: null,
          idempotent: false
        })
      );
      return;
    }

    if (path.match(/^\/api\/puantaj\/bildirim-etki-adaylari\/\d+\/manuel-uygula$/) && method === "POST") {
      const authHeader = request.headers()["authorization"] ?? request.headers()["Authorization"] ?? "";
      if (!authHeader.startsWith("Bearer ")) {
        await fulfillJson(route, 401, errorBody("UNAUTHORIZED", "Oturum gerekli."));
        return;
      }
      if (await denyUnlessRolePermission(route, "puantaj.bildirim_etki.apply")) {
        return;
      }

      const adayId = Number.parseInt(path.split("/")[4] ?? "", 10);
      if (!adayId) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Puantaj etki adayi bulunamadi."));
        return;
      }

      const itemIndex = puantajEtkiAdaylari.findIndex((entry) => entry.id === adayId);
      const item = itemIndex >= 0 ? puantajEtkiAdaylari[itemIndex] : null;
      if (!item) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Puantaj etki adayi bulunamadi."));
        return;
      }

      const adaySubeId = item.sube_id;
      const subeScope = getRequestSubeScope(request, url);
      if (subeScope !== null && subeScope !== adaySubeId) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(adaySubeId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }

      const payload = request.postDataJSON() as {
        expected_state?: string;
        karar_etki_turu?: string;
        etki_miktari?: number | null;
        gerekce?: string;
        geldi_mi?: unknown;
        state?: unknown;
      };

      if (payload.geldi_mi !== undefined || payload.state !== undefined) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Bilinmeyen alan kabul edilmez."));
        return;
      }

      if (payload.expected_state !== "INCELEME_GEREKLI") {
        await fulfillJson(
          route,
          422,
          errorBody("VALIDATION_ERROR", "Beklenen durum INCELEME_GEREKLI olmalidir.", "expected_state")
        );
        return;
      }

      const allowedKararTurleri = new Set([
        "DEVAMSIZLIK_GUN",
        "GEC_KALMA_DAKIKA",
        "ERKEN_CIKIS_DAKIKA",
        "GOREVDE_CALISILMIS_GUN"
      ]);
      const kararTuru = typeof payload.karar_etki_turu === "string" ? payload.karar_etki_turu.trim() : "";
      if (!allowedKararTurleri.has(kararTuru)) {
        await fulfillJson(
          route,
          422,
          errorBody("VALIDATION_ERROR", "Desteklenmeyen manuel karar turu.", "karar_etki_turu")
        );
        return;
      }

      const gerekce = typeof payload.gerekce === "string" ? payload.gerekce.trim() : "";
      if (gerekce.length < 5) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Karar gerekcesi en az 5 karakter olmalidir.", "gerekce"));
        return;
      }
      if ([...gerekce].length > 500) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Karar gerekcesi en fazla 500 karakter olabilir.", "gerekce"));
        return;
      }

      const requiresMiktar = kararTuru === "GEC_KALMA_DAKIKA" || kararTuru === "ERKEN_CIKIS_DAKIKA";
      const miktarRaw = payload.etki_miktari;
      let miktar: number | null = null;
      if (requiresMiktar) {
        if (typeof miktarRaw !== "number" || !Number.isInteger(miktarRaw) || miktarRaw <= 0 || miktarRaw > 1440) {
          await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Dakika degeri 1-1440 arasinda olmalidir.", "etki_miktari"));
          return;
        }
        miktar = miktarRaw;
      } else if (miktarRaw !== null && miktarRaw !== undefined) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "Bu karar turu icin miktar gonderilmemelidir.", "etki_miktari"));
        return;
      }

      if (gerekce === "E2E source integrity tetikleyici") {
        await fulfillJson(route, 409, errorBody("SOURCE_INTEGRITY_FAILED", "Aday kaynak verisi dogrulanamadi."));
        return;
      }

      if (item.state === "UYGULANDI") {
        if (item.uygulama_modu === "MANUEL") {
          const sameDecision =
            item.manuel_karar_turu === kararTuru &&
            item.manuel_karar_miktari === miktar &&
            (item.karar_gerekcesi ?? "").trim() === gerekce &&
            item.uygulanan_puantaj_id &&
            item.uygulama_hash &&
            item.sonraki_puantaj_snapshot;
          if (sameDecision) {
            await fulfillJson(route, 200, okBody({
              id: adayId,
              state: "UYGULANDI",
              uygulama_modu: "MANUEL",
              manuel_karar_turu: item.manuel_karar_turu,
              manuel_karar_miktari: item.manuel_karar_miktari,
              karar_veren_user_id: item.karar_veren_user_id,
              karar_zamani: item.karar_zamani,
              karar_gerekcesi: item.karar_gerekcesi,
              uygulanan_puantaj_id: item.uygulanan_puantaj_id,
              onceki_puantaj_snapshot: item.onceki_puantaj_snapshot,
              sonraki_puantaj_snapshot: item.sonraki_puantaj_snapshot,
              uygulama_hash: item.uygulama_hash,
              idempotent: true
            }));
            return;
          }
          await fulfillJson(route, 409, errorBody("MANUAL_DECISION_CONFLICT", "Bu aday daha once farkli bir manuel kararla uygulanmis."));
          return;
        }
        await fulfillJson(route, 409, errorBody("STATE_CONFLICT", "Otomatik uygulanmis aday manuel endpoint ile degistirilemez."));
        return;
      }

      if (item.state !== "INCELEME_GEREKLI") {
        await fulfillJson(route, 409, errorBody("STATE_CONFLICT", "Puantaj etki adayi manuel uygulanamaz."));
        return;
      }

      if (payload.expected_state !== item.state) {
        await fulfillJson(route, 409, errorBody("STATE_STALE", "Puantaj etki adayi durumu degismis. Listeyi yenileyip tekrar deneyin."));
        return;
      }

      if (item.tarih === "2026-06-01") {
        await fulfillJson(route, 409, errorBody("PERIOD_LOCKED", "Bu donem muhurlenmis, manuel karar uygulanamaz."));
        return;
      }

      if (item.mevcut_puantaj_id != null) {
        await fulfillJson(route, 409, errorBody("PUANTAJ_OLUSTU", "Bu personel ve tarih icin puantaj kaydi zaten olusmus."));
        return;
      }

      const puantajId = 9100 + adayId;
      const sonraki = {
        schema_version: "S74_MANUAL_APPLY_V1",
        aday_id: adayId,
        uygulama_modu: "MANUEL",
        manuel_karar_turu: kararTuru,
        manuel_karar_miktari: miktar,
        karar_gerekcesi: gerekce,
        puantaj: {
          id: puantajId,
          personel_id: item.personel_id,
          tarih: item.tarih,
          state: "ACIK",
          kaynak: "BILDIRIM_ETKI_ADAYI"
        }
      };
      const hash = `mock-manual-apply-hash-${adayId}-${kararTuru}-${miktar ?? "null"}-${gerekce.length}`;
      const updated = {
        ...item,
        state: "UYGULANDI" as const,
        uygulama_modu: "MANUEL" as const,
        manuel_karar_turu: kararTuru,
        manuel_karar_miktari: miktar,
        karar_veren_user_id: mockUserId,
        karar_zamani: "2026-07-15 12:00:00",
        karar_gerekcesi: gerekce,
        uygulanan_puantaj_id: puantajId,
        onceki_puantaj_snapshot: null,
        sonraki_puantaj_snapshot: sonraki,
        uygulama_hash: hash,
        updated_at: "2026-07-15 12:00:00"
      };
      puantajEtkiAdaylari[itemIndex] = updated;

      await fulfillJson(route, 200, okBody({
        id: adayId,
        state: "UYGULANDI",
        uygulama_modu: "MANUEL",
        manuel_karar_turu: kararTuru,
        manuel_karar_miktari: miktar,
        karar_veren_user_id: mockUserId,
        karar_zamani: "2026-07-15 12:00:00",
        karar_gerekcesi: gerekce,
        uygulanan_puantaj_id: puantajId,
        onceki_puantaj_snapshot: null,
        sonraki_puantaj_snapshot: sonraki,
        uygulama_hash: hash,
        idempotent: false
      }));
      return;
    }

    if (path.match(/^\/api\/puantaj\/bildirim-etki-adaylari\/\d+\/uygula$/) && method === "POST") {
      const authHeader = request.headers()["authorization"] ?? request.headers()["Authorization"] ?? "";
      if (!authHeader.startsWith("Bearer ")) {
        await fulfillJson(route, 401, errorBody("UNAUTHORIZED", "Oturum gerekli."));
        return;
      }
      if (await denyUnlessRolePermission(route, "puantaj.bildirim_etki.apply")) {
        return;
      }

      const adayId = Number.parseInt(path.split("/")[4] ?? "", 10);
      if (!adayId) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Puantaj etki adayi bulunamadi."));
        return;
      }

      const itemIndex = puantajEtkiAdaylari.findIndex((entry) => entry.id === adayId);
      const item = itemIndex >= 0 ? puantajEtkiAdaylari[itemIndex] : null;
      if (!item) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Puantaj etki adayi bulunamadi."));
        return;
      }

      const adaySubeId = item.sube_id;
      const subeScope = getRequestSubeScope(request, url);
      if (subeScope !== null && subeScope !== adaySubeId) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }
      if (mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(adaySubeId)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor."));
        return;
      }

      const payload = request.postDataJSON() as { expected_state?: string };
      if (payload.expected_state !== "HAZIR") {
        await fulfillJson(
          route,
          422,
          errorBody("VALIDATION_ERROR", "Beklenen durum HAZIR olmalidir.", "expected_state")
        );
        return;
      }

      if (item.state === "UYGULANDI") {
        if (
          item.uygulanan_puantaj_id &&
          item.uygulama_hash &&
          item.sonraki_puantaj_snapshot
        ) {
          await fulfillJson(
            route,
            200,
            okBody({
              id: adayId,
              state: "UYGULANDI",
              karar_veren_user_id: item.karar_veren_user_id,
              karar_zamani: item.karar_zamani,
              uygulanan_puantaj_id: item.uygulanan_puantaj_id,
              onceki_puantaj_snapshot: item.onceki_puantaj_snapshot,
              sonraki_puantaj_snapshot: item.sonraki_puantaj_snapshot,
              uygulama_hash: item.uygulama_hash,
              idempotent: true
            })
          );
          return;
        }
        await fulfillJson(
          route,
          409,
          errorBody("APPLY_INTEGRITY_CONFLICT", "Uygulanmis aday butunlugu bozuk.")
        );
        return;
      }

      if (item.state === "YOK_SAYILDI") {
        await fulfillJson(
          route,
          409,
          errorBody("STATE_CONFLICT", "Yok sayilmis puantaj etki adayi uygulanamaz.")
        );
        return;
      }

      if (item.state === "INCELEME_GEREKLI") {
        await fulfillJson(
          route,
          409,
          errorBody("STATE_CONFLICT", "Inceleme gerekli puantaj etki adayi uygulanamaz.")
        );
        return;
      }

      if (item.state !== "HAZIR") {
        await fulfillJson(route, 409, errorBody("STATE_CONFLICT", "Puantaj etki adayi uygulanamaz."));
        return;
      }

      if (payload.expected_state !== item.state) {
        await fulfillJson(
          route,
          409,
          errorBody("STATE_STALE", "Puantaj etki adayi durumu degismis. Listeyi yenileyip tekrar deneyin.")
        );
        return;
      }

      if (item.conflict_code === "UCRETSIZ_IZIN_MANUEL_INCELEME") {
        await fulfillJson(
          route,
          409,
          errorBody("APPLY_UNSUPPORTED", "Ucretsiz izin veya manuel inceleme adayi otomatik uygulanamaz.")
        );
        return;
      }

      if (item.tarih === "2026-06-01") {
        await fulfillJson(
          route,
          409,
          errorBody("PERIOD_LOCKED", "Bu donem muhurlenmis, puantaj kaydi olusturulamaz.")
        );
        return;
      }

      const puantajId = 9000 + adayId;
      const sonraki = {
        schema_version: "S74_APPLY_V1",
        aday_id: adayId,
        puantaj: {
          id: puantajId,
          personel_id: item.personel_id,
          tarih: item.tarih,
          state: "ACIK",
          gun_tipi: null,
          hareket_durumu: "Gec_Geldi",
          dayanak: "Yok_Izinsiz",
          durumu_bildirdi_mi: true,
          durum_bildirim_aciklamasi: item.bildirim_aciklama,
          hesap_etkisi: "Tam_Yevmiye_Ver",
          gec_kalma_dakika: item.etki_miktari,
          erken_cikis_dakika: null,
          kontrol_durumu: "BEKLIYOR",
          kaynak: "BILDIRIM_ETKI_ADAYI",
          muhur_id: null
        }
      };
      const hash = `mock-apply-hash-${adayId}`;
      const updated = {
        ...item,
        state: "UYGULANDI" as const,
        uygulama_modu: "OTOMATIK" as const,
        manuel_karar_turu: null,
        manuel_karar_miktari: null,
        karar_veren_user_id: mockUserId,
        karar_zamani: "2026-07-14 00:10:00",
        uygulanan_puantaj_id: puantajId,
        onceki_puantaj_snapshot: { schema_version: "S74_APPLY_V1", aday_id: adayId, puantaj: null },
        sonraki_puantaj_snapshot: sonraki,
        uygulama_hash: hash,
        updated_at: "2026-07-14 00:10:00"
      };
      puantajEtkiAdaylari[itemIndex] = updated;

      await fulfillJson(
        route,
        200,
        okBody({
          id: adayId,
          state: "UYGULANDI",
          uygulama_modu: "OTOMATIK",
          manuel_karar_turu: null,
          manuel_karar_miktari: null,
          karar_veren_user_id: mockUserId,
          karar_zamani: "2026-07-14 00:10:00",
          uygulanan_puantaj_id: puantajId,
          onceki_puantaj_snapshot: updated.onceki_puantaj_snapshot,
          sonraki_puantaj_snapshot: updated.sonraki_puantaj_snapshot,
          uygulama_hash: hash,
          idempotent: false
        })
      );
      return;
    }

    if (path === "/api/puantaj/donem-kapanis-preflight" && method === "GET") {
      if (await denyUnlessRolePermission(route, "puantaj.donem_kapanis.view")) {
        return;
      }

      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);
      const yil = Number.parseInt(url.searchParams.get("yil") ?? "", 10) || 2026;
      const ay = Number.parseInt(url.searchParams.get("ay") ?? "", 10) || 6;
      if (!Number.isFinite(subeId) || subeId <= 0) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "sube_id zorunludur.", "sube_id"));
        return;
      }

      const donem = `${yil}-${String(ay).padStart(2, "0")}`;
      const blockers = [
        {
          code: "CANDIDATE_HAZIR_PENDING",
          severity: "BLOCKER",
          domain: "etki_adayi",
          title: "HAZIR etki adayi",
          message: "Uygulanmayi bekleyen HAZIR etki adayi var.",
          count: 1,
          owner_role: "MUHASEBE",
          action_route: "/puantaj",
          action_permission: "puantaj.bildirim_etki.view",
          record_ids: [1],
          metadata: {}
        }
      ];

      await fulfillJson(route, 200, okBody({
        sube: { id: subeId, ad: subeId === 1 ? "Merkez" : `Sube ${subeId}` },
        yil,
        ay,
        donem,
        donem_state: "ACIK",
        muhur_state: "ACIK",
        muhur_id: null,
        kapanabilir_mi: false,
        blocker_count: 1,
        warning_count: 0,
        info_count: 1,
        kategori_sayaclari: { etki_adayi: 1 },
        blockers,
        warnings: [],
        infos: [
          {
            code: "CANDIDATE_APPLIED_COUNT",
            severity: "INFO",
            domain: "etki_adayi",
            title: "Uygulanan aday",
            message: "Uygulanan etki adayi sayisi.",
            count: 1,
            owner_role: "MUHASEBE",
            action_route: "/puantaj",
            action_permission: "puantaj.bildirim_etki.view",
            record_ids: [],
            metadata: {}
          }
        ],
        candidate_state_counts: { HAZIR: 1, INCELEME_GEREKLI: 1, UYGULANDI: 1, YOK_SAYILDI: 0 },
        notification_chain_counts: {},
        puantaj_counts: { kontrol_bekleyen: 1 },
        finance_readiness: {},
        preflight_hash: "e2e-preflight-hash",
        schema_version: "S76_PERIOD_CLOSE_PREFLIGHT_V1",
        generated_at: new Date().toISOString()
      }));
      return;
    }

    if (path === "/api/puantaj/donem-kapanis-preflight/items" && method === "GET") {
      if (await denyUnlessRolePermission(route, "puantaj.donem_kapanis.view")) {
        return;
      }

      const code = url.searchParams.get("code") ?? "";
      const items =
        code === "CANDIDATE_HAZIR_PENDING"
          ? [
              {
                record_id: 1,
                personel_id: 1,
                tarih: "2026-06-03",
                state: "HAZIR",
                detail: "Gec kalma etkisi",
                severity: "BLOCKER"
              }
            ]
          : [];

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: {
            items,
            page: 1,
            limit: 20,
            total: items.length,
            total_pages: 1,
            has_next_page: false,
            has_prev_page: false
          },
          meta: { page: 1, limit: 20, total: items.length, total_pages: 1 },
          errors: []
        })
      );
      return;
    }

    if (path === "/api/puantaj/donem-kapanis-preflight/export.csv" && method === "GET") {
      if (await denyUnlessRolePermission(route, "puantaj.donem_kapanis.export")) {
        return;
      }

      await route.fulfill({
        status: 200,
        contentType: "text/csv; charset=utf-8",
        body: "code,severity,domain,title\nCANDIDATE_HAZIR_PENDING,BLOCKER,etki_adayi,HAZIR etki adayi\n"
      });
      return;
    }

    if (path === "/api/puantaj/donem-kapanis-auditleri" && method === "GET") {
      if (await denyUnlessRolePermission(route, "puantaj.donem_kapanis.view")) {
        return;
      }

      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10) || 1;
      const yil = Number.parseInt(url.searchParams.get("yil") ?? "", 10) || 2026;
      const ay = Number.parseInt(url.searchParams.get("ay") ?? "", 10) || 6;

      await fulfillJson(route, 200, okBody({
        items: [
          {
            id: 1,
            sube_id: subeId,
            yil,
            ay,
            action: "CLOSE_ATTEMPT_BLOCKED",
            result_state: "BLOCKED",
            muhur_id: null,
            blocker_count: 1,
            warning_count: 0,
            preflight_hash: "e2e-preflight-hash",
            request_hash: "e2e-request-hash",
            result_hash: "e2e-result-hash",
            actor_user_id: 1,
            created_at: "2026-06-15T12:00:00Z"
          }
        ],
        page: 1,
        limit: 20,
        total: 1,
        total_pages: 1
      }));
      return;
    }

    // --- S77-C Maaş Hesaplama ---
    if (path === "/api/maas-hesaplama/preflight" && method === "GET") {
      if (await denyUnlessRolePermission(route, "maas_hesaplama.view")) {
        return;
      }
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);
      const yil = Number.parseInt(url.searchParams.get("yil") ?? "", 10) || 2026;
      const ay = Number.parseInt(url.searchParams.get("ay") ?? "", 10) || 3;
      if (!Number.isFinite(subeId) || subeId <= 0) {
        await fulfillJson(route, 422, errorBody("VALIDATION_ERROR", "sube_id zorunludur.", "sube_id"));
        return;
      }
      const donem = `${yil}-${String(ay).padStart(2, "0")}`;
      const sealed = subeId === 1 && yil === 2026 && ay === 3;
      const existing = maasSnapshots.find(
        (item) => item.sube_id === subeId && item.yil === yil && item.ay === ay && item.state === "OLUSTURULDU"
      );
      const items = sealed
        ? [
            {
              severity: "INFO",
              code: "PERIOD_SEALED",
              message: "Donem muhurlu.",
              record_type: "muhur",
              record_id: 1,
              personel_id: null,
              personel_adi: null,
              metadata: {}
            },
            {
              severity: "WARNING",
              code: "LEGAL_PARAMETER_SET_EMPTY",
              message: "Donemle kesisen mevzuat parametresi yok.",
              record_type: "mevzuat",
              record_id: null,
              personel_id: null,
              personel_adi: null,
              metadata: {}
            },
            {
              severity: "INFO",
              code: "PERSONNEL_COUNT",
              message: "Bordro kumesindeki personel sayisi.",
              record_type: "personel",
              record_id: null,
              personel_id: null,
              personel_adi: null,
              metadata: { adet: 2 }
            }
          ]
        : [
            {
              severity: "BLOCKER",
              code: "PERIOD_NOT_SEALED",
              message: "Donem muhurlenmemis; snapshot olusturulamaz.",
              record_type: "muhur",
              record_id: null,
              personel_id: null,
              personel_adi: null,
              metadata: {}
            }
          ];
      const sourceHash = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
      const preflightHash = sealed
        ? "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
        : "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd";
      await fulfillJson(
        route,
        200,
        okBody({
          sube: { id: subeId, ad: subeId === 1 ? "Merkez" : `Sube ${subeId}` },
          yil,
          ay,
          donem,
          donem_baslangic: `${donem}-01`,
          donem_bitis: `${donem}-31`,
          muhur: sealed
            ? { id: 1, durum: "MUHURLENDI", muhurlenen_kayit_sayisi: 2, created_at: "2026-03-31 23:59:00" }
            : null,
          snapshot_olusturulabilir_mi: sealed && !existing,
          blocker_count: sealed ? 0 : 1,
          warning_count: sealed ? 1 : 0,
          info_count: sealed ? 2 : 0,
          items,
          personel_summary: sealed
            ? [
                {
                  personel_id: 7,
                  ad_soyad: "Ali Yilmaz",
                  istihdam_baslangic: `${donem}-01`,
                  istihdam_bitis: `${donem}-15`,
                  ucret_segment_sayisi: 2,
                  puantaj_kayit_sayisi: 1,
                  finans_kalem_sayisi: 0,
                  hazir_mi: true,
                  blocker_count: 0,
                  warning_count: 0
                },
                {
                  personel_id: 8,
                  ad_soyad: "Ayse Demir",
                  istihdam_baslangic: `${donem}-01`,
                  istihdam_bitis: `${donem}-31`,
                  ucret_segment_sayisi: 1,
                  puantaj_kayit_sayisi: 1,
                  finans_kalem_sayisi: 0,
                  hazir_mi: true,
                  blocker_count: 0,
                  warning_count: 0
                }
              ]
            : [],
          source_summary: {
            personel_sayisi: sealed ? 2 : 0,
            ucret_segment_sayisi: sealed ? 3 : 0,
            puantaj_kayit_sayisi: sealed ? 2 : 0,
            finans_kalem_sayisi: 0,
            mevzuat_parametre_sayisi: 0
          },
          existing_snapshot: existing
            ? {
                id: existing.id,
                state: existing.state,
                revision_no: existing.revision_no,
                source_hash: existing.source_hash,
                snapshot_hash: existing.snapshot_hash,
                created_at: existing.created_at,
                source_changed: false
              }
            : null,
          preflight_hash: preflightHash,
          source_hash: sourceHash,
          hashes: { source_hash: sourceHash },
          schema_version: "S77_C_SNAPSHOT_V1",
          contract_version: "S77_C_SNAPSHOT_V1",
          generated_at: new Date().toISOString()
        })
      );
      return;
    }

    if (path === "/api/maas-hesaplama/snapshotlar" && method === "GET") {
      if (await denyUnlessRolePermission(route, "maas_hesaplama.view")) {
        return;
      }
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);
      const yil = Number.parseInt(url.searchParams.get("yil") ?? "", 10);
      const ay = Number.parseInt(url.searchParams.get("ay") ?? "", 10);
      await fulfillJson(
        route,
        200,
        okBody({
          items: maasSnapshots.filter(
            (item) =>
              item.sube_id === subeId &&
              (!Number.isFinite(yil) || item.yil === yil) &&
              (!Number.isFinite(ay) || item.ay === ay)
          )
        })
      );
      return;
    }

    if (path === "/api/maas-hesaplama/snapshotlar" && method === "POST") {
      if (await denyUnlessRolePermission(route, "maas_hesaplama.manage")) {
        return;
      }
      const payload = request.postDataJSON() as {
        sube_id?: number;
        yil?: number;
        ay?: number;
        expected_preflight_hash?: string;
      };
      const subeId = Number(payload.sube_id);
      const yil = Number(payload.yil);
      const ay = Number(payload.ay);
      const expected = String(payload.expected_preflight_hash ?? "");
      const sourceHash = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
      const preflightHash = "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc";
      const active = maasSnapshots.find(
        (item) => item.sube_id === subeId && item.yil === yil && item.ay === ay && item.state === "OLUSTURULDU"
      );
      if (active && active.source_hash === sourceHash) {
        await fulfillJson(route, 200, okBody({ snapshot: active, idempotent: true, audit: null }));
        return;
      }
      if (!(subeId === 1 && yil === 2026 && ay === 3)) {
        await fulfillJson(route, 409, errorBody("PAYROLL_PREFLIGHT_BLOCKED", "Preflight blocker iceriyor."));
        return;
      }
      if (expected !== preflightHash) {
        await fulfillJson(route, 409, errorBody("PAYROLL_PREFLIGHT_STALE", "Preflight sonucu guncel degil."));
        return;
      }
      const cancelled = [...maasSnapshots]
        .filter((item) => item.sube_id === subeId && item.yil === yil && item.ay === ay)
        .sort((a, b) => b.revision_no - a.revision_no)[0];
      const id = ++maasNextId;
      const snapshot = {
        id,
        snapshot_id: id,
        sube_id: subeId,
        yil,
        ay,
        donem: "2026-03",
        donem_baslangic: "2026-03-01",
        donem_bitis: "2026-03-31",
        muhur_id: 1,
        revision_no: cancelled ? cancelled.revision_no + 1 : 1,
        parent_snapshot_id: cancelled?.id ?? null,
        state: "OLUSTURULDU",
        contract_version: "S77_C_SNAPSHOT_V1",
        cutoff_at: "2026-03-31 23:59:00",
        preflight_hash: preflightHash,
        source_hash: sourceHash,
        snapshot_hash: sourceHash,
        personel_sayisi: 2,
        girdi_sayisi: 8,
        blocker_count: 0,
        warning_count: 1,
        created_by: 1,
        created_at: new Date().toISOString(),
        iptal_edildi_by: null,
        iptal_edildi_at: null,
        iptal_nedeni: null
      };
      maasSnapshots.push(snapshot);
      maasAudits.push({
        id: ++maasNextAuditId,
        donem_snapshot_id: id,
        sube_id: subeId,
        yil,
        ay,
        muhur_id: 1,
        aksiyon: "SNAPSHOT_CREATE",
        sonuc: "CREATED",
        actor_id: 1,
        actor_rol: "MUHASEBE",
        request_hash: "e2e-request",
        preflight_hash: preflightHash,
        source_hash: sourceHash,
        result_hash: sourceHash,
        blocker_count: 0,
        warning_count: 1,
        created_at: snapshot.created_at
      });
      await fulfillJson(route, 201, okBody({ snapshot, idempotent: false, audit: maasAudits[maasAudits.length - 1] }));
      return;
    }

    const maasDetailMatch = path.match(/^\/api\/maas-hesaplama\/snapshotlar\/(\d+)$/);
    if (maasDetailMatch && method === "GET") {
      if (await denyUnlessRolePermission(route, "maas_hesaplama.view")) {
        return;
      }
      const id = Number.parseInt(maasDetailMatch[1], 10);
      const snapshot = maasSnapshots.find((item) => item.id === id);
      if (!snapshot) {
        await fulfillJson(route, 404, errorBody("PAYROLL_SNAPSHOT_NOT_FOUND", "Snapshot bulunamadi."));
        return;
      }
      await fulfillJson(
        route,
        200,
        okBody({
          ...snapshot,
          girdi_ozet: { PERSONEL: 2, UCRET: 2, PUANTAJ: 2, MUHUR: 1 },
          hash_dogrulama: { dogrulandi: true, hesaplanan_snapshot_hash: snapshot.snapshot_hash }
        })
      );
      return;
    }

    const maasCancelMatch = path.match(/^\/api\/maas-hesaplama\/snapshotlar\/(\d+)\/iptal$/);
    if (maasCancelMatch && method === "POST") {
      if (await denyUnlessRolePermission(route, "maas_hesaplama.manage")) {
        return;
      }
      const id = Number.parseInt(maasCancelMatch[1], 10);
      const snapshot = maasSnapshots.find((item) => item.id === id);
      if (!snapshot) {
        await fulfillJson(route, 404, errorBody("PAYROLL_SNAPSHOT_NOT_FOUND", "Snapshot bulunamadi."));
        return;
      }
      const payload = request.postDataJSON() as { neden?: string };
      if (!String(payload.neden ?? "").trim()) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", "Iptal nedeni zorunludur.", "neden"));
        return;
      }
      if (snapshot.state === "IPTAL") {
        await fulfillJson(route, 200, okBody({ snapshot, idempotent: true, audit: null }));
        return;
      }
      snapshot.state = "IPTAL";
      snapshot.iptal_nedeni = String(payload.neden);
      snapshot.iptal_edildi_at = new Date().toISOString();
      await fulfillJson(route, 200, okBody({ snapshot, idempotent: false, audit: null }));
      return;
    }

    if (path === "/api/maas-hesaplama/auditler" && method === "GET") {
      if (await denyUnlessRolePermission(route, "maas_hesaplama.view")) {
        return;
      }
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10);
      const yil = Number.parseInt(url.searchParams.get("yil") ?? "", 10) || 2026;
      const ay = Number.parseInt(url.searchParams.get("ay") ?? "", 10) || 3;
      await fulfillJson(
        route,
        200,
        okBody({
          items: maasAudits.filter((item) => item.sube_id === subeId && item.yil === yil && item.ay === ay)
        })
      );
      return;
    }

    if (path === "/api/puantaj/bildirim-etki-adaylari/rapor" && method === "GET") {
      if (await denyUnlessRolePermission(route, "puantaj.bildirim_etki.rapor.view")) {
        return;
      }

      const ay = url.searchParams.get("ay") ?? "2026-06";
      const subeId = Number.parseInt(url.searchParams.get("sube_id") ?? "", 10) || 1;
      const scoped = puantajEtkiAdaylari.filter((item) => item.ay === ay && item.sube_id === subeId);
      const items = scoped.map((item) => {
        const personel = personeller.find((entry) => entry.id === item.personel_id);
        return {
          id: item.id,
          personel_id: item.personel_id,
          personel_ad_soyad: personel ? `${personel.ad} ${personel.soyad}` : `Personel #${item.personel_id}`,
          sicil_no: personel?.sicil_no ?? null,
          sube_ad: subeId === 1 ? "Merkez" : `Sube ${subeId}`,
          departman_ad: "Demo",
          tarih: item.tarih,
          bildirim_turu: item.bildirim_turu,
          etki_turu: item.etki_turu,
          effective_miktar: item.etki_miktari,
          effective_birim: item.etki_birimi,
          state: item.state,
          conflict_code: item.conflict_code,
          mevcut_puantaj_ozet: item.mevcut_puantaj_id ? "Mevcut kayit var" : null,
          uygulanan_puantaj_ozet: item.uygulanan_puantaj_id ? "Uygulandi" : null,
          karar_turu: item.uygulanan_puantaj_id ? "ADAY_ETKISIYLE_REVIZE_ET" : null,
          karar_veren: item.karar_veren_user_id ? "Muhasebe" : null,
          karar_zamani: item.karar_zamani,
          uygulama_modu: item.uygulama_modu,
          projection_version: item.projection_version,
          source_integrity: item.source_hash ? "OK" : "MISSING",
          audit_integrity: item.uygulama_hash ? "OK" : "PENDING"
        };
      });

      const summary = {
        toplam_aday: items.length,
        otomatik_uygulanan: items.filter((row) => row.uygulama_modu === "OTOMATIK" && row.state === "UYGULANDI").length,
        manuel_uygulanan: items.filter((row) => row.uygulama_modu === "MANUEL").length,
        koru: 0,
        revize: items.filter((row) => row.karar_turu === "ADAY_ETKISIYLE_REVIZE_ET").length,
        yok_sayilan: items.filter((row) => row.state === "YOK_SAYILDI").length,
        bekleyen: items.filter((row) => row.state === "HAZIR" || row.state === "INCELEME_GEREKLI").length,
        conflict_dagilimi: {},
        toplam_gec_kalma_dakika: 15,
        toplam_erken_cikis_dakika: 0,
        toplam_devamsizlik_gun: 1
      };

      await fulfillJson(
        route,
        200,
        JSON.stringify({
          data: {
            items,
            summary,
            page: 1,
            limit: 20,
            total: items.length,
            total_pages: 1,
            has_next_page: false,
            has_prev_page: false
          },
          meta: { page: 1, limit: 20, total: items.length, total_pages: 1 },
          errors: []
        })
      );
      return;
    }

    if (path === "/api/puantaj/bildirim-etki-adaylari/rapor/export.csv" && method === "GET") {
      if (await denyUnlessRolePermission(route, "puantaj.bildirim_etki.rapor.export")) {
        return;
      }

      await route.fulfill({
        status: 200,
        contentType: "text/csv; charset=utf-8",
        body: "id,personel_id,tarih,state\n1,1,2026-06-03,HAZIR\n"
      });
      return;
    }

    if (path === "/api/puantaj/muhurle" && method === "POST") {
      if (await denyUnlessRolePermission(route, "puantaj.muhurle")) {
        return;
      }

      const payload = request.postDataJSON() as { yil?: number; ay?: number };
      const subeScope = getRequestSubeScope(request, url);
      const yil = Number.isFinite(payload.yil) ? Number(payload.yil) : new Date().getFullYear();
      const ay = Number.isFinite(payload.ay) ? Number(payload.ay) : new Date().getMonth() + 1;
      const donem = `${yil}-${String(ay).padStart(2, "0")}`;
      let muhurlenenKayitSayisi = 0;

      for (let index = 0; index < puantajKayitlari.length; index += 1) {
        const item = puantajKayitlari[index];
        if (!item.tarih.startsWith(`${donem}-`) && !item.tarih.startsWith(`${donem}`)) {
          continue;
        }
        if (item.tarih.slice(0, 7) !== donem) {
          continue;
        }
        if (subeScope !== null) {
          const personel = personeller.find((entry) => entry.id === item.personel_id);
          if (personel && personel.sube_id !== subeScope) {
            continue;
          }
        }
        if (item.state === "MUHURLENDI") {
          continue;
        }
        puantajKayitlari[index] = { ...item, state: "MUHURLENDI" };
        muhurlenenKayitSayisi += 1;
      }

      await fulfillJson(
        route,
        200,
        okBody({ muhurlenen_kayit_sayisi: muhurlenenKayitSayisi, donem, muhur_id: 123 })
      );
      return;
    }

    if (path === "/api/haftalik-kapanis" && method === "POST") {
      const payload = request.postDataJSON() as {
        hafta_baslangic?: string;
        hafta_bitis?: string;
        departman_id?: number;
      };

      const kapanisId = 99;
      const hafta_baslangic = payload.hafta_baslangic ?? "2026-04-06";
      const hafta_bitis = payload.hafta_bitis ?? "2026-04-12";
      const departman_id = payload.departman_id ?? 3;
      const yilMatch = /^(\d{4})-/.exec(hafta_baslangic);
      const yil = yilMatch ? Number.parseInt(yilMatch[1], 10) : undefined;
      const hesaplama_zamani = new Date().toISOString();
      const snapshot_satirlari = personeller.map((personel, index) => ({
        snapshot_id: kapanisId * 1000 + index + 1,
        kapanis_id: kapanisId,
        personel_id: personel.id,
        departman_id,
        hafta_baslangic,
        hafta_bitis,
        yil,
        hafta_no: 1,
        state: "KAPANDI",
        kaynak_versiyon: "A1_CONTRACT_STUB",
        toplam_net_dakika: 0,
        normal_calisma_dakika: 0,
        fazla_calisma_dakika: 0,
        fazla_surelerle_calisma_dakika: 0,
        tam_hafta_verisi: false,
        compliance_uyarilari: [],
        compliance_uyari_sayisi: 0,
        kritik_uyari_var_mi: false,
        hesaplama_zamani,
        kaynak_gun_sayisi: 0,
        notlar: ["A1 contract stub; gerçek hesap A2 fazında bağlanacak."]
      }));

      await fulfillJson(
        route,
        200,
        okBody({
          id: kapanisId,
          kapanis_id: kapanisId,
          hafta_baslangic,
          hafta_bitis,
          departman_id,
          state: "KAPANDI",
          personel_sayisi: 24,
          snapshot_satir_sayisi: snapshot_satirlari.length,
          snapshot_satirlari
        })
      );
      return;
    }

    if (path === "/api/yonetim/kullanicilar" && method === "GET") {
      if (await denyUnlessRolePermission(route, "yonetim-paneli.manage")) {
        return;
      }
      await fulfillJson(
        route,
        200,
        okBody({
          items: yonetimKullanicilari.map((item) => {
            const { password: _password, password_hash: _hash, ...safe } = item as typeof item & {
              password?: string;
              password_hash?: string;
            };
            return {
              ...safe,
              sube_ids: normalizeMockSubeIdsWithVarsayilan(item.sube_ids, item.varsayilan_sube_id),
              personel_ad_soyad:
                item.personel_id != null
                  ? personeller.find((personel) => personel.id === item.personel_id)
                    ? `${personeller.find((personel) => personel.id === item.personel_id)?.ad} ${
                        personeller.find((personel) => personel.id === item.personel_id)?.soyad
                      }`
                    : null
                  : null
            };
          })
        })
      );
      return;
    }

    if (path === "/api/yonetim/kullanicilar" && method === "POST") {
      if (await denyUnlessRolePermission(route, "yonetim-paneli.manage")) {
        return;
      }

      const payload = request.postDataJSON() as {
        username?: string;
        password?: string;
        ad_soyad: string;
        telefon?: string;
        kullanici_tipi: "IC_PERSONEL" | "HARICI";
        rol: MockUserRole;
        personel_id?: number | null;
        sube_ids?: number[];
        varsayilan_sube_id?: number | null;
        durum: "AKTIF" | "PASIF";
        notlar?: string;
      };

      const username = String(payload.username ?? "").trim();
      const password = String(payload.password ?? "");
      if (!username) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", "Kullanici adi zorunludur.", "username"));
        return;
      }
      if (!password) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", "Sifre zorunludur.", "password"));
        return;
      }
      const scopeError = assertMockVarsayilanSubeInScope(payload.varsayilan_sube_id, payload.sube_ids ?? []);
      if (scopeError) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", scopeError, "varsayilan_sube_id"));
        return;
      }
      if (yonetimKullanicilari.some((item) => item.username === username)) {
        await fulfillJson(route, 409, errorBody("DUPLICATE_USERNAME", "Bu kullanici adi zaten kayitli.", "username"));
        return;
      }

      const linkedPersonel =
        payload.personel_id != null ? personeller.find((item) => item.id === payload.personel_id) ?? null : null;

      const normalizedSubeIds = normalizeMockSubeIdsWithVarsayilan(
        payload.sube_ids ?? [],
        payload.varsayilan_sube_id ?? null
      );
      const resolvedVarsayilan =
        payload.varsayilan_sube_id ?? (normalizedSubeIds.length > 0 ? normalizedSubeIds[0] : null);

      const created = {
        id: ++kullaniciIdCounter,
        username,
        ad_soyad: payload.ad_soyad,
        telefon: payload.telefon,
        kullanici_tipi: payload.kullanici_tipi,
        rol: payload.rol,
        personel_id: payload.personel_id ?? null,
        sube_ids: normalizedSubeIds,
        varsayilan_sube_id: resolvedVarsayilan,
        durum: payload.durum,
        notlar: payload.notlar
      };

      yonetimKullanicilari.unshift(created);

      await fulfillJson(
        route,
        200,
        okBody({
          ...created,
          personel_ad_soyad: linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : null
        })
      );
      return;
    }

    if (path.match(/^\/api\/yonetim\/kullanicilar\/\d+$/) && method === "PUT") {
      if (await denyUnlessRolePermission(route, "yonetim-paneli.manage")) {
        return;
      }

      const kullaniciId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const target = yonetimKullanicilari.find((item) => item.id === kullaniciId);
      if (!target) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Kullanici bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as {
        username?: string;
        password?: string;
        ad_soyad?: string;
        telefon?: string;
        kullanici_tipi?: "IC_PERSONEL" | "HARICI";
        rol?: MockUserRole;
        personel_id?: number | null;
        sube_ids?: number[];
        varsayilan_sube_id?: number | null;
        durum?: "AKTIF" | "PASIF";
        notlar?: string;
      };

      const nextUsername = payload.username != null ? String(payload.username).trim() : target.username;
      if (
        nextUsername !== target.username &&
        yonetimKullanicilari.some((item) => item.username === nextUsername && item.id !== target.id)
      ) {
        await fulfillJson(route, 409, errorBody("DUPLICATE_USERNAME", "Bu kullanici adi zaten kayitli.", "username"));
        return;
      }

      const nextSubeIds = payload.sube_ids ?? target.sube_ids;
      const nextVarsayilan =
        payload.varsayilan_sube_id !== undefined ? payload.varsayilan_sube_id : target.varsayilan_sube_id;
      const scopeError = assertMockVarsayilanSubeInScope(nextVarsayilan, nextSubeIds);
      if (scopeError) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", scopeError, "varsayilan_sube_id"));
        return;
      }

      const linkedPersonel =
        payload.personel_id != null ? personeller.find((item) => item.id === payload.personel_id) ?? null : null;

      const normalizedSubeIds = normalizeMockSubeIdsWithVarsayilan(nextSubeIds, nextVarsayilan);
      const resolvedVarsayilan = nextVarsayilan ?? (normalizedSubeIds.length > 0 ? normalizedSubeIds[0] : null);

      Object.assign(target, {
        username: nextUsername,
        ad_soyad: payload.ad_soyad ?? target.ad_soyad,
        telefon: payload.telefon ?? target.telefon,
        kullanici_tipi: payload.kullanici_tipi ?? target.kullanici_tipi,
        rol: payload.rol ?? target.rol,
        personel_id: payload.personel_id ?? null,
        sube_ids: normalizedSubeIds,
        varsayilan_sube_id: resolvedVarsayilan,
        durum: payload.durum ?? target.durum,
        notlar: payload.notlar ?? target.notlar
      });

      await fulfillJson(
        route,
        200,
        okBody({
          ...target,
          personel_ad_soyad: linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : null
        })
      );
      return;
    }

    if (path === "/api/yonetim/subeler" && method === "GET") {
      if (
        await denyUnlessAnyRolePermission(route, [
          "yonetim-paneli.view",
          "aylik-ozet.view",
          "personeller.create",
          "personeller.update"
        ])
      ) {
        return;
      }
      await fulfillJson(route, 200, okBody({ items: subeler }));
      return;
    }

    if (path === "/api/yonetim/subeler" && method === "POST") {
      if (await denyUnlessRolePermission(route, "yonetim-paneli.manage")) {
        return;
      }

      const payload = request.postDataJSON() as {
        kod: string;
        ad: string;
        departman_ids?: number[];
        durum: "AKTIF" | "PASIF";
      };

      const normalizedKod = payload.kod?.trim() ?? "";
      const normalizedAd = payload.ad?.trim() ?? "";
      if (subeler.some((item) => item.kod === normalizedKod)) {
        await fulfillJson(route, 409, errorBody("DUPLICATE_SUBE_KOD", "Bu sube kodu zaten kayitli.", "kod"));
        return;
      }
      if (subeler.some((item) => item.ad === normalizedAd)) {
        await fulfillJson(route, 409, errorBody("DUPLICATE_SUBE_AD", "Bu sube adi zaten kayitli.", "ad"));
        return;
      }

      const created = {
        id: ++subeIdCounter,
        ...normalizeSubePayload(payload)
      };

      subeler.unshift(created);
      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/yonetim\/subeler\/\d+$/) && method === "PUT") {
      if (await denyUnlessRolePermission(route, "yonetim-paneli.manage")) {
        return;
      }

      const subeId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const target = subeler.find((item) => item.id === subeId);
      if (!target) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Sube bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<(typeof subeler)[number]>;
      const nextKod = payload.kod?.trim() ?? target.kod;
      const nextAd = payload.ad?.trim() ?? target.ad;
      if (subeler.some((item) => item.id !== subeId && item.kod === nextKod)) {
        await fulfillJson(route, 409, errorBody("DUPLICATE_SUBE_KOD", "Bu sube kodu zaten kayitli.", "kod"));
        return;
      }
      if (subeler.some((item) => item.id !== subeId && item.ad === nextAd)) {
        await fulfillJson(route, 409, errorBody("DUPLICATE_SUBE_AD", "Bu sube adi zaten kayitli.", "ad"));
        return;
      }

      Object.assign(target, {
        ...(payload.kod ? { kod: payload.kod } : {}),
        ...(payload.ad ? { ad: payload.ad } : {}),
        ...(payload.departman_ids ? {
          departman_ids: payload.departman_ids,
          departman_adlari: payload.departman_ids.map((id) => getDepartmanLabel(id))
        } : {}),
        ...(payload.durum ? { durum: payload.durum } : {})
      });
      await fulfillJson(route, 200, okBody(target));
      return;
    }

    if (path.match(/^\/api\/yonetim\/subeler\/\d+$/) && method === "DELETE") {
      if (await denyUnlessRolePermission(route, "yonetim-paneli.manage")) {
        return;
      }

      const subeId = Number.parseInt(path.split("/")[4] ?? "0", 10);
      const targetIndex = subeler.findIndex((item) => item.id === subeId);
      if (targetIndex === -1) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Sube bulunamadi."));
        return;
      }

      const hasLinkedPersonel = personeller.some((personel) => personel.sube_id === subeId);
      if (hasLinkedPersonel) {
        await fulfillJson(route, 409, errorBody(SUBE_DELETE_BLOCKED_ERROR_CODE, SUBE_DELETE_BLOCKED_MESSAGE));
        return;
      }

      subeler.splice(targetIndex, 1);
      yonetimKullanicilari.forEach((kullanici) => {
        if (!kullanici.sube_ids.includes(subeId)) {
          return;
        }

        kullanici.sube_ids = kullanici.sube_ids.filter((id) => id !== subeId);
        if (kullanici.varsayilan_sube_id === subeId) {
          kullanici.varsayilan_sube_id = kullanici.sube_ids[0] ?? null;
        }
      });

      await fulfillJson(route, 200, okBody({ id: subeId, deleted: true }));
      return;
    }

    if (path === "/api/yonetim/aylik-ozet" && method === "GET") {
      if (await denyUnlessRolePermission(route, "aylik-ozet.view")) {
        return;
      }
      await fulfillJson(route, 200, okBody(buildAylikOzetResponse(url)));
      return;
    }

    if (path === "/api/yonetim/aylik-ozet/bolum-onay" && method === "POST") {
      if (
        await denyUnlessAnyRolePermission(route, ["aylik_bolum_onayi.approve", "aylik-ozet.review"])
      ) {
        return;
      }

      const payload = request.postDataJSON() as {
        ay?: string;
        sube_id?: number;
        departman_id?: number;
      };

      if (payload.sube_id != null && mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(payload.sube_id)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu islem icin yetkiniz yok."));
        return;
      }

      if (
        mockUserSubeIds.length > 0 &&
        (payload.sube_id == null || payload.sube_id <= 0)
      ) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", "Sube secimi zorunludur.", "sube_id"));
        return;
      }

      if (!payload.ay || !/^\d{4}-\d{2}$/.test(payload.ay)) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", "Gecersiz ay parametresi.", "ay"));
        return;
      }

      aylikOzetRows.forEach((item) => {
        if (item.ay !== (payload.ay ?? aylikOzetFixtureAy)) {
          return;
        }
        if (payload.sube_id != null && item.sube_id !== payload.sube_id) {
          return;
        }
        if (payload.departman_id != null && item.departman_id !== payload.departman_id) {
          return;
        }
        if (item.kapanis_durumu === "KAPANDI") {
          return;
        }

        item.bolum_onay_durumu = "BOLUM_ONAYLANDI";
        item.revize_var_mi = false;
        item.son_islem = "Bolum yoneticisi toplu onay verdi";
      });

      const responseUrl = new URL(url.toString());
      if (payload.ay) {
        responseUrl.searchParams.set("ay", payload.ay);
      }
      if (payload.sube_id != null) {
        responseUrl.searchParams.set("sube_id", String(payload.sube_id));
      }
      if (payload.departman_id != null) {
        responseUrl.searchParams.set("departman_id", String(payload.departman_id));
      }
      await fulfillJson(route, 200, okBody(buildAylikOzetResponse(responseUrl)));
      return;
    }

    if (path === "/api/yonetim/aylik-ozet/ay-kapat" && method === "POST") {
      if (
        await denyUnlessAnyRolePermission(route, [
          "genel_yonetici_onayi.approve",
          "aylik-ozet.executive_ack"
        ])
      ) {
        return;
      }

      const payload = request.postDataJSON() as {
        ay?: string;
        sube_id?: number;
        departman_id?: number;
      };

      if (payload.sube_id != null && mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(payload.sube_id)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Bu islem icin yetkiniz yok."));
        return;
      }

      if (
        mockUserSubeIds.length > 0 &&
        (payload.sube_id == null || payload.sube_id <= 0)
      ) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", "Sube secimi zorunludur.", "sube_id"));
        return;
      }

      if (!payload.ay || !/^\d{4}-\d{2}$/.test(payload.ay)) {
        await fulfillJson(route, 400, errorBody("VALIDATION_ERROR", "Gecersiz ay parametresi.", "ay"));
        return;
      }

      const hasPendingBolumOnay = aylikOzetRows.some((item) => {
        if (item.ay !== (payload.ay ?? aylikOzetFixtureAy)) {
          return false;
        }
        if (payload.sube_id != null && item.sube_id !== payload.sube_id) {
          return false;
        }
        if (payload.departman_id != null && item.departman_id !== payload.departman_id) {
          return false;
        }

        return item.kapanis_durumu !== "KAPANDI" && item.bolum_onay_durumu === "BOLUM_ONAYINDA";
      });

      if (hasPendingBolumOnay) {
        await fulfillJson(
          route,
          409,
          errorBody(
            "PENDING_BOLUM_ONAY",
            "Bekleyen bölüm onayları tamamlanmadan genel yönetici onayı verilemez."
          )
        );
        return;
      }

      const responseUrl = new URL(url.toString());
      if (payload.ay) {
        responseUrl.searchParams.set("ay", payload.ay);
      }
      if (payload.sube_id != null) {
        responseUrl.searchParams.set("sube_id", String(payload.sube_id));
      }
      if (payload.departman_id != null) {
        responseUrl.searchParams.set("departman_id", String(payload.departman_id));
      }

      aylikOzetRows.forEach((item) => {
        if (item.ay !== (payload.ay ?? aylikOzetFixtureAy)) {
          return;
        }
        if (payload.sube_id != null && item.sube_id !== payload.sube_id) {
          return;
        }
        if (payload.departman_id != null && item.departman_id !== payload.departman_id) {
          return;
        }

        item.kapanis_durumu = "KAPANDI";
        item.son_islem = "Genel yonetici ust onay verdi";
      });

      await fulfillJson(route, 200, okBody(buildAylikOzetResponse(responseUrl)));
      return;
    }

    if (path.startsWith("/api/raporlar/") && method === "GET") {
      if (await denyUnlessRolePermission(route, "raporlar.view")) {
        return;
      }

      const finansRaporPaths = ["/api/raporlar/tesvik", "/api/raporlar/ceza", "/api/raporlar/ekstra-prim"];
      if (finansRaporPaths.includes(path)) {
        if (await denyUnlessRolePermission(route, "finans.view")) {
          return;
        }
      }

      const subeScope = getRequestSubeScope(request, url);

      if (subeScope !== null && mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeScope)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Secili sube icin yetkiniz yok."));
        return;
      }

      if (path === "/api/raporlar/personel-ozet") {
        const raporUrl = new URL(route.request().url());
        const pageNumber = Number.parseInt(raporUrl.searchParams.get("page") ?? "1", 10) || 1;
        const pageLimit = Number.parseInt(raporUrl.searchParams.get("limit") ?? "10", 10) || 10;
        const departmanId = Number.parseInt(raporUrl.searchParams.get("departman_id") ?? "", 10);
        const personelId = Number.parseInt(raporUrl.searchParams.get("personel_id") ?? "", 10);
        const muhurId = Number.parseInt(raporUrl.searchParams.get("muhur_id") ?? "", 10);
        const baslangicTarihi = raporUrl.searchParams.get("baslangic_tarihi");
        const bitisTarihi = raporUrl.searchParams.get("bitis_tarihi");

        if (Number.isFinite(muhurId) && muhurId > 0 && isMuhurSealAccessDenied(muhurId, subeScope, mockUserSubeIds)) {
          await fulfillJson(
            route,
            403,
            errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor.")
          );
          return;
        }

        if (baslangicTarihi === "2026-13-01") {
          await fulfillJson(
            route,
            400,
            errorBody("VALIDATION_ERROR", "Gecersiz baslangic tarihi.", "baslangic_tarihi")
          );
          return;
        }

        await fulfillJson(
          route,
          200,
          personelOzetPaginatedBody(
            pageNumber,
            pageLimit,
            Number.isFinite(departmanId) ? departmanId : undefined,
            subeScope,
            mockUserSubeIds,
            {
              personelId: Number.isFinite(personelId) ? personelId : undefined,
              baslangicTarihi,
              bitisTarihi,
              muhurId: Number.isFinite(muhurId) ? muhurId : null
            }
          )
        );
        return;
      }

      if (path === "/api/raporlar/izin") {
        const raporUrl = new URL(route.request().url());
        const pageNumber = Number.parseInt(raporUrl.searchParams.get("page") ?? "1", 10) || 1;
        const pageLimit = Number.parseInt(raporUrl.searchParams.get("limit") ?? "10", 10) || 10;
        const departmanId = Number.parseInt(raporUrl.searchParams.get("departman_id") ?? "", 10);
        const personelId = Number.parseInt(raporUrl.searchParams.get("personel_id") ?? "", 10);
        const muhurId = Number.parseInt(raporUrl.searchParams.get("muhur_id") ?? "", 10);
        const baslangicTarihi = raporUrl.searchParams.get("baslangic_tarihi");
        const bitisTarihi = raporUrl.searchParams.get("bitis_tarihi");

        if (Number.isFinite(muhurId) && muhurId > 0 && isMuhurSealAccessDenied(muhurId, subeScope, mockUserSubeIds)) {
          await fulfillJson(
            route,
            403,
            errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor.")
          );
          return;
        }

        if (baslangicTarihi === "2026-13-01") {
          await fulfillJson(
            route,
            400,
            errorBody("VALIDATION_ERROR", "Gecersiz baslangic tarihi.", "baslangic_tarihi")
          );
          return;
        }

        await fulfillJson(
          route,
          200,
          izinPaginatedBody(
            pageNumber,
            pageLimit,
            Number.isFinite(departmanId) ? departmanId : undefined,
            subeScope,
            mockUserSubeIds,
            {
              personelId: Number.isFinite(personelId) ? personelId : undefined,
              baslangicTarihi,
              bitisTarihi,
              muhurId: Number.isFinite(muhurId) ? muhurId : null
            }
          )
        );
        return;
      }

      if (path === "/api/raporlar/devamsizlik") {
        const raporUrl = new URL(route.request().url());
        const pageNumber = Number.parseInt(raporUrl.searchParams.get("page") ?? "1", 10) || 1;
        const pageLimit = Number.parseInt(raporUrl.searchParams.get("limit") ?? "10", 10) || 10;
        const departmanId = Number.parseInt(raporUrl.searchParams.get("departman_id") ?? "", 10);
        const personelId = Number.parseInt(raporUrl.searchParams.get("personel_id") ?? "", 10);
        const muhurId = Number.parseInt(raporUrl.searchParams.get("muhur_id") ?? "", 10);
        const baslangicTarihi = raporUrl.searchParams.get("baslangic_tarihi");
        const bitisTarihi = raporUrl.searchParams.get("bitis_tarihi");

        if (Number.isFinite(muhurId) && muhurId > 0 && isMuhurSealAccessDenied(muhurId, subeScope, mockUserSubeIds)) {
          await fulfillJson(
            route,
            403,
            errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor.")
          );
          return;
        }

        if (baslangicTarihi === "2026-13-01") {
          await fulfillJson(
            route,
            400,
            errorBody("VALIDATION_ERROR", "Gecersiz baslangic tarihi.", "baslangic_tarihi")
          );
          return;
        }

        await fulfillJson(
          route,
          200,
          devamsizlikPaginatedBody(
            pageNumber,
            pageLimit,
            Number.isFinite(departmanId) ? departmanId : undefined,
            subeScope,
            mockUserSubeIds,
            {
              personelId: Number.isFinite(personelId) ? personelId : undefined,
              baslangicTarihi,
              bitisTarihi,
              muhurId: Number.isFinite(muhurId) ? muhurId : null
            }
          )
        );
        return;
      }

      if (path === "/api/raporlar/tesvik") {
        const raporUrl = new URL(route.request().url());
        const pageNumber = Number.parseInt(raporUrl.searchParams.get("page") ?? "1", 10) || 1;
        const pageLimit = Number.parseInt(raporUrl.searchParams.get("limit") ?? "10", 10) || 10;
        const departmanId = Number.parseInt(raporUrl.searchParams.get("departman_id") ?? "", 10);
        const personelId = Number.parseInt(raporUrl.searchParams.get("personel_id") ?? "", 10);
        const baslangicTarihi = raporUrl.searchParams.get("baslangic_tarihi");
        const bitisTarihi = raporUrl.searchParams.get("bitis_tarihi");

        await fulfillJson(
          route,
          200,
          tesvikPaginatedBody(
            pageNumber,
            pageLimit,
            Number.isFinite(departmanId) ? departmanId : undefined,
            subeScope,
            mockUserSubeIds,
            {
              personelId: Number.isFinite(personelId) ? personelId : undefined,
              baslangicTarihi,
              bitisTarihi
            }
          )
        );
        return;
      }

      if (path === "/api/raporlar/ceza") {
        const raporUrl = new URL(route.request().url());
        const pageNumber = Number.parseInt(raporUrl.searchParams.get("page") ?? "1", 10) || 1;
        const pageLimit = Number.parseInt(raporUrl.searchParams.get("limit") ?? "10", 10) || 10;
        const departmanId = Number.parseInt(raporUrl.searchParams.get("departman_id") ?? "", 10);
        const personelId = Number.parseInt(raporUrl.searchParams.get("personel_id") ?? "", 10);
        const baslangicTarihi = raporUrl.searchParams.get("baslangic_tarihi");
        const bitisTarihi = raporUrl.searchParams.get("bitis_tarihi");

        await fulfillJson(
          route,
          200,
          cezaPaginatedBody(
            pageNumber,
            pageLimit,
            Number.isFinite(departmanId) ? departmanId : undefined,
            subeScope,
            mockUserSubeIds,
            {
              personelId: Number.isFinite(personelId) ? personelId : undefined,
              baslangicTarihi,
              bitisTarihi
            }
          )
        );
        return;
      }

      if (path === "/api/raporlar/ekstra-prim") {
        const raporUrl = new URL(route.request().url());
        const pageNumber = Number.parseInt(raporUrl.searchParams.get("page") ?? "1", 10) || 1;
        const pageLimit = Number.parseInt(raporUrl.searchParams.get("limit") ?? "10", 10) || 10;
        const departmanId = Number.parseInt(raporUrl.searchParams.get("departman_id") ?? "", 10);
        const personelId = Number.parseInt(raporUrl.searchParams.get("personel_id") ?? "", 10);
        const baslangicTarihi = raporUrl.searchParams.get("baslangic_tarihi");
        const bitisTarihi = raporUrl.searchParams.get("bitis_tarihi");

        await fulfillJson(
          route,
          200,
          ekstraPrimPaginatedBody(
            pageNumber,
            pageLimit,
            Number.isFinite(departmanId) ? departmanId : undefined,
            subeScope,
            mockUserSubeIds,
            {
              personelId: Number.isFinite(personelId) ? personelId : undefined,
              baslangicTarihi,
              bitisTarihi
            }
          )
        );
        return;
      }

      if (path === "/api/raporlar/is-kazasi") {
        const raporUrl = new URL(route.request().url());
        const pageNumber = Number.parseInt(raporUrl.searchParams.get("page") ?? "1", 10) || 1;
        const pageLimit = Number.parseInt(raporUrl.searchParams.get("limit") ?? "10", 10) || 10;
        const departmanId = Number.parseInt(raporUrl.searchParams.get("departman_id") ?? "", 10);
        const personelId = Number.parseInt(raporUrl.searchParams.get("personel_id") ?? "", 10);
        const baslangicTarihi = raporUrl.searchParams.get("baslangic_tarihi");
        const bitisTarihi = raporUrl.searchParams.get("bitis_tarihi");

        await fulfillJson(
          route,
          200,
          isKazasiPaginatedBody(
            pageNumber,
            pageLimit,
            Number.isFinite(departmanId) ? departmanId : undefined,
            subeScope,
            mockUserSubeIds,
            {
              personelId: Number.isFinite(personelId) ? personelId : undefined,
              baslangicTarihi,
              bitisTarihi
            }
          )
        );
        return;
      }

      if (path === "/api/raporlar/bildirim") {
        const raporUrl = new URL(route.request().url());
        const pageNumber = Number.parseInt(raporUrl.searchParams.get("page") ?? "1", 10) || 1;
        const pageLimit = Number.parseInt(raporUrl.searchParams.get("limit") ?? "10", 10) || 10;
        const departmanId = Number.parseInt(raporUrl.searchParams.get("departman_id") ?? "", 10);
        const personelId = Number.parseInt(raporUrl.searchParams.get("personel_id") ?? "", 10);
        const muhurId = Number.parseInt(raporUrl.searchParams.get("muhur_id") ?? "", 10);
        const baslangicTarihi = raporUrl.searchParams.get("baslangic_tarihi");
        const bitisTarihi = raporUrl.searchParams.get("bitis_tarihi");

        if (Number.isFinite(muhurId) && muhurId > 0 && isMuhurSealAccessDenied(muhurId, subeScope, mockUserSubeIds)) {
          await fulfillJson(
            route,
            403,
            errorBody("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor.")
          );
          return;
        }

        if (baslangicTarihi === "2026-13-01") {
          await fulfillJson(
            route,
            400,
            errorBody("VALIDATION_ERROR", "Gecersiz baslangic tarihi.", "baslangic_tarihi")
          );
          return;
        }

        await fulfillJson(
          route,
          200,
          bildirimPaginatedBody(
            pageNumber,
            pageLimit,
            Number.isFinite(departmanId) ? departmanId : undefined,
            subeScope,
            mockUserSubeIds,
            {
              personelId: Number.isFinite(personelId) ? personelId : undefined,
              baslangicTarihi,
              bitisTarihi,
              muhurId: Number.isFinite(muhurId) ? muhurId : null
            }
          )
        );
        return;
      }

      const mockItems = RAPOR_MOCK_ITEMS[path];
      if (mockItems) {
        const scopedItems = filterRaporItemsBySubeScope(mockItems, subeScope, mockUserSubeIds);
        await fulfillJson(route, 200, raporListOkBody(scopedItems));
        return;
      }

      await fulfillJson(route, 200, raporListOkBody([]));
      return;
    }

    if (path === "/api/ek-odeme-kesinti" && method === "GET") {
      if (await denyUnlessRolePermission(route, "finans.view")) {
        return;
      }
      const finansUrl = new URL(route.request().url());
      const subeScope = getRequestSubeScope(request, finansUrl);

      if (subeScope !== null && mockUserSubeIds.length > 0 && !mockUserSubeIds.includes(subeScope)) {
        await fulfillJson(route, 403, errorBody("FORBIDDEN", "Secili sube icin yetkiniz yok."));
        return;
      }

      const personelId = Number.parseInt(finansUrl.searchParams.get("personel_id") ?? "", 10);
      const kalemTuru = finansUrl.searchParams.get("kalem_turu");
      const donem = finansUrl.searchParams.get("donem");
      const state = finansUrl.searchParams.get("state");
      const filtered = finansKalemleri.filter((item) => {
        if (!finansItemMatchesScope(item.personel_id, subeScope, mockUserSubeIds)) {
          return false;
        }
        if (Number.isFinite(personelId) && item.personel_id !== personelId) {
          return false;
        }
        if (kalemTuru && item.kalem_turu !== kalemTuru) {
          return false;
        }
        if (donem && item.donem !== donem) {
          return false;
        }
        if (state && item.state !== state) {
          return false;
        }
        return true;
      });
      await fulfillJson(route, 200, okBody({ items: filtered }));
      return;
    }

    if (path === "/api/ek-odeme-kesinti" && method === "POST") {
      if (await denyUnlessRolePermission(route, "finans.create")) {
        return;
      }
      const payload = request.postDataJSON() as {
        personel_id: number;
        donem: string;
        kalem_turu: string;
        tutar: number;
        aciklama?: string;
      };

      const created = {
        id: ++finansIdCounter,
        personel_id: payload.personel_id,
        donem: payload.donem,
        kalem_turu: payload.kalem_turu,
        tutar: payload.tutar,
        aciklama: payload.aciklama,
        state: "AKTIF"
      };
      finansKalemleri.unshift(created);

      await fulfillJson(route, 200, okBody(created));
      return;
    }

    if (path.match(/^\/api\/ek-odeme-kesinti\/\d+$/) && method === "PUT") {
      if (await denyUnlessRolePermission(route, "finans.update")) {
        return;
      }
      const kalemId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const kalem = finansKalemleri.find((item) => item.id === kalemId);
      if (!kalem) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Finans kalemi bulunamadi."));
        return;
      }

      const payload = request.postDataJSON() as Partial<typeof kalem>;
      Object.assign(kalem, payload);

      await fulfillJson(route, 200, okBody(kalem));
      return;
    }

    if (path.match(/^\/api\/ek-odeme-kesinti\/\d+\/iptal$/) && method === "POST") {
      if (await denyUnlessRolePermission(route, "finans.cancel")) {
        return;
      }
      const kalemId = Number.parseInt(path.split("/")[3] ?? "0", 10);
      const kalem = finansKalemleri.find((item) => item.id === kalemId);
      if (!kalem) {
        await fulfillJson(route, 404, errorBody("NOT_FOUND", "Finans kalemi bulunamadi."));
        return;
      }

      kalem.state = "IPTAL";
      await fulfillJson(route, 200, okBody({ id: kalem.id, state: kalem.state }));
      return;
    }

    await fulfillJson(route, 404, errorBody("NOT_MOCKED", `${method} ${path}`));
    }
  );
}
