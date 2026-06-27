# Medisa Personel ve Puantaj Yönetim Sistemi

React + Vite + TypeScript tabanlı PersonelMedisa uygulaması. Ana kapsam personel kayıtları, süreç yönetimi, personel kartı, puantaj, finans kalemleri ve raporlar/aylık kapanış akışlarıdır.

## Durum

- React + Vite + TypeScript araç zinciri çalışır durumda.
- Login, auth guard ve rol bazlı görünürlük aktif.
- Şube/rol farkındalığı uygulama akışında korunur.
- Kayıt ve Süreç alanı personel oluşturma, süreç kayıtları ve ilgili modalları yönetir.
- Personel Kartı personel detay, güncelleme ve geçmiş/kayıt görüntüleme akışlarının merkezidir.
- Günlük puantaj ve kapanış akışları API seviyesinde bağlıdır.
- Raporlar modülü aktif; rapor tipleri için kolon sözleşmesi, page/limit sayfalama ve export akışları bulunur.
- Finans modülü ek ödeme/kesinti kayıtlarını ve iptal akışlarını yönetir.
- Referans veri endpointleri form dropdownlarına bağlıdır.
- Ortak loading, error ve empty state bileşenleri kullanılır.
- Vitest test altyapısı aktif.
- Playwright E2E smoke hattı aktif.
- GitHub Actions CI hattı `unit + typecheck + build + E2E` doğrulaması yapar.

## Ana modüller

| Alan | Amaç |
|---|---|
| Login/Auth | Oturum, token ve yetki kontrolü |
| Kayıt ve Süreç | Personel kaydı, süreç girişi, belge/bildirim/aksiyon akışları |
| Personel Kartı | Personel detay, güncelleme ve geçmiş görünümü |
| Puantaj | Günlük puantaj ve kapanışa hazırlık |
| Raporlar | Aylık kapanış, rapor listeleri, kolon kontratları ve export |
| Finans | Ek ödeme/kesinti ve iptal akışları |

## Dokümanlar

Güncel ürün ve teknik dokümanlar `docs/guncel/` altında tutulur:

- `00-sistem-genel-bakis.md`
- `01-urun-anayasasi.md`
- `02-mvp-veri-kapsami.md`
- `03-ui-bilesen-sozlesmesi.md`
- `04-hesap-motoru-kurallari.md`
- `05-state-flow-api-kontrati.md`
- `06-proje-scaffold.md`
- `07-is-akislari-ve-senaryolar.md`
- `08-frontend-teknik-mimari.md`
- `09-rol-yetki-matrisi.md`
- `10-yuzey-gorev-sinirlari.md`

## Kurulum

Ön koşul: Node.js 20+

```bash
npm install
```

## Geliştirme

```bash
npm run dev
```

## Build

```bash
npm run build
```

## Type check

```bash
npm run typecheck
```

## Unit test

```bash
npm run test
```

## E2E smoke

```bash
npm run e2e
```

## Production environment

Canlı build için temel değerler:

```env
VITE_APP_BASE_PATH=/personelmedisa/
VITE_API_MODE=real
VITE_DEMO_API_FALLBACK=false
VITE_APP_ENV=production
VITE_ENABLE_DIAGNOSTICS=false
```

Demo/mock fallback üretimde açık bırakılmamalıdır.

## Deploy

Canlı yayın için `DEPLOY_CHECKLIST.md` izlenir. Ana deploy yolu GitHub Actions üzerinden `dist/` içeriğinin cPanel hedef klasörüne aktarılmasıdır.

## Çalışma kuralı

Kod değişikliklerinde mevcut owner yapı korunur. Yeni paralel akış, gereksiz CSS override, toplu format/refactor ve kapsam dışı dosya değişikliği yapılmaz. Kalıcı iş davranışı demo/local fallback ile maskelenmez; üretim ayarları gerçek API davranışına göre doğrulanır.
