import type {
  BildirimPuantajEtkiAdayListItem,
  BildirimPuantajEtkiAdayState,
  BildirimPuantajEtkiConflictDetail
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

export function isTerminalBildirimPuantajEtkiAdayState(
  state: BildirimPuantajEtkiAdayState | string
): boolean {
  return state === "UYGULANDI" || state === "YOK_SAYILDI";
}
