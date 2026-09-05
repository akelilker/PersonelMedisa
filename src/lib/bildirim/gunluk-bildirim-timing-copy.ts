/** Exact product copy for daily notification timing / missing-entry rules. */

export function formatEksikGirisAttention(count: number): string {
  return `${count} Personel Henüz Giriş Yapmadı`;
}

export function formatEksikGirisCompleteConfirm(count: number): string {
  return `${count} Personel Henüz Giriş Yapmadı. Önce Kontrol Etmeniz Önerilir. Yine de Devam Etmek İstiyor musunuz?`;
}

export function formatPazarMesaiMondayPrompt(count: number): string {
  return `Dün Mesaiye Gelen ${count} Personel Var. Bildirimi Tamamlamak İster misiniz?`;
}
