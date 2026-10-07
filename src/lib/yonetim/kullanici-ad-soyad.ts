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

/**
 * Retention imha / test-fixture tombstone placeholder'ı (backend
 * PersonelOzlukDestructionHandler: ad='DESTROYED', soyad='PERSONEL').
 * Bir kişinin adı değildir; Kullanıcı Yönetimi'nde görünen ad olarak gösterilmez.
 */
const TOMBSTONED_PERSONEL_AD_SOYAD = "DESTROYED PERSONEL";

export function isTombstonedPersonelAdSoyad(value: string | null | undefined) {
  return normalizeKullaniciAdSoyadForWrite(value ?? "").toUpperCase() === TOMBSTONED_PERSONEL_AD_SOYAD;
}
