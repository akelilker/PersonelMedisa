import type {
  BildirimPuantajEtkiAdayDetail,
  BildirimPuantajEtkiAdayListItem,
  BildirimPuantajEtkiAdayState,
  BildirimPuantajEtkiConflictDetail,
  BildirimPuantajEtkiConflictKararTuru,
  BildirimPuantajEtkiManualKararTuru,
  BildirimPuantajEtkiPuantajOzet,
  BildirimPuantajEtkiUygulamaModu
} from "../../types/bildirim-puantaj-etki-aday";

const STATE_LABELS: Record<BildirimPuantajEtkiAdayState, string> = {
  HAZIR: "Hazır",
  INCELEME_GEREKLI: "İnceleme Gerekli",
  UYGULANDI: "Uygulandı",
  YOK_SAYILDI: "Yok Sayıldı"
};

const STATE_BADGE_CLASS: Record<BildirimPuantajEtkiAdayState, string> = {
  HAZIR: "puantaj-etki-state-hazir",
  INCELEME_GEREKLI: "puantaj-etki-state-inceleme",
  UYGULANDI: "puantaj-etki-state-uygulandi",
  YOK_SAYILDI: "puantaj-etki-state-yok-sayildi"
};

const CONFLICT_CLASS_LABELS: Record<string, string> = {
  AYNI_ADAY_PUANTAJI: "Aynı adayın daha önce uyguladığı puantaj",
  BASKA_ADAY_KAYNAGI: "Başka bildirim etki adayının oluşturduğu puantaj",
  MANUEL_KAYNAK: "Manuel girilmiş puantaj kaydı",
  RESMI_SUREC_DAYANAK: "Resmî süreç dayanaklı puantaj",
  MUHURLU_PUANTAJ: "Mühürlenmiş puantaj",
  AMIR_KONTROL_EDILMIS: "Amir kontrolü yapılmış puantaj",
  LEGACY_BELIRSIZ: "Kaynağı belirsiz puantaj"
};

const CONFLICT_RISK_MESSAGES: Record<string, string> = {
  DUSUK: "Düşük risk: aynı aday tekrarı.",
  ORTA: "Orta risk: mevcut kayıt üzerinde bilinçli karar gerekir.",
  YUKSEK: "Yüksek risk: manuel veya belirsiz veri kaybı olasılığı vardır.",
  KRITIK: "Kritik: mühürlü dönem veya kaynak koruması geçerlidir."
};

const CONFLICT_CODE_LABELS: Record<string, string> = {
  COKLU_BILDIRIM_CELISKISI: "Aynı günde çakışan bildirimler var.",
  MEVCUT_PUANTAJ_VAR: "İlgili gün için mevcut puantaj kaydı bulunuyor.",
  COKLU_RESMI_SUREC: "Birden fazla resmi süreç eşleşmesi var.",
  DAKIKA_EKSIK: "Bildirimde dakika bilgisi eksik.",
  IZIN_SURECI_YOK: "İzin süreci bulunamadı.",
  RAPOR_SURECI_YOK: "Rapor süreci bulunamadı.",
  DIGER_MANUEL_INCELEME: "Manuel inceleme gerekiyor."
};

export const GEREKCE_MIN_LENGTH = 5;
export const GEREKCE_MAX_LENGTH = 500;

export function formatConflictClassDisplay(conflictClass: string | null | undefined): string {
  if (!conflictClass) {
    return "—";
  }
  return CONFLICT_CLASS_LABELS[conflictClass] ?? conflictClass.split("_").join(" ").toLowerCase();
}

export function formatConflictRiskMessage(risk: string | null | undefined): string {
  if (!risk) {
    return "Çakışma riski değerlendirilmelidir.";
  }
  return CONFLICT_RISK_MESSAGES[risk] ?? "Çakışma riski değerlendirilmelidir.";
}

export function canResolveConflictForDetail(detail: Pick<
  BildirimPuantajEtkiAdayDetail,
  "state" | "mevcut_puantaj" | "current_puantaj_hash" | "cakisma_cozum" | "conflict_class"
>): boolean {
  if (detail.cakisma_cozum) {
    return false;
  }
  if (detail.state !== "HAZIR" && detail.state !== "INCELEME_GEREKLI") {
    return false;
  }
  if (!detail.mevcut_puantaj || detail.mevcut_puantaj.id == null || !detail.current_puantaj_hash) {
    return false;
  }
  if (detail.conflict_class === "MUHURLU_PUANTAJ") {
    return false;
  }
  return true;
}

export function isConflictReviseAllowed(detail: Pick<BildirimPuantajEtkiAdayDetail, "conflict_revise_allowed" | "conflict_class">): boolean {
  if (detail.conflict_class === "RESMI_SUREC_DAYANAK" || detail.conflict_class === "MUHURLU_PUANTAJ") {
    return false;
  }
  return Boolean(detail.conflict_revise_allowed);
}

