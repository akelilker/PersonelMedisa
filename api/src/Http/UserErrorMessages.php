<?php

declare(strict_types=1);

namespace Medisa\Api\Http;

/**
 * Onaylı karar (t865/HATA-KARAR.md): kullanıcıya gösterilecek hata metinleri.
 * Ön yüzdeki src/lib/display/user-error.ts ile aynı tablo. Ham 'message' tanılama içindir;
 * kullanıcıya 'user_message' gösterilir.
 */
final class UserErrorMessages
{
    public const OTURUM = 'Oturumunuz Sona Erdi. Lütfen Yeniden Giriş Yapın.';
    public const YETKI = 'Bu İşlem İçin Yetkiniz Yok. Yetki Gerekiyorsa Sistem Yöneticinize Başvurun.';
    public const KAPSAM = 'Bu Şube Veya Personel İçin İşlem Yapamazsınız. Yalnızca Size Atanmış Şubelerde İşlem Yapabilirsiniz.';
    public const BULUNAMADI = 'Kayıt Bulunamadı. Sayfayı Yenileyip Tekrar Deneyin.';
    public const GECERSIZ = 'Bu Alan Geçerli Değil. Lütfen Kontrol Edin.';
    public const ZORUNLU = 'Bu Alan Boş Bırakılamaz.';
    public const MUKERRER = 'Bu Kayıt Zaten Var.';
    public const ESZAMANLI = 'Bu Kayıt Siz İşlem Yaparken Değişti. Sayfayı Yenileyip Tekrar Deneyin.';
    public const DONEM_KAPALI = 'Bu Dönem Kapatıldığı İçin Değişiklik Yapılamaz. Düzeltme İçin Revizyon Merkezi\'nden Talep Açın.';
    public const DURUM_UYGUN_DEGIL = 'Bu Kayıt Mevcut Durumunda Bu İşleme Uygun Değil. Yalnızca Uygun Durumdaki Kayıtlarda Bu İşlem Yapılabilir.';
    public const ALTYAPI = 'Bu Özellik Şu Anda Kullanılamıyor. Lütfen Sistem Yöneticisine Bildirin.';
    public const ISLEM_TAMAMLANAMADI = 'İşlem Tamamlanamadı, Hiçbir Değişiklik Kaydedilmedi. Tekrar Deneyin. Sorun Sürerse Sistem Yöneticisine Bildirin.';

    public static function forError(int $status, string $code): string
    {
        $c = strtoupper($code);
        if ($status === 401 || str_contains($c, 'UNAUTHORIZED')) {
            return self::OTURUM;
        }
        if (str_contains($c, 'SCHEMA') || str_contains($c, 'NOT_READY')) {
            return self::ALTYAPI;
        }
        if (str_contains($c, 'SCOPE')) {
            return self::KAPSAM;
        }
        if ($status === 403 || str_contains($c, 'FORBIDDEN')) {
            return self::YETKI;
        }
        if ($status === 404 || str_contains($c, 'NOT_FOUND')) {
            return self::BULUNAMADI;
        }
        if (str_contains($c, 'SEALED') || str_contains($c, 'LOCKED') || str_contains($c, 'CLOSED')) {
            return self::DONEM_KAPALI;
        }
        if (str_contains($c, 'DUPLICATE') || str_contains($c, 'ALREADY')) {
            return self::MUKERRER;
        }
        if (str_contains($c, '_HAS_') || str_contains($c, 'INVALID_STATE')) {
            return self::DURUM_UYGUN_DEGIL;
        }
        if ($status === 409) {
            return self::ESZAMANLI;
        }
        if (str_contains($c, 'REQUIRED')) {
            return self::ZORUNLU;
        }
        if ($status === 400 || $status === 422) {
            return self::GECERSIZ;
        }
        return self::ISLEM_TAMAMLANAMADI;
    }
}
