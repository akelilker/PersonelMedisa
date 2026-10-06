/**
 * Yönetim > Kullanıcılar `ad_soyad` WRITE normalizasyonu.
 *
 * Görünüm kuralı (Ad Türkçe Title Case + SOYAD BÜYÜK) yalnız display/render
 * katmanında uygulanır (YonetimPaneliPage içindeki `formatAdSoyad`). Canonical kayıt
 * mutate edilmez: burada yalnız baştaki/sondaki ve çoklu iç boşluk temizlenir;
 * kullanıcının girdiği canonical case (ör. "Saıf Tareq Jasım Al-Gburı") korunur.
 */
export function normalizeKullaniciAdSoyadForWrite(value: string) {
  return String(value ?? "")
    .trim()
    .replace(/\s+/g, " ");
}
