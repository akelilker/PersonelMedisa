import { sanitizeDisplayText } from "../lib/display/sanitize-display-text";

export const PERSONEL_BELGE_KAYIT_TIPI_KEYS = [
  "KIMLIK",
  "IS_SOZLESMESI",
  "DIPLOMA",
  "ADLI_SICIL",
  "SAGLIK_RAPORU",
  "IKAMETGAH",
  "SURUCU_BELGESI",
  "MESLEKI_YETERLILIK",
  "ISG_EGITIM",
  "EGITIM",
  "SERTIFIKA",
  "EHLIYET",
  "YETKINLIK",
  "DIGER"
] as const;

export type PersonelBelgeKayitTipi = (typeof PERSONEL_BELGE_KAYIT_TIPI_KEYS)[number];

export type PersonelBelgeKayitDurum = "AKTIF" | "IPTAL";

export type PersonelBelgeGecerlilikDurumu = "GECERLI" | "YAKINDA_DOLUYOR" | "SURESI_DOLMUS";

export type PersonelBelgeTakipDurumu =
  | "AKTIF"
  | "SURESI_YAKLASIYOR"
  | "SURESI_DOLDU"
  | "IPTAL"
  | "BELGE_DOSYASI_EKSIK";

export type PersonelBelgeDosyaInfo = {
  var_mi: boolean;
  surum_no?: number;
  orijinal_dosya_adi?: string;
  mime_type?: string;
  byte_boyutu?: number;
  sha256?: string;
  created_at?: string | null;
};

export type PersonelBelgeKaydi = {
  id: number;
  personel_id: number;
  kayit_tipi: PersonelBelgeKayitTipi;
  ad: string;
  veren_kurum: string | null;
  belge_no: string | null;
  belge_no_masked: string | null;
  baslangic_tarihi: string | null;
  bitis_tarihi: string | null;
  durum: PersonelBelgeKayitDurum;
  gecerlilik_durumu: PersonelBelgeGecerlilikDurumu;
  takip_durumu: PersonelBelgeTakipDurumu;
  ek_ref: string | null;
  aciklama: string | null;
  dosya?: PersonelBelgeDosyaInfo;
  yukleyen_ad?: string | null;
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
  dosya_adi?: string | null;
  dosya_mime?: string | null;
  dosya_icerik_base64?: string | null;
};

export type UpdatePersonelBelgeKaydiPayload = Partial<
  Omit<CreatePersonelBelgeKaydiPayload, "dosya_adi" | "dosya_mime" | "dosya_icerik_base64">
>;

export type CancelPersonelBelgeKaydiPayload = {
  iptal_nedeni: string;
};

export type ReplacePersonelBelgeDosyaPayload = {
  dosya_adi: string;
  dosya_mime: string;
  dosya_icerik_base64: string;
};

export type PersonelBelgeAuditKaydi = {
  id: number;
  islem_turu: string;
  yapan_kullanici_ad?: string | null;
  gerekce?: string | null;
  dosya_adi?: string | null;
  dosya_mime?: string | null;
  dosya_byte?: number | null;
  created_at: string;
};

export type BelgeTakipOzet = {
  toplam_aktif: number;
  suresi_yaklasan: number;
  suresi_dolan: number;
  dosyasi_eksik: number;
  belgesi_hic_bulunmayan: number;
};

export type BelgeTakipSatir = {
  belge_kaydi_id: number;
  personel_id: number;
  personel_ad_soyad: string;
  sube_id?: number | null;
  departman_id?: number | null;
  kayit_tipi: PersonelBelgeKayitTipi;
  ad: string;
  takip_durumu: PersonelBelgeTakipDurumu;
  bitis_tarihi: string | null;
  belge_no_masked: string | null;
  updated_at?: string | null;
};

export type BelgeTakipParams = {
  sube_id?: number | string;
  departman_id?: number | string;
  personel_id?: number | string;
  kayit_tipi?: PersonelBelgeKayitTipi;
  takip_durumu?: PersonelBelgeTakipDurumu;
  baslangic_tarihi?: string;
  bitis_tarihi?: string;
  personel_aktiflik?: "AKTIF" | "PASIF" | "tum";
  page?: number;
  limit?: number;
};

export const PERSONEL_BELGE_MAX_DECODED_BYTES = 10 * 1024 * 1024;
export const PERSONEL_BELGE_EXPIRY_WARNING_DAYS = 30;

export const PERSONEL_BELGE_ALLOWED_MIME_BY_EXTENSION: Record<string, readonly string[]> = {
  pdf: ["application/pdf"],
  png: ["image/png"],
  jpg: ["image/jpeg"],
  jpeg: ["image/jpeg"],
  webp: ["image/webp"],
  doc: ["application/msword"],
  docx: ["application/vnd.openxmlformats-officedocument.wordprocessingml.document"]
};

export const PERSONEL_BELGE_BLOCKED_EXTENSIONS = new Set([
  "php",
  "phtml",
  "html",
  "htm",
  "svg",
  "js",
  "exe",
  "bat",
  "cmd",
  "sh"
]);

export const PERSONEL_BELGE_KAYIT_TIPI_LABELS: Record<PersonelBelgeKayitTipi, string> = {
  KIMLIK: "Kimlik",
  IS_SOZLESMESI: "İş sözleşmesi",
  DIPLOMA: "Diploma / öğrenim belgesi",
  ADLI_SICIL: "Adli sicil",
  SAGLIK_RAPORU: "Sağlık raporu",
  IKAMETGAH: "İkametgah",
  SURUCU_BELGESI: "Sürücü belgesi",
  MESLEKI_YETERLILIK: "Mesleki yeterlilik",
  ISG_EGITIM: "İSG / eğitim sertifikası",
  EGITIM: "Eğitim",
  SERTIFIKA: "Sertifika",
  EHLIYET: "Ehliyet",
  YETKINLIK: "Yetkinlik",
  DIGER: "Diğer"
};

