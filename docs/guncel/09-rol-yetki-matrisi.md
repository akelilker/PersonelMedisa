# Medisa Personel ve Puantaj Yonetim Sistemi

## Rol Yetki Matrisi

Surum: `V3` (Ürün Reset — S70A)

## Belgenin Amaci

Bu dokuman, rol bazli gorunurluk ve aksiyon yetkilerini tek yerde sabitler.
Ürün reset sonrasi hedef permission matrisidir.

**Onemli:** S70C-S73 bildirim permission'lari 12.07.2026 itibarıyla çalışan kod gerçekliğine göre aşağıda güncellenmiştir. Genel Yönetici, patron, şirket parametreleri ve bordro permission'ları ise farklı legacy/hedef domain'leri de içerir; bir permission adının tanımlı olması ilgili uçtan uca ürün akışının tamamlandığı anlamına gelmez.

Mevcut kod referansi (gecis donemi):

- `src/lib/authorization/role-permissions.ts`

## Hedef Ürün Rolleri

Aşağıdaki kısa tanımlar hedef ürün sorumluluklarını anlatır. Güncel S70C-S73 bildirim owner'ları, devamındaki permission matrisinde çalışan kod gerçekliğine göre ayrıca gösterilir.

| Rol | Kisa tanim |
|-----|------------|
| `BIRIM_AMIRI` | Gunluk bildirim/kayit; sade arayuz; kendi kapsami |
| `BOLUM_YONETICISI` | Haftalik mutabakat; aylik bolum onayi; amir kayitlarini denetler |
| `GENEL_YONETICI` | Bordro oncesi operasyonel onay; sirket parametreleri; manuel inceleme cozumu |
| `PATRON` | Sembolik gordu/not; bordroyu bloklamaz |
| `MUHASEBE` | Bordro on izleme ve rapor kontrolu; operasyonel onay sahibi degil |

## Permission Anahtarları — Güncel Bildirim Zinciri ve Hedef Domain'ler

### Gunluk bildirim

| Permission | Aciklama |
|------------|----------|
| `gunluk_bildirim.create` | Gunluk operasyonel kayit olusturma |
| `gunluk_bildirim.update_own_open` | Kendi acik kaydini duzenleme |
| `gunluk_bildirim.submit` | Kaydi gonderme (`GONDERILDI`) |
| `gunluk_bildirim.request_correction` | Duzeltme talep etme (`BOLUM_YONETICISI`) |

### Haftalik mutabakat

| Permission | Aciklama |
|------------|----------|
| `haftalik_mutabakat.view` | Haftalik ozet ve mutabakat goruntuleme |
| `haftalik_mutabakat.approve` | A4/imza mutabakat onayi |
| `haftalik_mutabakat.reopen_request` | Kapali hafta icin revizyon talebi acma |

`haftalik_mutabakat.reopen_request` permission adı tanımlıdır; S71 haftalık bildirim mutabakatında çalışan reopen endpoint/UI akışı yoktur.

### Aylık bildirim onayı — S72 güncel

| Permission | Açıklama |
|---|---|
| `aylik_bildirim_onayi.view` | Aylık bildirim özeti ve onayını görüntüleme |
| `aylik_bildirim_onayi.approve` | BIRIM_AMIRI aylık bildirim onayı oluşturma |

### Genel Yönetici bildirim üst onayı — S73 güncel

| Permission | Açıklama |
|---|---|
| `genel_yonetici_bildirim_onayi.view` | Genel Yönetici bildirim üst onay özeti ve detay görüntüleme |
| `genel_yonetici_bildirim_onayi.approve` | Genel Yönetici bildirim üst onayı oluşturma |

Bu permission'lar legacy `genel_yonetici_onayi.*` ile aynı domain değildir ve alias değildir. UI ve API aynı permission kontratını kullanır.

### Rol × S73 permission matrisi

| Permission | BIRIM_AMIRI | BOLUM_YONETICISI | GENEL_YONETICI | MUHASEBE |
|---|---|---|---|---|
| `genel_yonetici_bildirim_onayi.view` | hayır | hayır | evet | hayır |
| `genel_yonetici_bildirim_onayi.approve` | hayır | hayır | evet | hayır |

