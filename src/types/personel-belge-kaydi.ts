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
