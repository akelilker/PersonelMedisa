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

## UI Davranis Kurallari

- Yetkisiz aksiyon butonlari (create/update/cancel/close) kullaniciya gosterilmez.
- Yetkisiz route denemelerinde kullanici `yetkisiz` ekranina yonlendirilir.
- `BIRIM_AMIRI` rolunde personel ve surec listeleri sube kapsami ile aciktir.
- `BIRIM_AMIRI`, bildirim modulu uzerinden gunluk durum bildirimi girebilir, guncelleyebilir ve iptal edebilir.
- `BIRIM_AMIRI` icin puantaj guncelleme ve finans aksiyonlari kapali kalir.

## Notlar

- Bu belge `role-permissions.ts` ile senkron tutulur.
- Yeni rol veya yeni modul eklendiginde once permission matrisi, sonra bu dokuman guncellenmelidir.
