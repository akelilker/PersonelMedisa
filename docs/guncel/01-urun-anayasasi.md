# Medisa Personel ve Puantaj Yönetim Sistemi

## Ürün Anayasası

Sürüm: `V1`

## Belgenin Amacı

Bu belge ürünün ana yönlendirme ve vizyon belgesidir.
Tek bakışlık giriş noktası `00-sistem-genel-bakis.md`, bu dosya ise onun hemen altındaki ürün merkezidir.
Uzun implementasyon detayına girmez; ürünün neden var olduğunu, neyi çözdüğünü, V1'de neyin hedeflendiğini ve hangi alt dokümana ne için bakılacağını netleştirir.

Bu dosya şunları cevaplar:

- Bu ürün neden var?
- V1'in çekirdeği nedir?
- Sistemin değişmeyecek ana kararları nelerdir?
- Hangi detay için hangi belge okunmalıdır?

## Bu Belge Nasıl Kullanılır

Okuma sırası:

1. Önce `00-sistem-genel-bakis.md` okunur.
2. Sonra bu belge okunur.
3. Veri kapsamı için `02-mvp-veri-kapsami.md` açılır.
4. Görünen ürün dili ve component contract için `03-ui-bilesen-sozlesmesi.md` açılır.
5. Hesap ve mevzuat kuralları için `04-hesap-motoru-kurallari.md` açılır.
6. API ve state yaşam döngüsü için `05-state-flow-api-kontrati.md` açılır.
7. Kod klasör yapısı için `06-proje-scaffold.md` açılır.
8. Kullanıcı yolculukları için `07-is-akislari-ve-senaryolar.md` açılır.
9. Frontend teknik kırmızı çizgiler için `08-frontend-teknik-mimari.md` açılır.

## Doküman Haritası

- `00-sistem-genel-bakis.md`
Tek bakışta sistem resmi, veri akışı ve doğru okuma sırası burada yaşar.
- `01-urun-anayasasi.md`
Ürünün kısa merkez belgesi. Vizyon, kapsam ve ana kararlar burada yaşar.
- `02-mvp-veri-kapsami.md`
Hangi alanın V1'de zorunlu, opsiyonel, sonraki faz veya sistem hesaplı olduğu burada kilitlenir.
- `03-ui-bilesen-sozlesmesi.md`
Ürünün görünen dili, rol bazlı yüz farkları ve component davranış kontratı burada yaşar.
- `04-hesap-motoru-kurallari.md`
Puantaj, izin, fazla çalışma, hafta tatili ve benzeri hesap kuralları burada yaşar.
- `05-state-flow-api-kontrati.md`
Ekranların hangi endpoint'leri çağırdığı ve kayıtların hangi state'lerden geçtiği burada tanımlanır.
- `06-proje-scaffold.md`
Repo ve kod sahipliği yapısı burada tanımlanır.
- `07-is-akislari-ve-senaryolar.md`
Gerçek kullanım akışları burada tek tek anlatılır.
- `08-frontend-teknik-mimari.md`
`!important`, inline style, override savaşı ve component sahipliği gibi teknik disiplin burada yaşar.

## 1. Çıkış Noktası

Medisa'da personel, izin, devamsızlık, puantaj, avans ve benzeri operasyonların Excel tabanlı ilerlemesi şu problemleri üretiyor:

- aynı verinin birden fazla yerde tutulması
- formül kaymaları ve manuel hesap hataları
- kişiye bağlı operasyon riski
- rapor ve kapanış süreçlerinde kırılganlık
- mevzuat ve denetim açısından operasyonel açık

Bu ürünün çıkış noktası, bu dağınık operasyonu tek merkezli ve izlenebilir bir sisteme taşımaktır.

## 2. Ürün Vizyonu

Bu sistem yalnızca bir personel listesi değildir.
Hedef, aşağıdaki katmanları tek omurgada birleştiren işletme sistemidir:

- personel ana kartı
- süreç / event kaydı
- günlük puantaj
- haftalık kapanış
- bildirimler
- raporlar
- ileride finans ve bordroya genişleyebilecek veri altyapısı

## 3. Değişmeyecek Ana Kararlar

- Personel kartı ana kayıt katmanıdır.
- Personelle ilgili hareketler süreç/event mantığında ayrı kaydedilir.
- `İşten Ayrılma` kart alanı değil, süreç kaydıdır.
- `Hizmet Süresi`, `Kalan İzin` gibi alanlar manuel giriş alanı değildir.
- Tek login vardır; rol bazlı görünürlük ve sadeleştirme uygulanır.
- Görsel dil `tasitmedisa` referansından beslenir.
- Eski projedeki teknik borç yeni projeye taşınmaz.

## 4. V1 Kapsamı

V1'in çekirdek modülleri:

- personel giriş ve listeleme
- personel kartı
- süreç takibi
- bildirimler
- günlük puantaj
- haftalık kapanış
- temel raporlar

V1'de amaç tam bordro motoru kurmak değil; doğru veri, doğru state ve doğru UI omurgasını ayağa kaldırmaktır.

## 5. Roller ve Yüz Ayrımı

İlk sürümde temel roller:

- genel yönetici
- bölüm yöneticisi
- muhasebe
- birim amiri rolü (`BIRIM_AMIRI`)

Ana ilke:

- ürün dili ortaktır
- yetki ve görünürlük role göre değişir
- Birim Amiri Rolü için daha sade yüz kurgulanır

## 6. Tasarım ve Teknik Sınır

Bu projede tasarım tarafında alınacak şey:

- Medisa hissi
- koyu tema
- kırmızı vurgu
- hero / footer / uygulama kabuğu karakteri

Bu projede alınmayacak şey:

- `!important` bağımlılığı
- inline style ile mimari delme
- aynı component'i birden fazla yerde yeniden tanımlama
- dosya sonuna yama mantığıyla büyüme

## 7. Bu Belgenin Bilerek Girmediği Şeyler

Bu dosya bilerek şunların detayına girmez:

- alan bazlı veri listesi
- component piksel / davranış kontratı
- hesap motoru algoritmaları
- endpoint detayları
- state geçiş sözleşmesi
- repo klasör sahipliği

Çünkü bu detayların tek sahibi başka belgelerde tanımlıdır.

## Sonuç

Bu belge ürünün kısa merkezidir.
Bir geliştirici ya da paydaş önce burada hizalanır, sonra ihtiyacına göre ilgili uzman belgeye iner.
