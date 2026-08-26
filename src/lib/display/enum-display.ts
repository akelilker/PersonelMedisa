import type { UiProfile, UserRole } from "../../types/auth";
import type { FinansDurum } from "../../types/finans";
import type { PersonelCalisanKapsami } from "../../types/personel";
import type { ComplianceUyariSeviye, GunlukPuantajState } from "../../types/puantaj";
import type { AylikOzetAggregateState, KullaniciTipi } from "../../types/yonetim";

const TR_LOCALE = "tr-TR";

const USER_ROLE_LABELS: Record<UserRole, string> = {
  PERSONEL: "Personel",
  MUHASEBE: "Muhasebe",
  IK_SORUMLUSU: "İK Sorumlusu",
  BIRIM_AMIRI: "Birim Yöneticisi",
  BOLUM_YONETICISI: "Bölüm Yöneticisi",
  SUBE_YONETICISI: "Şube Yöneticisi",
  GENEL_YONETICI: "Genel Yönetici",
  SISTEM_YONETICISI: "Sistem Yöneticisi",
  AUTH_SMOKE_READONLY: "Teknik doğrulama — Salt okuma"
};

const UI_PROFILE_LABELS: Record<UiProfile, string> = {
  yonetim: "Yönetim profili",
  birim_amiri: "Birim profili"
};

const PERSONEL_DURUM_LABELS: Record<string, string> = {
  AKTIF: "Çalışıyor",
  PASIF: "Ayrıldı"
};

const SUREC_TURU_LABELS: Record<string, string> = {
  IZIN: "İzin",
  DEVAMSIZLIK: "Devamsızlık",
  DISIPLIN: "Disiplin",
  TESVIK: "Teşvik",
  RAPOR: "Rapor",
  IS_KAZASI: "İş Kazası",
  BELGE: "Belge",
  SERTIFIKA: "Sertifika",
  SERTFIKA: "Sertifika",
  EGITIM: "Eğitim",
  EHLIYET: "Ehliyet",
  YETKINLIK: "Yetkinlik",
  SGK_TESVIK: "SGK Teşviki",
  IS_KAZASI_BILDIRIMI: "İş Kazası Bildirimi",
  IZINSIZ_GELMEDI: "İzinsiz Gelmedi",
  GEC_GELDI: "Geç Geldi",
  ERKEN_CIKTI: "Erken Çıktı",
  YILLIK_IZIN: "Yıllık İzin",
  MAZERET_IZNI: "Mazeret İzni",
  UCRETSIZ_IZIN: "Ücretsiz İzin",
  DOGUM_IZNI: "Doğum İzni",
  EVLILIK_IZNI: "Evlilik İzni",
  GOREVLENDIRME: "Görevlendirme",
  BAGLI_AMIR_ATANDI: "Bağlı Amir Atandı",
  BAGLI_AMIR_DEGISTI: "Bağlı Amir Değişti",
  BAGLI_AMIR_ATAMASI_KALDIRILDI: "Bağlı Amir Ataması Kaldırıldı",
  POZISYON_DEGISTI: "Pozisyon Değişti",
  ORG_DEGISIKLIK: "Organizasyon değişikliği",
  ISTEN_AYRILMA: "İsten ayrılma",
  BIRIM_AMIRI_ATANDI: "Birim Yöneticisi Olarak Atandı",
  BIRIM_AMIRI_ATAMASI_KALDIRILDI: "Birim Yöneticisi Ataması Kaldırıldı",
  SUBE_YETKISI_DEGISTI: "Bağlı Bölüm / Şube Yetkisi Değişti"
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
  ONAY_BEKLIYOR: "Onay Bekliyor",
  ONAYLANDI: "Onaylandı",
  REDDEDILDI: "Reddedildi",
  GONDERILDI: "Gönderildi",
  GEC_GONDERILDI: "Geç Gönderildi",
  DUZELTME_ISTENDI: "Düzeltme İstendi",
  HAFTALIK_MUTABAKATA_ALINDI: "Haftalık Mutabakata Alındı",
  YENI: "Yeni"
};

const KULLANICI_TIPI_LABELS: Record<KullaniciTipi, string> = {
  IC_PERSONEL: "İç Personel",
  HARICI: "Harici"
};

export const CALISAN_KAPSAMI_LABELS: Record<PersonelCalisanKapsami, string> = {
  IC_PERSONEL: "Dahili Personel",
  DIS_KAYNAK: "Harici Personel"
};

export const CALISAN_KAPSAMI_SELECT_OPTIONS: Array<{
  value: PersonelCalisanKapsami;
  label: string;
}> = [
  { value: "IC_PERSONEL", label: CALISAN_KAPSAMI_LABELS.IC_PERSONEL },
  { value: "DIS_KAYNAK", label: CALISAN_KAPSAMI_LABELS.DIS_KAYNAK }
];

