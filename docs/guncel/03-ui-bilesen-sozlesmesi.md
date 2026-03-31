# Medisa Personel ve Puantaj Yönetim Sistemi

## UI Sistemi ve Bilesen Sozlesmesi

Sürüm: `V1`

## Belgenin Amacı

Bu doküman, ürünün görünen yüzü için tek referanstır.
Eski frontend rehberindeki görsel yön bilgisini de emerek hem ürün hissini hem de component davranışını tek belgede toplar.

Amaç, geliştiriciye "yaklaşık böyle bir şey" değil, "bu ekran ailesi ve bu component böyle davranır" seviyesinde net kontrat vermektir.

Bu belge özellikle şu açıkları kapatır:

- görsel kimlik ve Medisa hissi
- rol bazlı yüz ayrımı
- ana modül görünüm ailesi
- header ve sağ üst aksiyon düzeni
- bildirim simgesi konumu ve davranışı
- footer iç yerleşimi ve dimmer davranışı
- buton aileleri
- modal header ve modal action area
- form yerleşim kontratı
- dropdown ve durum badge dili

## Temel İlke

Bu projede component geliştirme kuralı şudur:

- görsel dil `tasitmedisa` referansını taşır
- component contract yeni projede temiz ve tek sahibi olan bir mimari ile uygulanır

Yani component görünüşü referanstan ilham alır, ama aynı component birden fazla dosyada savaşarak tanımlanmaz.

## Dokumanin Sahipligi

Bu belge şu soruların tek sahibidir:

- ürün kullanıcıya nasıl görünmeli?
- Medisa hissi hangi görsel imzalarla taşınmalı?
- rol bazlı yüz sadeleşmesi nasıl korunmalı?
- header, hero, modal, footer, buton ve form ailesi nasıl davranmalı?

Bu belge şu konuların sahibi değildir:

- selector sahipliği
- CSS katman mimarisi
- `!important` yasağının teknik uygulaması
- state ve API yaşam döngüsü

Bu başlıklar sırasıyla `08-frontend-teknik-mimari.md` ve `05-state-flow-api-kontrati.md` içindedir.

## Gorsel Yon

- Koyu tema ana karakterdir.
- Kırmızı vurgu ve glow dili korunur.
- Başlıklarda metalik / krom hissi ürün imzasıdır.
- Uygulama masaüstünde bile tek gövdeli mobil-app hissi verir.
- Footer glow ve dimmer davranışı dekor değil, ürün karakteridir.

## Rol ve Yuz Ayrimi

- Tek login ekranı vardır.
- Yönetim rolleri tam ürün yüzüne girer.
- Birim amiri aynı tema ailesini taşıyan sadeleştirilmiş yüz ile çalışır.
- Rol farkı yeni tema üretmez; görünürlük, yoğunluk ve akışı sadeleştirir.

## Modul Baglami

Bu UI sistemi aşağıdaki ana modüller için ortak dildir:

- personel giriş ve süreç takibi
- personel kartı
- bildirimler
- puantaj ve haftalık kapanış
- raporlar

Çekirdek ürün kuralı:

- personel kartı sabittir
- hareketler süreç akışıyla görünür
- hesaplanan alanlar veri girişi gibi sunulmaz

Bu yüzden form, detay ve özet ekranları aynı şeyi farklı yerde tekrar etmez; her ekran kendi görevine göre yoğunluk taşır.

## 1. Uygulama Kabuk Contract'ı

### 1.1 Ana Gövde

- Tüm ana ekranlar `app-container` içinde çalışır
- Maksimum genişlik `500px` mantığı korunur
- Uygulama masaüstünde bile tek gövdeli mobil-app hissi verir
- İçerik ana kaydırma alanı `content-wrap` içinde yaşar
- Footer için altta daima rezerv alan bırakılır

### 1.2 Genel Dikey Ritim

- Hero sonrası ilk aksiyon satırı arasında düzenli boşluk korunur
- Header ile ilk içerik arası temel nefes `8px` omurgasına bağlıdır
- İçerik ile footer arasında kontrollü nefes alanı korunur
- Modal açıkken footer ile modal arasında sabit bir boşluk hissi kaybolmaz

## 2. Header ve Hero Contract'ı

### 2.1 Hero Yapısı

Hero bileşeni aşağıdaki parçaları içerir:

- `hero`
- `hero-logo`
- `h1`
- `hero-spacer`
- `animated-line`

### 2.2 Hero Davranışı

- Hero ekranın en üst görsel imzasıdır
- Koyu, premium ve teknolojik panel karakteri taşır
- Başlık metalik/krom gradient mantığıyla görünür
- Başlık tek satırda davranır; dar alanda taşmak yerine kontrollü kırpılır
- Altındaki kırmızı `animated-line` zorunlu parçadır, dekor değil imzadır

