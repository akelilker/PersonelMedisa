import { formatIsoDateDetail } from "../display/iso-date-format";
import { formatBildirimTuruLabel, normalizeEnumKey } from "../display/enum-display";
import type { Bildirim } from "../../types/bildirim";

export type HeaderNotificationCopy = {
  title: string;
  subtitle: string;
};

const PERSONEL_NAME_FALLBACK = "Personel bildirimi";
const HH_MM_PATTERN = /^([01]\d|2[0-3]):([0-5]\d)$/;

function trimText(value: string | null | undefined): string | null {
  if (typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  return trimmed ? trimmed : null;
}

function resolvePersonelName(
  item: Pick<Bildirim, "personel_ad_soyad">,
  lookupName?: string | null
): string {
  return trimText(item.personel_ad_soyad) ?? trimText(lookupName) ?? PERSONEL_NAME_FALLBACK;
}

function formatNotificationDate(tarih: string | null | undefined): string | null {
  const formatted = formatIsoDateDetail(tarih);
  return formatted === "-" ? null : formatted;
}

function formatClock(value: string | null | undefined): string | null {
  const trimmed = trimText(value);
  if (!trimmed || !HH_MM_PATTERN.test(trimmed)) {
    return null;
  }
  return trimmed;
}

function buildSubtitle(
  tarih: string | null | undefined,
  options?: { baslangic?: string | null; bitis?: string | null; subeAdi?: string | null }
): string {
  const dateLabel = formatNotificationDate(tarih);
  const start = formatClock(options?.baslangic);
  const end = formatClock(options?.bitis);
  const parts: string[] = [];

  if (dateLabel) {
    if (start && end) {
      parts.push(`${dateLabel} · ${start} → ${end}`);
    } else {
      parts.push(dateLabel);
    }
  }

  const sube = trimText(options?.subeAdi);
  if (sube && parts.length > 0) {
    // Keep subtitle scannable: only append branch when date exists and branch is distinct.
    parts[0] = `${parts[0]} · ${sube}`;
  } else if (sube) {
    parts.push(sube);
  }

  return parts[0] ?? "İşlem gerektiriyor";
}

function positiveDakika(value: number | null | undefined): number | null {
  if (typeof value !== "number" || !Number.isFinite(value) || value <= 0) {
    return null;
  }
  return Math.trunc(value);
}

/**
 * Pure FE formatter for header notification copy.
 * Preserves raw bildirim_turu identity; never leaks technical personel ids.
 */
export function formatHeaderBildirimCopy(
  item: Pick<
    Bildirim,
    | "bildirim_turu"
    | "personel_ad_soyad"
    | "tarih"
    | "aciklama"
    | "dakika"
    | "baslangic_saati"
    | "bitis_saati"
    | "sube_adi"
  >,
  options?: { lookupPersonelName?: string | null; includeSube?: boolean }
): HeaderNotificationCopy {
  const personelName = resolvePersonelName(item, options?.lookupPersonelName);
  const tur = normalizeEnumKey(item.bildirim_turu);
  const dakika = positiveDakika(item.dakika);
  const includeSube = options?.includeSube === true;
  const subtitleOptions = {
    baslangic: item.baslangic_saati,
    bitis: item.bitis_saati,
    subeAdi: includeSube ? item.sube_adi : null
  };

  switch (tur) {
    case "GELMEDI":
      return {
        title: `${personelName} gelmedi.`,
        subtitle: buildSubtitle(item.tarih, { subeAdi: subtitleOptions.subeAdi })
      };
    case "GEC_GELDI":
      return {
        title: dakika
          ? `${personelName} ${dakika} dakika geç geldi.`
          : `${personelName} geç geldi.`,
        subtitle: buildSubtitle(item.tarih, subtitleOptions)
      };
    case "ERKEN_CIKTI":
      return {
        title: dakika
          ? `${personelName} ${dakika} dakika erken çıktı.`
          : `${personelName} erken çıktı.`,
        subtitle: buildSubtitle(item.tarih, subtitleOptions)
      };
    case "IZINLI":
      return {
        title: `${personelName} izinli.`,
        subtitle: buildSubtitle(item.tarih, { subeAdi: subtitleOptions.subeAdi })
      };
    case "RAPORLU":
      return {
        title: `${personelName} raporlu.`,
        subtitle: buildSubtitle(item.tarih, { subeAdi: subtitleOptions.subeAdi })
      };
    case "GOREVDE":
      return {
        title: `${personelName} görevde çalıştı.`,
        subtitle: buildSubtitle(item.tarih, { subeAdi: subtitleOptions.subeAdi })
      };
    case "DIGER": {
      const aciklama = trimText(item.aciklama);
      return {
        title: aciklama
          ? `${personelName}: ${aciklama}`
          : `${personelName} için özel bildirim kaydı.`,
        subtitle: buildSubtitle(item.tarih, { subeAdi: subtitleOptions.subeAdi })
      };
    }
    default: {
      const eventLabel = formatBildirimTuruLabel(item.bildirim_turu);
      const hasPersonel = personelName !== PERSONEL_NAME_FALLBACK;
      return {
        title: hasPersonel
          ? `${personelName} · ${eventLabel}`
          : `Günlük bildirim · ${eventLabel}`,
        subtitle: buildSubtitle(item.tarih, { subeAdi: subtitleOptions.subeAdi })
      };
    }
  }
}

export type ReminderCopyInput = {
  key: "salary" | "sgk";
  daysLeft: number;
  dueDateLabel: string;
};

export function formatHeaderReminderCopy(input: ReminderCopyInput): HeaderNotificationCopy {
  const title =
    input.key === "salary"
      ? "Maaş ödeme zamanı yaklaşıyor."
      : "SGK prim ödeme takibini kontrol et.";

  const deadline =
    input.daysLeft <= 0
      ? `Bugün son gün · ${input.dueDateLabel}`
      : `${input.daysLeft} gün kaldı · ${input.dueDateLabel}`;

  return { title, subtitle: deadline };
}
