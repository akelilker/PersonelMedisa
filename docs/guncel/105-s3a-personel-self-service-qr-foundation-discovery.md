# 105 — S3A Personel Self-Service + QR Attendance Foundation Discovery
**Branch:** `feat/personel-self-service-qr-foundation` (historical S3A/S3B discovery)
**Baseline (docs refresh):** current `origin/main` = `0020f7dbf27322583785099258c8df687fbcb9ac` (includes S3B + merged PR #142 hesaplama docs)
**Status:** Decisions locked (D1–D6). Doc renumbered from colliding `104` → `105`. **S3B closed in production** (PR #144 merged; migration 056 applied/immutable; bindings 0). **S3C** implements dynamic QR + raw events on `feat/dynamic-qr-attendance-foundation` / draft PR #145 (see `106-s3c-dynamic-qr-attendance-foundation.md`).
**PR #142:** Merged on main (`docs/hesaplama-cevap-haritasi` / `102`); S3C must not regress those files.
**PR #142:** Untouched (`docs/hesaplama-cevap-haritasi`)
**DOC_NUMBER_COLLISION:** FIXED (`104-s3a-…` → `105-s3a-…`)

---

## 1. Mevcut durum

| Alan | Durum |
|------|--------|
| PERSONEL rolü | Kanonik (S1 / 054); permission matrisi **boş** |
| users ↔ personel binding | **YOK** (`users.personel_id` yok) |
| Self-service API (`/me`) | **YOK** |
| QR attendance | **YOK** (S1 `DEFERRED_WORK`) |
| PERSONEL UI | `/` üzerinde placeholder |
| Migration tip (prod) | **055** immutable/applied; next **056+** |
| 055 ledger | Initial 0 rows (given) |

S1 checkpoint (`103`) açıkça defer eder:

- `PERSONEL_SELF_SERVICE_BINDING`
- `QR_ATTENDANCE_FOUNDATION`

Kanonik zincir (hedef, henüz yok):

```
authenticated user
  → canonical bound personel
  → self-scope
  → authenticated QR scan
  → append-only raw attendance event
  → interval derivation
  → daily puantaj candidate/source
  → existing olay/karar / haftalık kapanış / bordro zinciri
```

**Invariant:** QR event doğrudan bordro/kanonik puantaj kaydı değildir.

---

## 2. Owner map

| Anahtar | Owner / kanıt |
|---------|----------------|
| CURRENT_USER_PERSONEL_BINDING | **NONE** — schema + AuthMiddleware session’da `personel_id` yok |
| BINDING_FOUNDATION | **partial** — PERSONEL rolü + FE deferred `personel_id` alanları + SGK `actor_identities.personel_id` (login binding değil) |
| BINDING_OWNER | **NONE** (self-service) |
| AUTH_DB_ROLE_OWNER | `api/src/Auth/RolePermissions.php` (+ FE `src/lib/authorization/role-permissions.ts`) |
| SESSION_OWNER | `AuthMiddleware` + `LoginController` + `Jwt` + FE `auth-manager.ts` |
| USER_MANAGEMENT_OWNER | BE `YonetimController`; FE `YonetimPaneliPage.tsx` |
| PERSONEL_MANAGEMENT_OWNER | BE `PersonellerController` + `PersonelCreateService`; FE `src/features/personeller/` |
| CURRENT_SUBE_SCOPE_OWNER | `api/src/Scope/SubeScope.php` |
| CURRENT_RETENTION_OWNER | `053` + `RetentionPolicyService` + `RetentionCategories` |
| LATE_EARLY_EXISTING_OWNER | `gunluk_puantaj.gec_*/erken_*` + `PuantajOlayKararService` + FE `puantaj-hesap-motoru.ts` |
| OVERTIME_EXISTING_OWNER | `puantaj-hesap-motoru` + `FazlaCalismaOdemeTercihi*` + 270h kilit |
| ANNUAL_LEAVE_READ_OWNER (S2) | `YillikIzinBakiyeService` (`GET /personeller/{id}/yillik-izin-bakiye`) |

**SGK ayrımı:** `actor_identities.personel_id` (048) dual-control actor köprüsüdür; session self-service binding değildir. Testler `uq_users_personel_id` varsayımını reddeder.

---

## 3. users ↔ personel binding

### 3.1 Mevcut şema

`users` (`001` + additive): `id`, `username` UNIQUE, `password_hash`, `ad_soyad`, `rol`, `durum` AKTIF/PASIF, `created_at`/`updated_at`, `actor_identity_id` (048), `varsayilan_sube_id` (051), `user_subeler`.

`personeller`: `sube_id` zorunlu, `aktif_durum` AKTIF/PASIF, TC UNIQUE; arşiv `PersonelArchiveGate` + retention overlay.

**Yasak eşleştirme (S3A invariant):** ad/soyad, e-posta benzerliği, TC tahmini, telefon, username, hardcode user id, person-specific source rule.

### 3.2 Önerilen model

```
RECOMMENDED_BINDING_MODEL = users.personel_id NULLABLE FK → personeller.id
```

Gerekçe:

- Identity property; role’dan ayrı (authorization property).
- AuthMiddleware zaten her request’te DB’den user yükler → binding DB-authoritative olur.
- Yönetim UI’da deferred `personel_id` alanı zaten tip/contract’ta var; gerçek persist eksik.
- Ayrı binding tablosu yalnızca audit history zenginliği için gerekirse S3B+’da değerlendirilir; v1 için kolon yeterli.

```
RECOMMENDED_BINDING_UNIQUE_POLICY =
  UNIQUE(personel_id)  -- MySQL: birden fazla NULL serbest
```

Yaşam döngüsü:

| Durum | Politika |
|-------|----------|
| Aynı personel + iki AKTIF user | **ENGEL** (UNIQUE) |
| Eski PASIF user + yeni hesap | Eski `personel_id` audit’li NULL’lanır veya rebind; sonra yeni bağ |
| Personel işten ayrılır / PASIF | Binding satırı kalabilir; QR/login politikası ayrı (D4) |
| Yönetici + personel kaydı | Binding izinli (identity ≠ role) |
| AUTH_SMOKE | Binding **yok** / NULL; self-service açılmaz |
| Prod backfill | **NO** — nullable leave; isim/TC/rol ile otomatik bağlama yok |

### 3.3 PERSONEL fail-closed

```
role = PERSONEL AND personel_id IS NULL
  → login olabilir
  → kişisel iş verisi / QR DENY
  → UI: "Hesabınız personel kaydıyla eşleştirilmemiş."
```

Guess/fallback yok.

### 3.4 Binding yazma sahibi

S1 `SYSTEM_ADMIN_INVARIANT`: `yonetim-paneli.manage` = user/role/şube teknik yönetimi; business approval değil.

```
BINDING_WRITE_PERMISSION_RECOMMENDATION = yonetim-paneli.manage
BINDING_WRITE_ROLES_BY_EXISTING_MATRIX = GENEL_YONETICI, SISTEM_YONETICISI
```

IK_SORUMLUSU personel kartı yönetir ama user-account binding yazmaz (mevcut matriste `yonetim-paneli.manage` yok). Yeni business approval oluşturma.

### 3.5 Binding audit

`users` üzerinde yalnızca `created_at`/`updated_at` var; sessiz UPDATE yetersiz.

Öneri: binding set/clear için audit satırı (kim, user_id, old/new personel_id, zaman) — mevcut user domain audit yoksa S3B’de dar `user_binding_audit` veya genel teknik audit reuse.

### 3.6 Session / binding değişimi

```
BINDING_DB_AUTHORITATIVE = YES
  (AuthMiddleware JWT rolünü bile güvenmez; sub → DB reload)
BINDING_CHANGE_RELOGIN_REQUIRED = NO (DB her request)
SESSION_INVALIDATION_REQUIRED = NO (binding claim JWT’de yok)
```

PASIF user: mevcut AuthMiddleware zaten fail-closed (`durum !== AKTIF` → 401).

---

## 4. Self-service authorization

### 4.1 Invariant

```
SELF_SCOPE_ONLY = YES
ARBITRARY_PERSONEL_ID_ALLOWED = NO
SELF_SERVICE_CANONICAL_WRITE = NO
```

**VERME:** `personeller.view`, `personeller.view.sube`, `personeller.detail.view` (başka personelleri açar).

### 4.2 Önerilen dar capability ailesi

Repo convention: `domain.action` / `domain.action.qualifier` (FE `AppPermission` + PHP `RolePermissions` parity).

```
PROPOSED_SELF_PERMISSIONS =
  self_service.view
  self_service.puantaj.view
  self_service.yillik_izin.view
  self_service.fazla_calisma.view
  self_service.qr.scan
  self_service.qr.events.view
```

Yalnız PERSONEL (ve isteğe bağlı self-bound diğer roller aynı permission ile). Broad inheritance yok.

### 4.3 Hedef okuma yüzeyleri (server-owned)

| Alan | Davranış |
|------|----------|
| BUGÜN | Giriş/çıkış raw, içeride/dışarıda, eksik scan uyarısı |
| AYLIK | Geç/erken adet+dk, çalışma özeti, FM özeti |
| GEÇMİŞ | Son 12 ay bounded |
| YILLIK İZİN | S2 bakiye assemble reuse |
| FAZLA ÇALIŞMA | Mevcut FM/özet owner reuse — finalize/ödeme yok |
| QR | Kendi raw event’leri (nonce/signature gösterilmez) |

Payroll/maaş self-view: **OUT_OF_SCOPE** bu fazda.

---

## 5. `/me` API contract

Client `personel_id` seçmez.

Önerilen şekil (repo path stiline uyumlu):

```
GET  /me
GET  /me/puantaj?from=&to=
GET  /me/yillik-izin-bakiye
GET  /me/fazla-calisma?...
GET  /me/qr-hareketleri?...
POST /me/qr-scan
```

Server: `AuthMiddleware` user → `users.personel_id` → canonical personel.

**Yasak:** `GET /personeller/{arbitraryId}/self` veya client-controlled kimlik.

Leave: mevcut `YillikIzinBakiyeService::assemble` wrap; hesap FE’de kopyalanmaz.

---

## 6. Mobile / PWA

| Anahtar | Bulgu |
|---------|--------|
| CURRENT_PERSONEL_ROUTE | Dedicated route yok; authenticated `/` |
| CURRENT_PERSONEL_PLACEHOLDER | `HomeIndexMainMenu` — `data-testid="personel-placeholder-page"` |
| MOBILE_FOUNDATION | AppShell max-width 500px; `mobile.css` / breakpoints |
| PWA_FOUNDATION | `site.webmanifest` + Apple meta; **service worker yok**; vite-plugin-pwa yok |
| Login redirect | Rol-özel landing yok → `/` |
| Menu | PERSONEL permission boş → MainMenu gizli, secondary nav boş |

```
RECOMMENDED_PERSONEL_SHELL = mevcut AppShell reuse
  (placeholder’ı self-service home ile değiştir; paralel app kurma)
```

Hedef: masaüstü yönetim UI’sını küçültmek değil; basit self-service içerik.

---

## 7. QR threat model

| Tehdit | Kontrol |
|--------|---------|
| QR screenshot / share | Dinamik kısa ömürlü signed token; auth zorunlu |
| Replay expired token | `expires_at` + server clock |
| Stolen employee credentials | Mevcut password/JWT; v1 device binding yok (opsiyonel gelecek) |
| Another-branch scan | Personel güncel `sube_id` ↔ QR site id; default DENY |
| Client clock spoof | Server timestamp only |
| Duplicate submit | User+token+event_type(+request nonce) idempotency |
| Missing exit scan | Incomplete interval; otomatik 8s varsayma yok |
| Fake personel_id | Client body’den kabul edilmez |
| Manager scanning for employee | QR endpoint self-context only; admin → revision |
| Offline queued spoof | OFFLINE_QR_WRITE = NO |
| Altered raw event | Append-only; correction ayrı mekanizma |
| Deleted audit evidence | Hard delete yok; retention + legal hold |
| Inactive user/personel | AuthMiddleware PASIF + personel aktif_durum / archive gate |

---

## 8. Static vs dynamic QR

| | A Static | B Dynamic signed short-lived | C Static + GPS/device |
|--|----------|------------------------------|------------------------|
| Avantaj | Basit baskı | Replay/share ciddi azalır | — |
| Dezavantaj | Foto replay kolay | Kiosk/ekran gerekir | Privacy/karmaşıklık |

```
RECOMMENDED_QR_MODEL = DYNAMIC_SIGNED_SHORT_LIVED_QR
STATIC_QR_SECURITY = UNSAFE_AS_DEFAULT (screenshot/replay)
DYNAMIC_QR_SECURITY = PREFERRED (TTL + signature + site id + nonce)
```

GPS / biyometri / selfie: **varsayılan YOK** (ayrı onay gerekir). Kamera yalnız QR okuma için.

---

## 9. Event direction alternatives

| Seçenek | Risk / not |
|---------|------------|
| A AUTO_TOGGLE | Unutulan scan zinciri kaydırır |
| B EXPLICIT GİRİŞ/ÇIKIŞ | Token presence doğrular; kullanıcı yön seçer |
| C TWO_QR | Fiziksel kurulum maliyeti |

```
RECOMMENDATION = TEK DİNAMİK QR + EXPLICIT GİRİŞ/ÇIKIŞ (D2)
```

---

## 10. Raw event model (tasarım — migration yok)

Gelecek tablo (ör. `qr_attendance_events` / `attendance_raw_events`):

| Alan | Not |
|------|-----|
| id | PK |
| personel_id | Server-resolved |
| user_id | Server-resolved |
| sube_id / workplace_id | Token’dan |
| event_type | `GIRIS` \| `CIKIS` |
| server_occurred_at | Kanonik zaman |
| qr_token_jti_hash | Replay/audit |
| qr_version | Payload version |
| source | `QR` |
| created_at | Insert time |
| client_* | Yalnız telemetry; business field değil |

**Yasak:** mutable canonical puantaj; client override timestamp; hard delete.

```
CLIENT_PERSONEL_ID = FORBIDDEN
CLIENT_USER_ID = FORBIDDEN
APPEND_ONLY_RAW_EVENT = YES
HARD_DELETE_RAW_EVENT = NO
```

### Idempotency / replay

Display token **global consume edilmez** (aynı pencerede çok personel).

```
IDEMPOTENCY_MODEL = user_id + qr_jti + event_type + short client_request_nonce
REPLAY_PROTECTION_MODEL = signature + expiry + jti validity window + per-user idempotency key
```

Rate limit: API’de mevcut throttle **yok**; S3C’de dar per-user duplicate-scan guard önerilir (gerçek giriş/çıkışı rastgele engellemeden).

### Token payload (min)

`version`, `site/sube id`, `issued_at`, `expires_at`, `jti/nonce` — server HMAC/signed; secret plaintext QR’da yok; sequential predictable ID güvenlik token’ı değil.

```
QR_SIGNING_SECRET_OWNER_RECOMMENDATION =
  api config.local.php / env (JWT_SECRET pattern) — asla VITE_* / repo commit
  önerilen key: qr_signing_secret
```

### Device

```
DEVICE_TRUST_FOUNDATION = NONE (client remember-me = storage seçimi only)
DEVICE_BINDING_REQUIRED_FOR_V1_RECOMMENDATION = NO
  → v1: authenticated session yeterli
```

### Offline

```
OFFLINE_QR_WRITE = NO
UI: "Bağlantı kurulamadı, kayıt oluşmadı."
```

---

## 11. Event → interval → puantaj pipeline

### Mevcut

- Canonical day: `gunluk_puantaj` (tek `giris_saati`/`cikis_saati` çifti)
- Soft provenance: `kaynak` VARCHAR (örn. `BILDIRIM_ETKI_ADAYI`)
- Otomatik köprü: bildirim → aday → `BildirimPuantajEtkiApplyService` (yalnız `HAZIR`)
- Multi-interval / turnike / work-session tablosu: **YOK**
- Gece taşıma: FE motorunda computation-only (`cikis < giris` → ertesi gün)

### Öneri entegrasyon

```
QR raw (append-only)
  → derived intervals (yeniden hesaplanabilir; incomplete allowed)
  → daily attendance candidate / aday (veya olay_karar öncesi evidence)
  → existing apply/decision/weekly close
QR_CAN_WRITE_CANONICAL_DIRECTLY = NO
```

`PuantajController::upsert` QR write path değildir.

Eksik scan: otomatik 8 saat **yok**; interval INCOMPLETE; self-service “Eksik çıkış kaydı”; operasyon correction/revision.

Geç/erken / FM / UBGT: Phase B/S1 mevcut motorlar reuse; QR yalnız actual interval kanıtı.

Cross-midnight / vardiya tablosu yok → **D karar** (CROSS_MIDNIGHT_POLICY_DECISION_REQUIRED).

### Missing scan review

Proven yakın owner’lar:

- `puantaj.olay_karar.decide` → **BOLUM_YONETICISI** (geç/erken karar)
- Revizyon `PUANTAJ_GIRIS_CIKIS_DUZELTME` / correction layer → BA create/submit; approve **GENEL_YONETICI**
- Dedicated `EKSIK_OKUTMA` tip: **yok**

```
MISSING_SCAN_REVIEW_OWNER_PROVEN = partial (olay_karar + giris/cikis revizyon)
MISSING_SCAN_DECISION_REQUIRED = YES (QR-specific tip mi, mevcut GIRIS_CIKIS_DUZELTME mi?)
```

---

## 12. Scope / şube

`SubeScope` personel erişimini **güncel `personeller.sube_id`** ile doğrular.

Geçici görevlendirme / multi-sube assignment tablosu: **YOK** (`GOREVLENDIRME` yalnız süreç label).

```
CROSS_BRANCH_SCAN_POLICY_PROVEN = NO (model yok)
CROSS_BRANCH_DECISION_REQUIRED = YES
RECOMMENDED_CROSS_BRANCH_POLICY = DENY
```

Transfer: binding user’da kalır; QR site check güncel personel şubesinden; historical event event-time `sube_id` korur.

---

## 13. Retention / legal hold

Phase C kategorileri (`RetentionCategories`): period `PUANTAJ`, …; termination `ISE_GIRIS_CIKIS`, `OLAY`, …

QR raw = attendance evidence → retention dışında “sonsuz” kalamaz.

```
QR_RETENTION_INTEGRATION = S3C ile aynı PR’da kategori bağlama önerilir
  (aday: PUANTAJ period-closure veya ISE_GIRIS_CIKIS — kesin seçim karar)
QR_RETENTION_DECISION_REQUIRED = YES (kategori + trigger eşlemesi)
LEGAL_HOLD_INTEGRATION =
  LegalHoldService + RetentionSchemaGate fail-closed;
  destruction eligibility personel/domain hold ile QR evidence’ı da kapsamalı
```

Binding audit + QR event audit: yeni append-only / audit owner S3B/S3C’de.

---

## 14. Migration phasing (yalnız plan)

Collision: tip **055**; next **056+**. 052–055 dokunulmaz.

```
PROPOSED_056 = identity binding (users.personel_id NULLABLE + UNIQUE + audit)
PROPOSED_057 = QR workplace/token metadata + append-only raw event
               (+ retention category registration)
```

Fazlama:

| Faz | Kapsam |
|-----|--------|
| **S3B** | Binding + self-service read shell (`/me` leave/puantaj/FM read) |
| **S3C** | Dynamic QR token + append-only raw capture (+ retention wire) |
| **S3D** | Raw → interval derivation |
| **S3E** | Interval → puantaj candidate/source integration |

Tek mega migration/PR önerilmez. Raw attendance evidence production’a retention owner olmadan çıkmamalı.

`PRODUCTION_BINDING_BACKFILL = NO`
`SELF_REGISTRATION = NO`

Provisioning: Yönetim → user create → PERSONEL → bağlı personel seç → credentials. Existing login/password reuse; QR login değildir.

---

## 15. Locked decisions (D1–D6) — bağlayıcı

S3B decision blockers: **NONE**. QR implementation remains S3C+.

### D1 — QR tipi — LOCKED

```
D1_QR_MODEL = DYNAMIC_SIGNED
```

### D2 — Hareket yönü — LOCKED

```
D2_EVENT_DIRECTION = EXPLICIT_GIRIS_CIKIS
```

Scan → token validate → user explicitly chooses GİRİŞ or ÇIKIŞ.
AUTO_TOGGLE = NO. TWO_QR = NO.

### D3 — Başka şube scan — LOCKED

```
D3_CROSS_BRANCH = DENY
```

v1: personel may only use their current şube QR. Temporary assignment model may change this later.

### D4 — İşten ayrılan / PASIF personel self-service — LOCKED

```
D4_TERMINATED_SELF_SERVICE = DENY_ALL
```

`personeller.aktif_durum != AKTIF` → self-service data DENY + future QR DENY.
Binding may remain in DB for audit; no “eski çalışan portalı” in S3.

### D5 — Missing scan correction — LOCKED

```
D5_MISSING_SCAN_CORRECTION = REUSE_GIRIS_CIKIS_DUZELTME_REVIZYON
```

No parallel correction workflow. S3E may add QR provenance metadata onto existing revision records.

### D6 — QR display / TTL — LOCKED

```
D6_DISPLAY_MODEL = AUTHENTICATED_KIOSK
D6_TTL_DEFAULT_SECONDS = 60
D6_TTL_CONFIGURABLE_RANGE = 30-120
```

TTL via server config. Secret public rotating URL = NO (v1). Static printed QR = NO (v1).
**S3B does not implement QR code** — decisions recorded for S3C only.

---

## 16. Recommended defaults (kullanıcıya tekrar sorulmayan teknik kilitler)

```
SERVER_TIMESTAMP = YES
CLIENT_TIMESTAMP_CANONICAL = NO
APPEND_ONLY_RAW_EVENT = YES
SELF_REGISTRATION = NO
PERSONEL_ARBITRARY_PERSONEL_ID = NO
PERSONEL_CANONICAL_DATA_WRITE = NO
QR_REQUIRES_AUTH = YES
GPS_REQUIRED = NO
BIOMETRIC_REQUIRED = NO
OFFLINE_QR_WRITE = NO
HARD_DELETE_RAW_EVENT = NO
AUTO_PRODUCTION_BINDING_BACKFILL = NO
MANUAL_TOKEN_ENTRY = NO
```

### Time

| Anahtar | Bulgu |
|---------|--------|
| DB_TIMEZONE | Migrations `SET time_zone = '+00:00'` |
| APP_TIMEZONE | Connection’da explicit set yok |
| BUSINESS_TIMEZONE | Ad hoc `Europe/Istanbul` (bazı controller’lar) |
| QR_TIMESTAMP_RECOMMENDATION | `server_occurred_at` UTC store; UI business local (`Europe/Istanbul`) mevcut puantaj gün owner’ına hizalı |

### Camera / PWA QR

| Platform | Strateji |
|----------|----------|
| Android Chrome | `BarcodeDetector` varsa native; yoksa hafif fallback lib (S3C) |
| iOS Safari/PWA | BarcodeDetector desteği sınırlı → fallback lib muhtemel |
| FALLBACK_LIBRARY_REQUIRED | **LIKELY YES** (S3A’da dependency ekleme yok) |
| MANUAL_TOKEN_ENTRY | NO |
| ACCESSIBILITY_FALLBACK_DECISION_REQUIRED | YES (kart/NFC/turnike out of scope) |

### QR display owner options

- A) Authenticated kiosk session
- B) Public display URL + rotating signed QR; configuration secret URL ile korunur
- Attendance identity display’de oluşmaz; yalnız presence token

