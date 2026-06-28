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
  src/                   # PHP uygulama kodu
  migrations/            # SQL schema
  seeds/                 # Smoke seed template
```

Canli deploy hedefi:

```text
public_html/personelmedisa/api/
```

Frontend `dist/` deploy'undan ayri yuklenmelidir. Mevcut GitHub Actions workflow yalnizca frontend `dist/` gonderir.

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

phpMyAdmin veya MySQL CLI ile sirasiyla:

1. `migrations/001_initial_schema.sql` — `karmotor_medisa` secili iken calistirin
2. `seeds/001_smoke_seed.example.sql` — smoke verisi (password hash canlida uretilecek)

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
