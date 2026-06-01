# Medisa Personel ve Puantaj Yonetim Sistemi

## Rol Yetki Matrisi

Surum: `V2`

## Belgenin Amaci

Bu dokuman, frontend tarafinda uygulanan rol bazli gorunurluk ve aksiyon yetkilerini tek yerde sabitler.
Kod tarafindaki permission matrisi ile birebir uyumludur.

Kaynagi:

- `src/lib/authorization/role-permissions.ts`

## Roller

- `GENEL_YONETICI`
- `BOLUM_YONETICISI`
- `MUHASEBE`
- `BIRIM_AMIRI`

## Modul Yetki Matrisi

### Personeller

- `personeller.view`: yonetim rolleri
- `personeller.view.sube`: tum roller
- `personeller.detail.view`: tum roller
- `personeller.create`: yonetim rolleri
- `personeller.update`: yonetim rolleri

Yonetim rolleri:

- `GENEL_YONETICI`
- `BOLUM_YONETICISI`
- `MUHASEBE`

### Surecler

- `surecler.view`: yonetim rolleri
- `surecler.view.sube`: tum roller
- `surecler.detail.view`: tum roller
- `surecler.create`: yonetim rolleri
- `surecler.update`: yonetim rolleri
- `surecler.cancel`: yonetim rolleri

### Bildirimler

- `bildirimler.view`: tum roller
- `bildirimler.detail.view`: tum roller
- `bildirimler.create`: yonetim rolleri + `BIRIM_AMIRI`
- `bildirimler.update`: yonetim rolleri + `BIRIM_AMIRI`
- `bildirimler.cancel`: yonetim rolleri + `BIRIM_AMIRI`

### Gunluk Puantaj

- `puantaj.view`: tum roller
- `puantaj.update`: yonetim rolleri

### Haftalik Kapanis (UI kaldirildi)

- Onceki `haftalik-kapanis.*` izinleri kaldirildi; haftalik kapanis ekrani yok.
- `/haftalik-kapanis` rotasi oturum icinde ana sayfaya yonlenir.

### Haftalik Kapanis Revizyon Talepleri

> **Karar / sonraki kod fazı:** Asagidaki `revizyon.*` permission anahtarlari 52 numarali revizyon talebi rol/yetki karar dokumanina gore bu belgeye eklenmistir. Bu anahtarlar henuz `src/lib/authorization/role-permissions.ts` icinde uygulanmamistir; kod fazina gecilmeden once bu belge karar sozlesmesi olarak okunmalidir.

Permission anahtarlari:

- `revizyon.view`
- `revizyon.create`
- `revizyon.submit`
- `revizyon.cancel`
- `revizyon.approve`
- `revizyon.reject`
- `revizyon.view_finance_effect`
- `revizyon.view_audit_history`

Yetki dagilimi (52 numarali karar):

- `revizyon.view`
  - `GENEL_YONETICI`: tum revizyonlar
  - `BOLUM_YONETICISI`: kendi bolumu
  - `MUHASEBE`: bordro etkili kayitlar + yetkili rapor kapsami
  - `BIRIM_AMIRI`: kendi personeli / kendi birimi sinirli

- `revizyon.create`
  - `GENEL_YONETICI`: evet
  - `BOLUM_YONETICISI`: kendi bolumu
  - `MUHASEBE`: bordro etkili gerekce ile evet
  - `BIRIM_AMIRI`: sinirli

- `revizyon.submit`
  - Tum roller: yalniz kendi olusturdugu talebi gonderebilir
  - Kapsam kontrolu ayrica uygulanir

- `revizyon.cancel`
  - `GENEL_YONETICI`: tum talepler
  - `BOLUM_YONETICISI`: kendi olusturdugu `TASLAK` / `ONAY_BEKLIYOR` talepler
  - `MUHASEBE`: kendi olusturdugu talepler
  - `BIRIM_AMIRI`: kendi olusturdugu talepler

- `revizyon.approve`
  - Sadece `GENEL_YONETICI`

- `revizyon.reject`
  - Sadece `GENEL_YONETICI`

