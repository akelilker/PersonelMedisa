# PersonelMedisa PHP API

Same-origin backend hedefi: `https://www.karmotors.com.tr/personelmedisa/api/`

## Hedef veritabani

- **DB adi:** `karmotor_medisa`
- **Dokunulmayacak DB:** `karmotor_wp73` (WordPress)

Bu repoda migration/seed SQL dosyalari vardir; canliya calistirma deploy sprintinde yapilir.

## Dizin yapisi

```text
api/
  .htaccess              # Apache rewrite -> public/index.php
  public/index.php       # Front controller
  bin/migrate.php        # CLI-only canonical migration runner
  bin/cpanel-migration-cron.php # cPanel Cron CLI worker
  src/                   # PHP uygulama kodu
  migrations/            # SQL schema
  seeds/                 # Smoke seed template
```

Canli deploy hedefi:

```text
public_html/personelmedisa/api/
```

GitHub Actions deploy workflow'u frontend `dist/` ile birlikte PHP API icin
asagidaki runtime yuzeyini gonderir:

- `api/.htaccess`
- `api/public/`
- `api/src/`
- `api/bin/`
- `api/migrations/`

`config.local.php` ve `seeds/` workflow tarafindan gonderilmez.
Normal deploy migration calistirmaz. FTP ile `api/bin/`, `api/migrations/`,
`api/.deploy-sha` ve protected `api/runtime/.htaccess` gonderilir. Ayrı
`Apply cPanel migrations` workflow'u FTP ile atomik bir control request bırakır;
kalıcı cPanel Cron worker bu request'i CLI-only olarak claim eder, pending
migration'lari ledger'a yazar ve schema-ready kontrolu yapar.
Migration SQL, worker ve runtime status dosyalari web'den `.htaccess` ile engellenir.

`api/.htaccess`, canli `config.local.php` ile backup/temp turevlerini
(`config.local.php.*`, `config.local.php~`, slash-path) web'den fail-closed engeller.
Config yedegi web root icinde olusturulmaz; private dizin (or. `~/.private-config-backups/`,
`0700`) ve dosya izni `0600` kullanilir.

## Yapilandirma

1. `api/src/Config/config.example.php` dosyasini referans alin.
2. Canlida **git disi** dosya olusturun:

```text
public_html/personelmedisa/api/config.local.php
```

Ornek (placeholder — gercek secret/password yazmayin):

```php
<?php
return [
    'app_env' => 'production',
    'db_host' => 'localhost',
    'db_name' => 'karmotor_medisa',
    'db_user' => 'YOUR_DB_USER',
    'db_password' => 'YOUR_DB_PASSWORD',
    'jwt_secret' => 'YOUR_RANDOM_SECRET_MIN_32_CHARS',
    'jwt_ttl_seconds' => 86400,
];
```

`config.local.php` repoya commit edilmemelidir.

## Migration / seed

Migration'lar phpMyAdmin veya manuel MySQL CLI ile calistirilmaz. Üretimde
yalnizca cPanel Cron tarafindan CLI ile cagrilan canonical runner kullanilir:

```bash
php api/bin/migrate.php
php api/bin/migrate.php --verify
```

Ilk mevcut production kurulumu icin Cron command ortaminda
`MEDISA_MIGRATION_BASELINE=067` yalnizca ledger bootstrap asamasinda kullanilir;
runner bu surum ve oncekileri tekrar calistirmadan ledger'a kaydeder. Sonraki
deploy'larda baseline etkisizdir. `seeds/` production deploy kapsaminda degildir.

Password hash uretimi:

```bash
php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT), PHP_EOL;"
```

## Endpoint ozeti (S22B read-only)

| Method | Path |
|--------|------|
| GET | `/health` |
| POST | `/auth/login` |
| GET | `/personeller` |
| GET | `/personeller/{id}` |
| GET | `/referans/departmanlar` |
| GET | `/referans/gorevler` |
| GET | `/referans/personel-tipleri` |
| GET | `/yonetim/subeler` |
| GET | `/yonetim/aylik-ozet` |
| GET | `/gunluk-puantaj/{personelId}/{tarih}` |
| GET | `/raporlar/{tip}` |

Write endpointleri su an **405 METHOD_NOT_ALLOWED** doner.

## Auth

- Login: `POST /auth/login` JSON `{ "username", "password" }`
- Diger endpointler: `Authorization: Bearer <token>`
- Sube scope: `X-Active-Sube-Id` header (frontend contract)

## PHP uyumluluk

Kod PHP **7.4+** ile uyumludur (domain su an 7.4; 8.x secilebilir).

## Dogrulama (deploy sonrasi)

Authsuz:

```http
GET /personelmedisa/api/personeller
```

Beklenen: **401 JSON** (404 degil)

Login sonrasi token ile ayni endpoint: **200 JSON**
