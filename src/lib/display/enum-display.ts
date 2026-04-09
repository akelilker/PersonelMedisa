import type { UiProfile, UserRole } from "../../types/auth";
import type { FinansDurum } from "../../types/finans";
import type { ComplianceUyariSeviye, GunlukPuantajState } from "../../types/puantaj";
import type { AylikOzetState, KullaniciTipi } from "../../types/yonetim";

const TR_LOCALE = "tr-TR";

const USER_ROLE_LABELS: Record<UserRole, string> = {
  GENEL_YONETICI: "Genel Yönetici",
  BOLUM_YONETICISI: "Bölüm Yöneticisi",
  MUHASEBE: "Muhasebe",
  BIRIM_AMIRI: "Birim Amiri"
};

const UI_PROFILE_LABELS: Record<UiProfile, string> = {
  yonetim: "Yönetim profili",
  birim_amiri: "Birim profili"
};

const PERSONEL_DURUM_LABELS: Record<string, string> = {
  AKTIF: "Aktif",
  PASIF: "Pasif"
};

const SUREC_TURU_LABELS: Record<string, string> = {
  IZIN: "İzin",
  RAPOR: "Rapor",
  YILLIK_IZIN: "Yıllık İzin",
  MAZERET_IZNI: "Mazeret İzni",
  UCRETSIZ_IZIN: "Ücretsiz İzin",
  DOGUM_IZNI: "Doğum İzni",
  EVLILIK_IZNI: "Evlilik İzni",
  GOREVLENDIRME: "Görevlendirme",
  EGITIM: "Eğitim"
};

const COMMON_STATE_LABELS: Record<string, string> = {
  AKTIF: "Aktif",
  PASIF: "Pasif",
  ACIK: "Açık",
  BEKLEMEDE: "Beklemede",
  HESAPLANDI: "Hesaplandı",
  IPTAL: "İptal",
  IPTAL_EDILDI: "İptal Edildi",
  KAPANDI: "Kapandı",
  MUHURLENDI: "Mühürlendi",
  MUHURLU: "Mühürlü",
  OKUNDU: "Okundu",
  BOLUM_ONAYINDA: "Bölüm Onayında",
  BOLUM_ONAYLANDI: "Bölüm Onaylandı",
  REVIZE_ISTENDI: "Revize İstendi",
  TAMAMLANDI: "Tamamlandı",
  TASLAK: "Taslak",
  YENI: "Yeni"
};

const KULLANICI_TIPI_LABELS: Record<KullaniciTipi, string> = {
  IC_PERSONEL: "İç Personel",
  HARICI: "Harici"
};

const BILDIRIM_TURU_LABELS: Record<string, string> = {
  DEVAMSIZLIK: "Devamsızlık",
  GEC_GELDI: "Geç Geldi",
  GEC_CIKTI: "Geç Çıktı",
  GELMEDI: "Gelmedi",
  IPTAL: "İptal",
  IPTAL_EDILDI: "İptal Edildi",
  IZINLI_GELMEDI: "İzinli Gelmedi",
  IZINSIZ: "İzinsiz",
  IZINSIZ_GELMEDI: "İzinsiz Gelmedi",
  IZINSIZ_DEVAMSIZLIK: "İzinsiz Devamsızlık",
  RAPORLU: "Raporlu",
  UYARI: "Uyarı"
};

const FINANS_KALEM_TURU_LABELS: Record<string, string> = {
  AVANS: "Avans",
  BONUS: "Bonus",
  CEZA: "Ceza",
  EKSTRA_PRIM: "Ekstra Prim",
  IKRAMIYE: "İkramiye",
  MAAS: "Maaş",
  MESAI: "Mesai",
  PRIM: "Prim"
};

const COMPLIANCE_LEVEL_LABELS: Record<string, string> = {
  BILGI: "Bilgi",
  KRITIK: "Kritik",
  UYARI: "Uyarı"
};

const SPECIAL_TOKEN_LABELS: Record<string, string> = {
  ID: "ID",
  SGK: "SGK",
  TC: "T.C."
};

export function normalizeEnumKey(value: string): string {
  return value.trim().replace(/-/g, "_").toUpperCase();
}

function humanizeEnumToken(token: string): string {
  const special = SPECIAL_TOKEN_LABELS[token];
  if (special) {
    return special;
  }

  const lower = token.toLocaleLowerCase(TR_LOCALE);
  return lower.slice(0, 1).toLocaleUpperCase(TR_LOCALE) + lower.slice(1);
}

