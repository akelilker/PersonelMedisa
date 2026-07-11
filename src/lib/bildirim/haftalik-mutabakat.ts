import type { HaftalikBildirimMutabakatCounts } from "../../types/haftalik-bildirim-mutabakat";

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