### 2.3 Hero Yerleşim Kuralları

- Logo solda yer alır
- Başlık ortalanmış hissi verir
- Sağ denge alanı spacer ile korunur
- Hero altına ikinci bir dekoratif başlık çizgisi eklenmez

## 3. Header Altı Aksiyon Satırı Contract'ı

### 3.1 Yapı

Hero altında ayrı bir `icons-row` yer alır.
Bu satır üç parçalıdır:

- `icons-row-left`
- `pwa-install-center`
- `icons-row-right`

### 3.2 Sağ Blok

Sağ blokta global aksiyon ikonları bulunur.
Varsayılan sıra:

1. Bildirim simgesi
2. Ayarlar simgesi

Kural:

- global aksiyonlar hero içine gömülmez
- global aksiyonlar header altındaki sağ blokta yaşar
- aynı ekranda hem hero içine hem farklı yere ikinci aksiyon kümesi açılmaz

### 3.3 Görünürlük

- Yönetim kabuğundaki ana ekranlarda bildirim ve ayarlar ikonları görünür
- Login ekranı bu contract'ın dışında tutulabilir
- Birim amiri sade yüzünde aynı aile korunur, ancak yetkisiz ikonlar görünmez

## 4. Bildirim Component Contract'ı

### 4.1 Konum

- Bildirim tetikleyicisi header altı aksiyon satırının sağ bloğunda yer alır
- Ayarlar ikonunun solunda konumlanır
- Tekil ikon butonudur; ayrı bir kart veya menü butonu değildir

### 4.2 Tetikleyici Bileşeni

- Sınıf ailesi `icon-btn`
- Kimlik düzeyinde tetikleyici `notifications-toggle-btn`
- Görsel temel: nötr ikon, hover'da daha parlak görünüm

### 4.3 Bildirim Durumları

Bildirim simgesi en az üç görsel state taşır:

- `nötr`: standart pasif renk
- `uyarı`: turuncu ton
- `kritik / okunmamış`: kırmızı ton

Gerektiğinde dikkat çekme davranışı:

- pulse animasyonu kullanılabilir
- pulse sürekli dekorasyon olarak değil, gerçekten aksiyon gerektiren durumda çalışır

### 4.4 Açılan Panel

- Bildirimler ayrı sayfaya gitmeden açılan panel mantığında çalışır
- Açılan yapı `settings-dropdown` ailesini kullanır
- Panel sağ üst aksiyon alanına bağlı davranır
- Panel scroll edilebilir olmalıdır
- Son öğe kesilmeden görülebilmelidir

### 4.5 İçerik Sunumu

Bildirim öğeleri aşağıdaki dili taşır:

- açık okunur başlık satırı
- daha pasif ikinci bilgi satırı
- border ile seviye vurgusu
- hover'da sadece kontrollü belirginleşme

Renk dili:

- kritik: kırmızı border
- yaklaşan uyarı: turuncu border
- okunmamış aktivite: kırmızıya yakın belirgin kenarlık

### 4.6 Toolbar

Bildirim paneli içinde üstte sticky bir mini toolbar bulunabilir.
Örnek aksiyon:

- `Tümünü okundu işaretle`

Bu toolbar içerik üstünde sabitlenir ama ikinci bir modal hissi üretmez.

### 4.7 Badge Kuralı

İlk sürümde numeric badge zorunlu değildir.
Öncelik renk ve pulse ile dikkat durumunu anlatmaktır.

Eğer ileride badge eklenirse:

- sadece küçük sayaç amacıyla kullanılır
- ikonun kendisini boğmaz
- ikonun sağ üstünde kompakt görünür
- ikinci bir metin etiketi gibi davranmaz

## 5. Ayarlar Dropdown Contract'ı

### 5.1 Tetikleyici

- Sağ üst aksiyon alanında bildirim ikonunun yanında bulunur
- Aynı `icon-btn` ailesinde davranır

### 5.2 Panel Yapısı

- `settings-dropdown` floating panel mantığı kullanılır
- Alt menü varsa `settings-submenu` ile açılır
- Panel butonları aynı yükseklik ve aynı ailede kalır
- Hover davranışları tema dışına taşmaz

## 6. Back Bar Contract'ı

### 6.1 Konum

- Geri dönüş aksiyonu hero içinde değil, hero altında ayrı `back-bar` satırında yaşar
- Modal veya alt ekran girişlerinde standart geri hissi burada sağlanır

### 6.2 Yapı

- `universal-back-bar`
- `universal-back-btn`
- `universal-back-label`
- `back-icon-svg`

