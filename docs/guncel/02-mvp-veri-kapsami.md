# Medisa Personel ve Puantaj Yönetim Sistemi

## MVP Veri Kapsamı ve Zorunlu Alanlar

Sürüm: `V1`

## Belgenin Amacı

Bu doküman, ilk sürümde hangi verilerin kesinlikle sisteme alınacağını, hangi alanların opsiyonel tutulacağını, hangi alanların sonraki faza bırakılacağını ve hangi verilerin kullanıcı tarafından asla manuel girilmeyeceğini netleştirir.

Bu belge şu soruların tek merkez cevabıdır:

- Personel ekleme ekranında hangi alanlar kesin olacak?
- Hangi alanlar ilk sürümde opsiyonel olacak?
- Hangi alanlar sonraki faza bırakılacak?
- Hangi alanlar sistem tarafından hesaplanacak?
- Hangi alanlar referans tablolardan beslenecek?

## Temel Prensip

İlk sürümde hedef, tüm olası alanları bir anda ekrana yığmak değil; sistemi düzgün çalıştıracak minimum ama sağlam veri omurgasını kurmaktır.

Bu yüzden her alan şu dört sınıftan birine girer:

- `MVP Zorunlu`
- `MVP Opsiyonel`
- `Sonraki Faz`
- `Sistem Hesaplar / Salt Okunur`

## Ana Kararlar

Bu dokümanda aşağıdaki kararlar kilitlenmiştir:

- `Ad` ve `Soyad` ayrı alanlardır
- `Ad Soyad` tek alan kullanılmayacaktır
- `Departman`, `Görev`, `Bağlı Amir` ve benzeri seçim alanları referans veri ile çalışacaktır
- `Hizmet Süresi`, `Kalan İzin` gibi alanlar manuel giriş alanı değildir
- `İşten Ayrılma` personel kartı alanı değil, süreç kaydıdır
- İlk sürümde form kalabalığı yerine veri doğruluğu önceliklidir

## 1. Personel Ana Kartı - MVP Zorunlu Alanlar

Bu alanlar ilk sürümde personel oluşturmak için zorunludur.
Form submit edilebilmesi için bu alanlar boş bırakılamaz.

### 1.1 Kimlik ve Temel Kişi Bilgileri

- `tc_kimlik_no`
- `ad`
- `soyad`
- `dogum_tarihi`

Notlar:

- `tc_kimlik_no` yalnızca rakam kabul eder ve `11` hane olmalıdır
- `dogum_tarihi` sadece görüntü amaçlı değil, yıllık izin yaş istisnası ve yaş kontrolü için kritik veridir

### 1.2 İşe Alım ve Organizasyon Bilgileri

- `ise_giris_tarihi`
- `departman_id`
- `gorev_id`
- `personel_tipi_id`
- `aktif_durum`

Notlar:

- kullanıcı ekranda departman ve görev adını görür
- sistem arka planda `id` veya eşdeğer referans kod ile çalışır
- `aktif_durum` varsayılan olarak `Aktif` gelir

### 1.3 Temel İletişim ve Acil Durum

- `telefon`
- `acil_durum_kisi`
- `acil_durum_telefon`

Notlar:

- telefon alanları serbest metin gibi görünmemeli, numara odaklı validasyon taşımalıdır
- mobil kullanım için uygun giriş tipi kullanılmalıdır

### 1.4 İç Operasyon Referansı

- `sicil_no`

Not:

- `sicil_no` bazı senaryolarda backend tarafından üretilebilir
- yine de ilk sürüm veri modelinde alan olarak bulunmalıdır

## 2. Personel Ana Kartı - MVP Opsiyonel Alanlar

Bu alanlar ilk sürümde veri modelinde ve formda bulunabilir; ancak kayıt oluşturmayı bloke eden zorunlu alanlar değildir.

- `dogum_yeri`
- `kan_grubu`
- `bagli_amir_id`

Gerekçe:

