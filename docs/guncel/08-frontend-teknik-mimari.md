# Personel Sistemi Frontend Teknik Mimari

## Amaç

Bu doküman, önceki projelerde yaşanan şu kök problemi tekrar üretmemek için hazırlanmıştır:

- aynı bileşenin birden fazla CSS dosyasında yeniden tanımlanması
- inline style ile CSS katmanının delinmesi
- modül dosyalarının core bileşenleri override ederek büyümesi
- `!important` ile geçici çözüm üretildikçe selector savaşının kalıcı hale gelmesi

Bu doküman "ürün nasıl görünür" sorusunu değil, "kodu nasıl temiz ve sürdürülebilir tutarız" sorusunu cevaplar.

## Kök Sebep Tanımı

Önceki yapıda problem, CSS'in fazla olması değildi.
Asıl problem, sahipliğin dağılmasıydı.

Bir bileşen bir kez tanımlanması gerekirken:

- core dosyada
- sayfa dosyasında
- modal özelinde
- bazen inline style içinde

yeniden tanımlandı.

Bu tekrarlar şu sonuçları üretti:

- specificity savaşı
- sürekli yeni override ihtiyacı
- `!important` bağımlılığı
- dosya sonuna eklenen yama blokları
- güvenle refactor edilemeyen şişmiş dosyalar

## Altın Kural

Bir UI parçasının tek sahibi olur.
Aynı bileşen ikinci kez tanımlanmaz.

Sorun çıktığında:

- önce bileşenin sahibi olan dosya bulunur
- çözüm orada yapılır
- başka dosyada karşı-yama açılmaz

## Katmanlı CSS Mimarisi

Yeni projede CSS katmanları aşağıdaki sırayla düşünülmelidir:

1. `tokens`
2. `base`
3. `layout`
4. `components`
5. `modules`
6. `platform`
7. `print`

## Katman Sorumlulukları

### 1. tokens

Sadece değişkenler burada yaşar:

- renkler
- spacing
- radius
- shadow
- z-index
- breakpoint
- footer ve modal ölçü değişkenleri

Burada selector yazılmaz.

### 2. base

Sadece global temel davranışlar burada olur:

- reset
- body/html davranışı
- font ailesi
- scrollbar politikası
- ortak metin davranışı

Burada modül görünümü tanımlanmaz.

### 3. layout

Uygulamanın taşıyıcı omurgası burada yaşar:

- `app-container`
- `content-wrap`
- ana shell
- ortak scroll alanları
- sayfa iskeleti

### 4. components

Tekrarlanan UI parçalarının tek sahipliği burada olur:

- hero
- animated line
- modal shell
- modal header
- form label/input/select/textarea
- universal button ailesi
- settings dropdown
- ortak badge, empty state, loading state
- footer

Bir bileşen burada tanımlandıysa modül dosyasında aynı selector tekrar açılamaz.

### 5. modules

Modül dosyaları sadece kendi ekran kökleri altında çalışır.

Örnek düşünce:

- `.personel-menu-page`
- `.personel-kayit-page`
- `.surec-page`
- `.personel-kart-page`
- `.raporlar-page`
- `.bildirimler-page`

Modül dosyası şunları yapabilir:

- modüle özel grid
- modüle özel kart dizilimi
- modüle özel tablo düzeni
- modüle özel alan grupları

Modül dosyası şunları yapamaz:

- global modal davranışını yeniden yazmak
- ortak button ailesini yeniden tanımlamak
- `form-input` temel stilini başka renge taşımak
- footer davranışını override etmek

### 6. platform

Platforma özel farklar tek yerde izole edilir:

- mobile
- desktop
- iOS PWA
- Android PWA

Tek cihaz için yapılan düzeltme modül dosyasına serpiştirilmez.

### 7. print

Yazdırma stili ayrı katmandır.
Ekran görünümünü düzeltmek için print selector'ü kullanılmaz.
Print görünümünü düzeltmek için ekran CSS'i bozulmaz.

## Selector Kuralları