const BILDIRIM_TURU_LABELS: Record<string, string> = {
  DEVAMSIZLIK: "Devamsızlık",
  DIGER: "Diğer",
  GEC_GELDI: "Geç Geldi",
  GEC_CIKTI: "Geç Çıktı",
  GELMEDI: "Gelmedi",
  GOREVDE: "Görevde",
  IPTAL: "İptal",
  IPTAL_EDILDI: "İptal Edildi",
  IZINLI: "İzinli",
  IZINLI_GELMEDI: "İzinli Gelmedi",
  IZINSIZ: "İzinsiz",
  IZINSIZ_GELMEDI: "İzinsiz Gelmedi",
  IZINSIZ_DEVAMSIZLIK: "İzinsiz Devamsızlık",
  RAPORLU: "Raporlu",
  UYARI: "Uyarı"
};

const FINANS_KALEM_TURU_LABELS: Record<string, string> = {
  AVANS: "Avans",
  BES: "BES",
  BONUS: "Bonus",
  CEZA: "Ceza",
  DIGER_KESINTI: "Diğer Kesinti",
  EKSTRA_PRIM: "Ekstra Prim",
  IKRAMIYE: "İkramiye",
  MAAS: "Maaş",
  MESAI: "Mesai",
  PRIM: "Prim",
  TESVIK: "Teşvik"
};

const COMPLIANCE_LEVEL_LABELS: Record<string, string> = {
  BILGI: "Bilgi",
  KRITIK: "Kritik",
  UYARI: "Uyarı"
};

const ISG_MAKINE_DURUM_LABELS: Record<string, string> = {
  AKTIF: "Aktif",
  ARIZALI: "Arızalı",
  PASIF: "Pasif"
};

const ISG_BAKIM_DURUM_LABELS: Record<string, string> = {
  GUNCEL: "Güncel",
  GECIKMIS: "Gecikmiş",
  EKSIK_VERI: "Eksik Veri"
};

const ZIMMET_URUN_TURU_LABELS: Record<string, string> = {
  AYAKKABI: "Ayakkabı",
  KASK: "Kask",
  KULAKLIK: "Kulaklık",
  MASKE: "Maske",
  TELEFON: "Telefon",
  DIGER: "Diğer"
};

const ZIMMET_TESLIM_DURUMU_LABELS: Record<string, string> = {
  YENI: "Yeni",
  IKINCI_EL: "İkinci El",
  ARIZALI: "Arızalı"
};

const ZIMMET_KAYIT_DURUMU_LABELS: Record<string, string> = {
  AKTIF: "Aktif Zimmet",
  IADE_EDILDI: "İade Edildi"
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

export function formatIsgMakineDurumLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, ISG_MAKINE_DURUM_LABELS);
}

export function formatIsgBakimDurumuLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, ISG_BAKIM_DURUM_LABELS);
}

export function formatZimmetUrunTuruLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, ZIMMET_URUN_TURU_LABELS);
}

export function formatZimmetTeslimDurumuLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, ZIMMET_TESLIM_DURUMU_LABELS);
}

export function formatZimmetKayitDurumuLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, ZIMMET_KAYIT_DURUMU_LABELS);
}

export function formatKullaniciTipiLabel(value: KullaniciTipi | null | undefined): string {
  if (!value) {
    return "-";
  }

  return KULLANICI_TIPI_LABELS[value] ?? humanizeEnumFallback(value);
}

export function formatCalisanKapsamiLabel(
  value: PersonelCalisanKapsami | null | undefined
): string {
  if (!value) {
    return "-";
  }

  return CALISAN_KAPSAMI_LABELS[value] ?? humanizeEnumFallback(value);
}

export function formatAylikOzetStateLabel(value: AylikOzetAggregateState | null | undefined): string {
  return formatMappedLabel(value, COMMON_STATE_LABELS);
}

const RETENTION_CATEGORY_LABELS: Record<string, string> = {
  PERSONEL_OZLUK: "Personel Özlük Dosyası",
  PERSONEL_BELGE: "Personel Belgesi",
  ISE_GIRIS_CIKIS: "İşe Giriş / Çıkış",
  IZIN: "İzin",
  RAPOR: "Rapor",
  IS_KAZASI: "İş Kazası",
  DISIPLIN: "Disiplin",
  OLAY: "Olay",
  SAVUNMA: "Savunma",
  PUANTAJ: "Puantaj",
  BORDRO: "Bordro",
  SGK_EKSIK_GUN: "SGK Eksik Gün",
  FAZLA_CALISMA: "Fazla Çalışma",
  SERBEST_ZAMAN: "Serbest Zaman",
  ONAY_AUDIT: "Onay Denetim Kaydı"
};

