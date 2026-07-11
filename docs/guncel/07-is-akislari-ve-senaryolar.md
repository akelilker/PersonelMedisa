# Medisa Personel ve Puantaj Yönetim Sistemi

## Is Akislari ve Senaryolar

Sürüm: `V2` (Ürün Reset — S70A)

## Belgenin Amaci

Bu doküman, ürünün gerçek kullanım akışlarını tek yerde toplar.
Parçalı halde bulunan state, veri ve hesap kurallarını kullanıcı yolculuğu üzerinden birleştirir.

Bu belge şu sorulara cevap verir:

- bir personel eklenince ne olur?
- süreç girişi hangi etkiyi üretir?
- günlük amir bildirimi nasıl işlenir?
- haftalık mutabakat ve teknik kapanış nasıl ilerler?
- aylık onay zinciri nasıl tamamlanır?
- bordro ön izleme ne zaman oluşur?
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

Detay gerektiğinde sırasıyla `05-state-flow-api-kontrati.md`, `04-hesap-motoru-kurallari.md`, `09-rol-yetki-matrisi.md` ve `03-ui-bilesen-sozlesmesi.md` açılır.

## 1. Yeni Personel Ekleme Senaryosu

### 1.1 Tetikleyici

Yetkili kullanıcı (`GENEL_YONETICI`, `BOLUM_YONETICISI`, `MUHASEBE`) `Yeni Personel` aksiyonunu açar.

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
- Hastalık raporunda şirket varsayılanı ve süreç bazlı override (`ilk_iki_gun_firma_oder_mi`) uygulanır.
- Backend, süreç tipine göre puantaj ve hak ediş etkisini belirler.
- Kayıt sonrası ilgili günler puantaj ekranında yokluk etkisiyle görünür.

### 3.3 Sistem Etkisi

- Günlük puantaj verisi süreçten etkilenir.
- İlgili hesap başlıkları `04-hesap-motoru-kurallari.md` kurallarına göre üretilir.
- Manuel kullanıcı yorumu ile sistem hesabı birbirine karışmaz.

## 4. Gunluk BIRIM_AMIRI Bildirimi Senaryosu

### 4.1 Tetikleyici

`BIRIM_AMIRI` oturum açmış kullanıcı günlük durum girmek ister.

### 4.2 Akış

- Kullanıcı `Günlük Kayıt Merkezi` ekranından kendi kapsamındaki personeli seçer.
- Sistem seçilen personelin bölümünü ve hızlı iletişim bilgilerini gösterir.
- Amir `GEC_GELDI`, `GELMEDI`, `IZINLI_GELMEDI`, `IZINSIZ_GELMEDI`, `RAPORLU` veya görevde/erken çıktı gibi tiplerden birini seçerek kayıt girer.
- Kayıt önce `TASLAK`, gönderimde `GONDERILDI` state'ine geçer.
- Güncel S70C çalışan state seti `TASLAK`, `GONDERILDI`, `DUZELTME_ISTENDI`, `HAFTALIK_MUTABAKATA_ALINDI` ve `IPTAL` değerlerinden oluşur.
- Kayıt sürece otomatik dönüşmez; operasyonel ham veri olarak kalır.
- Onaylı kayıtlar puantaj/bordro hesap zincirinin girdisi olabilir.

### 4.3 Sistem Etkisi

- Operasyonel günlük kayıt oluşur.
- `BOLUM_YONETICISI` haftalık mutabakat öncesi kayıtları denetler.
- Header bildirim paneli bu kayıt tipinden ayrıdır; panel yalnızca sistem uyarıları ve okunma durumu için kullanılır.

### 4.4 Bildirim Katmanlarının Ayrımı

| Katman | Örnek | Operasyonel veri mi? |
|--------|-------|----------------------|
| Günlük amir bildirimi | “Ayşe bugün geç geldi” | Evet |
| Sistem notification paneli | Okunmamış kayıt, hatırlatma | Hayır |
| Takvim hatırlatması (maaş/SGK) | İstemci oturum uyarısı | Hayır |

## 5. Gec Bildirim ve Duzeltme Istendi Senaryosu

### 5.1 Tetikleyici

`BIRIM_AMIRI` cut-off sonrası kayıt gönderir veya `BOLUM_YONETICISI` hatalı kayıt tespit eder.

### 5.2 Akış

- `BOLUM_YONETICISI` düzeltme isterse kayıt `DUZELTME_ISTENDI` olur.
- `BIRIM_AMIRI` kaydı düzeltir ve yeniden gönderir.
- Haftalık mutabakata alınmış kayıt doğrudan düzenlenemez; revizyon akışı gerekir.