- `dogum_yeri` faydalıdır ama çekirdek iş kurallarını kilitlemez
- `kan_grubu` operasyonel olarak değerlidir ama ilk kayıt anında zorunlu olması sistem açılışını gereksiz yavaşlatır
- `bagli_amir_id` organizasyon şemasına bağlıdır; ilk sürümde opsiyonel bırakılıp referans veri oturdukça sertleştirilebilir

## 3. Personel Ana Kartı - Sonraki Faz Alanları

Bu alanlar ürün vizyonunda değerlidir ama ilk sürümün açılması için zorunlu değildir.
Veri modelinde şimdiden düşünülür, fakat ilk sprintlerde formu şişirmemek için sonraya bırakılır.

- `cinsiyet`
- `ogrenim_durumu`
- `kart_no`
- `iban`
- `sozlesme_saati`
- `baz_maas`
- `bes_kesintisi_var_mi`

Gerekçe:

- `iban`, `baz_maas`, `sozlesme_saati` bordro ve banka çıktısı için değerlidir ama MVP'nin ilk ekranlarını açmak için şart değildir
- `bes_kesintisi_var_mi` finans motoru genişlerken zorunlu hale gelecektir
- `kart_no` fiziksel kart altyapısına bağlıdır
- `cinsiyet` ve `ogrenim_durumu` raporlama için faydalı olabilir ama çekirdek süreç motorunun ilk sürümü için şart değildir

## 4. Sistem Hesaplar / Salt Okunur Alanlar

Bu alanlar formda kullanıcıdan alınmayacaktır.
Sistem tarafından üretilecek veya diğer verilerden türetilecektir.

- `hizmet_suresi`
- `toplam_izin_hakki`
- `kullanilan_izin`
- `kalan_izin`
- `hafta_tatiline_hak_kazandi_mi`
- `net_calisma_suresi`
- `fazla_mesai_suresi`
- `fazla_surelerle_calisma_suresi`
- `pasiflik_durumu_etiketi`

Bu alanların görünme yerleri:

- personel kartı
- detay ekranları
- rapor ekranları
- haftalık kapanış ve özet panelleri

## 5. Referans Veri ile Çalışacak Alanlar

Bu alanlar düz metin olarak değil, referans kayıtlar üzerinden tutulmalıdır.

- `departman_id`
- `gorev_id`
- `personel_tipi_id`
- `bagli_amir_id`
- süreç türü alanları
- bildirim türü alanları

Temel kural:

- kullanıcı görünen adı seçer
- sistem arka planda referans kimliği saklar

## 6. İlk Sürüm Personel Ekleme Formu

İlk sürümde açılacak `Yeni Personel Ekle` formunun hedef kapsamı aşağıdaki gibidir.

### 6.1 Sol Kolon

- T.C. Kimlik No
- Ad
- Soyad
- Doğum Tarihi
- Telefon
- Acil Durum Kişisi
- Acil Durum Telefonu
- Doğum Yeri
- Kan Grubu

### 6.2 Sağ Kolon

- Sicil No
- İşe Giriş Tarihi
- Bölüm
- Görev
- Bağlı Amir
- Personel Tipi
- Aktif Durum

Form davranışı:

- `Doğum Yeri`, `Kan Grubu`, `Bağlı Amir` ilk sürümde opsiyoneldir
- geri kalan çekirdek alanlar kayıt için zorunludur
- formda hesaplanan özet alanlar görünmeyecektir

## 7. Kayıt Sırasında Çalışacak Temel Validasyonlar

İlk sürümde minimum validasyon kuralları şunlardır:

- `tc_kimlik_no` 11 hane olmalı
- `ad` boş olamaz
- `soyad` boş olamaz
- `dogum_tarihi` geçerli tarih olmalı
- `ise_giris_tarihi` geçerli tarih olmalı
- `telefon` boş olamaz
- `acil_durum_kisi` boş olamaz
- `acil_durum_telefon` boş olamaz
- `departman_id` seçilmiş olmalı
- `gorev_id` seçilmiş olmalı
- `personel_tipi_id` seçilmiş olmalı
- `aktif_durum` seçilmiş olmalı