### 6.3 Davranış

- Ok ve etiket tek satırda hizalı görünür
- Ok ile etiket arası sabit ve küçük boşluk taşır
- Etiket, ana başlıktan daha ikincil bir tonda görünür
- Aynı modülde geri aksiyonu başka yerde ikinci kez üretilmez

## 7. Footer Contract'ı

### 7.1 Genel Kural

- Footer tüm ana uygulama ekranlarında `fixed` davranır
- `sticky` footer kullanılmaz
- Footer uygulama kabuğunun parçasıdır, sayfa içi içerik değildir

### 7.2 Yapı

Footer aşağıdaki üç parçadan oluşur:

- `version`
- `brand`
- `status`

Yerleşim:

- sürüm bilgisi solda
- marka/logotype merkezde
- sistem durumu sağda

### 7.3 Boyut ve Alan

- Footer, uygulama gövdesiyle aynı maksimum genişlik karakterini korur
- Alt safe-area dikkate alınır
- İç padding mobil/PWA alt çentik davranışını bozmadan uygulanır

### 7.4 Görsel Davranış

- Footer yukarı doğru hafif glow verir
- Ana temadan kopuk ikinci bir alt bar üretilmez
- Border ve ışık yoğunluğu abartılmaz

### 7.5 Dimmer Davranışı

Footer dimmer davranışı bağlayıcıdır:

- ilk yüklemede `version` ve `status` daha baskın olabilir
- `brand` ilk anda daha soluk kalabilir
- kısa gecikme sonrası odak merkeze, yani `brand` alanına kayar

Bu geçiş:

- yumuşak opacity geçişiyle olur
- rastgele animasyon değil, ürün imzası gibi davranır

### 7.6 Durum Metni

Sağ bloktaki `status` alanı en az iki ana durumu taşımalıdır:

- `hazır / başarılı`
- `hata / sorun`

Renk dili:

- hazır: yeşil
- hata: kırmızı

### 7.7 Modal ile İlişki

- Modal açıkken footer tamamen yok olmaz
- Footer ile modal arasında kontrollü boşluk hissi korunur
- Glow görünürlüğü bozulmadan modal üstte çalışır

## 8. Buton Ailesi Contract'ı

### 8.1 Genel İlke

Sistemde tek bir ana aksiyon buton ailesi vardır.
Aynı iş için ikinci buton ailesi açılmaz.

### 8.2 Grup Yapısı

- `universal-btn-group` temel kapsayıcıdır
- Varsayılan yapı iki eşit kolonlu grid mantığıdır
- Grup tam genişlik çalışır

### 8.3 Kaydet Butonu

- Sınıf: `universal-btn-save`
- Anlam: pozitif ana aksiyon
- Renk dili: yeşil
- Görünüm: ince border, şeffaf zemin, hover'da kontrollü dolgu

### 8.4 Vazgeç Butonu

- Sınıf: `universal-btn-cancel`
- Anlam: işlemi geri alma / formu kapatma
- Renk dili: tema kırmızısı
- Görünüm: kaydet ile aynı ailede, sadece renk anlamı farklı

### 8.5 Sil / Yıkıcı Aksiyon

- Sınıf ailesi: `settings-btn-delete` veya yıkıcı aksiyon için tanımlı tek ortak sınıf
- Yıkıcı aksiyon ayrı bir üçüncü buton dünyası gibi davranmaz
- Görsel dili `Vazgeç` ile aynı ailede kalır ama anlamı net şekilde yıkıcıdır

### 8.6 Boyut Contract'ı

Ana aksiyon butonlarında aşağıdaki karakter korunur:

- kompakt yükseklik
- hafif radius
- ince border
- tek satırlık metin
- tam genişlik grid hücresi

Referans karakter:

- yükseklik yaklaşık `32px`
- radius yaklaşık `8px`
- ince `1px` border mantığı

Bu değerler component seviyesinde korunur; modül dosyaları keyfine göre değiştiremez.

### 8.7 Hover Contract'ı

- Hover'da tema dışı renk sapması olmaz
- beyaza/pembeye kaçan renk bozulması oluşmaz
- yazı zıplaması veya layout kayması olmaz
- hover etkisi aynı aile içinde hafif kuvvet artışı şeklindedir

### 8.8 Yasaklar

- modüle özel ikinci save/cancel ailesi açılamaz
- formdan forma farklı radius yüksekliği üretilmez
- destructive action için rastgele turuncu/siyah ayrı stil üretilemez

## 9. Form Component Contract'ı

### 9.1 Alan Yapısı

Her form alanı şu iskeleti izler:

- `form-section`
- `form-label`
- `form-input`

