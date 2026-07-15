# Medisa Personel ve Puantaj Yönetim Sistemi

## Hesap Motoru Kural Dokümanı

Sürüm: `V2` (Ürün Reset — S70A)

## Belgenin Amacı

Bu doküman, personel, puantaj ve süreç verilerinden hangi hesapların nasıl üretileceğini backend geliştiriciye ve ürün ekibine bağlayıcı şekilde anlatır.

Bu belge şu soruların cevabıdır:

- günlük çalışma süresi nasıl hesaplanır?
- mola düşümü hangi kuralla yapılır?
- fazla çalışma ile fazla sürelerle çalışma nasıl ayrılır?
- serbest zaman nasıl işler?
- telafi çalışması ile denkleştirme nasıl ayrılır?
- hafta tatili hakkı nasıl belirlenir?
- rapor, iş kazası ve yıllık izin sistemde nasıl etkiler üretir?
- hangi alan ne zaman sistem tarafından mühürlenir?

## Belgenin Kapsamı

Bu doküman:

- hesaplama mantığını
- karar kurallarını
- veri girdilerini
- çıktı alanlarını
- ürün içi öncelik sırasını

tanımlar.

Bu doküman şunları tam çözmez (ayrı belgelerde):

- ekran yerleşimi
- API endpoint listesi (state machine / onay akışı → `05-state-flow-api-kontrati.md`)
- banka TXT/Excel formatının kolon bazlı şeması

Bordro matematiği bu belgenin kapsamındadır; kodlama fazlara bölünür (`01-urun-anayasasi.md` §4).

## Kaynaklar

Bu doküman iki katmanlı kaynağa dayanır:

### İç kaynaklar

- `beyin-olmazsa-olmaz.docx`
- `01-urun-anayasasi.md`
- `02-mvp-veri-kapsami.md`
- `11-puantaj-kural-matrisi.md` (yoklama / süreç / puantaj ürün karar özeti; bu belge ile çelişmemeli)

### Resmî dayanaklar

