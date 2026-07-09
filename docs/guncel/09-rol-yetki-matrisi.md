# Medisa Personel ve Puantaj Yonetim Sistemi

## Rol Yetki Matrisi

Surum: `V3` (Ürün Reset — S70A)

## Belgenin Amaci

Bu dokuman, rol bazli gorunurluk ve aksiyon yetkilerini tek yerde sabitler.
Ürün reset sonrasi hedef permission matrisidir.

**Onemli:** Bu S70A dokuman revizyonudur. Asagidaki yeni permission anahtarlari henuz `src/lib/authorization/role-permissions.ts` icinde uygulanmamis olabilir. Kod fazina gecilmeden once bu belge karar sozlesmesi olarak okunmalidir.

Mevcut kod referansi (gecis donemi):

- `src/lib/authorization/role-permissions.ts`

## Roller

| Rol | Kisa tanim |
|-----|------------|
| `BIRIM_AMIRI` | Gunluk bildirim/kayit; sade arayuz; kendi kapsami |
| `BOLUM_YONETICISI` | Haftalik mutabakat; aylik bolum onayi; amir kayitlarini denetler |
| `GENEL_YONETICI` | Bordro oncesi operasyonel onay; sirket parametreleri; manuel inceleme cozumu |
| `PATRON` | Sembolik gordu/not; bordroyu bloklamaz |
| `MUHASEBE` | Bordro on izleme ve rapor kontrolu; operasyonel onay sahibi degil |

## Yeni Permission Anahtarlari (S70A Hedef)

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

### Aylik onay

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
- `haftalik_mutabakat.approve` — hayir
- `aylik_bolum_onayi.*` — hayir
- `genel_yonetici_onayi.*` — hayir
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
- `haftalik_mutabakat.approve` — evet (kendi bolumu)
- `haftalik_mutabakat.reopen_request` — evet (revizyon talebi ile)
- `aylik_bolum_onayi.view` — evet
- `aylik_bolum_onayi.approve` — evet (kendi bolumu)
- `genel_yonetici_onayi.*` — hayir
- `patron_ack.*` — hayir
- `sirket_parametreleri.view` — evet
- `sirket_parametreleri.manage` — hayir
- `bordro_on_izleme.view` — sinirli (kendi bolumu ozeti)
- `bordro_kesinlestirme.approve` — hayir

### GENEL_YONETICI

- Tum kapsamda `haftalik_mutabakat.view`
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

### Aylik kapanis ozeti (gecis — aylik_bolum_onayi.* / genel_yonetici_onayi.* ile birlestirilecek)

- `aylik-ozet.view`: `GENEL_YONETICI`, `BOLUM_YONETICISI`
- `aylik-ozet.review`: `BOLUM_YONETICISI` — bolum onayi
- `aylik-ozet.executive_ack`: `GENEL_YONETICI` — **revize:** artik akisi kilitlemez degil; bolum onayi onkosulu zorunlu (`05-state-flow-api-kontrati.md`)

### Haftalik Kapanis Revizyon Talepleri

`revizyon.*` permission anahtarlari 52 numarali karar dokumanina gore tanimlidir. Kod fazinda `haftalik_mutabakat.reopen_request` ile hizalanacaktir.

### Raporlar

- `raporlar.view`: tum roller (rol bazli yogunluk farki UI'da)

### Finans

- `finans.view/create/update/cancel`: yonetim rolleri (`BIRIM_AMIRI` ve `PATRON` haric)

## Rota Korumalari (Hedef)

| Rota | Permission |
|------|------------|
| `/bildirimler` | `gunluk_bildirim.create` veya `bildirimler.view` (gecis) |
| `/haftalik-mutabakat` | `haftalik_mutabakat.view` |
| `/aylik-kapanis-ozeti` | `aylik_bolum_onayi.view` veya `genel_yonetici_onayi.view` |
| `/patron-ozet` | `patron_ack.view` |
| `/bordro-on-izleme` | `bordro_on_izleme.view` |
| `/yonetim-paneli/sirket-parametreleri` | `sirket_parametreleri.manage` |

Gecis donemi: `/haftalik-kapanis` rotasi halen `/` yonlendirmesi yapabilir; S70D'de `/haftalik-mutabakat` ile degistirilecektir.

## UI Davranis Kurallari

- Yetkisiz aksiyon butonlari kullaniciya gosterilmez.
- Yetkisiz route denemelerinde kullanici `yetkisiz` ekranina yonlendirilir.
- `BIRIM_AMIRI` sade yuzunde yalnizca gunluk bildirim ve sinirli goruntuleme vardir.
- `BOLUM_YONETICISI` haftalik mutabakat ve aylik bolum onayi verir; gunluk kayit girmez.
- `GENEL_YONETICI` bolum onayi tamamlanmadan ust onay veremez (UI ve backend).
- `PATRON` yalnizca gordu/not birakir; bordro butonlari gorunmez.
- `MUHASEBE` bordro on izleme ve rapor gorur; operasyonel onay butonlari gorunmez.
- Yetkisiz API denemeleri backend permission kontrolunden gecmelidir.

## Notlar

- Bu belge S70A ile urun reset kararlarina gore revize edilmistir.
- Kod implementasyonu S70B ve sonraki sprintlerde yapilacaktir.
- Eski `aylik-ozet.executive_ack` “akisi kilitlemez” ifadesi kaldirilmistir; yerine zorunlu onay zinciri gecmistir.
- Patron rolu yeni eklenmistir; kod ve auth katmaninda S70B'de tanimlanacaktir.