const CONFLICT_COMPARE_FIELDS: Array<{ key: keyof BildirimPuantajEtkiPuantajOzet; label: string }> = [
  { key: "hareket_durumu", label: "Hareket" },
  { key: "dayanak", label: "Dayanak" },
  { key: "hesap_etkisi", label: "Hesap etkisi" },
  { key: "gec_kalma_dakika", label: "Geç kalma (dk)" },
  { key: "erken_cikis_dakika", label: "Erken çıkış (dk)" },
  { key: "giris_saati", label: "Giriş saati" },
  { key: "cikis_saati", label: "Çıkış saati" },
  { key: "kontrol_durumu", label: "Kontrol durumu" },
  { key: "kaynak", label: "Kaynak" }
];

export function buildConflictFieldComparisons(
  current: BildirimPuantajEtkiPuantajOzet,
  preview: Record<string, unknown> | null
): Array<{ label: string; current: string; next: string; changes: boolean; preserved: boolean }> {
  return CONFLICT_COMPARE_FIELDS.map(({ key, label }) => {
    const currentValue = current[key];
    const nextRaw = preview?.[key];
    const currentText = currentValue == null || currentValue === "" ? "—" : String(currentValue);
    const nextText = nextRaw == null || nextRaw === "" ? "—" : String(nextRaw);
    const changes = currentText !== nextText;
    const preserved = !changes && ["giris_saati", "cikis_saati"].includes(key);
    return { label, current: currentText, next: nextText, changes, preserved };
  });
}

export function defaultConflictKararTuru(detail: Pick<
  BildirimPuantajEtkiAdayDetail,
  "conflict_default_karar" | "conflict_class"
>): BildirimPuantajEtkiConflictKararTuru {
  if (detail.conflict_default_karar === "ADAY_ETKISIYLE_REVIZE_ET") {
    return "ADAY_ETKISIYLE_REVIZE_ET";
  }
  return "MEVCUT_PUANTAJI_KORU";
}

export function formatConflictKararTuruLabel(karar: BildirimPuantajEtkiConflictKararTuru | string): string {
  if (karar === "ADAY_ETKISIYLE_REVIZE_ET") {
    return "Aday Etkisiyle Revize Et";
  }
  return "Mevcut Puantajı Koru";
}

export function formatBildirimPuantajEtkiAdayStateLabel(
  state: BildirimPuantajEtkiAdayState | string | null | undefined
): string {
  if (!state) {
    return "—";
  }
  return STATE_LABELS[state as BildirimPuantajEtkiAdayState] ?? state;
}

export function getBildirimPuantajEtkiAdayStateBadgeClass(
  state: BildirimPuantajEtkiAdayState | string | null | undefined
): string {
  if (!state) {
    return "puantaj-etki-state-badge";
  }
  const modifier = STATE_BADGE_CLASS[state as BildirimPuantajEtkiAdayState];
  return modifier ? `puantaj-etki-state-badge ${modifier}` : "puantaj-etki-state-badge";
}

export function formatProjectedEtkiLabel(item: Pick<
  BildirimPuantajEtkiAdayListItem,
  "etki_turu" | "etki_miktari" | "etki_birimi"
>): string {
  const tur = item.etki_turu || "—";
  if (item.etki_miktari === null || item.etki_miktari === undefined) {
    return tur;
  }
  const birim = item.etki_birimi ? ` ${item.etki_birimi}` : "";
  return `${tur} (${item.etki_miktari}${birim})`;
}

function readConflictMessageFromDetail(detail: BildirimPuantajEtkiConflictDetail): string | null {
  if (!detail || typeof detail !== "object") {
    return null;
  }
  const message = detail.message;
  if (typeof message === "string" && message.trim()) {
    return message.trim();
  }
  const aciklama = detail.aciklama;
  if (typeof aciklama === "string" && aciklama.trim()) {
    return aciklama.trim();
  }
  return null;
}

export function formatConflictDisplay(
  conflictCode: string | null | undefined,
  conflictDetail?: BildirimPuantajEtkiConflictDetail
): string {
  const detailMessage = readConflictMessageFromDetail(conflictDetail ?? null);
  if (detailMessage) {
    return detailMessage;
  }
  if (!conflictCode) {
    return "Çakışma yok";
  }
  return CONFLICT_CODE_LABELS[conflictCode] ?? conflictCode.split("_").join(" ").toLowerCase();
}

export function countUnicodeCharacters(value: string): number {
  return [...value].length;
}

export function trimDismissGerekce(value: string): string {
  return value.trim();
}

