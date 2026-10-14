# Dinamik kullanıcı yetkisi — P5 (yetki ekranı)

Durum: kod main'de (PR #532). Migration 100 canlıda uygulandı (Apply `38222514124`). Hassas kilit alanının ekrana bağlanması ve etkin izinlerin oturuma alınması PR #560 ile yayında (Deploy run `38519660078`, SHA `fb1e3708…`). Canlı yazma yapılmadı.

## Ne
Genel Yönetici, mevcut Kullanıcı Yönetimi ekranında bir kullanıcıyı açınca o hesabın yetkisini görür ve değiştirir. Ayrı bir yetki uygulaması yoktur.

Görünen üç katman:
- Rolün varsayılan yetkileri
- Etkin yetkiler (rol, kişisel izin, kısıt nedeniyle kapalı)
- Kişisel istisnalar (izin veya kısıt, süre, gerekçe)

Yazma mevcut uçlardadır: `POST /yonetim/kullanicilar/{id}/yetkiler` ve `POST .../yetkiler/{istisnaId}/iptal`. Geçmiş `GET /yonetim/yetki-auditleri` ile okunur.

## Kurallar
- Kendi hesabı bu ekrandan değiştirilmez. Aynı kişinin başka hesabı, atayanın yetki genişletmesi ve hassas başlangıç kısıtı sunucudaki P3–P4 kurallarıyla reddedilir; ekran bu ret mesajını gösterir ve işlemi olmuş saymaz.
- Kırmızı listedeki yetkiler izin listesine konmaz. Kaldırma düğmesi yalnız sunucunun `baslangic_hassas_kilit` alanı true olan başlangıç kaydında gizlenir. Sonradan konan hassas kısıt ekrandan kaldırılabilir. Sunucu yalnız `GY_BASLANGIC_PROFILI` kaynaklı hassas DENY'ı reddeder.
- Şube bazlı istisna kapalı kalır; ekran şube göndermez.
- `schema_ready` değilse veya yazma `YETKI_SEMASI_HAZIR_DEGIL` dönerse ekran uyarı verir. Başarı yazılmaz, boş liste “istisna yok” diye gösterilmez.

## Bilinçli sınır
- Şirket bazlı Genel Yönetici mimarisi bu aşamada yoktur.
- Yönetici gelen kutusu bu ekranın dışında kaldı. Bildirim kaydı P3’te yazılır. Genel Yönetici ana sayfasındaki zil bu gelen kutusunu okur. İç ekranlarda gösterim kapsam dışıdır; yeni iş açılmaz.
- Kimliği personel kaydı veya doğrulanmış kimlik köprüsüyle belirlenemeyen eski ikinci hesaplarda aynı kişi koruması P3’teki gibi eksiktir. Ekran bu hesapları birleştirmez.
