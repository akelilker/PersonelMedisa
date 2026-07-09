# Medisa Personel ve Puantaj Yönetim Sistemi

## Sistem Genel Bakış

Sürüm: `V2` (Ürün Reset — S70A)

## Bu Dosya Ne İşe Yarar

Bu dosya sistemin tek bakışta anlaşılması için vardır.
Yeni gelen biri önce bunu okur, sonra ihtiyacına göre detay belgeye iner.

Bu dosya şu sorulara hızlı cevap verir:

- sistem ne yapıyor?
- veri hangi sırayla akıyor?
- hangi iş hangi modülü tetikliyor?
- hangi konu için hangi belgeye bakmalıyım?

## Sistemin Tek Cümlelik Özeti

Medisa Personel, personel kayıt sistemi değil; mevzuata uyumlu personel, puantaj, onay ve bordro yönetim sistemidir.

## 1. Ana Operasyon Zinciri

```text
Personel ana kayıtları
  -> Personel süreç / tarihçe kayıtları
  -> BIRIM_AMIRI günlük bildirimleri
  -> Haftalık A4/imza mutabakatı
  -> BOLUM_YONETICISI haftalık/aylık kontrol ve bölüm onayı
  -> GENEL_YONETICI bordro öncesi operasyonel onay
  -> PATRON sembolik gördü/onayı
  -> Hesap motoru
  -> Bordro ön izleme
  -> Nihai bordro / rapor
```

## 2. Veri Akışı

```text
Form / kullanıcı aksiyonu
  -> frontend validasyonu (görünüm; nihai hesap yapmaz)
  -> API isteği
  -> backend validasyonu, yetki ve state geçişi
  -> veritabanı kayıtları
  -> hesap motoru (backend tek yetkili)
  -> haftalık mutabakat + teknik kapanış snapshot
  -> aylık onay zinciri
  -> bordro ön izleme / nihai bordro
  -> UI özetleri, listeler ve raporlar
```

## 3. Çekirdek Modüller

- `Personeller`
Ana kart ve listeleme katmanı.
- `Süreçler`
İzin, rapor, devamsızlık, işten ayrılma gibi hareketlerin event katmanı.
- `Günlük Amir Bildirimi`
`BIRIM_AMIRI` tarafından girilen operasyonel günlük kayıt katmanı. Puantaj ve bordro hesap zincirinin ham veri kaynağıdır.
- `Haftalık Mutabakat + Teknik Kapanış`
Haftalık A4/imza mutabakatı (operasyonel onay) ve ardından teknik mühür/snapshot katmanı. Yalnızca teknik kapanış operasyonel onayın yerine geçmez.
- `Aylık Onay`
`BOLUM_YONETICISI` bölüm onayı ve `GENEL_YONETICI` bordro öncesi operasyonel onayı.
- `Hesap Motoru`
Mevzuat, şirket parametresi ve manuel inceleme kurallarına göre puantaj/bordro hesabı. Nihai hedefte backend tek yetkilidir.
- `Bordro Ön İzleme`
Onay zinciri tamamlandıktan sonra üretilen kontrol ve çıktı katmanı.
- `Raporlar`
Operasyon, yönetim ve denetim görünürlüğünü sağlayan çıktı katmanı.

### 3.1 Bildirim Kavramının İkili Ayrımı

Sistemde “bildirim” iki ayrı katman olarak düşünülür:

1. **Operasyonel günlük amir bildirimi**
   - `BIRIM_AMIRI` tarafından girilir.
   - Kim gelmedi, geç geldi, erken çıktı, raporlu, izinli, görevde gibi günlük operasyon verisidir.
   - Puantaj/bordro hesap zincirinin ham verisi olabilir.
   - Süreç kaydına otomatik dönüşmek zorunda değildir.

2. **Sistem uyarı / notification katmanı**
   - Header paneli, okunmamış kayıt, takvim hatırlatmaları gibi pasif uyarı yüzeyidir.
   - Operasyonel kayıt ile karıştırılmamalıdır.

## 4. En Kritik Ürün Kararları

- Personel kartı sabittir, hareketler süreç olarak akar.
- `İşten Ayrılma` kart alanı değil süreç kaydıdır.
- `Hizmet Süresi`, `Kalan İzin` gibi alanlar form alanı değildir.
- Tek login vardır; rol bazlı görünürlük değişir.
- Görsel dil `tasitmedisa` referansını alır, teknik borcu almaz.
- Haftalık mutabakat tamamlanmadan aylık bölüm onayı verilemez.
- Bölüm onayı tamamlanmadan genel yönetici onayı verilemez.
- Genel yönetici onayı tamamlanmadan nihai bordro oluşmaz.
- Patron gördü/onayı bordroyu bloklamaz.
- Şirket parametresi eksikse hesap kesinleşmez.
- Frontend nihai maaş/puantaj hesabı yapmaz.

## 5. Doküman Haritası

- Ürün yönü ve kapsam: `01-urun-anayasasi.md`
- Veri kapsamı: `02-mvp-veri-kapsami.md`
- UI ve component davranışı: `03-ui-bilesen-sozlesmesi.md`
- Hesap ve mevzuat kuralları: `04-hesap-motoru-kurallari.md`
- API ve state yaşam döngüsü: `05-state-flow-api-kontrati.md`
- Kod ve klasör yapısı: `06-proje-scaffold.md`
- Kullanıcı akışları: `07-is-akislari-ve-senaryolar.md`
- Frontend disiplin kuralları: `08-frontend-teknik-mimari.md`
- Rol ve yetki matrisi: `09-rol-yetki-matrisi.md`
- Eksik gün ve SGK prim günü karar matrisi: `13-eksik-gun-sgk-prim-gunu-kural-matrisi.md`
- SGK eksik gün nedeni eşleme tablosu: `14-sgk-eksik-gun-nedeni-esleme-tablosu.md`
- Güncel puantaj geliştirme devri ve doğrulama özeti: `12-puantaj-gelistirici-devir-notu.md`

## 6. Altın Kurallar

- Aynı veri iki farklı yerde ana kaynak olamaz.
- Aynı UI parçasının tek sahibi olur.
- Hesaplanan alan kullanıcıdan manuel alınmaz.
- State geçişinin tek sahibi backend'dir.
- Sorunlar yama ile değil, kök sebep düzeltilerek çözülür.
- Operasyonel onay ile teknik mühür aynı kavram değildir.

## 7. Okuma Sırası

1. `00-sistem-genel-bakis.md`
2. `01-urun-anayasasi.md`
3. İhtiyaca göre ilgili uzman belge

## Sonuç

Bu dosya sistemin akıl haritasıdır.
Detay belgesi değildir; doğru belgeye en hızlı yoldan ulaşmak için kullanılır.
