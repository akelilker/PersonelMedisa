# Medisa Personel ve Puantaj Yonetim Sistemi

Bu repo, Medisa Personel ve Puantaj Yonetim Sistemi icin React + Vite + TypeScript tabanli calisan baslangic scaffold'idir.

## Durum

- urun ve teknik anayasa belgeleri `docs/` altinda hazir
- sorumluluk bazli klasor iskeleti olusturuldu
- React + Vite + TypeScript arac zinciri calisir durumda
- route katmani ve modul sayfa gecisleri bagli
- login + auth guard + rol bazli gorunurluk aktif
- API katmaninda token enjeksiyonu ve 401/403 davranis yonetimi var
- personeller / surecler / bildirimler sayfalari gercek liste endpoint cagrilariyla calisir
- liste ekranlarinda filtre ve sayfalama (page/limit) akisi bagli
- liste yanitlari icin merkezi normalize katmani eklendi (`items + pagination`)
- personeller modulunde `Yeni Personel` modali ile create (`POST /api/personeller`) akisi bagli
- personel detay ekraninda gercek detay fetch + temel update (`PUT /api/personeller/{id}`) akisi bagli
- surec modulunde create/update (`POST/PUT /api/surecler`) modal akislar bagli
- bildirim modulunde create/update (`POST/PUT /api/bildirimler`) modal akislar bagli
- surec ve bildirim listelerinde `iptal` aksiyonlari (`POST /.../{id}/iptal`) bagli
- surec ve bildirim detay sayfalari route seviyesinde aktif (`/surecler/:surecId`, `/bildirimler/:bildirimId`)
- gunluk puantaj modulu aktif (`GET/PUT /api/gunluk-puantaj/{personelId}/{tarih}`)
- haftalik kapanis modulu aktif (`POST /api/haftalik-kapanis`)
- raporlar modulu aktif (`GET /api/raporlar/*`)
- finans modulu aktif (`GET/POST/PUT /api/ek-odeme-kesinti`, `POST /api/ek-odeme-kesinti/{id}/iptal`)
- rol bazli permission matrisi aktif; birim amiri create/update/cancel aksiyonlarinda read-only
- referans veri endpointleri form dropdownlarina baglandi (departman/gorev/personel tipi/surec turu/bildirim turu)
- ortak loading / error / empty state bilesenleri bagli
- Vitest test altyapisi aktif; query util + normalize + api client + permission + auth integration + puantaj/kapanis + rapor/finans API testleri yazildi
- Playwright e2e smoke hatti aktif; login -> personeller -> detay -> puantaj -> haftalik kapanis -> raporlar ve CRUD + iptal senaryolari yazildi
- GitHub Actions CI hatti aktif (`unit + typecheck + build` ve `e2e` paralel)

## Dokumanlar

- `docs/guncel/00-sistem-genel-bakis.md`
- `docs/guncel/01-urun-anayasasi.md`
- `docs/guncel/02-mvp-veri-kapsami.md`
- `docs/guncel/03-ui-bilesen-sozlesmesi.md`
- `docs/guncel/04-hesap-motoru-kurallari.md`
- `docs/guncel/05-state-flow-api-kontrati.md`
- `docs/guncel/06-proje-scaffold.md`
- `docs/guncel/07-is-akislari-ve-senaryolar.md`
- `docs/guncel/08-frontend-teknik-mimari.md`
- `docs/guncel/09-rol-yetki-matrisi.md`

## Kurulum

On kosul: `Node.js 20+`

```bash
npm install
```

## Calistirma

```bash
npm run dev
```

## Build

```bash
npm run build
```

## Type Check

```bash
npm run typecheck
```

## Test

```bash
npm run test
```

## E2E Smoke

```bash
npm run e2e
```

## Sonraki Adimlar

1. raporlar ekraninda her rapor tipi icin ozel kolon map ve sayfalama stratejisi eklemek
2. haftalik kapanis sonucu ile raporlar endpointlerini ayni snapshot ID uzerinden iliskilendirmek
3. finans modulunde kalem turu ve donem secimlerini referans endpointleri ile zenginlestirmek