<?php

declare(strict_types=1);

namespace Medisa\Api\Http;

/**
 * Onaylı karar (t869): alan anahtarı → kullanıcıya görünen alan adı (Title Case).
 * Ön yüz aynası: src/lib/display/field-labels.ts (guard testi eşitliği kontrol eder).
 */
final class FieldLabels
{
    public const LABELS = [
        'aciklama' => 'Açıklama',
        'ad' => 'Ad',
        'ad_soyad' => 'Ad Soyad',
        'aktif_durum' => 'Aktif Durum',
        'alt_tur' => 'Alt Tür',
        'ay' => 'Ay',
        'bagli_amir_id' => 'Bağlı Amir',
        'baslangic_tarihi' => 'Başlangıç Tarihi',
        'baslangic_at' => 'Başlangıç Saati',
        'belge_no' => 'Belge No.',
        'belge_turu' => 'Belge Türü',
        'bildirim_turu' => 'Bildirim Türü',
        'birim_amiri_user_id' => 'Birim Amiri',
        'birim_id' => 'Birim',
        'birim_ids' => 'Birimler',
        'bitis_tarihi' => 'Bitiş Tarihi',
        'bitis_at' => 'Bitiş Saati',
        'bolum_id' => 'Bölüm',
        'bolum_ids' => 'Bölümler',
        'calisma_lokasyonu_id' => 'Çalışma Lokasyonu',
        'cinsiyet' => 'Cinsiyet',
        'current_password' => 'Mevcut Şifre',
        'dakika' => 'Dakika',
        'departman_id' => 'Departman',
        'dogum_tarihi' => 'Doğum Tarihi',
        'dogum_yeri' => 'Doğum Yeri',
        'donem' => 'Dönem',
        'dosya_adi' => 'Dosya Adı',
        'effective_date' => 'Geçerlilik Tarihi',
        'etki_miktari' => 'Etki Miktarı',
        'etkilenen_tarih' => 'Etkilenen Tarih',
        'event_tarihi' => 'Olay Tarihi',
        'gecerlilik_baslangic' => 'Geçerlilik Başlangıcı',
        'gecerlilik_bitis' => 'Geçerlilik Bitişi',
        'gerekce' => 'Gerekçe',
        'gorev_id' => 'Görev / Unvan',
        'gun_sayisi' => 'Gün Sayısı',
        'gun_tipi' => 'Gün Tipi',
        'hafta_baslangic' => 'Hafta Başlangıcı',
        'hafta_bitis' => 'Hafta Bitişi',
        'hedef_birim_id' => 'Hedef Birim',
        'hedef_bolum_id' => 'Hedef Bölüm',
        'iptal_nedeni' => 'İptal Nedeni',
        'ise_giris_tarihi' => 'İşe Giriş Tarihi',
        'izin_turu' => 'İzin Türü',
        'kalem_turu' => 'Kalem Türü',
        'kan_grubu' => 'Kan Grubu',
        'karar_notu' => 'Karar Notu',
        'karar_turu' => 'Karar Türü',
        'kayit_tipi' => 'Kayıt Tipi',
        'kisa_kod' => 'Kısa Kod',
        'kod' => 'Kod',
        'maas_tutari' => 'Maaş Tutarı',
        'neden' => 'Neden',
        'new_password' => 'Yeni Şifre',
        'odeme_tipi' => 'Ödeme Tipi',
        'personel_id' => 'Personel',
        'personel_tipi_id' => 'Statü',
        'pozisyon_id' => 'Pozisyon',
        'referans_tarih' => 'Referans Tarihi',
        'rol' => 'Rol',
        'sgk_isveren_id' => 'SGK İşveren',
        'sicil_no' => 'Sicil No.',
        'sirket_id' => 'Şirket',
        'soyad' => 'Soyad',
        'sube_id' => 'Şube',
        'sube_ids' => 'Şubeler',
        'surec_turu' => 'Süreç Türü',
        'talep_tarihi' => 'Talep Tarihi',
        'tarih' => 'Tarih',
        'tc_kimlik_no' => 'T.C. Kimlik No.',
        'telefon' => 'Telefon',
        'teslim_durumu' => 'Teslim Durumu',
        'teslim_tarihi' => 'Teslim Tarihi',
        'tutar' => 'Tutar',
        'ucret_tipi_id' => 'Ücret Tipi',
        'urun_turu' => 'Ürün Türü',
        'username' => 'Kullanıcı Adı',
        'yeni_sube_id' => 'Yeni Şube',
        'yil' => 'Yıl',
        'zimmet_durumu' => 'Zimmet Durumu',
    ];

    public static function labelOf(?string $field): ?string
    {
        if ($field === null || $field === '') {
            return null;
        }
        return self::LABELS[$field] ?? null;
    }
}