### 5.3 Sistem Etkisi

- Audit trail korunur.
- Haftalık mutabakat paketi güncellenene kadar bordro etkisi üretilmez.

## 6. Haftalık Bildirim Mutabakatı Senaryosu

### 6.1 Tetikleyici

Hafta sonunda `BIRIM_AMIRI` Bildirimler sayfasındaki haftalık özeti kontrol eder.

### 6.2 Akış

- `BIRIM_AMIRI` hafta başlangıcını seçer ve gönderilmiş günlük bildirimleri kontrol eder.
- Eksik, taslak veya düzeltme bekleyen kayıt varsa haftalık approve yapılamaz.
- Koşullar uygunsa `POST /haftalik-bildirim-mutabakatlari` ile mutabakat oluşturulur.
- Mutabakat kaydı `TAMAMLANDI` olur.
- Bağlanan günlük kayıtlar `HAFTALIK_MUTABAKATA_ALINDI` state'ine geçer.
- Aynı hafta için tekrar approve `409` döner.
- Yönetim rolleri haftalık paneli salt okunur görür; approve sahibi değildir.

### 6.3 Sistem Etkisi

- Hafta bildirim zinciri kapsamında mutabakata alınmış sayılır.
- Aylık bildirim onayı için ilgili haftanın koşulu sağlanır.
- Bu işlem puantaj teknik kapanışını veya snapshot'ı otomatik oluşturmaz; teknik kapanış ayrı domain'dir.

## 7. Celiskili Kayit Manuel Inceleme Senaryosu

### 7.1 Tetikleyici

Günlük bildirim, süreç ve puantaj verisi çelişir veya mevzuat/şirket parametresi belirsizdir.

### 7.2 Akış

- Sistem satırı `MANUEL_INCELEME` olarak işaretler.
- `GENEL_YONETICI` veya yetkili kullanıcı kaynağı düzeltir, süreç ekler veya kaydı bordro dışı bırakır.
- Çözüm audit'e yazılır.
- Açık manuel inceleme varken bordro kesinleşmez.

### 7.3 Sistem Etkisi

- Yanlış otomatik bordro üretimi engellenir.
- Denetim izi korunur.

## 8. Aylık Bildirim Onayı Senaryosu

### 8.1 Tetikleyici

`BIRIM_AMIRI` Bildirimler sayfasındaki Aylık Bildirim Onayı panelinden ayı seçer.

### 8.2 Akış

- Sistem gerçek takvim ayının ilk/son gününü ve ayla kesişen haftaları hesaplar.
- Eksik veya mutabakata alınmamış bildirim/hafta varsa onay butonu devre dışı kalır veya API `409` döner.
- Koşullar uygunsa `POST /aylik-bildirim-onaylari` ile aylık bildirim onayı oluşturulur.
- Aylık bildirim onayı `TAMAMLANDI` state'ine geçer.
- Aynı ay için tekrar approve `409` döner.
- `BOLUM_YONETICISI`, `GENEL_YONETICI` ve `MUHASEBE` paneli salt okunur görür.

### 8.3 Sistem Etkisi

- S70C-S72 bildirim onay zinciri kendi kapsamında tamamlanır.
- Yeni aylık bildirim onayı henüz Genel Yönetici onayına, puantaj hesap motoruna veya bordro girdisine bağlanmaz.

## 9. Legacy/Hedef GENEL_YONETICI Bordro Öncesi Onay Senaryosu

Bu ve sonraki patron/bordro senaryoları hedef ürün zincirini ve legacy `aylik-ozet` hattını anlatır. Yeni S72 `aylik_bildirim_onaylari` domain'iyle otomatik bağlantı henüz yoktur.

### 9.1 Tetikleyici

Tüm bölümler `BOLUM_ONAYLANDI` durumuna geçtikten sonra `GENEL_YONETICI` ay kapanışını değerlendirir.

### 9.2 Akış

- Sistem açık manuel inceleme ve eksik şirket parametresi kontrolü yapar.
- Genel yönetici özet raporu inceler.
- Onay verildiğinde state `GENEL_YONETICI_ONAYLANDI` olur.
- Bordro ön izleme üretilebilir hale gelir.

### 9.3 Sistem Etkisi

- Nihai bordro üretimi için operasyonel kapı açılır.
- Patron ack henüz zorunlu değildir ve bordroyu bloklamaz.

## 10. PATRON Gordu / Not Ekledi Senaryosu

### 10.1 Tetikleyici

`PATRON` aylık genel durum özetini görüntüler.

### 10.2 Akış

