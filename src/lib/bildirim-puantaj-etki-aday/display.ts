import type {
  BildirimPuantajEtkiAdayListItem,
  BildirimPuantajEtkiAdayState,
  BildirimPuantajEtkiConflictDetail,
  BildirimPuantajEtkiManualKararTuru,
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
