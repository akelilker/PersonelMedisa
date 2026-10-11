# Dinamik kullanıcı yetkisi — P5 (yetki ekranı)

Durum: PR (MERGE=NO). Yeni migration yok. Migration 100 değiştirilmedi ve canlıya uygulanmadı.

## Ne
Genel Yönetici, mevcut Kullanıcı Yönetimi ekranında bir kullanıcıyı açınca o hesabın yetkisini görür ve değiştirir. Ayrı bir yetki uygulaması yoktur.

Görünen üç katman:
- Rolün varsayılan yetkileri
- Etkin yetkiler (rol, kişisel izin, kısıt nedeniyle kapalı)
- Kişisel istisnalar (izin veya kısıt, süre, gerekçe)

Yazma mevcut uçlardadır: `POST /yonetim/kullanicilar/{id}/yetkiler` ve `POST .../yetkiler/{istisnaId}/iptal`. Geçmiş `GET /yonetim/yetki-auditleri` ile okunur.

## Kurallar
- Kendi hesabı bu ekrandan değiştirilmez. Aynı kişinin başka hesabı, atayanın yetki genişletmesi ve hassas başlangıç kısıtı sunucudaki P3–P4 kurallarıyla reddedilir; ekran bu ret mesajını gösterir ve işlemi olmuş saymaz.
- Kırmızı listedeki yetkiler izin listesinde yoktur ve hassas kısıtın kaldırma düğmesi yoktur. Sunucu aynı isteği ayrıca reddeder.
- Şube bazlı istisna kapalı kalır; ekran şube göndermez.
- `schema_ready` değilse veya yazma `YETKI_SEMASI_HAZIR_DEGIL` dönerse ekran uyarı verir. Başarı yazılmaz, boş liste “istisna yok” diye gösterilmez.

## Bilinçli sınır
- Şirket bazlı Genel Yönetici mimarisi bu aşamada yoktur.
- Yönetici gelen kutusu bu ekranın dışında kaldı. Bildirim kaydı P3’te yazılır; kabukta gösterim ayrıca ele alınır.
- Kimliği personel kaydı veya doğrulanmış kimlik köprüsüyle belirlenemeyen eski ikinci hesaplarda aynı kişi koruması P3’teki gibi eksiktir. Ekran bu hesapları birleştirmez.
