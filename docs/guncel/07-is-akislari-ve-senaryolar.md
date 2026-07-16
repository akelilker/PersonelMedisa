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
- Kullanıcı bugünü veya geçmişteki açık bir operasyon gününü `tarih` alanında seçer.
- Sistem seçilen personelin bölümünü ve hızlı iletişim bilgilerini gösterir.
- Amir `GEC_GELDI`, `GELMEDI`, `IZINLI_GELMEDI`, `IZINSIZ_GELMEDI`, `RAPORLU` veya görevde/erken çıktı gibi tiplerden birini seçerek kayıt girer.
- Seçilen tarih request, create response, liste ve detay boyunca aynen korunur; eksik veya geçersiz tarih kaydı engeller.
- Kayıt önce `TASLAK`, gönderimde `GONDERILDI` state'ine geçer.
- Güncel S70C çalışan state seti `TASLAK`, `GONDERILDI`, `DUZELTME_ISTENDI`, `HAFTALIK_MUTABAKATA_ALINDI` ve `IPTAL` değerlerinden oluşur.
- Kayıt sürece otomatik dönüşmez; operasyonel ham veri olarak kalır.
- Onaylı kayıtlar puantaj/bordro hesap zincirinin girdisi olabilir.

### 4.3 Sistem Etkisi

- Operasyonel günlük kayıt oluşur.
- `created_at` yalnız audit zamanıdır; haftalık, aylık, Genel Yönetici ve aday projection kapsamları operasyon tarihi olan `tarih` alanını kullanır.
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

- S70C-S73 bildirim onay zinciri kendi kapsamında tamamlanır.
- S73 Genel Yönetici üst onayı operasyonel audit/onay kaydıdır; puantaj hesap motoruna, bordro girdisine veya legacy aylık kapanışa otomatik bağlanmaz.

## 9. Genel Yönetici Bildirim Üst Onayı Senaryosu — S73

### 9.1 Başarılı üst onay

1. `GENEL_YONETICI` Bildirimler sayfasını açar.
2. Tek şube seçer.
3. Seçilen şubedeki Birim Amirini seçer.
4. Ay seçer.
5. Sistem S72 aylık onayını ve haftalık bütünlüğü doğrular.
6. Genel Yönetici modalı onaylar.
7. Tek üst onay kaydı `TAMAMLANDI` olarak oluşur.
8. Panel onay ID, state ve tarihini gösterir.
9. Onay butonu devre dışı kalır.

### 9.2 Blok senaryoları

- şube seçilmemiş
- Birim Amiri seçilmemiş
- seçilen şubede aktif Birim Amiri yok
- S72 aylık onay kaydı yok (`AYLIK_BILDIRIM_ONAYI_GEREKLI`)
- S72 tamamlanmamış (`AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI`)
- eksik hafta var (`EKSIK_HAFTA_VAR`)
- üst onay zaten mevcut (`ZATEN_ONAYLANDI` / duplicate POST `409`)
- `genel_yonetici_bildirim_onayi.view` veya `approve` permission yok (`403`)

### 9.3 Kapsam dışı senaryolar

- red
- düzeltme isteme
- reopen
- patron acknowledgment
- puantaj köprüsü
- bordro üretimi

## 10. Legacy/Hedef GENEL_YONETICI Bordro Öncesi Onay Senaryosu

Bu ve sonraki patron/bordro senaryoları hedef ürün zincirini ve legacy `aylik-ozet` hattını anlatır. Yeni S72 `aylik_bildirim_onaylari` ve S73 `genel_yonetici_bildirim_onaylari` domain'leriyle otomatik bağlantı yoktur.

### 10.1 Tetikleyici

Tüm bölümler `BOLUM_ONAYLANDI` durumuna geçtikten sonra `GENEL_YONETICI` ay kapanışını değerlendirir.

### 10.2 Akış

- Sistem açık manuel inceleme ve eksik şirket parametresi kontrolü yapar.
- Genel yönetici özet raporu inceler.
- Onay verildiğinde state `GENEL_YONETICI_ONAYLANDI` olur.
- Bordro ön izleme üretilebilir hale gelir.

