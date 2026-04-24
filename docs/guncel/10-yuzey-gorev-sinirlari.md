# Medisa Personel ve Puantaj Yönetim Sistemi

## Yüzey Görev Sınırları ve Toparlama Planı

Sürüm: `V1-toparlama`

## Amaç

Bu belge, sistemin üç ana ekranının görev sınırlarını netleştirir.
Hedef yeni özellik eklemek değil; mevcut çalışan akışları bozmadan hangi ekranın neyi sahiplenmesi gerektiğini sabitlemektir.

Ana karar:

- `Kayıt ve Süreç` veri girişi ve operasyon merkezidir.
- `Personel Kartı` personel dosyası, görüntüleme ve geçmiş inceleme merkezidir.
- `Raporlar` çıktı, kontrol ve karar ekranıdır.

Bu ayrım tek hamlede uygulanmayacaktır. Önce mevcut operasyonlar envanterlenir, sonra küçük ve test edilebilir paketlerle taşınır.

## Üç Ana Yüzey

### Kayıt ve Süreç

Bu ekran sisteme veri girilen ana merkezdir.

Sahip olacağı işler:

- yeni personel kaydı
- personel seçimi üzerinden süreç ekleme
- atama, görev, ücret tipi, prim kuralı ve benzeri operasyonel değişiklikler
- ileride zimmet ve benzeri dosya işlemlerinin merkezi giriş akışı

Korunacak ana yapı:

- `Kayıt` sekmesi
- `Süreç` sekmesi

İleride `Süreç` sekmesi kişi seçimi merkezli hale getirilir:

- personel seçimi
- kısa kişi özeti
- işlem kolu seçimi
- ilgili forma geçiş

### Personel Kartı

Bu ekran personelin dosyasıdır.

Kalması gereken işler:

- kimlik ve çalışma bilgilerinin okunması
- puantaj görünümü
- günlük kayıt görünümü
- süreç geçmişi
- zimmet geçmişi
- timeline ve inceleme ekranları

Uzun vadeli hedef:

- Personel Kartı veri girişi başlatan ana yer olmayacak.
- Kart içindeki operasyon butonları ya kaldırılacak ya da `Kayıt ve Süreç` ekranına yönlendirme kapısı olacak.

### Raporlar

Bu ekran veri girişi ekranına dönüşmeyecek.

Kalması gereken işler:

- rapor görüntüleme
- filtreleme
- çıktı alma
- kapanış ve kontrol çıktılarının okunması

Özel durum:

- `Bölüm Onayı Ver`
- `Üst Kontrol Onayı Ver`

Bu iki aksiyon genel veri girişi değildir. Kapanış ve onay iş akışının parçası olarak ayrıca değerlendirilecektir.

## Personel Kartı Operasyon Envanteri

Kaynak owner: `src/features/personeller/pages/PersonelDetayPage.tsx`

| Aksiyon | Mevcut Davranış | Sınıf | Geçiş Kararı |
| --- | --- | --- | --- |
| `Kartı Düzenle` | Personel detay ekranında edit formunu açar. | `Kayıt ve Süreç` ekranına taşınacak | Merkezi personel düzenleme akışı hazır olana kadar geçici olarak korunur. |
| `Süreç Ekle` | Personel detay içinden süreç modalı açar. | `Kayıt ve Süreç` ekranına taşınacak | Süreç sekmesi kişi seçimi ve önseçimli form destekleyene kadar geçici olarak korunur. |
| `Yeni Zimmet Ekle` | Personel detay içinden zimmet modalı açar. | `Kayıt ve Süreç` ekranına taşınacak | Merkezi zimmet akışı kurulana kadar geçici olarak korunur. |
| Kart içi edit formları | Detay ekranında güncelleme yapar. | Taşınacak / azaltılacak | Merkezi düzenleme akışı oluşunca Personel Kartı readonly hale getirilir. |
| Timeline, süreç geçmişi, zimmet listesi | Geçmiş ve dosya bilgisi gösterir. | Görüntüleme olarak kalacak | Personel Kartı içinde kalır. |

## İlk Güvenli Paket Kararı

İlk paket uygulama davranışını kırmadan kararları repo içinde sabitler.
Bu nedenle bu aşamada Personel Kartı içinden buton veya modal sökülmez.

Sebep:

- mevcut E2E testleri `Kartı Düzenle`, `Süreç Ekle` ve `Yeni Zimmet Ekle` akışlarını kullanıyor
- merkezi `Kayıt ve Süreç` ekranı henüz bu üç operasyonun tamamını önseçimli şekilde karşılamıyor
- butonları hemen kaldırmak çalışan akışları kırar ve kullanıcıyı boşta bırakır

Bu belgeden sonraki ilk kod paketi, yalnızca merkezi karşılığı hazır olan en küçük aksiyonu taşımalıdır.

## Taşıma Sırası

1. `Kayıt ve Süreç` içinde kişi seçimi ve kısa kişi özeti hazırlanır.
2. `Süreç Ekle` Personel Kartı içindeki lokal modal yerine merkezi süreç akışına yönlendirilir.
3. `Kartı Düzenle` merkezi personel düzenleme akışına taşınır.
4. `Yeni Zimmet Ekle` merkezi işlem akışına taşınır.
5. Personel Kartı içindeki lokal create/edit modalları kaldırılır.
6. E2E testleri yeni owner akışına göre güncellenir.

## Test Sözleşmesi

Her küçük paket sonrası en az:

- `npm.cmd run build`

Personel Kartı etkilenirse:

- `tests/e2e/personel-dosya.spec.ts`

Kayıt ve Süreç veya Yönetim etkilenirse:

- `tests/e2e/yonetim.spec.ts`
- gerekiyorsa `tests/e2e/smoke.spec.ts`

Geniş yüzey değişirse:

- `npm.cmd run e2e`

## Kontrol Listesi

Bir değişiklik bu belgeye uygundur diyebilmek için:

- veri girişi yeni bir yerde çoğaltılmamalı
- Personel Kartı yeni operasyon üretmemeli
- çalışan geçici operasyonlar merkezi karşılığı hazır olmadan sökülmemeli
- Raporlar genel düzenleme ekranı gibi davranmamalı
- her taşımada ilgili E2E testi yeni davranışa göre güncellenmeli