- Patron özet raporu ve bordro ön izlemeyi salt okunur görür.
- “Gördüm” işaretler (`GORULDU`) veya not ekler (`NOT_EKLENDI`).
- Operasyonel düzeltme veya onay vermez.

### 10.3 Sistem Etkisi

- Sembolik üst yönetim görünürlüğü sağlanır.
- Bordro state'i değişmez; üretim bloklanmaz.

## 11. Bordro On Izleme Olusma Senaryosu

### 11.1 Tetikleyici

`GENEL_YONETICI_ONAYLANDI` state'i sağlandıktan sonra `MUHASEBE` veya `GENEL_YONETICI` bordro ön izlemeyi açar.

### 11.2 Akış

- Backend hesap motoru puantaj, süreç, onaylı bildirim ve finans adaylarını birleştirir.
- Her satır mevzuat / şirket parametresi / manuel inceleme sınıfı taşır.
- Ceza/kesinti kalemleri otomatik nihai bordroya bağlanmaz; ayrı işaretlenir.
- Ön izleme raporu ve kontrol listesi üretilir.

### 11.3 Sistem Etkisi

- `BORDRO_ON_IZLEME_HAZIR` state'i oluşur.
- Muhasebe kontrolü yapılır; kesinleştirme ayrı adımdır.

## 12. Parametre Eksikligi Nedeniyle Hesap Kesinlesmeme Senaryosu

### 12.1 Tetikleyici

Hesap motoru zorunlu şirket parametresini bulamaz (ör. hastalık ilk 2 gün firma öder mi varsayılanı tanımsız).

### 12.2 Akış

- Motor satırı `MANUEL_INCELEME` veya `PARAMETRE_EKSIK` olarak işaretler.
- Bordro kesinleştirme engellenir (`409 COMPANY_PARAM_MISSING`).
- `GENEL_YONETICI` şirket parametrelerini tanımlar veya günceller.
- Hesap yeniden çalıştırılır.

### 12.3 Sistem Etkisi

- Parametresiz kesin bordro üretilmez.
- Denetlenebilirlik korunur.

## 13. Isten Ayrilma Senaryosu

### 13.1 Tetikleyici

Yetkili kullanıcı personel için `İşten Ayrılma` süreci başlatır.

### 13.2 Akış

- Ayrılış tarihi ve gerekli açıklama girilir.
- Backend süreç kaydını oluşturur.
- Aynı işlem içinde personel kartının durumu `PASIF` olur.
- Personel aktif listelerde görünmez, ancak geçmiş ve rapor tarafında erişilebilir kalır.

### 13.3 Sistem Etkisi

- Süreç kaydı tarihsel olarak tutulur.
- Sonraki puantaj, mutabakat ve bordro işlemleri pasif durum dikkate alınarak yürür.

## 14. Gunluk Puantaj Isleme Senaryosu

### 14.1 Tetikleyici

Yetkili kullanıcı (`BOLUM_YONETICISI`, `GENEL_YONETICI`, `MUHASEBE`) günlük puantaj ekranını açar veya sistem süreç/bildirim etkileri sonrası günleri yeniden hesaplar.

### 14.2 Akış

- Gün bazlı çalışma, yokluk ve onaylı bildirim verileri okunur.
- Backend günlük süre, mola, fazla çalışma, hafta tatili etkisi gibi hesapları üretir.
- Şüpheli veya manuel inceleme gereken satırlar işaretlenir.
- `BIRIM_AMIRI` puantaj güncellemez; yalnızca görüntüleme ve amir kontrol işaretleri kullanabilir.

### 14.3 Sistem Etkisi

- Günlük puantaj satırları hesap motoru girdisi olarak davranır.
- Hatalı veri formül yamasıyla değil, veri kaynağı düzeltilerek çözülür.

## 15. Rapor Alma Senaryosu

### 15.1 Tetikleyici

Yetkili kullanıcı rapor ekranını filtrelerle çalıştırır.

### 15.2 Akış

- Kullanıcı tarih, departman, personel veya durum filtresi seçer.
- Sistem onaylı veri katmanlarından (mutabakat snapshot, aylık özet, bordro ön izleme) veriyi toplar.
- Liste, özet veya çıktı görünümü oluşturulur.
- Rol bazlı yoğunluk farkı uygulanır (`PATRON` özet, `MUHASEBE` detay).

### 15.3 Sistem Etkisi

- Rapor ekranı ham form girişini değil, doğrulanmış veri katmanını sunar.

## Sonuc

Bu belge ürünün operasyonel akış beynidir.
Bir işin kullanıcı adımıyla başlayıp hangi kayıt, onay ve bordro etkisine dönüştüğünü tek bakışta anlamak için önce buraya bakılır.