Bu contract input, select ve textarea için ortak mantık taşır.

### 9.2 Görsel Davranış

- input yükseklikleri aynı sistemde kalır
- placeholder ve girilmiş veri rengi tema ile uyumlu olur
- date input gibi alanlar da aynı aile içinde görünür
- form alanı arka planı tema panel mantığını korur

### 9.3 Kolon Davranışı

İki kolonlu modal formlarda:

- sol kolon kişisel bilgileri taşır
- sağ kolon iş bilgilerini taşır
- kolonlar ayrı dünya gibi davranmaz, tek modal gövdesinin parçasıdır

## 10. Modal Contract'ı

### 10.1 Açılma Mantığı

- Alt ekranlar `modal-overlay` ve `modal-container` sistemi ile açılır
- Modal rastgele sayfa içi kart gibi davranmaz
- Ana ürünün alt ekran standardı modaldır

### 10.2 Modal Header

Header aşağıdaki parçaları içerir:

- başlık
- varsa kapatma butonu

Contract:

- kırmızı gradient ailesi korunur
- başlık güçlü görünür
- close butonu her modalde aynı karakterdedir
- bazı modallarda header içinde, bazılarında dışında ikinci toolbar açılmaz

### 10.3 Modal Body

- İçerik `modal-body` içinde akar
- Kaydırma gerekiyorsa body seviyesinde çözülür
- İçerik üstten kesilmez
- Form başlangıcı modüller arasında aynı hizayı korur

### 10.4 Modal Action Area

- Ana aksiyonlar modal altına, içerikle görsel olarak bağlı ama düzenli şekilde yerleşir
- Save/Cancel grubu formdan kopuk ayrı bir kart gibi davranmaz
- Action alanı inputlarla aynı yatay hizayı takip eder

### 10.5 Modal ve Footer Aralığı

- Modal altı ile footer arasında sabit nefes alanı korunur
- Özellikle mobil ve PWA'da modal footer'a yapışık görünmez

## 11. Dropdown ve Floating Panel Contract'ı

### 11.1 Ortak Kural

- Dropdown'lar floating panel mantığında açılır
- Kırpılma, son elemanın kesilmesi ve yanlış scroll davranışı kabul edilmez

### 11.2 Davranış

- panel açıldığında hizası ekrandan taşmaz
- hover ve focus davranışları ortak temayı korur
- alt menü varsa yan panel veya alt panel olarak aynı ailede açılır

## 12. Badge ve Durum Kutusu Contract'ı

### 12.1 Badge Kullanımı

Badge yalnızca durum veya dikkat seviyesi anlatmak için kullanılır.
Dekoratif sayaç veya gereksiz renk gürültüsü için kullanılmaz.

### 12.2 Renk Anlamları

- yeşil: hazır / olumlu
- kırmızı: kritik / yıkıcı / okunmamış / riskli
- turuncu: yaklaşan uyarı / orta seviye dikkat
- gri: ikincil / salt okunur / pasif bilgi

## 13. Responsive ve PWA Contract'ı

### 13.1 Genel Kural

- Mobil, desktop ve PWA farkları component contract'ını bozmaz
- Aynı component farklı cihazda başka bir aileye dönüşmez

### 13.2 Safe-Area

- Footer alt padding'i safe-area dikkate alır
- Overlay ve modal alt boşluğu platforma özel kırılmaz
- iOS için yazılan kural Android veya desktop'a sızmaz

### 13.3 Aksiyon Satırı

- Sağ üst ikonlar mobilde de aynı ailede kalır
- Sıkışma halinde ikonlar kaybolmaz, layout kontrollü daralır

## 14. Bileşen Bazlı Yasaklar

Bu spec kapsamında aşağıdakiler yasaktır:

- hero içine ikinci aksiyon satırı eklemek
- geri butonunu back-bar dışında rastgele yere koymak
- footer içindeki üç alan dizilimini bozmak
- save/cancel dışında ikinci ana aksiyon ailesi üretmek
- modüle özel farklı modal-header dili açmak
- bildirim panelini bir yerde dropdown, başka yerde modal yapmak

## 15. Geliştiriciye Teslim Mantığı

Bu belge geliştiriciye şu soruların net cevabını vermelidir:

- bu component nerede durur?
- hangi aileye aittir?
- hangi state'leri vardır?
- hangi davranışları zorunludur?
- hangi şeyleri kesinlikle yapamaz?

Bu belge sonrası sıradaki doğru doküman:

- `Hesap Motoru Kural Dokümanı`

çünkü artık veri kapsamı ve component contract netleştiğine göre, arka plandaki iş kurallarını algoritma seviyesinde kilitleme zamanı gelmiştir.
