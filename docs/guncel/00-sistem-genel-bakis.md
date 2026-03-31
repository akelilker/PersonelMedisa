# Medisa Personel ve Puantaj Yönetim Sistemi

## Sistem Genel Bakış

Sürüm: `V1`

## Bu Dosya Ne İşe Yarar

Bu dosya sistemin tek bakışta anlaşılması için vardır.
Yeni gelen biri önce bunu okur, sonra ihtiyacına göre detay belgeye iner.

Bu dosya şu sorulara hızlı cevap verir:

- sistem ne yapıyor?
- veri hangi sırayla akıyor?
- hangi iş hangi modülü tetikliyor?
- hangi konu için hangi belgeye bakmalıyım?

## Sistemin Tek Cümlelik Özeti

Bu ürün, Excel ile yürüyen personel, süreç, puantaj ve kapanış operasyonunu tek merkezli, izlenebilir ve kurallı bir sisteme çevirir.

## 1. Sistem Akışı

```text
Personel oluştur
  -> süreç / hareket ekle
  -> günlük puantaj etkisi oluşur
  -> hesap motoru kuralları çalışır
  -> haftalık kapanış alınır
  -> rapor ve çıktı üretilir
```

## 2. Veri Akışı

```text
Form / kullanıcı aksiyonu
  -> frontend validasyonu
  -> API isteği
  -> backend validasyonu ve state kararı
  -> veritabanı kayıtları
  -> hesap motoru / türetilmiş alanlar
  -> haftalık snapshot / kapanış
  -> UI özetleri, listeler ve raporlar
```

## 3. Çekirdek Modüller

- `Personeller`
Ana kart ve listeleme katmanı.
- `Süreçler`
İzin, rapor, devamsızlık, işten ayrılma gibi hareketlerin event katmanı.
- `Puantaj`
Günlük çalışma ve yokluk etkilerinin işlendiği katman.
- `Haftalık Kapanış`
Belirli haftanın mühürlendiği ve raporlama tabanının oluştuğu katman.
- `Bildirimler`
İşlem gerektiren durumları kullanıcıya taşıyan uyarı katmanı.
- `Raporlar`
Operasyon ve yönetim görünürlüğünü sağlayan çıktı katmanı.

## 4. En Kritik Ürün Kararları

- Personel kartı sabittir, hareketler süreç olarak akar.
- `İşten Ayrılma` kart alanı değil süreç kaydıdır.
- `Hizmet Süresi`, `Kalan İzin` gibi alanlar form alanı değildir.
- Tek login vardır; rol bazlı görünürlük değişir.
- Görsel dil `tasitmedisa` referansını alır, teknik borcu almaz.

## 5. Doküman Haritası

- Ürün yönü ve kapsam: `01-urun-anayasasi.md`
- Veri kapsamı: `02-mvp-veri-kapsami.md`
- UI ve component davranışı: `03-ui-bilesen-sozlesmesi.md`
- Hesap ve mevzuat kuralları: `04-hesap-motoru-kurallari.md`
- API ve state yaşam döngüsü: `05-state-flow-api-kontrati.md`
- Kod ve klasör yapısı: `06-proje-scaffold.md`
- Kullanıcı akışları: `07-is-akislari-ve-senaryolar.md`
- Frontend disiplin kuralları: `08-frontend-teknik-mimari.md`

## 6. Altın Kurallar

- Aynı veri iki farklı yerde ana kaynak olamaz.
- Aynı UI parçasının tek sahibi olur.
- Hesaplanan alan kullanıcıdan manuel alınmaz.
- State geçişinin tek sahibi backend'dir.
- Sorunlar yama ile değil, kök sebep düzeltilerek çözülür.

## 7. Okuma Sırası

1. `00-sistem-genel-bakis.md`
2. `01-urun-anayasasi.md`
3. İhtiyaca göre ilgili uzman belge

## Sonuç

Bu dosya sistemin akıl haritasıdır.
Detay belgesi değildir; doğru belgeye en hızlı yoldan ulaşmak için kullanılır.
