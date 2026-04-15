# Medisa Personel ve Puantaj Yönetim Sistemi

## Is Akislari ve Senaryolar

Sürüm: `V1`

## Belgenin Amaci

Bu doküman, ürünün gerçek kullanım akışlarını tek yerde toplar.
Parçalı halde bulunan state, veri ve hesap kurallarını kullanıcı yolculuğu üzerinden birleştirir.

Bu belge şu sorulara cevap verir:

- bir personel eklenince ne olur?
- süreç girişi hangi etkiyi üretir?
- bildirim nasıl ele alınır?
- hafta kapanınca hangi kayıtlar kilitlenir?
- kullanıcı ekranlarda hangi sırayla ilerler?

## Dokumanin Siniri

Bu dosya:

- kullanıcı ve operasyon akışını anlatır
- ekranlar arası geçiş mantığını özetler
- ilgili veri ve state belgelerine köprü olur

Bu dosya:

- endpoint listesinin sahibi değildir
- hesap algoritmasının sahibi değildir
- component piksel sözleşmesinin sahibi değildir

Detay gerektiğinde sırasıyla `05-state-flow-api-kontrati.md`, `04-hesap-motoru-kurallari.md` ve `03-ui-bilesen-sozlesmesi.md` açılır.

## 1. Yeni Personel Ekleme Senaryosu

### 1.1 Tetikleyici

Yetkili kullanıcı `Yeni Personel` aksiyonunu açar.

### 1.2 Akış

- Kullanıcı personel formunu doldurur.
- Form sadece manuel girilen alanları toplar.
- Zorunlu alan validasyonu backend tarafından yapılır.
- Kayıt başarılıysa sistem yeni personel kartını oluşturur.
- Kayıt varsayılan olarak `AKTIF` duruma alınır.
- Kullanıcı listeye ya da personel kartına yönlendirilir.

### 1.3 Sistem Etkisi

- `personeller` kaydı açılır.
- Hesaplanan alanlar formdan gelmez.
- Personelin süreç geçmişi başlangıçta boştur.

## 2. Personel Karti ve Surec Ekleme Senaryosu

### 2.1 Tetikleyici

Yetkili kullanıcı personel kartından ya da süreç ekranından yeni süreç başlatır.

### 2.2 Akış

- Kullanıcı süreç tipini seçer.
- Tipine göre tarih, açıklama ve ilgili ek alanlar girilir.
- Kayıt validasyondan geçerse süreç aktif olarak yazılır.
- Personel kartı süreç geçmişinde yeni satır görünür.
- Süreç tipi kartın durumunu etkiliyorsa bu etki backend tarafından uygulanır.

### 2.3 Sistem Etkisi

- `personel_surecleri` kaydı oluşur.
- Kart üzerindeki özet alanlar gerektiğinde yeniden hesaplanır.
- Hareket tarihsel olarak izlenebilir hale gelir.

## 3. Devamsizlik veya Rapor Girisi Senaryosu

### 3.1 Tetikleyici

Yetkili kullanıcı devamsızlık, hastalık raporu veya benzeri yokluk sürecini işler.

### 3.2 Akış

- Süreç tipi seçilir.
- Tarih aralığı ve gerekli açıklamalar girilir.
- Backend, süreç tipine göre puantaj ve hak ediş etkisini belirler.
- Kayıt sonrası ilgili günler puantaj ekranında yokluk etkisiyle görünür.

### 3.3 Sistem Etkisi

- Günlük puantaj verisi süreçten etkilenir.
- İlgili hesap başlıkları `04-hesap-motoru-kurallari.md` kurallarına göre üretilir.
- Manuel kullanıcı yorumu ile sistem hesabı birbirine karışmaz.

## 4. Gunluk Durum Bildirimi ve Bildirim Degerlendirme Senaryosu

### 4.1 Tetikleyici

Birim Amiri Rolü (`BIRIM_AMIRI`) ile oturum acmis bir kullanici gunluk durum girmek ister ya da kullanici header sag ustteki bildirim ikonundan paneli acar.

### 4.2 Akış

- Bu roldeki kullanici `Bildirimler` ekranindan personel secer.
- Sistem secilen personelin bolumunu ve hizli iletisim bilgilerini ayni ekranda gosterir.
- Amir `GEC_GELDI`, `GELMEDI`, `IZINLI_GELMEDI`, `IZINSIZ_GELMEDI` veya `RAPORLU` tiplerinden birini secerek kayit girer.
- Gunluk durum bildirimi bildirim kaydi olarak yazilir; surece otomatik donusmez.
- Sistem okunmamis bildirimleri listeler.
- Kullanici bildirimi okur, gerekirse ilgili ekrana gider.
- Bildirim ilk surumde otomatik surece donusmez.
- Operasyon gerekiyorsa kullanici ilgili surec veya kayit ekraninda ayri islem yapar.