- `revizyon.view_finance_effect`
  - `GENEL_YONETICI`: evet
  - `BOLUM_YONETICISI`: kendi bolumu
  - `MUHASEBE`: evet
  - `BIRIM_AMIRI`: hayir veya sinirli

- `revizyon.view_audit_history`
  - `GENEL_YONETICI`: evet
  - `BOLUM_YONETICISI`: kendi bolumu
  - `MUHASEBE`: bordro etkili kayitlar
  - `BIRIM_AMIRI`: kendi personeli icin sinirli

### Aylik kapanis ozeti

- `aylik-ozet.view`: `GENEL_YONETICI`, `BOLUM_YONETICISI` (sayfa erisimi)
- `aylik-ozet.review`: `BOLUM_YONETICISI` — bolum onayi (operasyonel tamamlama)
- `aylik-ozet.executive_ack`: `GENEL_YONETICI` — ust kontrol / teyit (istege bagli, akisi kilitlemez)

### Raporlar

- `raporlar.view`: tum roller

### Finans

- `finans.view`: yonetim rolleri
- `finans.create`: yonetim rolleri
- `finans.update`: yonetim rolleri
- `finans.cancel`: yonetim rolleri

## Rota Korumalari

Aktif route guard kurallari:

- `/personeller` -> `personeller.view` veya `personeller.view.sube`
- `/personeller/:personelId` -> `personeller.detail.view`
- `/surecler` -> `surecler.view` veya `surecler.view.sube`
- `/surecler/:surecId` -> `surecler.detail.view`
- `/bildirimler` -> `bildirimler.view`
- `/bildirimler/:bildirimId` -> `bildirimler.detail.view`
- `/puantaj` -> `puantaj.view`
- `/haftalik-kapanis` -> oturumlu kullanicida `/` yonlendirmesi (ayri izin yok)
- `/raporlar` -> `raporlar.view`
- `/aylik-kapanis-ozeti` -> `aylik-ozet.view`
- `/finans` -> `finans.view`

Ileride acilacak revizyon route'lari (karar; kodda henuz yok):

- `/haftalik-kapanis/revizyon-talepleri` -> `revizyon.view`
- `/haftalik-kapanis/revizyon-talepleri/:talepId` -> `revizyon.view`
- `/haftalik-kapanis/revizyon-talepleri/:talepId/onay` -> `revizyon.approve`
- `/haftalik-kapanis/revizyon-talepleri/:talepId/red` -> `revizyon.reject`

## UI Davranis Kurallari

- Yetkisiz aksiyon butonlari (create/update/cancel/close) kullaniciya gosterilmez.
- Yetkisiz route denemelerinde kullanici `yetkisiz` ekranina yonlendirilir.
- `BIRIM_AMIRI` rolunde personel ve surec listeleri sube kapsami ile aciktir.
- `BIRIM_AMIRI`, bildirim modulu uzerinden gunluk durum bildirimi girebilir, guncelleyebilir ve iptal edebilir.
- `BIRIM_AMIRI` icin puantaj guncelleme ve finans aksiyonlari kapali kalir.
- Yetkisiz revizyon aksiyon butonlari kullaniciya gosterilmez.
- Yetkisiz API denemeleri backend/API permission kontrolunden gecmelidir.
- `BIRIM_AMIRI` sade yuzunde revizyon gorunumu sinirli olmalidir.
- `MUHASEBE` bordro etkili revizyonlari gorebilir; onay/red veremez.
- Onay/red aksiyonlari V1'de yalniz `GENEL_YONETICI`'ye gorunur.

## Notlar

- Bu belge `role-permissions.ts` ile senkron tutulur.
- Yeni rol veya yeni modul eklendiginde once permission matrisi, sonra bu dokuman guncellenmelidir.
- Revizyon permission anahtarlari 52 numarali karar dokumanina gore eklenmistir.
- Kod tarafindaki `src/lib/authorization/role-permissions.ts` guncellemesi ayri kod fazinda yapilmalidir.
- Bu dokuman guncellemesi kod implementasyonu degildir.
- Kod fazina gecilmeden once permission matrix ile backend/API enforcement uyumu kontrol edilmelidir.