`BIRIM_AMIRI`, `BOLUM_YONETICISI` ve `MUHASEBE` S73 panelini DOM'a eklemez ve S73 API isteği göndermez.

### Legacy/hedef aylık bölüm ve Genel Yönetici onayı

Bu permission'lar yeni S72 `aylik_bildirim_onayi.*` domain'i değildir. Legacy `aylik-ozet` ve hedef üst onay zincirinde ayrı tutulur.

| Permission | Aciklama |
|------------|----------|
| `aylik_bolum_onayi.view` | Aylik bolum ozeti goruntuleme |
| `aylik_bolum_onayi.approve` | Bolum onayi verme |
| `genel_yonetici_onayi.view` | Ust onay ozeti goruntuleme |
| `genel_yonetici_onayi.approve` | Bordro oncesi operasyonel onay |

### Patron ack

| Permission | Aciklama |
|------------|----------|
| `patron_ack.view` | Patron ozet raporu goruntuleme |
| `patron_ack.mark_seen` | Gordu / not ekleme |

`patron_ack.*` permission kayıtları bulunabilir; tamamlanmış patron acknowledgment domain/API/UI akışı henüz yoktur. Patron acknowledgment S73 kapsamında değildir.

### Sirket parametreleri

| Permission | Aciklama |
|------------|----------|
| `sirket_parametreleri.view` | Parametreleri goruntuleme |
| `sirket_parametreleri.manage` | Parametre tanimlama/guncelleme |

### Bordro

| Permission | Aciklama |
|------------|----------|
| `bordro_on_izleme.view` | Bordro on izleme goruntuleme |
| `bordro_kesinlestirme.approve` | Nihai bordro kesinlestirme |

## Rol Bazli Yetki Dagilimi

### BIRIM_AMIRI

- `gunluk_bildirim.create` — evet (kendi personeli / sube kapsami)
- `gunluk_bildirim.update_own_open` — evet (yalniz kendi `TASLAK` / `DUZELTME_ISTENDI` kayitlari)
- `gunluk_bildirim.submit` — evet
- `gunluk_bildirim.request_correction` — hayir
- `haftalik_mutabakat.view` — sinirli (kendi birimi ozeti)
- `haftalik_mutabakat.approve` — evet (kendi haftası / şube kapsamı)
- `aylik_bildirim_onayi.view` — evet (kendi ayı / şube kapsamı)
- `aylik_bildirim_onayi.approve` — evet
- `aylik_bolum_onayi.*` — hayir
- `genel_yonetici_onayi.*` — hayir
- `genel_yonetici_bildirim_onayi.*` — hayir
- `patron_ack.*` — hayir
- `sirket_parametreleri.*` — hayir
- `bordro_on_izleme.view` — hayir
- `bordro_kesinlestirme.approve` — hayir

Ek (mevcut kod — gecis):

- `bildirimler.view/create/update/cancel` — evet (gunluk kayit merkezi)
- `personeller.view.sube`, `surecler.view.sube` — evet
- `puantaj.view`, `puantaj.amir_kontrol` — evet
- `puantaj.update`, `finans.*` — hayir

### BOLUM_YONETICISI

- `gunluk_bildirim.create` — hayir (denetler, girmez)
- `gunluk_bildirim.request_correction` — evet (kendi bolumu)
- `haftalik_mutabakat.view` — evet (kendi bolumu)
- `haftalik_mutabakat.approve` — hayir; güncel S71 approve sahibi `BIRIM_AMIRI`
- `haftalik_mutabakat.reopen_request` — permission tanımlı, çalışan S71 reopen akışı yok
- `aylik_bildirim_onayi.view` — evet (read-only)
- `aylik_bildirim_onayi.approve` — hayir
- `genel_yonetici_bildirim_onayi.*` — hayir
- `aylik_bolum_onayi.view` — evet
- `aylik_bolum_onayi.approve` — evet (kendi bolumu)
- `genel_yonetici_onayi.*` — hayir
- `genel_yonetici_bildirim_onayi.*` — hayir
- `patron_ack.*` — hayir
- `sirket_parametreleri.view` — evet
- `sirket_parametreleri.manage` — hayir
- `bordro_on_izleme.view` — sinirli (kendi bolumu ozeti)
- `bordro_kesinlestirme.approve` — hayir