### 10.3 Sistem Etkisi

- Nihai bordro üretimi için operasyonel kapı açılır.
- Patron ack henüz zorunlu değildir ve bordroyu bloklamaz.

## 11. PATRON Gordu / Not Ekledi Senaryosu

### 11.1 Tetikleyici

`PATRON` aylık genel durum özetini görüntüler.

### 11.2 Akış

- Patron özet raporu ve bordro ön izlemeyi salt okunur görür.
- “Gördüm” işaretler (`GORULDU`) veya not ekler (`NOT_EKLENDI`).
- Operasyonel düzeltme veya onay vermez.

### 11.3 Sistem Etkisi

- Sembolik üst yönetim görünürlüğü sağlanır.
- Bordro state'i değişmez; üretim bloklanmaz.

## 12. Bordro On Izleme Olusma Senaryosu

### 12.1 Tetikleyici

`GENEL_YONETICI_ONAYLANDI` state'i sağlandıktan sonra `MUHASEBE` veya `GENEL_YONETICI` bordro ön izlemeyi açar.

### 12.2 Akış

- Backend hesap motoru puantaj, süreç, onaylı bildirim ve finans adaylarını birleştirir.
- Her satır mevzuat / şirket parametresi / manuel inceleme sınıfı taşır.
- Ceza/kesinti kalemleri otomatik nihai bordroya bağlanmaz; ayrı işaretlenir.
- Ön izleme raporu ve kontrol listesi üretilir.

### 12.3 Sistem Etkisi

- `BORDRO_ON_IZLEME_HAZIR` state'i oluşur.
- Muhasebe kontrolü yapılır; kesinleştirme ayrı adımdır.

## 13. Parametre Eksikligi Nedeniyle Hesap Kesinlesmeme Senaryosu

### 13.1 Tetikleyici

Hesap motoru zorunlu şirket parametresini bulamaz (ör. hastalık ilk 2 gün firma öder mi varsayılanı tanımsız).

### 13.2 Akış

- Motor satırı `MANUEL_INCELEME` veya `PARAMETRE_EKSIK` olarak işaretler.
- Bordro kesinleştirme engellenir (`409 COMPANY_PARAM_MISSING`).
- `GENEL_YONETICI` şirket parametrelerini tanımlar veya günceller.
- Hesap yeniden çalıştırılır.

### 13.3 Sistem Etkisi

- Parametresiz kesin bordro üretilmez.
- Denetlenebilirlik korunur.

## 14. Isten Ayrilma Senaryosu

### 14.1 Tetikleyici

Yetkili kullanıcı personel için `İşten Ayrılma` süreci başlatır.

### 14.2 Akış

- Ayrılış tarihi ve gerekli açıklama girilir.
- Backend süreç kaydını oluşturur.
- Aynı işlem içinde personel kartının durumu `PASIF` olur.
- Personel aktif listelerde görünmez, ancak geçmiş ve rapor tarafında erişilebilir kalır.

### 14.3 Sistem Etkisi

- Süreç kaydı tarihsel olarak tutulur.
- Sonraki puantaj, mutabakat ve bordro işlemleri pasif durum dikkate alınarak yürür.

## 15. Gunluk Puantaj Isleme Senaryosu

### 15.1 Tetikleyici

Yetkili kullanıcı (`BOLUM_YONETICISI`, `GENEL_YONETICI`, `MUHASEBE`) günlük puantaj ekranını açar veya sistem süreç/bildirim etkileri sonrası günleri yeniden hesaplar.

### 15.2 Akış

- Gün bazlı çalışma, yokluk ve onaylı bildirim verileri okunur.
- Backend günlük süre, mola, fazla çalışma, hafta tatili etkisi gibi hesapları üretir.
- Şüpheli veya manuel inceleme gereken satırlar işaretlenir.
- `BIRIM_AMIRI` puantaj güncellemez; yalnızca görüntüleme ve amir kontrol işaretleri kullanabilir.

### 15.3 Sistem Etkisi