- Öncelik class selector'dadır
- ID selector stil sahipliği için kullanılmaz
- Derin descendant zinciri açılmaz
- Bir selector mümkünse üç seviyeyi geçmez
- Tag selector üzerinden ortak bileşen stili kurulmaz
- Bileşen ismi modül kökü olmadan global override amacıyla tekrar yazılmaz

Yanlış yaklaşım:

- `#some-modal .modal-container .form-section input`

Doğru yaklaşım:

- `.surec-page .surec-filter-row`
- `.personel-kart-page .summary-grid`

## `!important` Politikası

Varsayılan kural: yasak.

Sadece iki istisna vardır:

1. Tarayıcı veya üçüncü parti bileşeni ezmek zorunluysa
2. Ortak utility sınıfı görünürlük gibi tek amaçlı kesin davranış veriyorsa

Bu iki istisna dışında `!important` kullanılmaz.

Her istisna yanında kısa açıklama yorumu olmak zorundadır.

## Inline Style Politikası

Varsayılan kural: yasak.

JS, stil vermek için `element.style` yazmayacak.
Bunun yerine:

- class toggle
- data attribute
- root seviyesinde kontrollü CSS variable güncellemesi

kullanılacak.

Amaç, görünüm kararını JS içine gömmemektir.

## JS ve CSS Sözleşmesi

JS'nin görevi görünüm üretmek değildir.
JS yalnızca durum değiştirir.

Kullanılacak yöntemler:

- `.is-open`
- `.is-active`
- `.is-hidden`
- `.is-loading`
- `data-state`
- `data-role`

JS, mümkün olduğunca şunları yapmamalıdır:

- piksel bazlı width atamak
- margin/padding enjekte etmek
- `display: none` yazmak
- inline renk ve border basmak

## Responsive Stratejisi

- mobile-first yaklaşım kullanılacak
- breakpoint kararları token seviyesinde sabitlenecek
- modül dosyaları kendi başına breakpoint üretmeyecek
- gerçekten modüle özelse, yalnızca modül kökü altında yazılacak

Amaç, bir mobil düzeltmenin masaüstünü kırmasını engellemektir.

## Bileşen Sahipliği Matrisi

Aşağıdaki parçalar ortak bileşen kabul edilir ve tek yerden yönetilir:

- app shell
- hero
- back bar
- modal overlay
- modal container
- modal header
- footer
- form input ailesi
- button ailesi
- dropdown ailesi
- sistem durum kutuları

Bu parçaların ikinci sahibi olmaz.

## Dosya Açma Kuralı

Yeni CSS dosyası açmadan önce şu soru sorulur:

- bu ihtiyaç gerçekten yeni bir modül mü?
- yoksa mevcut component sahibinde çözülmesi gereken bir sorun mu?

Eğer mevcut bileşen içinde çözülebiliyorsa yeni dosya açılmaz.

## Bug Fix Kuralı

Bir UI bug bulunduğunda çözüm sırası şöyledir:

1. Sorun hangi bileşende?
2. O bileşenin sahibi olan dosya hangisi?
3. Sorun specificity mi, yanlış sahiplik mi, yanlış DOM yapısı mı?
4. Çözüm sahibi dosyada yapılabiliyor mu?

Bu dört soru cevaplanmadan dosya sonuna yeni kural eklenmez.

## Kod Review Kapısı

Bir CSS değişikliği merge edilmeden önce şu sorular cevaplanmalıdır:

- Aynı bileşeni ikinci kez mi tanımlıyorum?
- Bu kural owner dosyada mı yaşıyor?
- Aynı sonucu daha düşük specificity ile çözebilir miyim?
- JS bir stil kararı mı veriyor?
- Bu kural başka modülleri etkiler mi?
- Burada `!important` ihtiyacı doğuyorsa asıl sahiplik sorunu nerede?

## Dokümanlar Arası Ayrım

Ana rehber şunu söyler:

- ekran nasıl görünmeli
- kullanıcı ne görmeli
- ortak Medisa dili ne olmalı

Teknik mimari şunu söyler:

- hangi selector nerede yaşar
- hangi dosya neyin sahibidir
- override ne zaman yasaktır
- `!important` hangi istisna dışında kullanılamaz

Bu ayrım korunursa ürün büyürken dosyalar şişmez, ekranlar birbirine savaş açmaz.
