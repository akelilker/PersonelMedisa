import type { RevizyonJsonDeger, RevizyonTipi } from "../../types/revizyon-talebi";

export function formatRevizyonDeger(value: RevizyonJsonDeger | undefined | null): string {
  if (value === null || value === undefined) {
    return "—";
  }
  if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
    return String(value);
  }
  if (Array.isArray(value)) {
    return value.map((item) => formatRevizyonDeger(item)).join(", ");
  }

  const entries = Object.entries(value);
  if (entries.length === 0) {
    return "—";
  }

  const labelMap: Record<string, string> = {
    giris_saati: "Giriş",
    cikis_saati: "Çıkış",
    gercek_mola_dakika: "Mola (dk)",
    gec_kalma_dakika: "Geç kalma (dk)",
    gun_tipi: "Gün tipi",
    hareket_durumu: "Hareket",
    dayanak: "Dayanak",
    toplam_net_dakika: "Net dk",
    normal_calisma_dakika: "Normal dk",
    fazla_calisma_dakika: "Fazla çalışma dk",
    event_tipi: "Olay tipi",
    dakika: "Dakika",
    surec_turu: "Süreç türü",
    baslangic_tarihi: "Başlangıç",
    bitis_tarihi: "Bitiş"
  };

  return entries
    .map(([key, nested]) => `${labelMap[key] ?? key}: ${formatRevizyonDeger(nested)}`)
    .join(" · ");
}

export function formatRevizyonTipiLabel(tipi: RevizyonTipi | string): string {
  const labels: Record<string, string> = {
    PUANTAJ_GIRIS_CIKIS_DUZELTME: "Puantaj giriş/çıkış",
    MOLA_DUZELTME: "Mola düzeltme",
    DEVAMSIZLIK_DUZELTME: "Devamsızlık düzeltme",
    SUREC_GEC_GIRIS: "Süreç / geç giriş",
    SERBEST_ZAMAN_ETKI_DUZELTME: "Serbest zaman etkisi",
    KAPANIS_HESAP_REVIZYONU: "Kapanış hesap revizyonu",
    BORDRO_ETKI_NOTU: "Bordro etki notu"
  };
  return labels[tipi] ?? tipi;
}

export function formatRevizyonDurumLabel(durum: string): string {
  const labels: Record<string, string> = {
    TASLAK: "Taslak",
    ONAY_BEKLIYOR: "Onay bekliyor",
    ONAYLANDI: "Onaylandı",
    REDDEDILDI: "Reddedildi",
    IPTAL: "İptal"
  };
  return labels[durum] ?? durum;
}

export function revizyonUserMessage(code: string | undefined, fallback: string): string {
  switch (code) {
    case "REVISION_ALREADY_EXISTS":
    case "ALREADY_EXISTS":
      return "Bu kaynak kayıt için zaten açık bir revizyon talebi bulunuyor.";
    case "PERIOD_NOT_CLOSED":
      return "Revizyon yalnız kapalı haftalar için açılabilir.";
    case "REVISION_SCOPE_DENIED":
    case "CORRECTION_SCOPE_DENIED":
      return "Bu kayıt kapsamınız dışında.";
    case "UNAUTHORIZED_REVISION_APPROVAL":
      return "Onay/red yetkiniz yok.";
    case "CORRECTION_ALREADY_EXISTS":
      return "Bu talep için correction zaten üretilmiş.";
    case "INVALID_STATE_TRANSITION":
    case "STATE_CONFLICT":
      return "Bu işlem mevcut talep durumu için geçerli değil.";
    case "CORRECTION_NOT_FOUND":
    case "NOT_FOUND":
    case "TARGET_NOT_FOUND":
      return "Kayıt bulunamadı.";
    case "VALIDATION_ERROR":
      return fallback.includes("onceki_deger")
        ? "Eski değer sunucu tarafından çözümlenir; gönderilen değer uyuşmuyor."
        : fallback;
    default:
      return fallback;
  }
}

export type RevizyonCreatePrefill = {
  personel_id?: string | number;
  hafta_baslangic?: string;
  hafta_bitis?: string;
  etkilenen_tarih?: string;
  kaynak_tipi?: string;
  kaynak_id?: string | number;
};

/** Create route with server-owned kaynak prefill query (no client onceki_deger). */
export function buildRevizyonTalebiCreatePath(prefill: RevizyonCreatePrefill = {}): string {
  const params = new URLSearchParams();
  if (prefill.personel_id != null && String(prefill.personel_id) !== "") {
    params.set("personel_id", String(prefill.personel_id));
  }
  if (prefill.hafta_baslangic) {
    params.set("hafta_baslangic", prefill.hafta_baslangic);
  }
  if (prefill.hafta_bitis) {
    params.set("hafta_bitis", prefill.hafta_bitis);
  }
  if (prefill.etkilenen_tarih) {
    params.set("etkilenen_tarih", prefill.etkilenen_tarih);
  }
  if (prefill.kaynak_tipi) {
    params.set("kaynak_tipi", prefill.kaynak_tipi);
  }
  if (prefill.kaynak_id != null && String(prefill.kaynak_id) !== "") {
    params.set("kaynak_id", String(prefill.kaynak_id));
  }
  const query = params.toString();
  return query ? `/haftalik-kapanis/revizyonlar/yeni?${query}` : "/haftalik-kapanis/revizyonlar/yeni";
}
