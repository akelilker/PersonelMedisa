import type { AylikBildirimOnayOzet } from "../../types/aylik-bildirim-onay";

export type AylikBildirimOnayCounts = {
  toplam_bildirim: number;
  mutabakata_alinan: number;
  mutabakatli_hafta: number;
  eksik_hafta: number;
  taslak: number;
  duzeltme_istendi: number;
  gonderildi: number;
};

export type AylikBildirimOnayHafta = {
  hafta_baslangic: string;
  hafta_bitis: string;
  mutabakat_id: number | null;
  state: string | null;
  bildirim_sayisi: number;
  mutabakata_alinan_sayisi: number;
  eksik_mi: boolean;
  blok_nedeni: string | null;
};

export function getCurrentMonthValue(reference = new Date()): string {
  return `${reference.getFullYear()}-${String(reference.getMonth() + 1).padStart(2, "0")}`;
}

export function isAylikBildirimOnayApproveEnabled(
  canApprove: boolean,
  ozet: AylikBildirimOnayOzet | null
): boolean {
  return canApprove && ozet?.onaylanabilir_mi === true && !ozet?.mevcut_onay_id;
}

export function resolveAylikBildirimOnayStatusMessage(
  ozet: AylikBildirimOnayOzet | null
): string | null {
  if (!ozet) {
    return null;
  }

  if (ozet.mevcut_onay_id) {
    return "Bu ay aylık bildirim onayına gönderilmiş.";
  }

  if (ozet.onaylanabilir_mi) {
    return "Bu ay aylık onaya gönderilebilir.";
  }

  return ozet.blok_nedeni;
}

export function isValidAyValue(value: string): boolean {
  if (!/^\d{4}-\d{2}$/.test(value)) {
    return false;
  }
  const month = Number.parseInt(value.split("-")[1] ?? "", 10);
  return Number.isFinite(month) && month >= 1 && month <= 12;
}

export function resolveAyBounds(ay: string): { ay_baslangic: string; ay_bitis: string } | null {
  if (!isValidAyValue(ay)) {
    return null;
  }

  const [yearText, monthText] = ay.split("-");
  const year = Number.parseInt(yearText, 10);
  const month = Number.parseInt(monthText, 10);
  if (!Number.isFinite(year) || !Number.isFinite(month) || month < 1 || month > 12) {
    return null;
  }

  const ayBaslangic = `${yearText}-${monthText}-01`;
  const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
  const ayBitis = `${yearText}-${monthText}-${String(lastDay).padStart(2, "0")}`;
  return { ay_baslangic: ayBaslangic, ay_bitis: ayBitis };
}

export function listWeeksIntersectingMonth(ayBaslangic: string, ayBitis: string): Array<{
  hafta_baslangic: string;
  hafta_bitis: string;
}> {
  const start = new Date(`${ayBaslangic}T00:00:00Z`);
  const end = new Date(`${ayBitis}T00:00:00Z`);
  const day = start.getUTCDay();
  const diff = day === 0 ? -6 : 1 - day;
  start.setUTCDate(start.getUTCDate() + diff);

  const weeks: Array<{ hafta_baslangic: string; hafta_bitis: string }> = [];
  while (start <= end) {
    const haftaBaslangic = start.toISOString().slice(0, 10);
    const weekEnd = new Date(start);
    weekEnd.setUTCDate(weekEnd.getUTCDate() + 6);
    weeks.push({
      hafta_baslangic: haftaBaslangic,
      hafta_bitis: weekEnd.toISOString().slice(0, 10)
    });
    start.setUTCDate(start.getUTCDate() + 7);
  }

  return weeks;
}

export function isDateWithinMonthRange(tarih: string, ayBaslangic: string, ayBitis: string): boolean {
  return tarih >= ayBaslangic && tarih <= ayBitis;
}

type ApprovalInput = {
  counts: AylikBildirimOnayCounts;
  mevcutOnayId: number | null;
  eksikHaftaSayisi: number;
};

export function resolveAylikBildirimOnayApproval(input: ApprovalInput): {
  onaylanabilir_mi: boolean;
  blok_nedeni: string | null;
} {
  if (input.mevcutOnayId) {
    return { onaylanabilir_mi: false, blok_nedeni: "Bu ay icin aylik bildirim onayi zaten mevcut." };
  }
  if (input.counts.taslak > 0) {
    return { onaylanabilir_mi: false, blok_nedeni: "Ayda taslak bildirim bulunuyor." };
  }
  if (input.counts.duzeltme_istendi > 0) {
    return { onaylanabilir_mi: false, blok_nedeni: "Ayda duzeltme bekleyen bildirim bulunuyor." };
  }
  if (input.counts.gonderildi > 0) {
    return { onaylanabilir_mi: false, blok_nedeni: "Ayda haftalik mutabakata alinmamis gonderilmis bildirim bulunuyor." };
  }
  if (input.counts.mutabakata_alinan < 1) {
    return { onaylanabilir_mi: false, blok_nedeni: "Aylik onaya alinacak mutabakata alinmis bildirim bulunamadi." };
  }
  if (input.eksikHaftaSayisi > 0) {
    return { onaylanabilir_mi: false, blok_nedeni: "Ayda eksik haftalik mutabakat bulunuyor." };
  }
  if (input.counts.toplam_bildirim < 1) {
    return { onaylanabilir_mi: false, blok_nedeni: "Aylik onaya alinacak bildirim bulunamadi." };
  }

  return { onaylanabilir_mi: true, blok_nedeni: null };
}