function humanizeEnumFallback(value: string): string {
  return normalizeEnumKey(value)
    .split("_")
    .filter(Boolean)
    .map(humanizeEnumToken)
    .join(" ");
}

function formatMappedLabel(value: string | null | undefined, labels: Record<string, string>): string {
  if (typeof value !== "string" || value.trim() === "") {
    return "-";
  }

  const normalized = normalizeEnumKey(value);
  return labels[normalized] ?? humanizeEnumFallback(normalized);
}

export function formatBooleanLabel(
  value: boolean | null | undefined,
  labels: { trueLabel?: string; falseLabel?: string } = {}
): string {
  if (value === true) {
    return labels.trueLabel ?? "Evet";
  }

  if (value === false) {
    return labels.falseLabel ?? "Hayır";
  }

  return "-";
}

export function formatUserRoleLabel(value: UserRole | null | undefined): string {
  if (!value) {
    return "-";
  }

  return USER_ROLE_LABELS[value] ?? humanizeEnumFallback(value);
}

export function formatUiProfileLabel(value: UiProfile | null | undefined): string {
  if (!value) {
    return "-";
  }

  return UI_PROFILE_LABELS[value] ?? humanizeEnumFallback(value);
}

export function formatAktifDurumLabel(value: "AKTIF" | "PASIF" | string | null | undefined): string {
  return formatMappedLabel(value, PERSONEL_DURUM_LABELS);
}

export function formatSurecTuruLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, SUREC_TURU_LABELS);
}

export function formatSurecStateLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, COMMON_STATE_LABELS);
}

export function formatUcretliMiLabel(value: boolean | null | undefined): string {
  return formatBooleanLabel(value);
}

export function formatBildirimTuruLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, BILDIRIM_TURU_LABELS);
}

export function formatBildirimStateLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, COMMON_STATE_LABELS);
}

export function formatFinansKalemTuruLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, FINANS_KALEM_TURU_LABELS);
}

export function formatFinansStateLabel(value: FinansDurum | null | undefined): string {
  return formatMappedLabel(value, COMMON_STATE_LABELS);
}

export function formatPuantajStateLabel(value: GunlukPuantajState | null | undefined): string {
  return formatMappedLabel(value, COMMON_STATE_LABELS);
}

export function formatComplianceLevelLabel(value: ComplianceUyariSeviye | null | undefined): string {
  return formatMappedLabel(value, COMPLIANCE_LEVEL_LABELS);
}

export function formatHaftalikKapanisStateLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, COMMON_STATE_LABELS);
}

export function formatKullaniciTipiLabel(value: KullaniciTipi | null | undefined): string {
  if (!value) {
    return "-";
  }

  return KULLANICI_TIPI_LABELS[value] ?? humanizeEnumFallback(value);
}

export function formatAylikOzetStateLabel(value: AylikOzetState | "KAPANDI" | null | undefined): string {
  return formatMappedLabel(value, COMMON_STATE_LABELS);
}

function coerceBooleanValue(value: unknown): boolean | null {
  if (typeof value === "boolean") {
    return value;
  }

  if (typeof value !== "string") {
    return null;
  }

  const normalized = value.trim().toLocaleLowerCase(TR_LOCALE);
  if (normalized === "true" || normalized === "1" || normalized === "evet") {
    return true;
  }
  if (normalized === "false" || normalized === "0" || normalized === "hayir") {
    return false;
  }

  return null;
}

export function formatReportCellValue(column: string, value: unknown): string | null {
  if (typeof column !== "string" || column.trim() === "") {
    return null;
  }

  const normalizedColumn = normalizeEnumKey(column);

  switch (normalizedColumn) {
    case "AKTIF_DURUM":
      return formatAktifDurumLabel(typeof value === "string" ? value : null);
    case "SUREC_TURU":
    case "TUR":
      return formatSurecTuruLabel(typeof value === "string" ? value : null);
    case "BILDIRIM_TURU":
      return formatBildirimTuruLabel(typeof value === "string" ? value : null);
    case "KALEM_TURU":
    case "KALEM":
      return formatFinansKalemTuruLabel(typeof value === "string" ? value : null);
    case "STATE":
    case "DURUM":
      return formatSurecStateLabel(typeof value === "string" ? value : null);
    case "UCRETLI_MI": {
      const booleanValue = coerceBooleanValue(value);
      return formatBooleanLabel(booleanValue);
    }
    default:
      break;
  }

  if (value === null || value === undefined) {
    return "-";
  }

  if (typeof value === "string") {
    return value;
  }

  return String(value);
}