export function validateDismissGerekce(value: string): string | null {
  const trimmed = trimDismissGerekce(value);
  if (!trimmed) {
    return "Yok sayma gerekçesi zorunludur.";
  }
  if (countUnicodeCharacters(trimmed) < GEREKCE_MIN_LENGTH) {
    return "Yok sayma gerekçesi en az 5 karakter olmalıdır.";
  }
  if (countUnicodeCharacters(trimmed) > GEREKCE_MAX_LENGTH) {
    return "Yok sayma gerekçesi en fazla 500 karakter olabilir.";
  }
  return null;
}

export function canDismissBildirimPuantajEtkiAday(
  state: BildirimPuantajEtkiAdayState | string
): boolean {
  return state === "HAZIR" || state === "INCELEME_GEREKLI";
}

export function canApplyBildirimPuantajEtkiAday(
  state: BildirimPuantajEtkiAdayState | string
): boolean {
  return state === "HAZIR";
}

export function canManualApplyBildirimPuantajEtkiAday(
  state: BildirimPuantajEtkiAdayState | string
): boolean {
  return state === "INCELEME_GEREKLI";
}

export const MANUAL_KARAR_PRESET_OPTIONS: Array<{
  value: BildirimPuantajEtkiManualKararTuru;
  label: string;
}> = [
  { value: "DEVAMSIZLIK_GUN", label: "Devamsızlık" },
  { value: "GEC_KALMA_DAKIKA", label: "Geç Kalma" },
  { value: "ERKEN_CIKIS_DAKIKA", label: "Erken Çıkış" },
  { value: "GOREVDE_CALISILMIS_GUN", label: "Görevde Çalışma" }
];

export function manualKararRequiresMiktar(
  kararTuru: BildirimPuantajEtkiManualKararTuru | string
): boolean {
  return kararTuru === "GEC_KALMA_DAKIKA" || kararTuru === "ERKEN_CIKIS_DAKIKA";
}

export function formatManualKararPreview(
  kararTuru: BildirimPuantajEtkiManualKararTuru | string
): { hareket: string; dayanak: string; hesapEtkisi: string; gunTipi: string } {
  switch (kararTuru) {
    case "DEVAMSIZLIK_GUN":
      return {
        hareket: "Gelmedi",
        dayanak: "Yok İzinsiz",
        hesapEtkisi: "Yevmiye Kes",
        gunTipi: "Tarihe göre sistem tarafından belirlenecek"
      };
    case "GEC_KALMA_DAKIKA":
      return {
        hareket: "Geç Geldi",
        dayanak: "Yok İzinsiz",
        hesapEtkisi: "Tam Yevmiye Ver",
        gunTipi: "Tarihe göre sistem tarafından belirlenecek"
      };
    case "ERKEN_CIKIS_DAKIKA":
      return {
        hareket: "Erken Çıktı",
        dayanak: "Yok İzinsiz",
        hesapEtkisi: "Tam Yevmiye Ver",
        gunTipi: "Tarihe göre sistem tarafından belirlenecek"
      };
    case "GOREVDE_CALISILMIS_GUN":
    default:
      return {
        hareket: "Geldi",
        dayanak: "Görevde Çalışma",
        hesapEtkisi: "Tam Yevmiye Ver",
        gunTipi: "Tarihe göre sistem tarafından belirlenecek"
      };
  }
}

export function formatUygulamaModuLabel(
  modu: BildirimPuantajEtkiUygulamaModu | string | null | undefined
): string {
  if (modu === "MANUEL") {
    return "Manuel";
  }
  if (modu === "CAKISMA_COZUM") {
    return "Çakışma Çözümü";
  }
  return "Otomatik";
}

export function validateManualGerekce(value: string): string | null {
  return validateDismissGerekce(value);
}

export function validateManualMiktar(
  kararTuru: BildirimPuantajEtkiManualKararTuru | string,
  value: string
): string | null {
  if (!manualKararRequiresMiktar(kararTuru)) {
    if (value.trim()) {
      return "Bu karar türü için dakika girilmemelidir.";
    }
    return null;
  }
  const trimmed = value.trim();
  if (!trimmed) {
    return "Dakika değeri zorunludur.";
  }
  if (!/^\d+$/.test(trimmed)) {
    return "Dakika değeri pozitif tam sayı olmalıdır.";
  }
  const parsed = Number.parseInt(trimmed, 10);
  if (parsed <= 0 || parsed > 1440) {
    return "Dakika değeri 1-1440 arasında olmalıdır.";
  }
  return null;
}

export function isTerminalBildirimPuantajEtkiAdayState(
  state: BildirimPuantajEtkiAdayState | string
): boolean {
  return state === "UYGULANDI" || state === "YOK_SAYILDI";
}