### GENEL_YONETICI

- Tum kapsamda `haftalik_mutabakat.view`
- `haftalik_mutabakat.approve` — hayir
- `aylik_bildirim_onayi.view` — evet (read-only)
- `aylik_bildirim_onayi.approve` — hayir
- `genel_yonetici_bildirim_onayi.view` — evet
- `genel_yonetici_bildirim_onayi.approve` — evet
- `genel_yonetici_onayi.view` — evet
- `genel_yonetici_onayi.approve` — evet
- `sirket_parametreleri.view` — evet
- `sirket_parametreleri.manage` — evet
- `bordro_on_izleme.view` — evet
- `bordro_kesinlestirme.approve` — evet
- Manuel inceleme cozumu ve bordro disi birakma — evet
- `patron_ack.view` — evet (patron adina yonetim gorunumu)

### PATRON

- `patron_ack.view` — evet
- `patron_ack.mark_seen` — evet
- Ozet rapor / bordro on izleme salt okunur — evet
- Operasyonel duzeltme, onay, parametre yonetimi — hayir
- Bordro bloklama — hayir (teknik olarak mumkun degil)

### MUHASEBE

- `haftalik_mutabakat.view` — evet (read-only)
- `haftalik_mutabakat.approve` — hayir
- `aylik_bildirim_onayi.view` — evet (read-only)
- `aylik_bildirim_onayi.approve` — hayir
- `genel_yonetici_bildirim_onayi.*` — hayir
- `bordro_on_izleme.view` — evet
- Finans / bordro rapor `view` — evet
- Operasyonel onay (`haftalik_mutabakat.approve`, `aylik_bolum_onayi.approve`, `genel_yonetici_onayi.approve`) — hayir
- `sirket_parametreleri.manage` — hayir
- `bordro_kesinlestirme.approve` — hayir

## Mevcut Modul Yetki Matrisi (Gecis Donemi)

Asagidaki anahtarlar kodda halen kullanilmaktadir. S70B kod fazinda yeni anahtarlara migrate edilecektir.

### Personeller

- `personeller.view`: yonetim rolleri
- `personeller.view.sube`: tum roller (BIRIM_AMIRI dahil)
- `personeller.detail.view`: tum roller
- `personeller.create`: yonetim rolleri
- `personeller.update`: yonetim rolleri

### Surecler

- `surecler.view`: yonetim rolleri
- `surecler.view.sube`: tum roller
- `surecler.create/update/cancel`: yonetim rolleri

### Bildirimler (gecis — gunluk_bildirim.* ile birlestirilecek)

- `bildirimler.view`: tum roller
- `bildirimler.create/update/cancel`: yonetim rolleri + `BIRIM_AMIRI`

### Gunluk Puantaj

- `puantaj.view`: tum roller
- `puantaj.update`: yonetim rolleri
- `puantaj.amir_kontrol`: `BIRIM_AMIRI`
- `puantaj.bildirim_etki.view`: `GENEL_YONETICI`, `BOLUM_YONETICISI`, `MUHASEBE` (S74-B liste/detay read-only; S74-C2B panel)
- `puantaj.bildirim_etki.generate`: `MUHASEBE` (S74-B)
- `puantaj.bildirim_etki.apply`, `puantaj.bildirim_etki.dismiss`: yalniz `MUHASEBE` (`dismiss` S74-C2A; `apply` S74-C3-B2 `/uygula`)

### Legacy aylık kapanış özeti

`aylik-ozet.*`, `aylik_bolum_onayi.*`, `genel_yonetici_onayi.*` ve `genel_yonetici_bildirim_onayi.*` permission'ları yeni S72 `aylik_bildirim_onayi.*` ile aynı domain değildir ve otomatik bağlı değildir.

