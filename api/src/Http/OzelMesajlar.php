<?php

declare(strict_types=1);

namespace Medisa\Api\Http;

/**
 * ÜRETİLMİŞ DOSYA — elle düzenlemeyin. Kaynak: src/lib/display/ozel-mesajlar.ts
 * (GEN_PHP=1 npx vite-node scripts/gen-ozel-mesajlar-php.ts). Onaylı karar t871 (OZEL-MESAJLAR-KISA.md, 39 satır).
 */
final class OzelMesajlar
{
    /** @var list<array{0:int,1:string,2:string}> [satır, desen, metin] */
    public const KURALLAR = [
        [39, 't\\.?\\s?c\\.? kimlik no\\.? 11 hane', 'T.C. Kimlik No. 11 Haneli Ve Yalnızca Rakam Olmalıdır.'],
        [1, 'i[sş]ten ayr[ıi]lma s[uü]reci manuel iptal', 'İşten Ayrılma Süreci İptal Edilemez. Personeli Geri Almak İçin Yeniden Aktif İşlemini Kullanın.'],
        [2, 'personel hesaplar[ıi] y[oö]netim paneli', 'Personel Hesabı Buradan Açılamaz. Personel Kartındaki Hesap Oluşturma Adımını Kullanın.'],
        [3, 'kullan[ıi]c[ıi] ad[ıi] canonical|canonical kullan[ıi]c[ıi] ad[ıi]', 'Kullanıcı Adı Personelin Adı Ve Soyadıyla Uyuşmuyor. Önce Kullanıcı Adını Düzeltin.'],
        [4, 'personel kayd[ıi] ba[sş]ka bir kullan[ıi]c[ıi]ya ba[gğ]l[ıi]', 'Bu Personel Başka Bir Kullanıcı Hesabına Bağlı. Farklı Bir Personel Seçin.'],
        [5, 'rol[uü] canonical modele [cç][oö]z[uü]lemedi', 'Hesabınızın Rolü Tanımlı Değil. Sistem Yöneticinizden Rolünüzü Düzenlemesini İsteyin.'],
        [6, 'giri[sş] s[ıi]ras[ıi]nda bir hata|first-login credential', 'Giriş Yapılamadı. Bilgilerinizi Kontrol Edip Tekrar Deneyin.'],
        [7, '^(sgk i[sş]vereni?)( kayd[ıi]| tan[ıi]m[ıi])? (kaydedilemedi|olu[sş]turulamad[ıi]|g[uü]ncellenemedi|eklenemedi)', 'SGK İşvereni Kaydedilemedi. Bilgileri Kontrol Edip Tekrar Deneyin.'],
        [7, '^([sş]irket)( kayd[ıi]| tan[ıi]m[ıi])? (kaydedilemedi|olu[sş]turulamad[ıi]|g[uü]ncellenemedi|eklenemedi)', 'Şirket Kaydedilemedi. Bilgileri Kontrol Edip Tekrar Deneyin.'],
        [7, '^([sş]ube)( kayd[ıi]| tan[ıi]m[ıi])? (kaydedilemedi|olu[sş]turulamad[ıi]|g[uü]ncellenemedi|eklenemedi)', 'Şube Kaydedilemedi. Bilgileri Kontrol Edip Tekrar Deneyin.'],
        [7, '^(departman)( kayd[ıi]| tan[ıi]m[ıi])? (kaydedilemedi|olu[sş]turulamad[ıi]|g[uü]ncellenemedi|eklenemedi)', 'Departman Kaydedilemedi. Bilgileri Kontrol Edip Tekrar Deneyin.'],
        [7, '^(kullan[ıi]c[ıi])( kayd[ıi]| tan[ıi]m[ıi])? (kaydedilemedi|olu[sş]turulamad[ıi]|g[uü]ncellenemedi|eklenemedi)', 'Kullanıcı Kaydedilemedi. Bilgileri Kontrol Edip Tekrar Deneyin.'],
        [8, '^(sgk i[sş]vereni?)( kayd[ıi]| tan[ıi]m[ıi])? (kal[ıi]c[ıi] olarak )?silinemedi', 'SGK İşvereni Silinemedi. Bağlı Kayıt Olup Olmadığını Kontrol Edip Tekrar Deneyin.'],
        [8, '^([sş]irket)( kayd[ıi]| tan[ıi]m[ıi])? (kal[ıi]c[ıi] olarak )?silinemedi', 'Şirket Silinemedi. Bağlı Kayıt Olup Olmadığını Kontrol Edip Tekrar Deneyin.'],
        [8, '^([sş]ube)( kayd[ıi]| tan[ıi]m[ıi])? (kal[ıi]c[ıi] olarak )?silinemedi', 'Şube Silinemedi. Bağlı Kayıt Olup Olmadığını Kontrol Edip Tekrar Deneyin.'],
        [8, '^(departman)( kayd[ıi]| tan[ıi]m[ıi])? (kal[ıi]c[ıi] olarak )?silinemedi', 'Departman Silinemedi. Bağlı Kayıt Olup Olmadığını Kontrol Edip Tekrar Deneyin.'],
        [8, '^(kullan[ıi]c[ıi])( kayd[ıi]| tan[ıi]m[ıi])? (kal[ıi]c[ıi] olarak )?silinemedi', 'Kullanıcı Silinemedi. Bağlı Kayıt Olup Olmadığını Kontrol Edip Tekrar Deneyin.'],
        [9, 'silinebilirlik kontrol', 'Bu Kaydın Silinip Silinemeyeceği Kontrol Edilemedi. Sayfayı Yenileyip Tekrar Deneyin.'],
        [10, 'saklama paneli y[uü]klenemedi', 'Saklama Paneli Yüklenemedi. Sayfayı Yenileyip Tekrar Deneyin.'],
        [10, 'saklama s[uü]resi de[gğ]erlendirilemedi', 'Saklama Süresi Hesaplanamadı. Sayfayı Yenileyip Tekrar Deneyin.'],
        [10, '[uü]cret tipi envanteri y[uü]klenemedi', 'Ücret Tipi Listesi Yüklenemedi. Sayfayı Yenileyip Tekrar Deneyin.'],
        [10, 'y[oö]netim paneli y[uü]klenemedi', 'Yönetim Paneli Yüklenemedi. Sayfayı Yenileyip Tekrar Deneyin.'],
        [11, 'koruma alt[ıi]na al[ıi]namad', 'Koruma Altına Alma Yapılamadı. Kayıt Değişmedi; Tekrar Deneyin.'],
        [11, 'koruma kald[ıi]r[ıi]lamad', 'Koruma Kaldırma Yapılamadı. Kayıt Değişmedi; Tekrar Deneyin.'],
        [11, 'saklama s[uü]resi bilgisi al[ıi]namad', 'Saklama Süresi Sorgusu Yapılamadı. Kayıt Değişmedi; Tekrar Deneyin.'],
        [11, 'imha talebi olu[sş]turulamad', 'İmha Talebi Yapılamadı. Kayıt Değişmedi; Tekrar Deneyin.'],
        [11, 'imha onay[ıi] i[sş]lenemedi', 'İmha Onayı Yapılamadı. Kayıt Değişmedi; Tekrar Deneyin.'],
        [12, 'ar[sş]iv\\.(view|download) yetkisi', 'Pasif Personelin Arşiv Bilgilerini Görmek İçin Arşiv Yetkisi Gerekir. Sistem Yöneticinize Başvurun.'],
        [13, 'demo_test_verisi', 'Demo Test Verisi Seçeneğini Yalnızca Genel Yönetici Kullanabilir.'],
        [14, 'bordro [oö]n izleme yetkisi gerekir', 'Bu Ekran İçin Bordro Ön İzleme Yetkisi Gerekir. Sistem Yöneticinize Başvurun.'],
        [15, 'haz[ıi]rlayan kullan[ıi]c[ıi] politikay[ıi] onaylayamaz', 'Politikayı Hazırlayan Kişi Onaylayamaz. Onayı Başka Bir Yetkili Vermelidir.'],
        [16, 'kan[ıi]t ge[cç]erli de[gğ]il; onay kapal', 'Kanıt Belgesi Geçerli Değil, Bu Yüzden Onay Verilemiyor. Belgeyi Yenileyip Tekrar Deneyin.'],
        [17, 'belge_id ve belge_sha256 birlikte', 'Belge Ve Belge Doğrulama Bilgisi Birlikte Girilmelidir.'],
        [18, 'politika tasla[gğ][ıi] olu[sş]turulamad', 'Politika Taslağı Oluşturulamadı. Tekrar Deneyin.'],
        [18, 'politika onaya g[oö]nderilemedi', 'Politika Onaya Gönderilemedi. Tekrar Deneyin.'],
        [18, 'politika onaylanamad', 'Politika Onaylanamadı. Tekrar Deneyin.'],
        [19, 'maa[sş] aday[ıi] [uü]retilemedi|bordro kesinle[sş]tirilemedi|^devir kaydedilemedi', 'Maaş İşlemi Tamamlanamadı, Hiçbir Değişiklik Kaydedilmedi. Ön Kontrolü Yeniden Çalıştırıp Tekrar Deneyin.'],
        [20, 'aktif snapshot sonras[ıi] kaynaklar de[gğ]i[sş]ti', 'Hesap Kaydından Sonra Puantaj Veya Ücret Bilgileri Değişti. Önce Mevcut Hesabı İptal Edip Yeniden Hesaplayın.'],
        [21, '[oö]nce [oö]n kontrol [cç]al[ıi][sş]t[ıi]r[ıi]lmal|[oö]nce ge[cç]erli [oö]n kontrol sonucu|i[cç]e aktarma [oö]n kontrol[uü] uygun de[gğ]il', 'Önce Ön Kontrolü Çalıştırın Ve Hataları Giderin, Sonra Devam Edin.'],
        [22, '(aday listesi|aday kalemleri|snapshot detay[ıi]) al[ıi]namad|bordro haz[ıi]rl[ıi]k verisi y[uü]klenemedi|devir [sş]ablonu indirilemedi', 'Maaş Hesaplama Bilgileri Yüklenemedi. Sayfayı Yenileyip Tekrar Deneyin.'],
        [23, '^(snapshot |[cç]al[ıi][sş]t[ıi]rma |[uü]cret kayd[ıi] )?iptal (edilemedi|ba[sş]ar[ıi]s[ıi]z)', 'İptal Yapılamadı, Kayıt Olduğu Gibi Duruyor. Sayfayı Yenileyip Tekrar Deneyin.'],
        [24, 'rapor verisi al[ıi]namad|ayl[ıi]k [oö]zet y[uü]klenemedi|serbest zaman takip y[uü]klenemedi|puantaj raporu d[ıi][sş]a aktar[ıi]lamad', 'Rapor Hazırlanamadı. Filtreleri Kontrol Edip Tekrar Deneyin.'],
        [25, 'sgk csv i[cç]in ay ve [sş]ube', 'SGK Dosyası İçin Önce Ay Ve Şube Seçin.'],
        [26, '(b[oö]l[uü]m onay[ıi]|[uü]st onay|genel y[oö]netici bildirim onay[ıi]) tamamlanamad', 'Onay Kaydedilemedi. Sayfayı Yenileyip Tekrar Deneyin.'],
        [28, 'manuel giri[sş] kullan[ıi]labilir', 'Seçenekler Yüklenemedi. Bilgiyi Elle Girerek Devam Edebilirsiniz.'],
        [27, '[sş]u an g[uü]ncellenemiyor', 'Liste Şu An Güncellenemiyor; Son Kaydedilen Bilgiler Gösteriliyor. Biraz Sonra Sayfayı Yenileyin.'],
        [29, 'ge[cç]erli bir (bildirim [oö]zeti|g[uü]nl[uü]k kay[ıi]t|personel|s[uü]re[cç]) id verilmedi', 'Açılmak İstenen Kayıt Bulunamadı. Listeden Kaydı Seçerek Tekrar Açın.'],
        [30, 'kayd[ıi] g[uü]ncellemek i[cç]in [oö]nce personel ve tarih', 'Kaydı Güncellemek İçin Önce Personel Ve Tarih Seçin.'],
        [31, 'm[uü]h[uü]rleme i[sş]lemi ba[sş]ar[ıi]s[ıi]z|kaynak m[uü]h[uü]r de[gğ]i[sş]mi[sş]', 'Dönem Mühürlenemedi; Dönem Bilgileri Bu Arada Değişmiş Olabilir. Sayfayı Yenileyip Tekrar Deneyin.'],
        [32, 'aday[ıi]n kaynak verisi do[gğ]rulanamad|aday daha [oö]nce farkl[ıi] bir manuel kararla', 'Bu Aday Bu Arada Değişti Veya Başka Bir Kararla Uygulandı. Liste Yenilendi; Kontrol Edip Tekrar Karar Verin.'],
        [33, 'a[cç][ıi]k giri[sş] kayd[ıi] varken yeni giri[sş]', 'Açık Bir Giriş Kaydınız Var. Önce Çıkış Okutun Veya Düzeltme Talebi Açın.'],
        [33, 'a[cç][ıi]k giri[sş] olmadan [cç][ıi]k[ıi][sş]', 'Giriş Kaydınız Yok. Önce Giriş Okutun.'],
        [34, 'qr token surumu|qr suresi doldu|qr okuma hatas', 'QR Kod Okunamadı Ya Da Süresi Doldu. Ekrandaki Güncel QR Kodu Tekrar Okutun.'],
        [35, 'kamera i[cç]in g[uü]venli ba[gğ]lant|kamera eri[sş]imini desteklemiyor', 'Kamera Açılamadı. Uygulamayı Güvenli Bağlantıyla (HTTPS) Açın Veya Kamerası Olan Bir Cihaz Kullanın.'],
        [36, 'bekleyen d[uü]zeltme talebi var', 'Düzeltme Talebi Açılamadı: Bu Hareket İçin Bekleyen Talep Var.'],
        [36, 'a[cç][ıi]k giri[sş]/[cç][ıi]k[ıi][sş] anomalisi yok', 'Düzeltme Talebi Açılamadı: Düzeltilecek Açık Kayıt Yok.'],
        [36, 'uygun ba[gğ][ıi]ms[ıi]z y[oö]netici bulunamad', 'Düzeltme Talebi Açılamadı: Onaylayacak Yönetici Bulunamadı. Amirinizle Görüşün.'],
        [36, 'd[uü]zeltme talebi s[uü]resi doldu', 'Düzeltme Talebi Açılamadı: Talep Süresi Doldu. Amirinizle Görüşün.'],
        [37, '(tarih penceresi|tarih aral[ıi][gğ][ıi]) en fazla 31 g[uü]n', 'Tarih Aralığı Uygun Değil: Aralık En Fazla 31 Gün Olabilir.'],
        [37, '(tarih penceresi|tarih aral[ıi][gğ][ıi]) en fazla 366 g[uü]n', 'Tarih Aralığı Uygun Değil: Aralık En Fazla 366 Gün Olabilir.'],
        [37, '(biti[sş]|kapan[ıi][sş]) tarihi .{0,30}[oö]nce olamaz|gecerlilik_bitis ba[sş]lang[ıi][cç]tan [oö]nce', 'Tarih Aralığı Uygun Değil: Bitiş Tarihi Başlangıçtan Önce Olamaz.'],
        [40, 'disiplin aday vakas[ıi] olu[sş]turulamad', 'Disiplin Vakası Oluşturulamadı. Bilgileri Kontrol Edip Tekrar Deneyin.'],
        [40, 'savunma belgesi bu a[sş]amada eklenemez', 'Savunma Belgesi Bu Aşamada Eklenemez. Önce Savunma Talebini Tamamlayın.'],
        [40, 'vaka bu a[sş]amada i[sş]lemsiz kapat[ıi]lamaz', 'Vaka Bu Aşamada İşlem Yapılmadan Kapatılamaz. Önce Karar Verin.'],
        [40, 'belge s[uü]reci personel ile uyu[sş]muyor', 'Seçilen Belge Bu Personele Ait Değil. Doğru Belgeyi Seçin.'],
        [41, '^talep olu[sş]turulamad', 'Talep Oluşturulamadı. Bilgileri Kontrol Edip Tekrar Deneyin.'],
        [42, 'olay karar[ıi] kaydedilemedi', 'Olay Kararı Kaydedilemedi. Sayfayı Yenileyip Tekrar Deneyin.'],
        [38, 'okundu i[sş]aretlenemedi', 'Okundu Olarak İşaretlenemedi. Biraz Sonra Tekrar Deneyin.'],
    ];

    public static function forMessage(string $message, string $code = ''): ?string
    {
        $raw = trim($message);
        if ($raw === '') {
            return null;
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower(str_replace('I', 'ı', str_replace('İ', 'i', $raw)), 'UTF-8') : strtolower($raw);
        $adaylar = [$lower, $raw, trim($code . ' ' . $lower)];
        foreach (self::KURALLAR as [, $desen, $metin]) {
            foreach ($adaylar as $t) {
                if (@preg_match('~' . $desen . '~iu', $t) === 1) {
                    return $metin;
                }
            }
        }
        return null;
    }
}