- [4857 sayılı İş Kanunu - T.C. Çalışma ve Sosyal Güvenlik Bakanlığı](https://www.csgb.gov.tr/Media/crldhi51/4857-say%C4%B1l%C4%B1-i%C5%9F-kanunu.pdf)
- [İş Kanununa İlişkin Fazla Çalışma ve Fazla Sürelerle Çalışma Yönetmeliği - T.C. Çalışma ve Sosyal Güvenlik Bakanlığı](https://www.csgb.gov.tr/Media/gekgpqcm/i%C5%9F-kanununa-ili%C5%9Fkin-fazla-%C3%A7al%C4%B1%C5%9Fma-ve-fazla-s%C3%BCrelerle-%C3%A7al%C4%B1%C5%9Fma-y%C3%B6netmeli%C4%9Fi.pdf)
- [Yıllık Ücretli İzin Yönetmeliği - T.C. Çalışma ve Sosyal Güvenlik Bakanlığı](https://www.csgb.gov.tr/Media/trddpibj/y%C4%B1ll%C4%B1k-%C3%BCcretli-izin-y%C3%B6netmeli%C4%9Fi.pdf)
- [Hastalık Hali - SGK](https://ankara.sgk.gov.tr/Content/Post/e33fffdb-16b9-4722-a9c1-2bd0a2a220d1/Hastalik-Hali-2025-02-27-11-27-26)
- [İş Kazası - SGK](https://www.sgk.gov.tr/Content/Post/7b0b48c6-ceba-472b-8011-c4a9b3125133/Is-Kazasi-2025-02-27-10-08-15)

## Yorumlama Prensibi

Bu sistemde hesap motoru şu sırayla çalışır:

1. Resmî mevzuatın asgari kuralları
2. Ürünün iç iş kuralı kararları
3. İşletmeye özel parametreler

Kural çatışması olduğunda:

- mevzuata aykırı kural uygulanmaz
- mevzuatın izin verdiği alanlarda ürün kararı uygulanır
- belirsiz alanlar `manuel inceleme` veya `parametre gerektirir` olarak işaretlenir

## Ürün Reset Hesap Motoru İlkeleri (S70A)

Aşağıdaki ilkeler ürün reset sonrası bağlayıcıdır:

### Yetki ve sahiplik

- **Frontend nihai maaş/puantaj hesabı yapamaz.** İstemci yalnızca backend'in döndürdüğü hesap sonuçlarını gösterir; ön izleme bile backend kaynaklı olmalıdır.
- **Hesap motoru nihai hedefte backend tek yetkilidir.** Geçiş döneminde frontend'te çalışan motorlar kaldırılana kadar backend sonucu esas alınır.

### Sınıflandırma

Her hesap satırı aşağıdaki sınıflardan birine bağlanmalıdır:

| Sınıf | Açıklama |
|-------|----------|
| `MEVZUAT_DAYANAKLI` | Resmî mevzuat gereği zorunlu kural |
| `SIRKET_PARAMETRESI` | İşletme parametresi ile belirlenen alan |
| `MANUEL_INCELEME` | Eksik/çelişkili veri; otomatik kesinleşmez |

### Kesinleşmeme kuralları

- **Şirket parametresi eksikse sonuç kesinleşmez.** Zorunlu parametre listesi `sirket_parametreleri` katmanında tanımlanır.
- **Eksik veya çelişkili kayıt `manuel_inceleme` statüsüne düşer.** Açık manuel inceleme varken bordro kesinleşmez.
- **Ceza/kesinti otomatik nihai bordroya bağlanmaz.** Yüksek riskli kalemler ayrı onay veya manuel işaretleme gerektirir.

### Şirket parametresi — hastalık raporu ilk 2 gün

- `ilk_iki_gun_firma_oder_mi` kararı **şirket parametresi** olarak tanımlanmalıdır (varsayılan değer).
- Süreç bazlı `ilk_iki_gun_firma_oder_mi` alanı **override** olarak kalır; şirket varsayılanından farklıysa audit izi bırakır.
- Parametre tanımsızsa hastalık raporu günleri `MANUEL_INCELEME` veya `PARAMETRE_EKSIK` olarak işaretlenir; bordro kesinleşmez.

### Veri girdileri — günlük amir bildirimi

Hesap motoru, onaylı günlük amir bildirimlerini puantaj/süreç verisiyle birlikte değerlendirir:

- `GONDERILDI` / `HAFTALIK_MUTABAKATA_ALINDI` state'indeki bildirimler ham veri adayıdır
- `TASLAK`, `DUZELTME_ISTENDI`, `IPTAL` kayıtları hesaba dahil edilmez
- Haftalık mutabakat tamamlanmamış haftanın bildirimleri bordro girdisi olamaz

### Onay zinciri önkoşulu

Hesap motoru bordro çıktısı üretmeden önce backend şu state'leri doğrular:

1. İlgili haftalar `MUTABAKAT_TAMAMLANDI`
2. Aylık satırlar `BOLUM_ONAYLANDI`
3. Ay `GENEL_YONETICI_ONAYLANDI`
4. Açık `MANUEL_INCELEME` yok
5. Zorunlu şirket parametreleri tanımlı

Patron ack (`GORULDU` / `NOT_EKLENDI`) bu önkoşul listesinde yer almaz.

## Uygulama Durumu Notu — Bildirim Zinciri

11.07.2026 itibarıyla günlük bildirim, haftalık bildirim mutabakatı, aylık bildirim onayı ve Genel Yönetici bildirim üst onayı domain'leri kendi operasyon kapsamlarında çalışır.

- Bu kayıtlar henüz hesap motorunun gerçek backend girdisi değildir.
- Bir bildirimde görünen “kesinti adayı”, “fazla çalışma adayı” veya benzeri hesap etkisi otomatik olarak kesinleşmez.
- Bildirim onayının veya S73 Genel Yönetici üst onayının tamamlanmış olması kaydın bordroya esas olduğu anlamına gelmez.
- S73 üst onayı puantaj değiştirmez, puantaj mühürlemez, finans adayı veya bordro girdisi oluşturmaz.
- Bildirim zinciri ile hesap motoru arasındaki köprü ayrı geliştirme fazıdır.
- Aylık bildirim onayı ile bordro girdisi ve bordro kesinleştirme arasında otomatik bağlantı yoktur.

Bu uygulama durumu notu aşağıdaki mevzuat kararlarını veya hesap formüllerini değiştirmez; yalnızca çalışan kod ile hedef hesap zinciri arasındaki sınırı kaydeder.

## 1. Hesap Motorunun Veri Girdileri

Hesap motorunun doğru çalışması için minimum girdiler şunlardır:

### 1.1 Personel ana kartı

- `personel_id`
- `dogum_tarihi`
- `ise_giris_tarihi`
- `aktif_durum`
- `personel_tipi`
- `sozlesme_saati` varsa (sonraki faz; mevcut işletmede 45 saat altı sözleşmeli personel yok)
- `net_maas_tutari` varsa
- `brut_maas_tutari` varsa (sistem hesaplı; kullanıcı girdisi değildir)
- `bes_kesintisi_var_mi` varsa

### 1.5 Maaş alan semantiği (S62A kilit)

İşletmede işçiye sabit **net** maaş ödenir.

| Alan | Rol |
|------|-----|
| `net_maas_tutari` | Kullanıcı girdisi / canonical alan |
| `brut_maas_tutari` | Sistem hesaplı / salt okunur |
| `brut_hesaplama_modeli` | Brüt üretim yöntemi izi |
| `brut_hesaplama_donemi` veya `model_versiyonu` | Dönem / model izlenebilirliği |

- Mevcut `maas_tutari` semantik olarak belirsizdir; yeni bordro altyapısında tek başına ana alan olmamalıdır.
- Fazla mesai, saatlik ücret ve parasal kesinti hesapları **brüt** kaynak üzerinden yürür.
- Netten brüte gerçek hesap motoru ve bordro/vergi parametreleri **bu sprintte yazılmaz**; sonraki fazdır.

### 1.2 Günlük puantaj verisi

- `tarih`
- `giris_saati`
- `cikis_saati`
- varsa `gercek_mola_kayitlari`
- `planli_calisma_gunu_mu`
- `hafta_ici_gun_kodu`

### 1.3 Süreç verisi

- `surec_turu`
- `alt_tur`
- `baslangic_tarihi`
- `bitis_tarihi`
- `ucretli_mi`
- `aciklama`

### 1.4 Ek ödeme / kesinti verisi

- `prim`
- `ceza`
- `avans`
- `ekstra_odeme`
- `bes_kesintisi`

## 2. Hesap Motorunun Çıktıları

Motor en az aşağıdaki alanları üretir:

- `gunluk_brut_sure`
- `gunluk_mola_dusumu`
- `gunluk_net_calisma_suresi`
- `haftalik_toplam_sure`
- `hafta_tatiline_hak_kazandi_mi`
- `fazla_calisma_suresi`
- `fazla_surelerle_calisma_suresi`
- `serbest_zaman_hakki`
- `hizmet_suresi`
- `toplam_izin_hakki`
- `kullanilan_izin`
- `kalan_izin`
- `pasiflik_etiketi`

## 3. Günlük Süre Hesabı

### 3.1 Tanımlar

- `presence_duration`: giriş ile çıkış arasındaki toplam ham süre
- `break_duration`: mola veya ara dinlenmesi olarak düşülecek süre
- `net_work_duration`: günlük fiili çalışma süresi

### 3.2 Temel Formül

İlk sürüm günlük hesap akışı:

1. `presence_duration = cikis_saati - giris_saati`
2. `break_duration = gercek_mola_kaydi varsa o, yoksa yasal minimum otomatik düşüm`
3. `net_work_duration = presence_duration - break_duration`

### 3.3 Ara Dinlenmesi Kuralları

4857 sayılı İş Kanunu Madde 68 temel alınır.

Otomatik minimum mola düşümü:

- günlük çalışma süresi `4 saat` veya daha kısa ise: `15 dakika`
- `4 saatten fazla` ve `7,5 saate kadar` ise: `30 dakika`
- `7,5 saatten fazla` ise: `60 dakika`

Ürün kuralı:

- ayrı mola kaydı yoksa sistem bu minimumları otomatik uygular
- ayrı mola kaydı varsa sistem gerçek kaydı kullanır, ancak yasal minimumun altına düşen kayıtları `uyarı` veya `manuel inceleme` olarak işaretler

### 3.4 11 Saat Eşiği

4857 sayılı İş Kanunu Madde 63 uyarınca çalışma süresinin dağıtımı günlük `11 saat` sınırını aşmamalıdır.

Ürün davranışı:

- hesaplanan günlük çalışma süresi `11 saati` aşarsa `compliance alert` üretilir
- ilk sürümde bu uyarı kayıt girişini bloklamayabilir, ancak ekran ve rapor tarafında kırmızı risk olarak gösterilir

## 4. Haftalık Çalışma Süresi Hesabı

### 4.1 Temel Kural

4857 sayılı İş Kanunu Madde 63'e göre genel çalışma süresi haftada en çok `45 saat`tir.

Sistem haftalık toplamı şu şekilde bulur:

- ilgili hafta içindeki tüm `net_work_duration` değerleri toplanır
- gerekirse o hafta için çalışılmış sayılan günler ayrı kategoride tutulur

### 4.2 Hafta Referansı

Ürün varsayılanı:

- hafta kapanışı `Pazartesi 00:00 - Pazar 23:59` aralığında çalışır

Eğer işletme farklı hafta tanımı isterse bu yapı parametreleştirilebilir; ancak ilk sürüm varsayılanı sabittir.

## 5. Hafta Tatili Hakkı Algoritması

### 5.1 Temel Mevzuat Dayanağı

4857 sayılı İş Kanunu Madde 46'ya göre işçiye, tatil gününden önceki iş günlerinde çalışmış olması koşulu ile yedi günlük zaman dilimi içinde kesintisiz en az 24 saat hafta tatili verilir.

Aynı maddede bazı günlerin çalışılmış gibi sayılacağı belirtilir.

### 5.2 Sistem Kuralı

Sistem her hafta için `hafta_tatiline_hak_kazandi_mi` alanını üretir.

Bu alan `true` olur if:

- personel, planlı iş günlerinde fiilen çalışmışsa
- veya ilgili günler mevzuat gereği çalışılmış sayılıyorsa

### 5.3 Çalışılmış Sayılan Günler

İlk sürümde aşağıdaki statüler çalışılmış gün mantığında değerlendirilir:

- fiili çalışma
- hekim raporuyla belgelenmiş hastalık / dinlenme izni
- ücretli izin
- kanunen çalışılmış sayılan diğer süreler

### 5.4 Hak Kaybettiren Temel Durum

İlk sürüm ürün kuralı:

- `mazeretsiz_devamsizlik` bulunan hafta için `hafta_tatiline_hak_kazandi_mi = false`

Bu durumda sistem:

- devamsızlık gününü ayrıca eksik gün / kesinti adayı olarak işler
- buna ek olarak `Pazar yevmiyesi` kesinti adayı üretir

### 5.5 Pazar Kuralı

Medisa ürün varsayımı:

- haftalık tatil günü varsayılan olarak `Pazar` kabul edilir

Bu nedenle hafta tatili hakkı kaybedildiğinde sistem `Pazar` gününü ücret etkisi açısından ayrı değerlendirir.

## 6. Fazla Çalışma ve Fazla Sürelerle Çalışma

### 6.1 Tanımlar

Resmî dayanak:

- 4857 sayılı İş Kanunu Madde 41
- Fazla Çalışma ve Fazla Sürelerle Çalışma Yönetmeliği Madde 3 ve 4

Tanım ayrımı:

- `fazla çalışma`: haftalık `45 saati aşan` çalışma
- `fazla sürelerle çalışma`: sözleşmeyle belirlenen haftalık normal süre `45 saatin altındaysa`, bu sözleşme süresini aşan ama `45 saate kadar` olan çalışma

### 6.2 Ücret Çarpanları

- `fazla çalışma` her saat için `1.5`
- `fazla sürelerle çalışma` her saat için `1.25`

### 6.3 Hesap Akışı

İlk sürüm hesap mantığı:

1. Personelin sözleşme bazlı haftalık normal süresini belirle
2. Gerçek haftalık toplam net süreyi hesapla
3. Eğer normal süre `45 saat` ise:
   - `45` üstü = `fazla çalışma`
4. Eğer normal süre `45 saatten düşük` ise:
   - normal süre ile `45 saat` arası = `fazla sürelerle çalışma`
   - `45 saat` üstü = `fazla çalışma`

### 6.3.1 V1 FSC kararı (S62A kilit)

Mevcut işletmede haftalık 45 saatin altında sözleşmeli personel **yoktur**.

- Fazla Sürelerle Çalışma (FSC, x1.25) mevzuat dokümanında yer alır; mimaride ve backlog'da açık kalabilir.
- V1 mevzuat kapatma sprintlerinde FSC **aktif öncelik değildir**.
- `sozlesme_saati` ve FSC hesap zinciri **sonraki fazdır**.
- V1 motor varsayımı: tam süreli personel; yalnızca 45 saat eşiği üzerinden fazla çalışma ayrımı yapılır; `fazla_surelerle_calisma` çıktısı **0** olabilir.

### 6.4 Yuvarlama

Yönetmelik uyarınca:

- yarım saatten az süreler `yarım saat`
- yarım saati aşan süreler `bir saat`

olarak kabul edilir.

İlk sürümde bu yuvarlama fazla çalışma hesap satırında uygulanacaktır.

### 6.5 Yıllık Sınır

Yönetmeliğe göre fazla çalışma süresinin toplamı bir yılda `270 saatten` fazla olamaz.

Ürün davranışı:

- sistem kümülatif yıllık fazla çalışma toplamını tutar
- eşik yaklaşırken uyarı üretir
- eşik aşıldığında kaydı `yüksek risk / manuel kontrol` statüsüne çeker

### 6.6 Onay Gereksinimi

Yönetmelik uyarınca fazla çalışma için işçinin yazılı onayı aranır.

İlk sürüm ürün davranışı:

- personel kartında veya özlük tarafında `fazla_calisma_onayi_var_mi` alanı sonraki faz için düşünülür
- ilk sürümde bu veri yoksa sistem uyarı üretir ama hesaplamayı durdurmaz

## 7. Serbest Zaman Algoritması

### 7.1 Dayanak

Fazla Çalışma ve Fazla Sürelerle Çalışma Yönetmeliği Madde 6.

### 7.2 Kural

İşçi isterse zamlı ücret yerine serbest zaman kullanabilir.

Sistem dönüşüm kuralı:

- `fazla çalışma` 1 saat = `1 saat 30 dakika` serbest zaman
- `fazla sürelerle çalışma` 1 saat = `1 saat 15 dakika` serbest zaman

### 7.3 Kullanım

İlk sürüm ürün kuralı:

- amir veya yetkili kullanıcı süreç ekranında `odeme_tipi = ucret | serbest_zaman` seçer
- `serbest_zaman` seçildiğinde sistem ödeme değil hak üretir
- bu hak ayrı bakiye olarak tutulur

### 7.4 Süre Limiti

Yönetmeliğe göre serbest zaman `6 ay` içinde kullanılmalıdır.

Ürün davranışı:

- hak oluşturulma tarihi tutulur
- 6 ay yaklaşırken uyarı verilir
- 6 ay aşıldığında yönetim ekranında eskimiş hak olarak işaretlenir

## 8. Denkleştirme ve Telafi Ayrımı

Bu iki kavram sistemde kesinlikle karıştırılmayacaktır.

### 8.1 Denkleştirme

Dayanak:

- 4857 sayılı İş Kanunu Madde 63

Mantık:

- haftalık normal süre bazı haftalarda daha fazla, bazı haftalarda daha az dağıtılabilir
- günlük çalışma süresi `11 saati` aşamaz
- iki aylık denkleştirme süresi içinde haftalık ortalama normal süreyi aşamaz

Ürün sonucu:

- denkleştirme aktifse, bazı haftalarda `45 saat üzeri` görünse bile ortalama sınır aşılmıyorsa bu saatler otomatik olarak fazla çalışma sayılmaz

### 8.2 Telafi Çalışması

Dayanak:

- 4857 sayılı İş Kanunu Madde 64

Mantık:

- işin durması
- tatil öncesi/sonrası işyerinin tatil edilmesi
- önemli ölçüde eksik çalışma
- işçinin talebiyle verilen izin

gibi nedenlerle eksik kalan süre sonradan telafi ettirilebilir.

Kural:

- telafi süresi `4 ay` içinde yaptırılır
- günde `3 saatten` fazla telafi yapılamaz
- günlük azami çalışma süresi aşılamaz
- tatil günlerinde telafi yapılamaz
- telafi çalışması fazla çalışma sayılmaz

### 8.3 Sistemsel Ayrım

Sistemde:

- `denklestirme` normal süre dağıtım modelidir
- `telafi` eksik çalışılmış geçmiş sürenin geri kazanılmasıdır

Bu nedenle aynı kayıt tipi olarak işlenmeyecek, ayrı süreç türleri veya ayrı hesap flag'leri taşıyacaktır.

## 9. Saat Devri Yasağı

İç ürün kuralı:

- ay içinde eksik kalan saatler sonraki aylara taşınıp biriktirilerek toplu tam gün kesinti üretilemez

Sistem davranışı:

- eksik saat aynı bordro döneminde çözülür
- çözüm ya `telafi çalışması` ile olur
- ya da o dönemin ücret etkisine yansıtılır

Bu karar, sistemin aylar arası keyfi eksi saat borcu taşımasını engeller.

## 9.1 Geç Kalma / Erken Çıkma Kesinti Notu

V1 ürün kuralı:

- `beklenen_giris_saati` ve `beklenen_cikis_saati` günlük puantaj kaydındaki opsiyonel snapshot alanlarıdır
- bu alanlar ana detay kartında readonly görünür; hesap girdisi olarak UI'da yeniden türetilmez
- `gercek_eksik_dakika` beklenen ve fiili saat farkından üretilir; gerçek fark korunur
- parasal kesinti doğrudan bu gerçek dakika üzerinden değil, `kesintiye_esas_dakika` üzerinden hesaplanır
- `kesintiye_esas_dakika = Math.ceil(gercek_eksik_dakika / 30) * 30`
- `gercek_eksik_dakika = 0` ise kesinti yoktur
- geç / erken fark hesaplanamazsa parasal kesinti gösterilmez

**S74-C3-B1 authoritative dakika (apply önkoşulu):**

- `gec_kalma_dakika` ve `erken_cikis_dakika` nullable unsigned integer kolonlarıdır (`gunluk_puantaj`, `puantaj_aylik_muhur_satirlari`).
- Açık dakika alanı doluysa saat farkı hesabından **önce** authoritative kabul edilir.
- Saat farkı yalnız açık dakika alanı yoksa fallback olarak kullanılır.
- Explicit `0` geçerli değerdir; saat fallback'ini tetiklemez.
- Aylık mühür snapshot'ı her iki dakika kolonunu kayıpsız kopyalar.
- Migration `012_gunluk_puantaj_gec_erken_dakika.sql` owner onayıyla canlı `karmotor_medisa` veritabanına manuel uygulanmıştır (S74-C3-B1); tekrar çalıştırılmamalıdır.

**S74-D1-B manuel inceleme preset mapping (authoritative apply hedefi):**

- `DEVAMSIZLIK_GUN` → `Gelmedi` / `Yok_Izinsiz` / `Yevmiye_Kes`
- `GEC_KALMA_DAKIKA` → `Gec_Geldi` + `gec_kalma_dakika` (1–1440)
- `ERKEN_CIKIS_DAKIKA` → `Erken_Cikti` + `erken_cikis_dakika` (1–1440)
- `GOREVDE_CALISILMIS_GUN` → `Geldi` / `Gorevde_Calisma` / `Tam_Yevmiye_Ver`
- İzin/rapor türleri manuel preset değildir; personel süreci dayanağı gerektirir.

Bu yuvarlama tolerans değildir. Gerçek eksik süre `0` ise kesinti yoktur; `1-30 dk` arası parasal hesapta `30 dk`, `31-60 dk` arası `60 dk`, `61-90 dk` arası `90 dk` kabul edilir.

Sınır örnekleri:

| Gerçek eksik süre | Kesintiye esas süre |
|---|---|
| `0 dk` | `0 dk` |
| `1-30 dk` | `30 dk` |
| `31-60 dk` | `60 dk` |
| `61-90 dk` | `90 dk` |

Güvenli hesap prensibi:

- beklenen giriş yoksa parasal özet üretilmez
- beklenen çıkış yoksa parasal özet üretilmez
- gerçek giriş yoksa parasal özet üretilmez
- gerçek çıkış yoksa parasal özet üretilmez
- geçersiz saat formatında parasal tutar üretilmez
- eksik dakika `0` ise kart şişirilmez

Katman prensibi:

- iş kuralı servis katmanında kalır
- hook servis sonucunu view model'e taşır
- page / UI hesap yapmaz, sadece hook'tan gelen readonly veriyi render eder

Doğrulama kapsamı:

- `tests/unit/puantaj-hesap-motoru.test.ts` içinde güvenli durumlar ve 30 dk sınır değerleri kilitlendi
- `tests/e2e/smoke.spec.ts` içinde 1 dk geç gelme senaryosunda UI seviyesinde `Gerçek Eksik Süre (dk) = 1` ve `Kesintiye Esas Süre (dk) = 30` assert edildi

Bu başlık, ürün karar özeti olan `docs/guncel/11-puantaj-kural-matrisi.md` Bölüm 8 ile uyumlu tutulmalıdır.

## 10. Yıllık İzin Algoritması

### 10.1 Hak Ediş

4857 sayılı İş Kanunu Madde 53 ve Yıllık Ücretli İzin Yönetmeliği temel alınır.

Kural:

- işe giriş tarihinden itibaren, deneme süresi dahil, en az `1 yıl` çalışan işçi yıllık izne hak kazanır

### 10.2 Süreler

- `1 yıl - 5 yıl arası` = `14 gün`
- `5 yıldan fazla - 15 yıldan az` = `20 gün`
- `15 yıl ve üzeri` = `26 gün`

### 10.3 Yaş İstisnası

4857 sayılı İş Kanunu Madde 53'e göre:

- `18 yaş ve altı`
- `50 yaş ve üzeri`

işçiler için yıllık ücretli izin `20 günden az olamaz`.

### 10.4 Sistem Kuralı

İlk sürüm hesap akışı:

1. `ise_giris_tarihi` ile hak ediş tarihi hesaplanır
2. `dogum_tarihi` ile yaş bandı kontrol edilir
3. kıdem grubu bulunur
4. yaş istisnası gerekiyorsa taban hak `20 gün`e çekilir

### 10.5 Kullanım Kuralları

Yıllık Ücretli İzin Yönetmeliği Madde 6'ya göre:

- yıllık izin işveren tarafından bölünemez
- ancak tarafların anlaşmasıyla bölünebilir
- bölünüyorsa bir parçası `10 günden az olamaz`

İlk sürüm ürün davranışı:

- yıllık izin kaydı oluşturulurken toplam izin içinde en az bir blok `10 gün ve üzeri` değilse uyarı verilir
- ihtiyaç halinde kayıt `manuel onay gerekli` statüsüne alınabilir

### 10.6 İzin Mahsubu Kuralı

Yıllık ücretli izin yönetmeliğine göre:

- hastalık izni
- diğer ücretli / ücretsiz izinler

yıllık izne otomatik mahsup edilemez.

Sistem davranışı:

- farklı izin türleri ayrı event olarak tutulur
- yıllık izin bakiyesi sadece `yillik_izin` event'leri ile azalır

## 11. Rapor ve İş Kazası Ayrımı

### 11.1 Hastalık Raporu

SGK'ya göre hastalık halinde geçici iş göremezlik ödeneği:

- raporun `3. gününden` itibaren başlar
- hak ediş için son 1 yıl içinde en az `90 gün` kısa vadeli sigorta primi gerekir

Ürün davranışı:

- `rapor_turu = hastalik` seçildiğinde sistem prim gün şartı için kontrol alanı veya uyarı üretir
- ücret etkisi ve SGK ödenek ayrımı sonraki bordro katmanında ayrıca işlenir

#### 11.1.1 İlk 2 gün firma ödemesi (S62A + S70A)

Yalnızca `Raporlu_Hastalik` için geçerlidir; `Raporlu_Is_Kazasi` ayrı değerlendirilir.

**Şirket parametresi (canonical varsayılan):**

| Parametre | Varsayılan | Açıklama |
|-----------|------------|----------|
| `hastalik_ilk_iki_gun_firma_oder_mi` | `false` | İşletme varsayılan politikası |

**Süreç bazlı override:**

| Alan | Rol |
|------|-----|
| `ilk_iki_gun_firma_oder_mi` | Tek rapor/süreç için şirket varsayılanından sapma |

Kurallar:

- Şirket parametresi tanımsızsa ilgili günler `MANUEL_INCELEME` veya `PARAMETRE_EKSIK` olarak işaretlenir.
- Kullanıcı rapor girişinde override değiştirebilir; audit izi zorunludur.
- “İlk 2 gün” hesabı rapor event / periyot kaydı üzerinden düşünülür.

### 11.2 İş Kazası

SGK'ya göre iş kazası halinde geçici iş göremezlik ödeneği:

- her gün için ödenir
- belirli bir prim gün şartına bağlanmaz

Ürün davranışı:

- `rapor_turu = is_kazasi` olduğunda 90 gün prim kontrolü aranmaz
- kayıt, standart hastalık raporundan farklı severity ile işaretlenir

### 11.3 Çalışılmış Gün Sayımı Etkisi

Hafta tatili hesabında:

- hekim raporuyla belgelenmiş hastalık ve dinlenme izinleri çalışılmış gün gibi dikkate alınır

Bu nedenle `raporlu` statü her zaman `mazeretsiz_devamsizlik` ile aynı sınıfa düşmez.

## 12. Ücretli Mazeret İzni Kuralı

İç ürün kuralı:

- düğün
- cenaze
- doğum gibi

ücretli mazeret izinleri `ucretli` etiket taşır.

Sistem etkisi:

- tam yevmiye mantığında ücret etkisi korunur
- hafta tatili hakkı hesabında çalışılmış gün gibi sayılır
- yıllık izin bakiyesinden düşülmez

## 13. Devamsızlık Algoritması

### 13.1 Tür Ayrımı

Devamsızlık event'leri en az şu alt sınıfları taşımalıdır:

- `mazeretli_devamsizlik`
- `mazeretsiz_devamsizlik`

### 13.2 Sistem Etkisi

- `mazeretsiz_devamsizlik` ücret kesinti adayı üretir
- `mazeretsiz_devamsizlik` hafta tatili hakkını düşürebilir
- `mazeretli_devamsizlik` ayrı değerlendirilir; otomatik aynı sonucu üretmez

## 14. BES, Avans, Prim ve Ceza

### 14.1 BES

İç ürün kuralı:

- `bes_kesintisi_var_mi = evet` ise bordro döneminde ilgili oran uygulanır

İlk sürüm notu:

- tam bordro matematiği bu belgede detaylandırılmaz
- ancak veri modeli ve hesap motoru bu alanı taşıyacak şekilde hazırlanır

### 14.2 Avans

- avans, maaştan düşülecek ayrı finansal event olarak tutulur
- ana personel kartı alanı değildir

### 14.3 Prim / Ceza / Ekstra Ödeme

- bunlar ana maaş verisinin parçası değil, dönemsel event ve finans kalemidir
- hesap motoru ilgili dönem toplamına ekler veya düşer

## 15. Haftalık Kapanış Mantığı

### 15.1 Amaç

`haftalik_kapanis` tablosu sadece rapor değil, mühür alanıdır.

Hafta kapanışında şu alanlar kesinleşir:

- haftalık toplam süre
- fazla çalışma
- fazla sürelerle çalışma
- hafta tatiline hak kazanma durumu
- kritik uyarılar

### 15.2 Davranış

İlk sürüm ürün kuralı:

- hafta kapanışı alındığında o haftanın hesap özeti snapshot olarak saklanır
- sonradan değişiklik gerekirse doğrudan alan ezmek yerine düzeltme event'i veya yeniden hesap tetiklemesi tercih edilir

Not:

- tam kilitleme / yeniden açma akışı ayrı `State Flow + API Contract` dokümanında netleştirilecektir

## 16. Hesap Sırası

İlk sürümde motor aşağıdaki sırayla hesap yapar:

1. Günlük giriş/çıkıştan ham süreyi bul
2. Mola düşümünü uygula
3. Günlük net süreyi üret
4. Haftalık toplamı oluştur
5. Süreç event'lerini haftaya bindir
6. Hafta tatili hakkını hesapla
7. Fazla çalışma / fazla sürelerle çalışma ayrımını yap
8. Serbest zaman veya ücret tercihine göre çıktı üret
9. İzin haklarını ve bakiyeleri güncelle
10. Finans event'lerini bordro katmanına hazırla

## 17. Manuel İnceleme Gerektiren Durumlar

İlk sürümde aşağıdaki durumlar otomatik hesaplanır ama ayrıca `manuel_inceleme` işareti taşımalıdır:

- 11 saat üzeri günlük çalışma
- yıllık 270 saat sınırına yaklaşan veya aşan fazla çalışma
- eksik veya çelişkili giriş/çıkış verisi
- rapor türü girilmiş ama SGK kontrol verisi eksik kayıt
- hafta tatili hakkı ile süreç kayıtları arasında çelişki
- yıllık izin bölünme kuralına uymayan talep

## 18. V1 Sınırı

Bu dokümanın `V1` sınırında şunlar kesin vardır:

- günlük net süre hesabı
- mola düşümü
- hafta tatili kararı
- fazla çalışma ayrımı (45 saat eşiği; tam süreli varsayım)
- serbest zaman üretimi
- yıllık izin hak edişi
- rapor / iş kazası ayrımı
- devamsızlık etkisi

Bu dokümanın `V1` sınırında şunlar sonraki detay dokümana bırakılmıştır:

- net bordro formülü ve netten brüte hesap motoru
- `brut_maas_tutari` üretim motoru ve bordro/vergi parametreleri
- `sozlesme_saati` ve FSC (x1.25) aktif hesap zinciri
- vergi ve SGK matrah akışı
- banka ödeme dosyası kolon formatı
- dönem kapatma ve geri açma workflow'u
- API endpoint ve request/response sözleşmesi

## 19. Sonuç

Bu motorun özü şudur:

- günlük veriyi toplar
- haftalık hakkı mühürler
- süreç event'lerini maaş ve izin etkisine dönüştürür
- insan yorumuna bırakılan gri alanları azaltır

Bu belge sonrası sıradaki doğru doküman:

- `State Flow + API Contract`

çünkü artık ürün mantığı, veri kapsamı, UI contract'ı ve hesap motoru netleştiğine göre, uygulamanın veri yaşam döngüsünü ve ekran-endpoint ilişkisini çivileme zamanı gelmiştir.

## Belge Geçmişi

| Tarih | Not |
|-------|-----|
| 2026-07-07 | S62A: net maaş canonical alan, brüt salt okunur, FSC V1 backlog, hastalık ilk 2 gün rapor event politikası kilitlendi. |
| 2026-05-15 | Geç kalma / erken çıkma için 30 dakikalık yukarı yuvarlama ve `kesintiye_esas_dakika` notu eklendi. |
| 2026-05-15 | Geç / Erken Kesinti V1 kapanış davranışı, güvenli hesap prensibi, katman sınırı ve test kapsamı sabitlendi. |
