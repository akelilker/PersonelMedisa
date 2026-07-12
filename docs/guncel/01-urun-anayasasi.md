# Medisa Personel ve Puantaj Yönetim Sistemi

## Ürün Anayasası

Sürüm: `V2` (Ürün Reset — S70A)

## Belgenin Amacı

Bu belge ürünün ana yönlendirme ve vizyon belgesidir.
Tek bakışlık giriş noktası `00-sistem-genel-bakis.md`, bu dosya ise onun hemen altındaki ürün merkezidir.
Uzun implementasyon detayına girmez; ürünün neden var olduğunu, neyi çözdüğünü, ana hedefini ve hangi alt dokümana ne için bakılacağını netleştirir.

Bu dosya şunları cevaplar:

- Bu ürün neden var?
- Ürünün nihai hedefi nedir?
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
9. Rol ve yetki için `09-rol-yetki-matrisi.md` açılır.
10. Frontend teknik kırmızı çizgiler için `08-frontend-teknik-mimari.md` açılır.

## Doküman Haritası

- `00-sistem-genel-bakis.md`
Tek bakışta sistem resmi, veri akışı ve doğru okuma sırası burada yaşar.
- `01-urun-anayasasi.md`
Ürünün kısa merkez belgesi. Vizyon, kapsam ve ana kararlar burada yaşar.
- `02-mvp-veri-kapsami.md`
Hangi alanın zorunlu, opsiyonel, sonraki faz veya sistem hesaplı olduğu burada kilitlenir.
- `03-ui-bilesen-sozlesmesi.md`
Ürünün görünen dili, rol bazlı yüz farkları ve component davranış kontratı burada yaşar.
- `04-hesap-motoru-kurallari.md`
Puantaj, izin, fazla çalışma, hafta tatili, bordro hesap kuralları burada yaşar.
- `05-state-flow-api-kontrati.md`
Ekranların hangi endpoint'leri çağırdığı ve kayıtların hangi state'lerden geçtiği burada tanımlanır.
- `06-proje-scaffold.md`
Repo ve kod sahipliği yapısı burada tanımlanır.
- `07-is-akislari-ve-senaryolar.md`
Gerçek kullanım akışları burada tek tek anlatılır.
- `09-rol-yetki-matrisi.md`
Rol bazlı görünürlük ve aksiyon yetkileri burada yaşar.
- `08-frontend-teknik-mimari.md`
`!important`, inline style, override savaşı ve component sahipliği gibi teknik disiplin burada yaşar.

## 1. Çıkış Noktası

Medisa'da personel, izin, devamsızlık, puantaj, onay ve bordro operasyonlarının Excel tabanlı ilerlemesi şu problemleri üretiyor:

- aynı verinin birden fazla yerde tutulması
- formül kaymaları ve manuel hesap hataları
- kişiye bağlı operasyon riski
- rapor ve kapanış süreçlerinde kırılganlık
- mevzuat ve denetim açısından operasyonel açık
- onay zinciri ve bordro üretiminin izlenememesi

Bu ürünün çıkış noktası, bu dağınık operasyonu tek merkezli, izlenebilir, onaylı ve mevzuata uyumlu bir sisteme taşımaktır.

## 2. Ürün Vizyonu

Bu sistem yalnızca bir personel listesi değildir.

**Ana ürün tanımı:**

Medisa Personel, personel kayıt sistemi değil; mevzuata uyumlu personel, puantaj, onay ve bordro yönetim sistemidir.

Hedef, aşağıdaki katmanları tek omurgada birleştiren işletme sistemidir:

- personel ana kartı
- süreç / event kaydı
- günlük amir bildirimi (`BIRIM_AMIRI`)
- haftalık A4/imza mutabakatı + teknik kapanış
- aylık onay zinciri (`BOLUM_YONETICISI` → `GENEL_YONETICI`)
- patron sembolik gördü/onayı (`PATRON`)
- mevzuata uyumlu hesap motoru
- bordro ön izleme
- nihai bordro / rapor altyapısı

Bordro, ürünün “ileride belki” eklenecek modülü değil; ürünün nihai ana hedefidir. Kodlama bu hedefe fazlar halinde ilerler; her faz onay zinciri ve hesap motoru disiplinine uygun açılır.

## 3. Değişmeyecek Ana Kararlar

