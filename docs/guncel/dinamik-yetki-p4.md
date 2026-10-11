# Dinamik kullanıcı yetkisi — P4 (yeni Genel Yönetici başlangıcı)

Durum: PR (MERGE=NO). Migration yok. Migration 100 değiştirilmedi ve canlıya uygulanmadı.

## Karar
- Mevcut Genel Yöneticiler aynen kalır. Geriye dönük DENY yazılmaz; rol matrisi değişmez.
- Sonradan Genel Yönetici atanan hesap, operasyonel işlemlerde yalnız görür. Personel düzenleme, bordro, izin düzeltme ve diğer operasyonel yazmalar otomatik açılmaz.
- Kullanıcı ve yetki yönetimi kalır (`yonetim-paneli`, `kullanici_yetkileri`, kalıcı sil). Bu haklar DENY ile kapatılamaz (P2).
- Başka bir Genel Yönetici, normal operasyonel yazmayı ilgili DENY kaydını iptal ederek açar. İzin rol varsayılanında durduğu için ALLOW gerekmez ve reddedilir.
- Kırmızı listedeki başlangıç DENY kayıtları normal iptal ucundan kalkmaz. Bu hassas yetkilerin devri ayrı karardır; devir yolu yoktur.
- Kimse kendi operasyonel yetkisini genişletemez. Atayan, uygun başka Genel Yönetici varken de genişletemez (K3).
- Başka aktif Genel Yönetici yokken yapılan atama tam yetkilidir. Pasif Genel Yönetici bu hesabı kısıtlamaz.
- İlk yönetici kuralı sistem genelinde çalışır: başka aktif Genel Yönetici yoksa atama tam yetkilidir. Genel Yönetici şirket kapsamı alamaz; rol tüm sisteme açıktır. Şirket bazında ilk Genel Yönetici bu aşamada karşılanmış sayılmaz. Yeni şirket/yönetici mimarisi kurulmadı.
- Rol değişince eski istisnalar iptal edilir (P3). Yeniden Genel Yönetici ataması, başka yönetici varsa yine kısıtlı başlar.

## Nerede
`KullaniciYetkiYazmaService::rolDegisti`. Şema yoksa (migration 100 uygulanmamış) eskisi gibi 0 döner; kısıt yazılmaz, mevcut işlemler rol varsayılanıyla sürer.

Kısıt, atama şema hazırken yapılır. Migration 100 canlıda yokken yeni atama bugünkü gibi tam yetkiyle oluşur ve sonradan kendiliğinden kısıtlanmaz. Kısıt, 100 uygulandıktan sonraki atamalarda başlar.

Personel düzenleme, rol adına bakıp yazmayı açmaz. Etkin izin esastır. Bölüm yöneticisi ve muhasebe için eski yazma köprüsü durur.

## Bilinçli sınır
- Şube kapsamlı istisna kapalı kalır.
- Yetki ekranı ve yönetici gelen kutusu P5.

## Hassas yetki
Kırmızı listedeki izinler (SGK nihai onayı, bordro kesinleştirme, genel yönetici onayı, hukuki bekletme, imha) kişiye özel ALLOW ile verilemez (P2). Kısıtlı başlayan Genel Yöneticide bunlar başlangıç DENY kaydıdır. Normal iptal ucu bu kaydı kaldırmaz; yetki geri gelmez. Tam yetkili yöneticide sonradan konmuş aynı iznin DENY kaydı (başlangıç kaydı değil) iptal edilebilir. Rol değişince tüm istisnalar, başlangıç kayıtları dahil, eskisi gibi kapanır.