- Günlük puantaj satırları hesap motoru girdisi olarak davranır.
- Hatalı veri formül yamasıyla değil, veri kaynağı düzeltilerek çözülür.

## 15.4 Puantaj Etki Adayı Karar Altyapısı — S74-C1

### Tetikleyici

S73 tamamlanmış Genel Yönetici bildirim üst onayı sonrası `puantaj.bildirim_etki.view` yetkisine sahip roller (Genel Yönetici, Bölüm Yöneticisi, Muhasebe) S74-B ile üretilmiş puantaj etki adaylarını listeler veya detayını inceler; yok sayma yalnız Muhasebe/dismiss yetkisindedir.

### Akış

- `MUHASEBE` aday listesinde karar audit özet alanlarını (`karar_veren_user_id`, `karar_zamani`, `uygulanan_puantaj_id`) görür.
- Detayda karar audit alanları (`karar_gerekcesi`, puantaj snapshot'ları, `uygulama_hash`) okunur.
- `BildirimPuantajEtkiDecisionPolicy` hangi state'ten hangi kararın mümkün olduğunu belirler: `HAZIR` → UYGULA/YOK_SAY; `INCELEME_GEREKLI` → MANUEL UYGULA/YOK_SAY; `UYGULANDI`/`YOK_SAYILDI` terminal.
- S74-D1-B: `INCELEME_GEREKLI` adaylarda dört puantaj preset'i (`DEVAMSIZLIK_GUN`, `GEC_KALMA_DAKIKA`, `ERKEN_CIKIS_DAKIKA`, `GOREVDE_CALISILMIS_GUN`) ile `/manuel-uygula`; izin/rapor süreç akışına bırakılır.
- S74-C1 yalnız altyapıdır; yok-say S74-C2A, uygula S74-C3-B2, frontend Uygula S74-C3-B3 ile tamamlanmıştır.

### Sistem Etkisi

- `onayli_bildirim_puantaj_etki_adaylari` tablosuna karar audit kolonları eklenir.
- `gunluk_puantaj`, finans ve bordro tabloları değişmez.

## 15.5 Puantaj Etki Adayı Yok Say — S74-C2A

### Tetikleyici

`MUHASEBE`, S74-B ile üretilmiş ve `HAZIR` veya `INCELEME_GEREKLI` durumundaki puantaj etki adayını yok sayar.

### Akış

- `POST /puantaj/bildirim-etki-adaylari/{id}/yok-say` çağrılır.
- `expected_state` ile optimistic lock uygulanır; stale ise 409 `STATE_STALE`.
- Gerekçe trim edilir ve 5–500 karakter aralığında doğrulanır.
- Transaction içinde `SELECT ... FOR UPDATE` ile aday alınır; şube erişimi `SubeScope` ile doğrulanır.
- `BildirimPuantajEtkiDecisionPolicy` ile YOK_SAY izni kontrol edilir.
- Başarılı kararda yalnız `state`, `karar_veren_user_id`, `karar_zamani`, `karar_gerekcesi` güncellenir.
- Terminal `YOK_SAYILDI` + aynı gerekçe → idempotent 200; farklı gerekçe → 409 `STATE_CONFLICT`.

### Sistem Etkisi

- `onayli_bildirim_puantaj_etki_adaylari` tablosunda karar alanları güncellenir.
- `gunluk_puantaj`, finans, bordro, süreç ve bildirim zinciri tabloları değişmez.

## 15.5.1 Puantaj Yazımı ve Aylık Mühür Yarış Koruması — S74-D1/D3R

### Ortak dönem anahtarı

Generate zincirindeki onay ayı ile apply/upsert zincirindeki operasyon tarihi aynı `(sube_id, yil, ay)` tuple'ına normalize edilir. Cross-month batch akışı yoktur; her request tek dönem kilitler. Farklı şube veya aylar bağımsız ilerler.

### Bağlayıcı akış

1. Transaction başlar.
2. `puantaj_donem_kilitleri` satırı güvenli biçimde ensure edilir ve `FOR UPDATE` kilitlenir.
3. Aylık mühür durumu kontrol edilir.
4. Aday veya mevcut puantaj owner satırı kontrol/kilit işlemine alınır.
5. Business validation çalışır.
6. Puantaj, aday audit/hash veya mühür snapshot mutation'ı yapılır.
7. Transaction commit edilir; hata halinde tamamı rollback olur.

Bu sıra aday generation, otomatik `/uygula`, manuel `/manuel-uygula`, günlük puantaj create/upsert ve aylık mühür/snapshot için aynıdır. Seal önce tamamlarsa bekleyen write `PERIOD_LOCKED` ile durur. Apply/upsert önce tamamlarsa seal bekler ve yeni puantajı snapshot kapsamına alır. Kör deadlock/timeout retry yoktur.

`/yok-say` yalnız aday karar alanlarını günceller; puantaj veya mühür üretmediği için dönem kilidine dahil değildir.

### Yayın ve canlı sınır

Migration 014 eski kod için additive ve etkisizdir; bu nedenle schema-first uygulanır. Hardening kodu tablo olmadan kilitsiz devam etmez. Bu sıra 15.07.2026'da canlıda tamamlanmıştır. Kontrollü `2026-04` zincirinde bildirim `#7` → haftalık `#6` → aylık `#4` → GY `#4` → aday `#5` → puantaj `#5` üretilmiş; tek UI manuel apply ve aynı body ile tek idempotency POST doğrulanmıştır. Fixture `#4/#5`, haftalık `#4` ve aday `#1/#3` değişmemiştir.
## 15.6 Puantaj Etki Adayı Yok Say Ekranı — S74-C2B

### Tetikleyici

`puantaj.bildirim_etki.view` yetkisine sahip kullanıcı `/puantaj` ekranındaki `Onaylı Bildirim Puantaj Etki Adayları` bölümünden S74-B adaylarını inceler; `puantaj.bildirim_etki.dismiss` yetkisine sahip Muhasebe uygun kayıtları yok sayar.

### Akış

- Panel `puantaj.bildirim_etki.view` yetkisinde görünür; Genel Yönetici ve Bölüm Yöneticisi read-only kalır.
- Yok Say yalnız `puantaj.bildirim_etki.dismiss` (Muhasebe) ile açılır.
- Ay + birim amiri + aktif şube bağlamı hazır olmadan liste/özet isteği yapılmaz; şube değişince stale veri temizlenir.
- `sube_ids=[]` (tüm şubeler) kullanıcıları panel içinden yerel şube seçer; global auth session değişmez.
- View yetkisi olmayan rolde panel yardımcı request'leri (birim amiri seçenekleri dahil) çalışmaz.
- Canlı kontrollü smoke dönemi `2026-06`; `2026-07` boş-state beklenen sonuçtur.
- Özet için `genel_yonetici_bildirim_onayi_id` gerekir: Muhasebe/Bölüm için liste satırından veya bağlam içi cache; Genel Yönetici için ayrıca `genel_yonetici_bildirim_onayi` özet API'si kullanılabilir.
- `HAZIR` ve `INCELEME_GEREKLI` için Yok Say modalı açılır; gerekçe 5–500 karakter doğrulanır.
- `INCELEME_GEREKLI` satırında “otomatik uygulanamaz” uyarısı gösterilir.
- Başarılı yok sayma sonrası frontend özet/liste/detayı refetch eder; terminal `YOK_SAYILDI` görünür.
### Sistem Etkisi

- Yalnız frontend ve mock/E2E katmanı değişir; backend S74-C2A kontratı aynı kalır.
- `gunluk_puantaj` ve diğer operasyonel tablolar değişmez.
- Uygula aksiyonu S74-C3-B3 ile aynı panelde eklenmiştir; Yok Say kontratı değişmez.

## 15.7 Dakika Altyapısı ve Projection Kilidi — S74-C3-B1

### Tetikleyici

S74-C3 apply (`/uygula`) öncesi altyapı fazı; kullanıcı akışı değişmez.

### Akış

- Geç/erken dakika değerleri `gec_kalma_dakika` / `erken_cikis_dakika` kolonlarında saklanır; hesap motoru açık değeri saat farkından önce kullanır.
- GÖREVDE bildirimi projection'da `GOREVDE_CALISILMIS_GUN` üretir; apply hedefi `Geldi` + `Gorevde_Calisma` + `Tam_Yevmiye_Ver`.
- Ücretsiz izin süreci (`ucretli_mi=0`) ile `IZINLI` bildirimi → `INCELEME_GEREKLI` / `IZIN_GUNU` / `UCRETSIZ_IZIN_MANUEL_INCELEME`; otomatik `HAZIR` üretilmez.
- Aylık mühür snapshot'ı dakika kolonlarını korur.

### Sistem Etkisi

- Migration `012` repoda ve canlıda uygulanmıştır.
- `/uygula` endpointi S74-C3-B2, frontend Uygula S74-C3-B3 ile tamamlanmıştır.
- `gunluk_puantaj` mutation: mevcut PUT upsert + HAZIR aday apply INSERT (overwrite yok).

## 15.8 Puantaj Etki Adayı Uygula — S74-C3-B2 / S74-C3-B3

### Tetikleyici

`MUHASEBE`, S74-B ile üretilmiş ve `HAZIR` durumundaki puantaj etki adayını `/puantaj` panelinden uygular.

### Akış

- Detayda `puantaj.bildirim_etki.apply` + state `HAZIR` + detay yüklü iken **Uygula** görünür.
- AppModal onayı: personel, tarih, bildirim, etki, miktar, şube, birim amiri, state ve uyarı metni.
- `POST /puantaj/bildirim-etki-adaylari/{id}/uygula` body: `{ "expected_state": "HAZIR" }`.
- Optimistic lock: stale state → `409 STATE_STALE`.
- Başarı: aday `UYGULANDI`, yeni `gunluk_puantaj` INSERT, audit snapshot/hash dolar; liste/detay/özet refetch.
- `INCELEME_GEREKLI` ve terminal state'lerde Uygula gösterilmez.
- Buton sırası: Uygula → Yok Say → Kapat; liste satırında Uygula yok.

### Sistem Etkisi

- Yalnız hedef `(personel_id, tarih)` için INSERT; mevcut puantaj UPDATE edilmez.
- Mühürlü ay → `409 PERIOD_LOCKED`.
- Duplicate puantaj → `409 PUANTAJ_OLUSTU`.
- Finans, bordro ve bildirim zinciri state'leri değişmez.

### Canlı kanıt (C3-B4)

Kontrollü canlı apply ve idempotency tamamlandı (`LIVE_APPLY_IDEMPOTENCY_PASSED`).

- Fixture: bildirim `#3` (GOREVDE, kontrollü kabul fixture'ı — gerçek operasyon kaydı değil)
- Aday `#3`: `HAZIR` → UI apply → `UYGULANDI`; puantaj `#3` oluştu
- İkinci POST: HTTP `200`, `idempotent: true`; hash/snapshot/audit değişmedi
- Canonical mapping: `Geldi` + `Gorevde_Calisma` + `Tam_Yevmiye_Ver`, `ACIK`, dakika `NULL`
- UI özet: HAZIR `0`, INCELEME_GEREKLI `1`, UYGULANDI `1`

Detay: `docs/guncel/76-s74-c3-puantaj-etki-adayi-uygula-kapanis-checkpoint.md` §8–9.

## 15.9 Puantaj Çakışma Çözümü — S75-BC

### Tetikleyici

`MUHASEBE`, `HAZIR` veya `INCELEME_GEREKLI` adayda `/uygula` veya `/manuel-uygula` dener; hedef `(personel_id, tarih)` için puantaj zaten vardır → `409 PUANTAJ_OLUSTU`. `puantaj.bildirim_etki.resolve_conflict` varsa çakışma modalı açılır.

### Akış

- Detay refetch; `mevcut_puantaj`, `current_puantaj_hash`, `conflict_class`, `conflict_default_karar`, `revize_onizleme` yüklenir.
- MUHASEBE sınıf/risk/alan karşılaştırmasını inceler; gerekçe girer.
- **Mevcut Puantajı Koru:** mevcut satır korunur; aday `YOK_SAYILDI`, `uygulama_modu=CAKISMA_COZUM`.
- **Aday Etkisiyle Revize Et:** aynı puantaj id UPDATE (`kaynak=BILDIRIM_ETKI_REVIZYON`); aday `UYGULANDI`.
- `POST /puantaj/bildirim-etki-adaylari/{id}/cakisma-coz`; başarı veya `idempotent: true` → özet/liste/detay refetch.

### Çakışma sınıfları (A–G)

| Sınıf | Koşul | Varsayılan karar | Revize |
|-------|-------|------------------|:------:|
| **A** `AYNI_ADAY_PUANTAJI` | Aday `UYGULANDI` + `uygulanan_puantaj_id` = mevcut id | Revize | Evet |
| **B** `BASKA_ADAY_KAYNAGI` | `kaynak` = `BILDIRIM_ETKI_ADAYI` veya `BILDIRIM_ETKI_REVIZYON` | Koru | Evet |
| **C** `MANUEL_KAYNAK` | `kaynak=MANUEL` | Koru | Evet |
| **D** `RESMI_SUREC_DAYANAK` | Dayanak: `Yillik_Izin`, `Ucretli_Izinli`, `Raporlu_*` | Koru | Hayır |
| **E** `MUHURLU_PUANTAJ` | `state=MUHURLENDI` veya `muhur_id` dolu | Koru | Hayır |
| **F** `AMIR_KONTROL_EDILMIS` | `kontrol_durumu=AMIR_KONTROL_ETTI` | Koru | Evet (revize sonrası `BEKLIYOR`) |
| **G** `LEGACY_BELIRSIZ` | Boş/belirsiz `kaynak` | Koru | Evet |

### Sistem Etkisi

- Audit: `bildirim_puantaj_etki_cakisma_cozumleri` (aday başına tek kayıt).
- Koru: puantaj mutation yok. Revize: yalnız hedef satır UPDATE; INSERT yok.
- Mühürlü ay / sınıf D/E revize → `409 PERIOD_LOCKED` / `PUANTAJ_SOURCE_PROTECTED`.
- Finans, bordro, bildirim zinciri state'leri değişmez.
- **Canlı kabul:** migration `015` ve S75 deploy canlıdır. Mevcut tek personel/iki tarih fixture modeli yeniden üretim olmadan kullanılmış; aday `#6` Koru, aday `#7` Revize, aynı-body idempotency ve tek farklı-karar conflict başarıyla tamamlanmıştır.
- Aday `#7` raw `S74_V1` miktarı `NULL` iken source snapshot/hash doğrulamasıyla effective `20 DAKIKA` ve dolu revize preview üretmiştir; aday backfill edilmemiştir.
- Audit finalde iki satırdır; puantaj `#6` değişmemiş, puantaj `#7` aynı ID ile alan sahipliği kurallarına göre revize edilmiştir. Final: `S75_FULLY_COMPLETE`; ayrıntı `docs/guncel/78-s75-puantaj-cakisma-cozum-kapanis-checkpoint.md`.

## 16. Rapor Alma Senaryosu

### 16.1 Tetikleyici

Yetkili kullanıcı rapor ekranını filtrelerle çalıştırır.

### 16.2 Akış

- Kullanıcı tarih, departman, personel veya durum filtresi seçer.
- Sistem onaylı veri katmanlarından (mutabakat snapshot, aylık özet, bordro ön izleme) veriyi toplar.
- Liste, özet veya çıktı görünümü oluşturulur.
- Rol bazlı yoğunluk farkı uygulanır (`PATRON` özet, `MUHASEBE` detay).

### 16.3 Sistem Etkisi

- Rapor ekranı ham form girişini değil, doğrulanmış veri katmanını sunar.

## Sonuc

Bu belge ürünün operasyonel akış beynidir.
Bir işin kullanıcı adımıyla başlayıp hangi kayıt, onay ve bordro etkisine dönüştüğünü tek bakışta anlamak için önce buraya bakılır.
