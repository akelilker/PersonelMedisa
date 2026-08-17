# Deploy Checklist (cPanel)

Canlı klasör: `public_html/personelmedisa`  
Repo klasörü: `public_html` dışında ayrı bir dizin

`<BASE_URL>`: Canlı site kök adresinin protokol + host kısmı (ör. `https://ornek-host`). Gerçek domain bu dokümana yazılmaz; operasyon sırasında yerel not veya güvenli secret store kullanılır.

## GitHub Actions ile otomatik deploy

Ana deploy yolu GitHub Actions üzerindendir. cPanel üzerinde `npm build` çalıştırılmaz; GitHub Actions build çıktısı olan `dist/` içeriği yayınlanır ve PHP API runtime dosyaları ayrıca senkronlanır.

1. GitHub repo → `Settings` → `Secrets and variables` → `Actions`:
   - `FTP_SERVER` (ör. `ftp.domain.com`)
   - `FTP_USERNAME`
   - `FTP_PASSWORD`
   - `FTP_PORT` (`Deploy cPanel` workflow'u bu proje için `21` bekler; SFTP/`22` kullanılacaksa workflow ayrıca güncellenmelidir)
   - `CPANEL_SSH_HOST`
   - `CPANEL_SSH_PORT`
   - `CPANEL_SSH_USER`
   - `CPANEL_SSH_PRIVATE_KEY`
   - `CPANEL_SSH_KNOWN_HOSTS`
2. Workflow dosyası: `.github/workflows/deploy-cpanel.yml`
3. `main` branch'e push sonrası deploy doğrudan başlamaz. Önce `CI` workflow'u (`typecheck + unit test + build`) başarıyla tamamlanır; ardından `Deploy cPanel` workflow'u `workflow_run` ile tetiklenir.
4. Yalnızca `CI` fail olursa otomatik deploy çalışmaz. **E2E workflow deploy gate değildir**; E2E ayrı `workflow_dispatch` ile manuel çalıştırılır (son doğrulamada 193 test).
5. İstersen `Actions` → `Deploy cPanel` → `Run workflow` ile manuel deploy yapabilirsin (`workflow_dispatch`).

**Deploy success ne garanti eder?** Production build alınması ve FTP/cPanel upload adımının tamamlanması. Canlı URL 200, API DB bağlantısı, login/read akışı ve bundle tutarlılığını tek başına garanti etmez; aşağıdaki smoke checklist uygulanmalıdır.

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

1. `main` push → `CI` workflow çalışır (`typecheck`, `npm run test`, `npm run build`).
2. `CI` success → `Deploy cPanel` workflow başlar.
3. Deploy, CI'da test edilen commit SHA ile checkout yapar (`github.event.workflow_run.head_sha`).
4. Deploy içinde: `npm ci` → `npm run build` (`VITE_APP_BASE_PATH=/personelmedisa/`) → `dist/` içeriğini hedef klasöre yükler. Deploy workflow **tekrar typecheck veya unit test çalıştırmaz**.
5. Aynı workflow ayrıca `api/.htaccess`, `api/public/`, `api/src/`, `api/bin/` ve `api/migrations/` dosyalarını `public_html/personelmedisa/api/` altına gönderir.
6. FTP upload tamamlandıktan sonra workflow, pinned known-hosts ile SSH üzerinden `api/bin/run-production-migrations.sh` çalıştırır. Migration veya schema-ready kontrolü başarısız olursa deploy durur.
7. `api/config.local.php` ve `api/seeds/` deploy kapsamı dışındadır; DB credential yalnız sunucudaki config'ten okunur.
8. Migration runner tamamlandıktan sonra deploy workflow otomatik health/smoke çalıştırır.

Manuel deploy (`workflow_dispatch`) korunur; checkout seçilen branch/ref üzerinden yapılır.

## cPanel Git Deployment (yedek / manuel)

- Ana deploy GitHub Actions üzerinden yapılır.
- cPanel Git Version Control / `.cpanel.yml` ana deploy yolu değildir.
- Yedek veya acil durumda cPanel Git Deployment kullanılacaksa wildcard ile tüm repo deploy edilmemelidir; yalnızca `dist/` içeriği hedef klasöre kopyalanmalıdır.

## 1) Build (manuel veya lokal doğrulama)

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
- PHP API deploy kapsamı `api/.htaccess`, `api/public/`, `api/src/`, `api/bin/`, `api/migrations/`
- `api/config.local.php` ve `api/seeds/` otomatik deploy edilmez

## 2) Sunucuyu temizle (manuel yedek yol)

`public_html/personelmedisa` içindeki eski frontend dosyalarını sil:

- eski `assets/`
- eski `index.html`
- test/kaynak dosyaları
- deploy zip dosyaları

> Not: Canlı frontend kökünde `.git`, `tests`, `node_modules` olmamalı. `public_html/personelmedisa/api/src/` PHP runtime klasörüdür ve korunmalıdır.

## 3) Dist içeriğini kopyala (manuel yedek yol)

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
- `api/bin/` → `public_html/personelmedisa/api/bin/`
- `api/migrations/` → `public_html/personelmedisa/api/migrations/`

Şunlar workflow tarafından gönderilmez:

- `api/config.local.php`
- `api/seeds/`

Canlı `config.local.php` sunucuda kalmalı; gerçek DB secret bilgileri repodan gelmemelidir.
İlk mevcut production kurulumu için SSH ortamında zaten uygulanmış son migration
sürümü `MEDISA_MIGRATION_BASELINE` ile sağlanır; sonrasında runner yalnız pending
dosyaları uygular ve ledger üzerinden idempotent çalışır.

`api/.htaccess` canlı `config.local.php` ve backup/temp türevlerini (`*.bak`, `*.old`, `*.tmp`, `~`,
suffix/path varyantları) web’den engeller. Config yedeği yalnız web root dışında tutulur
(private dizin `0700`, yedek dosya `0600`); web root içinde `config.local.php.*` / `~` oluşturulmaz.

## Deploy Sonrası Canlı Smoke Checklist

Deploy cPanel workflow **success** sonrası aşağıdaki adımları sırayla uygula. `<BASE_URL>` yerine canlı host adresini kullan.

### Otomatik HTTP smoke (local script)

Adımlar 2–5 (API health, auth guard, frontend kök, bundle/asset tutarlılığı) repo kökünde local script ile otomatik çalıştırılabilir. Gerçek domain bu dosyaya veya koda yazılmaz; operasyon sırasında env ile verilir.

```bash
SMOKE_BASE_URL=https://<canlı-host> npm run smoke:live
```

Opsiyonel alt klasör override:

```bash
SMOKE_BASE_URL=https://<canlı-host> SMOKE_APP_PREFIX=/personelmedisa npm run smoke:live
```

- **Script:** `scripts/post-deploy-smoke.mjs`
- **npm script:** `smoke:live`
- **Zorunlu env:** `SMOKE_BASE_URL` (protokol + host; sondaki `/` temizlenir)
- **Opsiyonel env:** `SMOKE_APP_PREFIX` (varsayılan: `/personelmedisa`)
- **Otomatik kapsam:** API health, API auth guard 401, frontend kök HTML, `index.html` asset 200
- **Manuel kalır:** GitHub Actions exact-SHA doğrulama (1), cache bypass (6), normal kullanıcı login/read kabulü (7–8) ve sonuç kaydı (9)
- **Çıkış kodu:** tüm otomatik kontroller OK → `0`; herhangi fail veya eksik env → `1`
- **Credential yoksa:** script yalnız anonim smoke kapsamını çalıştırır.
- **Authenticated read-only:** `SMOKE_AUTH_USERNAME` + `SMOKE_AUTH_PASSWORD` set edilirse script login + `GET /api/auth/smoke-read` yapar; write çağırmaz; personel/domain PII okumaz. Nihai canlı kabulte dedicated `AUTH_SMOKE_READONLY` hesabı zorunludur. Ayrıntı: `docs/guncel/95-s96-release-ops-runbook.md`.

`SMOKE_BASE_URL` verilmeden çalıştırılırsa usage gösterilir ve script `exit 1` döner.

### Release readiness gate

Kod hazırlığı ve dış ops kapılarının durumunu yazmadan raporlar:

```bash
npm run release:gate
```

Ops acknowledgement env’leri (`…=ready`) ve `REQUIRE_OPS_READY=1` için runbook’a bakın.

### 1. GitHub Actions doğrulama

- **Amaç:** Doğru commit deploy edildi mi?
- **Beklenen:** CI success + Deploy cPanel success; deploy run commit SHA, merge edilen `main` commit'i ile uyumlu.
- **Hata olursa:** Actions deploy log, build adımı, FTP mirror adımları.

### 2. API health kontrolü

```bash
curl -i <BASE_URL>/personelmedisa/api/health
```

- **Beklenen:** HTTP 200; JSON içinde `status: ok`, `service: personelmedisa-api`.
- **Not:** Bu endpoint DB kontrolü yapmaz; yalnızca API router/process ayakta mı gösterir.
- **Hata olursa:** `api/.htaccess`, `api/public/index.php`, PHP error log.

### 3. API auth guard negatif kontrolü

```bash
curl -i <BASE_URL>/personelmedisa/api/personeller
```

- **Beklenen:** Token yokken HTTP 401 JSON.
- **Not:** 200 dönmesi yetki açığıdır; HTML veya 404 dönmesi route/base path problemidir.
- **Hata olursa:** Router auth sırası, `config.local.php`, API rewrite.

### 4. Frontend kök 200 kontrolü

```bash
curl -I <BASE_URL>/personelmedisa/
```

- **Beklenen:** HTTP 200 HTML.
- **Tarayıcı:** Login ekranı veya app shell açılmalı.
- **Hata olursa:** `public/.htaccess`, `VITE_APP_BASE_PATH`, FTP hedef klasör.

### 5. Bundle / asset tutarlılığı

- **Amaç:** `index.html`'in işaret ettiği hashed JS/CSS dosyaları canlıda var mı?
- **Beklenen:** `index.html` içindeki `/personelmedisa/assets/index-*.js` ve ilgili CSS dosyaları HTTP 200 dönmeli (deploy log hash ile karşılaştırılabilir).
- **Hata olursa:** Partial FTP upload, mirror `--delete` problemi, yanlış klasör.

### 6. Cache bypass kontrolü

- **Amaç:** Tarayıcı eski bundle'a takıldı mı?
- **Beklenen:** Gizli pencere veya hard refresh (`Ctrl+F5`) ile güncel login/app shell açılır.
- **Not:** Repoda service worker yok; SW cache beklenmez. Tarayıcı ve PWA kısayol cache riski kalır.
- **Hata olursa:** Hard refresh, incognito, PWA kısayol cache temizliği.

### 7. Login smoke

- **Amaç:** Canlı auth + frontend routing çalışıyor mu?
- **Beklenen:** Yetkili kullanıcıyla login sonrası ana shell açılır; demo/mock fallback görünmez.
- **Hata olursa:** `.env.production`, API base path, `config.local.php`, CORS/session.

### 8. Kısa read smoke

- **Amaç:** DB + auth + temel read endpoint sağlıklı mı?
- **Beklenen:** Personel listesi veya Personel Kartı açılır; veri yoksa anlamlı boş state görünür, sistem kırılmaz.
- **Hata olursa:** DB bağlantısı, migration, API hata logları, yetki/scope.

### 9. Smoke sonuç kaydı

Her deploy sonrası aynı formatta kayıt tut:

```
Deploy smoke kayıt şablonu:
- Tarih/saat:
- main commit:
- CI run:
- Deploy cPanel run:
- API health: OK/FAIL
- API auth guard 401: OK/FAIL
- Frontend 200: OK/FAIL
- Asset 200: OK/FAIL
- Cache bypass: OK/FAIL
- Login smoke: OK/FAIL
- Read smoke: OK/FAIL
- Not/Risk:
```

## Sorun olursa hızlı kontrol

- Beyaz ekran + JS 404: `VITE_APP_BASE_PATH` veya kopyalama hedefi yanlış olabilir
- Route 404: `dist/.htaccess` eksik veya yanlış yerde olabilir
- Demo veri görünüyorsa: `VITE_API_MODE` / `VITE_DEMO_API_FALLBACK` değerlerini kontrol et
- Eski ekran: Tarayıcı/PWA cache temizlenmemiş olabilir
- Deploy hatası: GitHub `Actions` logunda `FTP_SERVER/USERNAME/PASSWORD/PORT` secret adlarını kontrol et
