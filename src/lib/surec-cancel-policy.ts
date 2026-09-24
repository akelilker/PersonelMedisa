/**
 * Canonical süreç iptal politikası (generic cancel fail-closed).
 *
 * ISTEN_AYRILMA, PersonelIstenAyrilmaService'in sahip olduğu personel yaşam
 * döngüsü çıkışıdır: personeli PASIF yapar ve retention/arşiv manifestlerini
 * üretir. Geri dönüşün tek kanonik yolu audited yeniden-aktif akışıdır
 * (PersonelYenidenAktifService), o da tam olarak BİR açık ISTEN_AYRILMA süreci
 * arar. Bu süreç generic olarak iptal edilirse personel PASIF kalır ve açık
 * çıkış süreci sıfırlanır; kayıt geri dönüşsüz sıkışır (backend 409
 * REACTIVATE_EXIT_SUREC_MISSING). Bu yüzden generic "İptal" aksiyonu
 * ISTEN_AYRILMA için hiç sunulmaz; backend'de de fail-closed reddedilir.
 *
 * Backend owner: SureclerController::cancel
 * (kod: ISTEN_AYRILMA_CANCEL_NOT_ALLOWED).
 */
export const ISTEN_AYRILMA_SUREC_TURU = "ISTEN_AYRILMA";

export const ISTEN_AYRILMA_CANCEL_NOT_ALLOWED_CODE = "ISTEN_AYRILMA_CANCEL_NOT_ALLOWED";

export const ISTEN_AYRILMA_CANCEL_NOT_ALLOWED_MESSAGE =
  "İşten ayrılma süreci manuel iptal edilemez. Yeniden aktif akışı kullanılmalıdır.";

/** Diğer tüm süreç türlerinde generic iptal davranışı aynen korunur. */
export function isSurecTuruCancelBlocked(surecTuru: string | null | undefined): boolean {
  return String(surecTuru ?? "").trim().toUpperCase() === ISTEN_AYRILMA_SUREC_TURU;
}