- Personel kartı ana kayıt katmanıdır.
- Personelle ilgili hareketler süreç/event mantığında ayrı kaydedilir.
- `İşten Ayrılma` kart alanı değil, süreç kaydıdır.
- `Hizmet Süresi`, `Kalan İzin` gibi alanlar manuel giriş alanı değildir.
- Tek login vardır; rol bazlı görünürlük ve sadeleştirme uygulanır.
- Görsel dil `tasitmedisa` referansından beslenir.
- Eski projedeki teknik borç yeni projeye taşınmaz.
- Günlük amir bildirimi operasyonel veri katmanıdır; sistem notification panelinden ayrıdır.
- Haftalık mutabakat, teknik haftalık kapanıştan önce gelir.
- Onay zinciri sırası zorunludur; atlama backend tarafından reddedilir.
- Patron onayı semboliktir; bordroyu bloklamaz.
- Hesap motoru nihai hedefte backend tek yetkilidir.
- Şirket parametresi eksikse bordro kesinleşmez.

## 4. Ürün Hedefi ve Kodlama Fazları

Ürünün nihai hedef modülleri:

- personel giriş ve listeleme
- personel kartı
- süreç takibi
- günlük amir bildirimi
- haftalık mutabakat + teknik kapanış
- aylık onay zinciri
- hesap motoru (backend)
- bordro ön izleme
- nihai bordro ve raporlar
- şirket parametreleri yönetimi

Kodlama fazları (özet):

| Faz | Odak |
|-----|------|
| S70A | Doküman hizalama (bu sprint) |
| S70B–S70H | Rol, günlük bildirim, haftalık mutabakat, aylık onay, parametreler, hesap motoru, test |

Her fazın çıkış kriteri `05-state-flow-api-kontrati.md` ve `09-rol-yetki-matrisi.md` ile uyumlu olmalıdır.

### 4.1 Tarihsel Not (V1 — arşiv)

Önceki V1 kararında “tam bordro motoru kurmak hedef değil; doğru veri, doğru state ve doğru UI omurgasını ayağa kaldırmak” ifadesi kullanılmıştı. Ürün reset (RAPOR-01/02/03/04) ile bu karar **aktif ana karar olmaktan çıkarılmıştır**. Omurga kodu korunur; ürün ekseni bordro yönetim sistemine çekilir.

## Uygulanmış Faz Durumu — 11.07.2026

- S70C günlük bildirim fazı tamamlandı.
- S71 haftalık bildirim mutabakatı fazı tamamlandı.
- S72 aylık bildirim onayı fazı tamamlandı.
- S73 Genel Yönetici bildirim üst onayı fazı tamamlandı.
- Günlük, haftalık ve aylık bildirim write/approve sahibi `BIRIM_AMIRI` rolüdür.
- `GENEL_YONETICI`, S73 kapsamında bildirim üst onayını görür ve onaylar (`genel_yonetici_bildirim_onayi.*`).
- `BOLUM_YONETICISI`, `GENEL_YONETICI` (S72 panelleri) ve `MUHASEBE` haftalık ve aylık bildirim panellerini salt okunur görür; S72 approve sahibi değildir.
- Patron acknowledgment katmanının tamamlanmış domain/API/UI akışı henüz yoktur (`patron_ack.*` permission kayıtları ayrı kalır).
- Bildirim zincirinin puantaj hesap motoru ve bordro girdisiyle gerçek backend köprüsü henüz yoktur.

Bu bölüm çalışan mevcut zinciri kaydeder. Bölüm onayı, legacy Genel Yönetici onayı, patron görünürlüğü, hesap motoru ve nihai bordrodan oluşan daha geniş akış ise **hedef ürün zinciridir**; S70C-S73'nin tamamlanması ürünün tamamlandığı anlamına gelmez.

## 5. Roller ve Yüz Ayrımı

Hedef ürün zincirindeki temel roller:

| Rol | Kısa sorumluluk |
|-----|-----------------|
| `BIRIM_AMIRI` | Günlük bildirim/kayıt; sade arayüz; kendi kapsamı |
| `BOLUM_YONETICISI` | Haftalık mutabakat; aylık bölüm onayı; amir kayıtlarını denetler |
| `GENEL_YONETICI` | Bordro öncesi operasyonel onay; şirket parametreleri; manuel inceleme çözümü |
| `PATRON` | Sembolik gördü/not; bordroyu bloklamaz |
| `MUHASEBE` | Bordro ön izleme ve rapor kontrolü; operasyonel onay sahibi değil |

Bu tablo hedef ürün sorumluluklarını gösterir. S70C-S72 kapsamında uygulanmış haftalık ve aylık bildirim approve sahibi yukarıdaki faz durumu bölümünde belirtildiği üzere `BIRIM_AMIRI` rolüdür.

Ana ilke:

- ürün dili ortaktır
- yetki ve görünürlük role göre değişir
- `BIRIM_AMIRI` için daha sade yüz kurgulanır
- `PATRON` operasyonel mutasyon yetkisi taşımaz

Detay: `09-rol-yetki-matrisi.md`

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
- frontend'te nihai maaş/puantaj hesabı

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