- `aylik-ozet.view`: `GENEL_YONETICI`, `BOLUM_YONETICISI`
- `aylik-ozet.review`: `BOLUM_YONETICISI` — bolum onayi
- `aylik-ozet.executive_ack`: `GENEL_YONETICI` — **revize:** artik akisi kilitlemez degil; bolum onayi onkosulu zorunlu (`05-state-flow-api-kontrati.md`)

### Haftalik Kapanis Revizyon Talepleri

`revizyon.*` permission anahtarlari 52 numarali karar dokumanina gore tanimlidir. Kod fazinda `haftalik_mutabakat.reopen_request` ile hizalanacaktir.

### Raporlar

- `raporlar.view`: tum roller (rol bazli yogunluk farki UI'da)

### Finans

- `finans.view/create/update/cancel`: yonetim rolleri (`BIRIM_AMIRI` ve `PATRON` haric)

## Rota Korumaları — Güncel ve Hedef Ayrımı

Güncel S71/S72/S73 bildirim panelleri `/bildirimler` sayfasına gömülüdür. Panel sırası: haftalık mutabakat, aylık bildirim onayı, Genel Yönetici bildirim üst onayı (yalnız `genel_yonetici_bildirim_onayi.view` sahibinde). Aşağıdaki `/haftalik-mutabakat`, `/aylik-kapanis-ozeti`, patron ve bordro rotaları tarihsel/hedef ürün yüzeyleridir; çalışan S71/S72/S73 endpoint adları değildir.

| Rota | Permission |
|------|------------|
| `/bildirimler` | `gunluk_bildirim.create` veya `bildirimler.view` (gecis) |
| `/haftalik-mutabakat` | `haftalik_mutabakat.view` |
| `/aylik-kapanis-ozeti` | `aylik_bolum_onayi.view` veya `genel_yonetici_onayi.view` |
| `/patron-ozet` | `patron_ack.view` |
| `/bordro-on-izleme` | `bordro_on_izleme.view` |
| `/yonetim-paneli/sirket-parametreleri` | `sirket_parametreleri.manage` |

Tarihsel hedef notu: `/haftalik-kapanis` rotası `/` yönlendirmesi yapabilir. Güncel S71 paneli `/bildirimler` içinde ve `/haftalik-bildirim-mutabakatlari` API ailesiyle çalışır.

## UI Davranis Kurallari

- Yetkisiz aksiyon butonlari kullaniciya gosterilmez.
- Yetkisiz route denemelerinde kullanici `yetkisiz` ekranina yonlendirilir.
- `BIRIM_AMIRI` sade yüzünde günlük bildirim write, haftalık bildirim mutabakatı approve ve aylık bildirim onayı approve sahibidir.
- `BOLUM_YONETICISI` günlük kayıt girmez; uygun kayıtta düzeltme isteyebilir ve haftalık/aylık bildirim panellerini salt okunur görür; S73 panelini görmez.
- `GENEL_YONETICI` S73 bildirim üst onay panelini görür ve onaylar; legacy `aylik-ozet` hattındaki bölüm onayı guard'ı S72/S73 domain'ine otomatik bağlı değildir.
- Legacy `aylik-ozet` hattında `GENEL_YONETICI` bölüm onayı tamamlanmadan üst onay veremez; bu guard yeni S72 aylık bildirim onayı ve S73 üst onayına otomatik bağlı değildir.
- `PATRON` yalnizca gordu/not birakir; bordro butonlari gorunmez.
- `MUHASEBE` bordro on izleme ve rapor gorur; operasyonel onay butonlari gorunmez.
- Yetkisiz API denemeleri backend permission kontrolunden gecmelidir.

## Notlar

- Bu belge S70A ile urun reset kararlarina gore revize edilmistir.
- Kod implementasyonu S70B ve sonraki sprintlerde yapilacaktir.
- Eski `aylik-ozet.executive_ack` “akisi kilitlemez” ifadesi kaldirilmistir; yerine zorunlu onay zinciri gecmistir.
- Patron rolu yeni eklenmistir; kod ve auth katmaninda S70B'de tanimlanacaktir.