const RETENTION_IMHA_STATUS_LABELS: Record<string, string> = {
  REQUESTED: "Onay Bekliyor",
  APPROVED: "Onaylandı",
  REJECTED: "Reddedildi",
  BLOCKED: "Engellendi"
};

const RETENTION_ELIGIBILITY_CODE_LABELS: Record<string, string> = {
  ELIGIBLE_FOR_DESTRUCTION_REQUEST: "Saklama süresi tamamlanmış. İmha talebi oluşturulabilir.",
  RETENTION_NOT_MATURE: "Bu kayıt henüz imha edilemez.",
  LEGAL_HOLD_ACTIVE: "Kayıt koruma altında; imha yapılamaz.",
  TERMINATION_DATE_MISSING: "İşten ayrılma / çıkış tarihi eksik.",
  TRIGGER_NOT_RESOLVED: "Saklama başlangıç tarihi belirlenemedi.",
  PERIOD_NOT_CLOSED: "İlgili dönem kapanışı henüz tamamlanmamış.",
  UNKNOWN_CATEGORY: "Bilinmeyen saklama kategorisi.",
  SCHEMA_NOT_READY: "Saklama altyapısı henüz hazır değil."
};

export const RETENTION_CATEGORY_SELECT_OPTIONS: Array<{ value: string; label: string }> = [
  { value: "PERSONEL_OZLUK", label: RETENTION_CATEGORY_LABELS.PERSONEL_OZLUK },
  { value: "PERSONEL_BELGE", label: RETENTION_CATEGORY_LABELS.PERSONEL_BELGE },
  { value: "ISE_GIRIS_CIKIS", label: RETENTION_CATEGORY_LABELS.ISE_GIRIS_CIKIS },
  { value: "IZIN", label: RETENTION_CATEGORY_LABELS.IZIN },
  { value: "RAPOR", label: RETENTION_CATEGORY_LABELS.RAPOR },
  { value: "IS_KAZASI", label: RETENTION_CATEGORY_LABELS.IS_KAZASI },
  { value: "DISIPLIN", label: RETENTION_CATEGORY_LABELS.DISIPLIN }
];

export function formatRetentionCategoryLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, RETENTION_CATEGORY_LABELS);
}

export function formatRetentionImhaStatusLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, RETENTION_IMHA_STATUS_LABELS);
}

const PERSONEL_IMPORT_SATIR_DURUM_LABELS: Record<string, string> = {
  GECERLI: "Geçerli",
  HATALI: "Hatalı",
  MEVCUT: "Mevcut kayıt"
};

const RESMI_TATIL_DURUM_LABELS: Record<string, string> = {
  TASLAK: "Taslak",
  AKTIF: "Aktif",
  IPTAL: "İptal"
};

const RESMI_TATIL_GUN_KAPSAMI_LABELS: Record<string, string> = {
  TAM_GUN: "Tam gün",
  YARIM_GUN: "Yarım gün"
};

const RESMI_TATIL_TURU_LABELS: Record<string, string> = {
  UBGT: "UBGT",
  DIGER: "Diğer"
};

export function formatPersonelImportSatirDurumLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, PERSONEL_IMPORT_SATIR_DURUM_LABELS);
}

export function formatResmiTatilDurumLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, RESMI_TATIL_DURUM_LABELS);
}

export function formatResmiTatilGunKapsamiLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, RESMI_TATIL_GUN_KAPSAMI_LABELS);
}

export function formatResmiTatilTuruLabel(value: string | null | undefined): string {
  return formatMappedLabel(value, RESMI_TATIL_TURU_LABELS);
}

export function formatRetentionEligibilitySummary(eligibility: {
  eligible?: boolean;
  code?: string;
  retention_until?: string | null;
  message?: string | null;
}): string {
  if (eligibility.eligible === true) {
    return "Saklama süresi tamamlanmış. İmha talebi oluşturulabilir.";
  }

  const untilRaw = eligibility.retention_until?.trim();
  if (untilRaw) {
    const parsed = new Date(untilRaw);
    const untilLabel = Number.isNaN(parsed.getTime())
      ? untilRaw
      : parsed.toLocaleDateString("tr-TR");
    return `Bu kayıt henüz imha edilemez. En erken değerlendirme tarihi: ${untilLabel}`;
  }

  const code = eligibility.code ? normalizeEnumKey(eligibility.code) : "";
  if (code && RETENTION_ELIGIBILITY_CODE_LABELS[code]) {
    return RETENTION_ELIGIBILITY_CODE_LABELS[code];
  }

  const message = eligibility.message?.trim();
  if (message) {
    return message
      .replace(/\blegal hold\b/gi, "kayıt koruması")
      .replace(/\bhold\b/gi, "koruma");
  }

  return "Saklama süresi değerlendirildi. İmha için ek kontrol gerekir.";
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
