# Dinamik kullanıcı yetkisi — P4 (yeni Genel Yönetici başlangıcı)

Durum: PR (MERGE=NO). Migration yok. Migration 100 değiştirilmedi ve canlıya uygulanmadı.

## Karar
- Mevcut Genel Yöneticiler aynen kalır. Geriye dönük DENY yazılmaz; rol matrisi değişmez.
- Sonradan Genel Yönetici atanan hesap, operasyonel işlemlerde yalnız görür. Personel düzenleme, bordro, izin düzeltme ve diğer operasyonel yazmalar otomatik açılmaz.
- Kullanıcı ve yetki yönetimi kalır (`yonetim-paneli`, `kullanici_yetkileri`, kalıcı sil). Bu haklar DENY ile kapatılamaz (P2).
- Başka bir Genel Yönetici, ihtiyaç duyulan operasyonel yazmayı ilgili DENY kaydını iptal ederek açar. İzin rol varsayılanında durduğu için ALLOW gerekmez ve reddedilir.
- Kimse kendi operasyonel yetkisini genişletemez. Atayan, uygun başka Genel Yönetici varken de genişletemez (K3).
- Başka aktif Genel Yönetici yokken yapılan atama tam yetkilidir. Pasif Genel Yönetici bu hesabı kısıtlamaz.
- Genel Yönetici şirket kapsamı alamaz (`user_sirketler` bu rol için kapalı, rol tüm sisteme açıktır). Bu yüzden "şu şirketin ilk yöneticisi" mevcut bağlarla ayrılmaz. Şirket adına özel koşul yoktur.
- Rol değişince eski istisnalar iptal edilir (P3). Yeniden Genel Yönetici ataması, başka yönetici varsa yine kısıtlı başlar.

## Nerede
`KullaniciYetkiYazmaService::rolDegisti`. Şema yoksa (migration 100 uygulanmamış) eskisi gibi 0 döner; kısıt yazılmaz, mevcut işlemler rol varsayılanıyla sürer.

Kısıt, atama şema hazırken yapılır. Migration 100 canlıda yokken yeni atama bugünkü gibi tam yetkiyle oluşur ve sonradan kendiliğinden kısıtlanmaz. Kısıt, 100 uygulandıktan sonraki atamalarda başlar.

Personel düzenleme, rol adına bakıp yazmayı açmaz. Etkin izin esastır. Bölüm yöneticisi ve muhasebe için eski yazma köprüsü durur.

## Bilinçli sınır
- Şube kapsamlı istisna kapalı kalır.
- Yetki ekranı ve yönetici gelen kutusu P5.

## Açık karar — hassas izin
Kırmızı listedeki izinler (SGK nihai onayı, bordro kesinleştirme, genel yönetici onayı, legal hold, imha) kişiye özel ALLOW ile verilemez (P2). P4 bu izinlere başlangıç DENY yazar. Başka Genel Yönetici o DENY kaydını iptal edince izin, rol varsayılanından geri gelir. Bu, ALLOW yasağını dolanır. Normal operasyonel yazma (personel ücreti vb.) iptalle açılmaya devam eder. Hassas izinlerin hiç açılamaması ayrı iş kuralıdır; uygulanmadı.