İlave kurallar:

- gelecekteki işe giriş tarihi kabul edilip edilmeyeceği ayrı iş kuralı dokümanında sertleştirilecektir
- aynı `tc_kimlik_no` ile mükerrer kayıt açılması engellenmelidir
- aynı `sicil_no` için benzersizlik stratejisi backend seviyesinde tanımlanmalıdır

## 8. Süreç Verilerinin İlk Sürüm Kapsamı

İlk sürümde süreç ekranı için en az aşağıdaki event türleri desteklenmelidir:

- `izin`
- `devamsizlik`
- `rapor`
- `is_kazasi`
- `ceza`
- `ekstra_prim`
- `avans`
- `isten_ayrilma`
- `diger`

Süreç kaydı için ilk sürüm minimum alanları:

- `personel_id`
- `surec_turu`
- `baslangic_tarihi`
- `bitis_tarihi` veya tekil tarih
- `durum_alt_turu` gerektiğinde
- `aciklama`

Not:

- belge yükleme alanı ilk sürümde placeholder olabilir
- işten ayrılma girildiğinde personel durumu sistem tarafından güncellenir

## 9. Günlük Bildirim Verilerinin İlk Sürüm Kapsamı

Bildirim katmanı için minimum alanlar:

- `tarih`
- `departman_id`
- `personel_id`
- `bildirim_turu`
- `aciklama`

Bildirim türü örnekleri:

- geç geldi
- gelmedi
- izinli
- raporlu
- görevde
- erken çıktı
- diğer

## 10. Bordro ve Finans İçin Erken Hazırlık Alanları

Bu alanlar ilk sürümde ekranda tam açılmasa bile veri modelinde düşünülmelidir:

- `iban`
- `baz_maas`
- `sozlesme_saati`
- `bes_kesintisi_var_mi`

Sebep:

- bunlar daha sonra eklenecek diye veri modelini baştan bozmak istemiyoruz
- ilk sürümde ekran sade tutulurken şema tarafı geleceğe kapalı olmamalı

## 11. Kesinlikle Forma Konmayacak Alanlar

İlk sürümde aşağıdaki alanlar doğrudan personel ekleme formuna konmayacaktır:

- hizmet süresi
- kalan izin
- kullanılan izin
- toplam izin hakkı
- pasiflik açıklaması
- süreç geçmişi özeti
- haftalık kapanış sonucu
- fazla mesai özeti
- maaş net hesap sonucu

Bu alanlar ana kayıt ekranını bozduğu için sonradan detay katmanlarında gösterilecektir.

## 12. İlk Sürümde Kilitlenen Kararlar

Bu belge ile aşağıdaki kararlar sabitlenmiştir:

- `Ad` ve `Soyad` ayrı alan kalacaktır
- `Doğum Tarihi` zorunludur
- `Bölüm`, `Görev` ve `Personel Tipi` zorunludur
- `Kan Grubu` ilk sürümde opsiyoneldir
- `Bağlı Amir` ilk sürümde opsiyoneldir
- `IBAN`, `Baz Maaş`, `Sözleşme Saati`, `BES` ilk sürümde sonraki faz alanlarıdır
- hesaplanan alanlar kayıt formuna girmeyecektir
- işten ayrılma süreç kaydı olarak çalışacaktır

## 13. Bu Belgeden Sonra Gelecek Doküman

Bu belge veri kapsamını netleştirir; ama tek başına yeterli değildir.
Bundan sonra en doğru belge:

- `03-ui-bilesen-sozlesmesi.md`

çünkü artık hangi alanların olduğu netleştiğine göre, bu alanların ekranda nasıl yerleşeceğini ve hangi bileşen ailesiyle sunulacağını sertleştirebiliriz.