### 4.3 Sistem Etkisi

- Bu rol personel kartindaki telefon, acil durum kisisi, acil telefon ve kan grubu gibi bilgilere hizli ulasir.
- Bildirimin okunma durumu guncellenir (`okundu_mi`, `PUT /api/bildirimler/{bildirimId}`; ayrinti `05-state-flow-api-kontrati.md`).
- Takvim hatirlatmalari (or. maas / SGK) kayit tabanli bildirim degildir; okundu bilgisi yalnizca istemci oturumunda tutulur.
- Bildirim ile surec kaydi birbirine otomatik baglanmaz.
- Uyari katmani ile islem katmani bilincli olarak ayrilir.

## 5. Isten Ayrilma Senaryosu

### 5.1 Tetikleyici

Yetkili kullanıcı personel için `İşten Ayrılma` süreci başlatır.

### 5.2 Akış

- Ayrılış tarihi ve gerekli açıklama girilir.
- Backend süreç kaydını oluşturur.
- Aynı işlem içinde personel kartının durumu `PASIF` olur.
- Personel aktif listelerde görünmez, ancak geçmiş ve rapor tarafında erişilebilir kalır.

### 5.3 Sistem Etkisi

- Süreç kaydı tarihsel olarak tutulur.
- Kart alanına doğrudan yazılan bir ayrılış flag'i kullanılmaz.
- Sonraki puantaj ve kapanış işlemleri pasif durum dikkate alınarak yürür.

## 6. Gunluk Puantaj Isleme Senaryosu

### 6.1 Tetikleyici

Yetkili kullanıcı günlük puantaj ekranını açar ya da sistem süreç etkileri sonrası günleri yeniden hesaplar.

### 6.2 Akış

- Gün bazlı çalışma, yokluk ve özel durum verileri okunur.
- Backend günlük süre, mola, fazla çalışma, hafta tatili etkisi gibi hesapları üretir.
- Şüpheli veya manuel inceleme gereken satırlar işaretlenir.
- Kullanıcı gerekli ise düzeltme kaydı ya da süreç girişi ile veri kaynağını düzeltir.

### 6.3 Sistem Etkisi

- Günlük puantaj satırları canlı çalışma katmanı olarak davranır.
- Hesap motoru sonuçları kullanıcıya okunur alanlar olarak sunulur.
- Hatalı veri formül yamasıyla değil, veri kaynağı düzeltilerek çözülür.

## 7. Haftalik Kapanis Senaryosu

### 7.1 Tetikleyici

Yetkili kullanıcı belirli bir hafta için kapanış alır.

### 7.2 Akış

- Sistem ilgili hafta verisini toplar.
- Eksik veya problemli kayıtlar varsa kullanıcı uyarılır.
- Kapanış onayı verildiğinde backend haftayı mühürler.
- Haftaya ait puantaj ve özet alanlar kilitlenir.
- Rapor ve çıktı katmanı kapanış verisini temel alır.

### 7.3 Sistem Etkisi

- `haftalik_kapanis` kaydı oluşur.
- Haftaya ait kritik kayıtlar düzenlemeye kapanır.
- Sonradan değişiklik ihtiyacı varsa kontrollü yeniden açma akışı gerekir.

## 8. Rapor Alma Senaryosu

### 8.1 Tetikleyici

Yetkili kullanıcı rapor ekranını filtrelerle çalıştırır.

### 8.2 Akış

- Kullanıcı tarih, departman, personel veya durum filtresi seçer.
- Sistem ilgili kaynak tablolardan veriyi toplar.
- Liste, özet veya çıktı görünümü oluşturulur.
- Gerekirse kapanış verisi öncelikli kaynak olarak kullanılır.

### 8.3 Sistem Etkisi

- Rapor ekranı ham form girişini değil, doğrulanmış veri katmanını sunar.
- Aynı rapor farklı roller için farklı yoğunlukta gösterilebilir.

## Sonuc

Bu belge ürünün operasyonel akış beynidir.
Bir işin kullanıcı adımıyla başlayıp hangi kayıt ve etkiye dönüştüğünü tek bakışta anlamak için önce buraya bakılır.
