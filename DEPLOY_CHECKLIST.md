# Deploy Checklist (cPanel)

Canlı klasör: `public_html/personelmedisa`  
Repo klasörü: `public_html` dışında ayrı bir dizin

## GitHub Actions ile otomatik deploy

Ana deploy yolu GitHub Actions üzerindendir. cPanel üzerinde `npm build` çalıştırılmaz; GitHub Actions build çıktısı olan `dist/` içeriği yayınlanır ve PHP API runtime dosyaları ayrıca senkronlanır.

1. GitHub repo → `Settings` → `Secrets and variables` → `Actions`:
   - `FTP_SERVER` (ör. `ftp.domain.com`)
   - `FTP_USERNAME`
   - `FTP_PASSWORD`
   - `FTP_PORT` (genelde `21`; SFTP için `22`)
2. Workflow dosyası: `.github/workflows/deploy-cpanel.yml`
3. `main` branch'e push sonrası deploy doğrudan başlamaz. Önce `CI` workflow'u (`unit + typecheck + build + E2E`) başarıyla tamamlanır; ardından `Deploy cPanel` workflow'u `workflow_run` ile tetiklenir.
4. CI veya E2E fail olursa deploy çalışmaz.
5. İstersen `Actions` → `Deploy cPanel` → `Run workflow` ile manuel deploy yapabilirsin (`workflow_dispatch`).

## Üretim environment kontrolü

Build öncesi üretim değerleri net doğrulanmalıdır:

```env
VITE_APP_BASE_PATH=/personelmedisa/
VITE_API_MODE=real
VITE_DEMO_API_FALLBACK=false
VITE_APP_ENV=production
VITE_ENABLE_DIAGNOSTICS=false
```

Kontrol notları:

- `VITE_APP_BASE_PATH` canlı alt klasörle birebir uyumlu olmalı ve `/` ile bitmeli.
- Üretimde demo/mock fallback açık bırakılmamalı; API hatalarını maskeleyebilir.
- Diagnostics canlıda varsayılan olarak kapalı kalmalı.
- Gerçek secret/token `.env*` dosyalarına commit edilmemeli; GitHub Actions secret veya sunucu ortam değişkeni kullanılmalı.

## Otomatik deploy sırası

1. `main` push → `CI` workflow çalışır.
2. `CI` success → `Deploy cPanel` workflow başlar.
3. Deploy, CI'da test edilen commit SHA ile checkout yapar (`github.event.workflow_run.head_sha`).
4. Deploy içinde: `npm ci` → `npm run typecheck` → `npm run test` → `npm run build` → `dist/` içeriğini `public_html/personelmedisa/` hedefine yükler.
5. Aynı workflow ayrıca `api/.htaccess`, `api/public/` ve `api/src/` dosyalarını `public_html/personelmedisa/api/` altına gönderir.
6. `api/config.local.php`, `api/migrations/` ve `api/seeds/` deploy kapsamı dışındadır; migration otomatik çalıştırılmaz.

Manuel deploy (`workflow_dispatch`) korunur; checkout seçilen branch/ref üzerinden yapılır.

## cPanel Git Deployment (yedek / manuel)

- Ana deploy GitHub Actions üzerinden yapılır.
- cPanel Git Version Control / `.cpanel.yml` ana deploy yolu değildir.
- Yedek veya acil durumda cPanel Git Deployment kullanılacaksa wildcard ile tüm repo deploy edilmemelidir; yalnızca `dist/` içeriği hedef klasöre kopyalanmalıdır.

## 1) Build

Repo klasöründe:

```bash
npm ci
npm run typecheck
npm run test
npm run build
```

Kontrol:

- `dist/index.html` var
- `dist/assets/` var
- `dist/.htaccess` var
- Build çıktısı içinde `src`, `tests`, `.git`, `node_modules` yok
- PHP API deploy kapsamı yalnızca `api/.htaccess`, `api/public/`, `api/src/`
- `api/config.local.php`, `api/migrations/`, `api/seeds/` otomatik deploy edilmez

## 2) Sunucuyu temizle

`public_html/personelmedisa` içindeki eski frontend dosyalarını sil:

- eski `assets/`
- eski `index.html`
- test/kaynak dosyaları
- deploy zip dosyaları

> Not: Canlı frontend kökünde `.git`, `tests`, `node_modules` olmamalı. `public_html/personelmedisa/api/src/` PHP runtime klasörüdür ve korunmalıdır.

## 3) Dist içeriğini kopyala

`dist` klasörünün kendisi değil, sadece içeriği kopyalanır:

- `index.html`
- `assets/`
- `.htaccess`
- diğer statik dosyalar (`favicon.svg` vb.)

Hedef: `public_html/personelmedisa/`

## 3.1) PHP API dosyalarını kopyala

GitHub Actions deploy workflow'u şu dosyaları ayrıca gönderir:

- `api/.htaccess` → `public_html/personelmedisa/api/.htaccess`
- `api/public/` → `public_html/personelmedisa/api/public/`
- `api/src/` → `public_html/personelmedisa/api/src/`

Şunlar workflow tarafından gönderilmez ve otomatik çalıştırılmaz:

- `api/config.local.php`
- `api/migrations/`
- `api/seeds/`

Canlı `config.local.php` sunucuda kalmalı; gerçek DB secret bilgileri repodan gelmemelidir.

## 4) Son kontrol

- Siteyi `/personelmedisa/` ile aç
- Hard refresh yap: `Ctrl + F5`
- DevTools Network'te `index-*.js` ve `index-*.css` 200 dönüyor mu kontrol et
- Login, ana menü, Kayıt/Süreç, Personel Kartı ve Raporlar ekranlarında kısa smoke yap
- Raporlar/Aylık Kapanış export akışı varsa canlıda indirme davranışını doğrula

## Sorun olursa hızlı kontrol

- Beyaz ekran + JS 404: `VITE_APP_BASE_PATH` veya kopyalama hedefi yanlış olabilir
- Route 404: `dist/.htaccess` eksik veya yanlış yerde olabilir
- Demo veri görünüyorsa: `VITE_API_MODE` / `VITE_DEMO_API_FALLBACK` değerlerini kontrol et
- Eski ekran: Tarayıcı/PWA cache temizlenmemiş olabilir
- Deploy hatası: GitHub `Actions` logunda `FTP_SERVER/USERNAME/PASSWORD/PORT` secret adlarını kontrol et
