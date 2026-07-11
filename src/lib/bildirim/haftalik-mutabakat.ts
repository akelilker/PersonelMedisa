import type {
  HaftalikBildirimMutabakatCounts,
  HaftalikBildirimMutabakatOzet
} from "../../types/haftalik-bildirim-mutabakat";

export function getCurrentMondayIsoDate(reference = new Date()): string {
  const date = new Date(reference);
  const day = date.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  date.setDate(date.getDate() + diff);
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const dayOfMonth = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${dayOfMonth}`;
}

export function computeHaftaBitisFromMonday(haftaBaslangic: string): string | null {
  if (!isMondayIsoDate(haftaBaslangic)) {
    return null;
  }

  const date = new Date(`${haftaBaslangic}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + 6);
  return date.toISOString().slice(0, 10);
}

export function isHaftalikMutabakatApproveEnabled(
  canApprove: boolean,
  ozet: HaftalikBildirimMutabakatOzet | null
): boolean {
  return canApprove && ozet?.onaylanabilir_mi === true;
}

export function resolveHaftalikMutabakatStatusMessage(
  ozet: HaftalikBildirimMutabakatOzet | null
): string | null {
  if (!ozet) {
    return null;
  }

  if (ozet.mevcut_mutabakat_id) {
    return "Bu hafta mutabakata alinmis.";
  }

  if (ozet.onaylanabilir_mi) {
    return "Bu hafta onaylanabilir.";
  }

  return ozet.blok_nedeni;
}

export function isMondayIsoDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value && date.getUTCDay() === 1;
}

export function resolveHaftalikMutabakatApproval(
  counts: HaftalikBildirimMutabakatCounts,
  mevcutMutabakatId: number | null
): { onaylanabilir_mi: boolean; blok_nedeni: string | null } {
  if (mevcutMutabakatId) return { onaylanabilir_mi: false, blok_nedeni: "Bu hafta icin mutabakat zaten mevcut." };
  if (counts.taslak > 0) return { onaylanabilir_mi: false, blok_nedeni: "Haftada taslak bildirim bulunuyor." };
  if (counts.duzeltme_istendi > 0) return { onaylanabilir_mi: false, blok_nedeni: "Haftada duzeltme bekleyen bildirim bulunuyor." };
  if (counts.haftalik_mutabakata_alindi > 0) return { onaylanabilir_mi: false, blok_nedeni: "Haftadaki bildirimler daha once mutabakata alinmis." };
  if (counts.gonderildi < 1) return { onaylanabilir_mi: false, blok_nedeni: "Mutabakata alinacak gonderilmis bildirim bulunamadi." };
  return { onaylanabilir_mi: true, blok_nedeni: null };
}