```
RECOMMENDED_QR_DISPLAY_OWNER = technical config (SISTEM_YONETICISI / yonetim-paneli.manage)
  + display route ayrı (login’siz token yayın)
```

---

## 17. Security checklist (S3 implementasyon kapıları)

- IDOR: `/me` only; arbitrary personel id yok
- Role escalation: self_service.* broad `personeller.*` vermez
- QR endpoint: auth + permission + bound + AKTIF user + AKTIF personel + valid signed QR + expiry + workplace + event type + server time
- No anonymous QR attendance
- No manager-for-employee QR via scan endpoint
- QR token ≠ user JWT

---

## 18. Out of scope (S3A)

Face/fingerprint/GPS/NFC/turnstile; payroll finalization changes; automatic discipline/OT approval; production personel import; self-registration; push; native apps; background location; offline attendance writes; migration files; production writes; merge/deploy.

---

## 19. Evidence index (read-only)

- `api/migrations/001_initial_schema.sql` — users, personeller, gunluk_puantaj
- `api/migrations/048_*` — actor_identities (non-binding)
- `api/migrations/051_*` — varsayilan_sube_id
- `api/migrations/052–055` — tip; dokunulmaz
- `api/src/Auth/{AuthMiddleware,LoginController,RolePermissions,Jwt}.php`
- `api/src/Controllers/YonetimController.php` — `personel_id: null` hardcoded
- `api/src/Scope/SubeScope.php`
- `api/src/Services/BildirimPuantajEtkiApplyService.php`
- `api/src/Services/Retention/*`
- `src/app/routes.tsx` — PERSONEL placeholder
- `src/lib/authorization/role-permissions.ts` — `PERSONEL: []`
- `docs/guncel/103-s1-canonical-role-consolidation-checkpoint.md`

---

## 20. Stop gate

```
S3A = discovery doc + draft PR + exact-head CI
Implementation / migration 056 / merge / deploy = STOP
NEXT = user review of D1–D6 (+ retention category)
```