export const PERSONEL_BELGE_TAKIP_DURUMU_LABELS: Record<PersonelBelgeTakipDurumu, string> = {
  AKTIF: "Aktif",
  SURESI_YAKLASIYOR: "Süresi yaklaşıyor",
  SURESI_DOLDU: "Süresi doldu",
  IPTAL: "İptal",
  BELGE_DOSYASI_EKSIK: "Dosya eksik"
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

export function formatPersonelBelgeTakipDurumuLabel(value: unknown): string {
  if (typeof value !== "string" || !value.trim()) {
    return "-";
  }

  const key = normalizeBelgeEnumKey(value) as PersonelBelgeTakipDurumu;
  return PERSONEL_BELGE_TAKIP_DURUMU_LABELS[key] ?? "-";
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

  if (diffDays <= PERSONEL_BELGE_EXPIRY_WARNING_DAYS) {
    return "YAKINDA_DOLUYOR";
  }

  return "GECERLI";
}

export function deriveTakipDurumu(
  lifecycle: PersonelBelgeKayitDurum,
  bitisTarihi: string | null | undefined,
  hasActiveFile: boolean,
  referenceDate: Date = new Date()
): PersonelBelgeTakipDurumu {
  if (lifecycle === "IPTAL") {
    return "IPTAL";
  }

  if (!hasActiveFile) {
    return "BELGE_DOSYASI_EKSIK";
  }

  const gecerlilik = computeGecerlilikDurumu(bitisTarihi, referenceDate);
  if (gecerlilik === "SURESI_DOLMUS") {
    return "SURESI_DOLDU";
  }

  if (gecerlilik === "YAKINDA_DOLUYOR") {
    return "SURESI_YAKLASIYOR";
  }

  return "AKTIF";
}

export function maskBelgeNo(belgeNo: string | null | undefined): string | null {
  if (belgeNo == null) {
    return null;
  }

  const value = belgeNo.trim();
  if (!value) {
    return null;
  }

  const chars = [...value];
  const len = chars.length;
  if (len <= 4) {
    return "*".repeat(len);
  }

  return "*".repeat(len - 4) + chars.slice(-4).join("");
}

export function validatePersonelBelgeFileSelection(file: File): string | null {
  const name = file.name.trim();
  if (!name || name.includes("\0") || /[\\/]/.test(name)) {
    return "Dosya adı geçersiz.";
  }

  const lower = name.toLowerCase();
  const parts = lower.split(".");
  if (parts.length < 2) {
    return "Dosya uzantısı zorunludur.";
  }

  for (let index = 1; index < parts.length - 1; index += 1) {
    if (PERSONEL_BELGE_BLOCKED_EXTENSIONS.has(parts[index] ?? "")) {
      return "Bu dosya tipi yüklenemez. İzinli: PDF, PNG, JPG, WEBP, DOC, DOCX.";
    }
  }

  const extension = parts[parts.length - 1] ?? "";
  if (PERSONEL_BELGE_BLOCKED_EXTENSIONS.has(extension) || !PERSONEL_BELGE_ALLOWED_MIME_BY_EXTENSION[extension]) {
    return "Bu dosya tipi yüklenemez. İzinli: PDF, PNG, JPG, WEBP, DOC, DOCX.";
  }

  const allowedMimes = PERSONEL_BELGE_ALLOWED_MIME_BY_EXTENSION[extension];
  if (!allowedMimes.includes(file.type) && file.type !== "") {
    return "Dosya uzantısı ile MIME tipi uyuşmuyor.";
  }

  if (file.size > PERSONEL_BELGE_MAX_DECODED_BYTES) {
    return `Dosya boyutu ${Math.round(PERSONEL_BELGE_MAX_DECODED_BYTES / (1024 * 1024))} MB sınırını aşıyor.`;
  }

  return null;
}

export async function readFileAsBase64Payload(file: File): Promise<ReplacePersonelBelgeDosyaPayload> {
  const validationError = validatePersonelBelgeFileSelection(file);
  if (validationError) {
    throw new Error(validationError);
  }

  const buffer = await file.arrayBuffer();
  if (buffer.byteLength > PERSONEL_BELGE_MAX_DECODED_BYTES) {
    throw new Error(`Dosya boyutu ${Math.round(PERSONEL_BELGE_MAX_DECODED_BYTES / (1024 * 1024))} MB sınırını aşıyor.`);
  }

  const bytes = new Uint8Array(buffer);
  let binary = "";
  for (let index = 0; index < bytes.length; index += 1) {
    binary += String.fromCharCode(bytes[index] ?? 0);
  }

  const extension = file.name.toLowerCase().split(".").pop() ?? "";
  const mime =
    file.type ||
    PERSONEL_BELGE_ALLOWED_MIME_BY_EXTENSION[extension]?.[0] ||
    "application/octet-stream";

  return {
    dosya_adi: file.name,
    dosya_mime: mime,
    dosya_icerik_base64: btoa(binary)
  };
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

export function takipDurumuClassName(durum: PersonelBelgeTakipDurumu): string {
  if (durum === "SURESI_DOLDU" || durum === "IPTAL") {
    return "personel-belge-kayit-state is-expired";
  }
  if (durum === "SURESI_YAKLASIYOR") {
    return "personel-belge-kayit-state is-expiring";
  }
  if (durum === "BELGE_DOSYASI_EKSIK") {
    return "personel-belge-kayit-state is-missing-file";
  }
  return "personel-belge-kayit-state";
}
