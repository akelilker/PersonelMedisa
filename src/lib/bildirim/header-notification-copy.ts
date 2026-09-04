import { formatIsoDateDetail } from "../display/iso-date-format";
import type { GunlukTamamlamaHeaderItem } from "../../types/bildirim";

export type HeaderNotificationCopy = {
  title: string;
  subtitle: string;
};

function trimText(value: string | null | undefined): string | null {
  if (typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  return trimmed ? trimmed : null;
}

function formatNotificationDate(tarih: string | null | undefined): string | null {
  const formatted = formatIsoDateDetail(tarih);
  return formatted === "-" ? null : formatted;
}

/**
 * Header copy for a completed daily attendance submission.
 * Compact 2-line compose (title + date · CTA) preserves tasit density.
 */
export function formatHeaderGunlukTamamlamaCopy(
  item: Pick<GunlukTamamlamaHeaderItem, "tamamlayan_ad_soyad" | "tarih">
): HeaderNotificationCopy {
  const actor = trimText(item.tamamlayan_ad_soyad) ?? "Birim amiri";
  const dateLabel = formatNotificationDate(item.tarih) ?? "İşlem gerektiriyor";

  return {
    title: `${actor} Devamsızlık Bildirimini Tamamladı.`,
    subtitle: `${dateLabel} · Detayları Gör`
  };
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
