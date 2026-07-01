import { sanitizeDisplayText } from "../lib/display/sanitize-display-text";

export const PERSONEL_BELGE_KAYIT_TIPI_KEYS = ["EGITIM", "SERTIFIKA", "EHLIYET", "YETKINLIK"] as const;

export type PersonelBelgeKayitTipi = (typeof PERSONEL_BELGE_KAYIT_TIPI_KEYS)[number];

export type PersonelBelgeKayitDurum = "AKTIF" | "IPTAL";

export type PersonelBelgeGecerlilikDurumu = "GECERLI" | "YAKINDA_DOLUYOR" | "SURESI_DOLMUS";

export type PersonelBelgeKaydi = {
  id: number;
  personel_id: number;
  kayit_tipi: PersonelBelgeKayitTipi;
  ad: string;
  veren_kurum: string | null;
  belge_no: string | null;
  baslangic_tarihi: string | null;
  bitis_tarihi: string | null;
  durum: PersonelBelgeKayitDurum;
  gecerlilik_durumu: PersonelBelgeGecerlilikDurumu;
  ek_ref: string | null;
  aciklama: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type CreatePersonelBelgeKaydiPayload = {
  kayit_tipi: PersonelBelgeKayitTipi;
  ad: string;
  veren_kurum?: string | null;
  belge_no?: string | null;
  baslangic_tarihi?: string | null;
  bitis_tarihi?: string | null;
  ek_ref?: string | null;
  aciklama?: string | null;
};

export type UpdatePersonelBelgeKaydiPayload = Partial<CreatePersonelBelgeKaydiPayload>;

export const PERSONEL_BELGE_KAYIT_TIPI_LABELS: Record<PersonelBelgeKayitTipi, string> = {
  EGITIM: "Eğitim",
  SERTIFIKA: "Sertifika",
  EHLIYET: "Ehliyet",
  YETKINLIK: "Yetkinlik"
};

export const PERSONEL_BELGE_KAYIT_EMPTY_MESSAGE = "Belge kaydı bulunmuyor.";

const PERSONEL_BELGE_KAYIT_TIPI_ALIASES: Record<string, PersonelBelgeKayitTipi> = {
  SERTFIKA: "SERTIFIKA"
};

const PERSONEL_BELGE_KAYIT_DURUM_LABELS: Record<PersonelBelgeKayitDurum, string> = {
  AKTIF: "Aktif",
  IPTAL: "İptal"
};

function normalizeBelgeEnumKey(value: string): string {
  return value.trim().replace(/-/g, "_").toUpperCase();
}

export function normalizePersonelBelgeKayitTipi(value: unknown): PersonelBelgeKayitTipi | null {
  if (typeof value !== "string" || !value.trim()) {
    return null;
  }

  const key = normalizeBelgeEnumKey(value);
  if ((PERSONEL_BELGE_KAYIT_TIPI_KEYS as readonly string[]).includes(key)) {
    return key as PersonelBelgeKayitTipi;
  }

  return PERSONEL_BELGE_KAYIT_TIPI_ALIASES[key] ?? null;
}

export function formatPersonelBelgeKayitTipiLabel(value: unknown): string {
  const normalized = normalizePersonelBelgeKayitTipi(value);
  if (normalized) {
    return PERSONEL_BELGE_KAYIT_TIPI_LABELS[normalized];
  }

  if (typeof value !== "string" || !value.trim()) {
    return "-";
  }

  const key = normalizeBelgeEnumKey(value);
  const alias = PERSONEL_BELGE_KAYIT_TIPI_ALIASES[key];
  if (alias) {
    return PERSONEL_BELGE_KAYIT_TIPI_LABELS[alias];
  }

  return key
    .split("_")
    .filter(Boolean)
    .map((token) => token.charAt(0) + token.slice(1).toLocaleLowerCase("tr-TR"))
    .join(" ");
}

export function formatPersonelBelgeKayitDurumLabel(value: unknown): string {
  if (typeof value !== "string" || !value.trim()) {
    return "-";
  }

  const key = normalizeBelgeEnumKey(value) as PersonelBelgeKayitDurum;
  return PERSONEL_BELGE_KAYIT_DURUM_LABELS[key] ?? "-";
}

export function formatPersonelBelgeDisplayText(value: string | null | undefined): string {
  return sanitizeDisplayText(value);
}

export const PERSONEL_BELGE_GECERLILIK_LABELS: Record<PersonelBelgeGecerlilikDurumu, string> = {
  GECERLI: "Geçerli",
  YAKINDA_DOLUYOR: "Yakında doluyor",
  SURESI_DOLMUS: "Süresi dolmuş"
};

type IsoDateParts = { y: number; m: number; d: number };

function parseIsoDate(value: string): IsoDateParts | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
  if (!match) {
    return null;
  }

  return {
    y: Number(match[1]),
    m: Number(match[2]),
    d: Number(match[3])
  };
}

function toUtcMs(parts: IsoDateParts): number {
  return Date.UTC(parts.y, parts.m - 1, parts.d);
}

export function computeGecerlilikDurumu(
  bitisTarihi: string | null | undefined,
  referenceDate: Date = new Date()
): PersonelBelgeGecerlilikDurumu {
  if (!bitisTarihi) {
    return "GECERLI";
  }

  const bitis = parseIsoDate(bitisTarihi);
  if (!bitis) {
    return "GECERLI";
  }

  const refParts: IsoDateParts = {
    y: referenceDate.getUTCFullYear(),
    m: referenceDate.getUTCMonth() + 1,
    d: referenceDate.getUTCDate()
  };

  const diffDays = Math.round((toUtcMs(bitis) - toUtcMs(refParts)) / 86_400_000);

  if (diffDays < 0) {
    return "SURESI_DOLMUS";
  }

  if (diffDays <= 30) {
    return "YAKINDA_DOLUYOR";
  }

  return "GECERLI";
}

export function createEmptyBelgeKaydiDraft(): CreatePersonelBelgeKaydiPayload {
  return {
    kayit_tipi: "SERTIFIKA",
    ad: "",
    veren_kurum: "",
    belge_no: "",
    baslangic_tarihi: "",
    bitis_tarihi: "",
    ek_ref: "",
    aciklama: ""
  };
}
