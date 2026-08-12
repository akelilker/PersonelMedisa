# 105 â€” S3A Personel Self-Service + QR Attendance Foundation Discovery

**Branch:** `feat/personel-self-service-qr-foundation` (historical S3A/S3B discovery)
**Baseline (docs refresh):** current `origin/main` = `0020f7dbf27322583785099258c8df687fbcb9ac` (includes S3B + merged PR #142 hesaplama docs)
**Status:** Decisions locked (D1â€“D6). Doc renumbered from colliding `104` â†’ `105`. **S3B closed in production** (PR #144 merged; migration 056 applied/immutable; bindings 0). **S3C** implements dynamic QR + raw events on `feat/dynamic-qr-attendance-foundation` / draft PR #145 (see `106-s3c-dynamic-qr-attendance-foundation.md`).
**PR #142:** Merged on main (`docs/hesaplama-cevap-haritasi` / `102`); S3C must not regress those files.
**DOC_NUMBER_COLLISION:** FIXED (`104-s3a-â€¦` â†’ `105-s3a-â€¦`)

---

## 1. Mevcut durum

| Alan | Durum |
|------|--------|
| PERSONEL rolÃ¼ | Kanonik (S1 / 054); permission matrisi **boÅŸ** |
| users â†” personel binding | **YOK** (`users.personel_id` yok) |
| Self-service API (`/me`) | **YOK** |
| QR attendance | **YOK** (S1 `DEFERRED_WORK`) |
| PERSONEL UI | `/` Ã¼zerinde placeholder |
| Migration tip (prod) | **055** immutable/applied; next **056+** |
| 055 ledger | Initial 0 rows (given) |

S1 checkpoint (`103`) aÃ§Ä±kÃ§a defer eder:

- `PERSONEL_SELF_SERVICE_BINDING`
- `QR_ATTENDANCE_FOUNDATION`

Kanonik zincir (hedef, henÃ¼z yok):

```
authenticated user
  â†’ canonical bound personel
  â†’ self-scope
  â†’ authenticated QR scan
  â†’ append-only raw attendance event
  â†’ interval derivation
  â†’ daily puantaj candidate/source
  â†’ existing olay/karar / haftalÄ±k kapanÄ±ÅŸ / bordro zinciri
```

**Invariant:** QR event doÄŸrudan bordro/kanonik puantaj kaydÄ± deÄŸildir.

---

## 2. Owner map

| Anahtar | Owner / kanÄ±t |
|---------|----------------|
| CURRENT_USER_PERSONEL_BINDING | **NONE** â€” schema + AuthMiddleware sessionâ€™da `personel_id` yok |
| BINDING_FOUNDATION | **partial** â€” PERSONEL rolÃ¼ + FE deferred `personel_id` alanlarÄ± + SGK `actor_identities.personel_id` (login binding deÄŸil) |
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

**SGK ayrÄ±mÄ±:** `actor_identities.personel_id` (048) dual-control actor kÃ¶prÃ¼sÃ¼dÃ¼r; session self-service binding deÄŸildir. Testler `uq_users_personel_id` varsayÄ±mÄ±nÄ± reddeder.

---

## 3. users â†” personel binding

### 3.1 Mevcut ÅŸema

`users` (`001` + additive): `id`, `username` UNIQUE, `password_hash`, `ad_soyad`, `rol`, `durum` AKTIF/PASIF, `created_at`/`updated_at`, `actor_identity_id` (048), `varsayilan_sube_id` (051), `user_subeler`.

`personeller`: `sube_id` zorunlu, `aktif_durum` AKTIF/PASIF, TC UNIQUE; arÅŸiv `PersonelArchiveGate` + retention overlay.

**Yasak eÅŸleÅŸtirme (S3A invariant):** ad/soyad, e-posta benzerliÄŸi, TC tahmini, telefon, username, hardcode user id, person-specific source rule.

### 3.2 Ã–nerilen model

```
RECOMMENDED_BINDING_MODEL = users.personel_id NULLABLE FK â†’ personeller.id
```

GerekÃ§e:

- Identity property; roleâ€™dan ayrÄ± (authorization property).
- AuthMiddleware zaten her requestâ€™te DBâ€™den user yÃ¼kler â†’ binding DB-authoritative olur.
- YÃ¶netim UIâ€™da deferred `personel_id` alanÄ± zaten tip/contractâ€™ta var; gerÃ§ek persist eksik.
- AyrÄ± binding tablosu yalnÄ±zca audit history zenginliÄŸi iÃ§in gerekirse S3B+â€™da deÄŸerlendirilir; v1 iÃ§in kolon yeterli.

```
RECOMMENDED_BINDING_UNIQUE_POLICY =
  UNIQUE(personel_id)  -- MySQL: birden fazla NULL serbest
```

YaÅŸam dÃ¶ngÃ¼sÃ¼:

| Durum | Politika |
|-------|----------|
| AynÄ± personel + iki AKTIF user | **ENGEL** (UNIQUE) |
| Eski PASIF user + yeni hesap | Eski `personel_id` auditâ€™li NULLâ€™lanÄ±r veya rebind; sonra yeni baÄŸ |
| Personel iÅŸten ayrÄ±lÄ±r / PASIF | Binding satÄ±rÄ± kalabilir; QR/login politikasÄ± ayrÄ± (D4) |
| YÃ¶netici + personel kaydÄ± | Binding izinli (identity â‰  role) |
| AUTH_SMOKE | Binding **yok** / NULL; self-service aÃ§Ä±lmaz |
| Prod backfill | **NO** â€” nullable leave; isim/TC/rol ile otomatik baÄŸlama yok |

### 3.3 PERSONEL fail-closed

```
role = PERSONEL AND personel_id IS NULL
  â†’ login olabilir
  â†’ kiÅŸisel iÅŸ verisi / QR DENY
  â†’ UI: "HesabÄ±nÄ±z personel kaydÄ±yla eÅŸleÅŸtirilmemiÅŸ."
```

Guess/fallback yok.

### 3.4 Binding yazma sahibi

S1 `SYSTEM_ADMIN_INVARIANT`: `yonetim-paneli.manage` = user/role/ÅŸube teknik yÃ¶netimi; business approval deÄŸil.

```
BINDING_WRITE_PERMISSION_RECOMMENDATION = yonetim-paneli.manage
BINDING_WRITE_ROLES_BY_EXISTING_MATRIX = GENEL_YONETICI, SISTEM_YONETICISI
```

IK_SORUMLUSU personel kartÄ± yÃ¶netir ama user-account binding yazmaz (mevcut matriste `yonetim-paneli.manage` yok). Yeni business approval oluÅŸturma.

### 3.5 Binding audit

`users` Ã¼zerinde yalnÄ±zca `created_at`/`updated_at` var; sessiz UPDATE yetersiz.

Ã–neri: binding set/clear iÃ§in audit satÄ±rÄ± (kim, user_id, old/new personel_id, zaman) â€” mevcut user domain audit yoksa S3Bâ€™de dar `user_binding_audit` veya genel teknik audit reuse.

### 3.6 Session / binding deÄŸiÅŸimi

```
BINDING_DB_AUTHORITATIVE = YES
  (AuthMiddleware JWT rolÃ¼nÃ¼ bile gÃ¼venmez; sub â†’ DB reload)
BINDING_CHANGE_RELOGIN_REQUIRED = NO (DB her request)
SESSION_INVALIDATION_REQUIRED = NO (binding claim JWTâ€™de yok)
```

PASIF user: mevcut AuthMiddleware zaten fail-closed (`durum !== AKTIF` â†’ 401).

---

## 4. Self-service authorization

### 4.1 Invariant

```
SELF_SCOPE_ONLY = YES
ARBITRARY_PERSONEL_ID_ALLOWED = NO
SELF_SERVICE_CANONICAL_WRITE = NO
```

**VERME:** `personeller.view`, `personeller.view.sube`, `personeller.detail.view` (baÅŸka personelleri aÃ§ar).

### 4.2 Ã–nerilen dar capability ailesi

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

YalnÄ±z PERSONEL (ve isteÄŸe baÄŸlÄ± self-bound diÄŸer roller aynÄ± permission ile). Broad inheritance yok.

### 4.3 Hedef okuma yÃ¼zeyleri (server-owned)

| Alan | DavranÄ±ÅŸ |
|------|----------|
| BUGÃœN | GiriÅŸ/Ã§Ä±kÄ±ÅŸ raw, iÃ§eride/dÄ±ÅŸarÄ±da, eksik scan uyarÄ±sÄ± |
| AYLIK | GeÃ§/erken adet+dk, Ã§alÄ±ÅŸma Ã¶zeti, FM Ã¶zeti |
| GEÃ‡MÄ°Å | Son 12 ay bounded |
| YILLIK Ä°ZÄ°N | S2 bakiye assemble reuse |
| FAZLA Ã‡ALIÅMA | Mevcut FM/Ã¶zet owner reuse â€” finalize/Ã¶deme yok |
| QR | Kendi raw eventâ€™leri (nonce/signature gÃ¶sterilmez) |

Payroll/maaÅŸ self-view: **OUT_OF_SCOPE** bu fazda.

---

## 5. `/me` API contract

Client `personel_id` seÃ§mez.

Ã–nerilen ÅŸekil (repo path stiline uyumlu):

```
GET  /me
GET  /me/puantaj?from=&to=
GET  /me/yillik-izin-bakiye
GET  /me/fazla-calisma?...
GET  /me/qr-hareketleri?...
POST /me/qr-scan
```

Server: `AuthMiddleware` user â†’ `users.personel_id` â†’ canonical personel.

**Yasak:** `GET /personeller/{arbitraryId}/self` veya client-controlled kimlik.

Leave: mevcut `YillikIzinBakiyeService::assemble` wrap; hesap FEâ€™de kopyalanmaz.

---

## 6. Mobile / PWA

| Anahtar | Bulgu |
|---------|--------|
| CURRENT_PERSONEL_ROUTE | Dedicated route yok; authenticated `/` |
| CURRENT_PERSONEL_PLACEHOLDER | `HomeIndexMainMenu` â€” `data-testid="personel-placeholder-page"` |
| MOBILE_FOUNDATION | AppShell max-width 500px; `mobile.css` / breakpoints |
| PWA_FOUNDATION | `site.webmanifest` + Apple meta; **service worker yok**; vite-plugin-pwa yok |
| Login redirect | Rol-Ã¶zel landing yok â†’ `/` |
| Menu | PERSONEL permission boÅŸ â†’ MainMenu gizli, secondary nav boÅŸ |

```
RECOMMENDED_PERSONEL_SHELL = mevcut AppShell reuse
  (placeholderâ€™Ä± self-service home ile deÄŸiÅŸtir; paralel app kurma)
```

Hedef: masaÃ¼stÃ¼ yÃ¶netim UIâ€™sÄ±nÄ± kÃ¼Ã§Ã¼ltmek deÄŸil; basit self-service iÃ§erik.

---

## 7. QR threat model

| Tehdit | Kontrol |
|--------|---------|
| QR screenshot / share | Dinamik kÄ±sa Ã¶mÃ¼rlÃ¼ signed token; auth zorunlu |
| Replay expired token | `expires_at` + server clock |
| Stolen employee credentials | Mevcut password/JWT; v1 device binding yok (opsiyonel gelecek) |
| Another-branch scan | Personel gÃ¼ncel `sube_id` â†” QR site id; default DENY |
| Client clock spoof | Server timestamp only |
| Duplicate submit | User+token+event_type(+request nonce) idempotency |
| Missing exit scan | Incomplete interval; otomatik 8s varsayma yok |
| Fake personel_id | Client bodyâ€™den kabul edilmez |
| Manager scanning for employee | QR endpoint self-context only; admin â†’ revision |
| Offline queued spoof | OFFLINE_QR_WRITE = NO |
| Altered raw event | Append-only; correction ayrÄ± mekanizma |
| Deleted audit evidence | Hard delete yok; retention + legal hold |
| Inactive user/personel | AuthMiddleware PASIF + personel aktif_durum / archive gate |

---

## 8. Static vs dynamic QR

| | A Static | B Dynamic signed short-lived | C Static + GPS/device |
|--|----------|------------------------------|------------------------|
| Avantaj | Basit baskÄ± | Replay/share ciddi azalÄ±r | â€” |
| Dezavantaj | Foto replay kolay | Kiosk/ekran gerekir | Privacy/karmaÅŸÄ±klÄ±k |

```
RECOMMENDED_QR_MODEL = DYNAMIC_SIGNED_SHORT_LIVED_QR
STATIC_QR_SECURITY = UNSAFE_AS_DEFAULT (screenshot/replay)
DYNAMIC_QR_SECURITY = PREFERRED (TTL + signature + site id + nonce)
```

GPS / biyometri / selfie: **varsayÄ±lan YOK** (ayrÄ± onay gerekir). Kamera yalnÄ±z QR okuma iÃ§in.

---

## 9. Event direction alternatives

| SeÃ§enek | Risk / not |
|---------|------------|
| A AUTO_TOGGLE | Unutulan scan zinciri kaydÄ±rÄ±r |
| B EXPLICIT GÄ°RÄ°Å/Ã‡IKIÅ | Token presence doÄŸrular; kullanÄ±cÄ± yÃ¶n seÃ§er |
| C TWO_QR | Fiziksel kurulum maliyeti |

```
RECOMMENDATION = TEK DÄ°NAMÄ°K QR + EXPLICIT GÄ°RÄ°Å/Ã‡IKIÅ (D2)
```

---

## 10. Raw event model (tasarÄ±m â€” migration yok)

Gelecek tablo (Ã¶r. `qr_attendance_events` / `attendance_raw_events`):

| Alan | Not |
|------|-----|
| id | PK |
| personel_id | Server-resolved |
| user_id | Server-resolved |
| sube_id / workplace_id | Tokenâ€™dan |
| event_type | `GIRIS` \| `CIKIS` |
| server_occurred_at | Kanonik zaman |
| qr_token_jti_hash | Replay/audit |
| qr_version | Payload version |
| source | `QR` |
| created_at | Insert time |
| client_* | YalnÄ±z telemetry; business field deÄŸil |

**Yasak:** mutable canonical puantaj; client override timestamp; hard delete.

```
CLIENT_PERSONEL_ID = FORBIDDEN
CLIENT_USER_ID = FORBIDDEN
APPEND_ONLY_RAW_EVENT = YES
HARD_DELETE_RAW_EVENT = NO
```

### Idempotency / replay

Display token **global consume edilmez** (aynÄ± pencerede Ã§ok personel).

```
IDEMPOTENCY_MODEL = user_id + qr_jti + event_type + short client_request_nonce
REPLAY_PROTECTION_MODEL = signature + expiry + jti validity window + per-user idempotency key
```

Rate limit: APIâ€™de mevcut throttle **yok**; S3Câ€™de dar per-user duplicate-scan guard Ã¶nerilir (gerÃ§ek giriÅŸ/Ã§Ä±kÄ±ÅŸÄ± rastgele engellemeden).

### Token payload (min)

`version`, `site/sube id`, `issued_at`, `expires_at`, `jti/nonce` â€” server HMAC/signed; secret plaintext QRâ€™da yok; sequential predictable ID gÃ¼venlik tokenâ€™Ä± deÄŸil.

```
QR_SIGNING_SECRET_OWNER_RECOMMENDATION =
  api config.local.php / env (JWT_SECRET pattern) â€” asla VITE_* / repo commit
  Ã¶nerilen key: qr_signing_secret
```

### Device

```
DEVICE_TRUST_FOUNDATION = NONE (client remember-me = storage seÃ§imi only)
DEVICE_BINDING_REQUIRED_FOR_V1_RECOMMENDATION = NO
  â†’ v1: authenticated session yeterli
```

### Offline

```
OFFLINE_QR_WRITE = NO
UI: "BaÄŸlantÄ± kurulamadÄ±, kayÄ±t oluÅŸmadÄ±."
```

---

## 11. Event â†’ interval â†’ puantaj pipeline

### Mevcut

- Canonical day: `gunluk_puantaj` (tek `giris_saati`/`cikis_saati` Ã§ifti)
- Soft provenance: `kaynak` VARCHAR (Ã¶rn. `BILDIRIM_ETKI_ADAYI`)
- Otomatik kÃ¶prÃ¼: bildirim â†’ aday â†’ `BildirimPuantajEtkiApplyService` (yalnÄ±z `HAZIR`)
- Multi-interval / turnike / work-session tablosu: **YOK**
- Gece taÅŸÄ±ma: FE motorunda computation-only (`cikis < giris` â†’ ertesi gÃ¼n)

### Ã–neri entegrasyon

```
QR raw (append-only)
  â†’ derived intervals (yeniden hesaplanabilir; incomplete allowed)
  â†’ daily attendance candidate / aday (veya olay_karar Ã¶ncesi evidence)
  â†’ existing apply/decision/weekly close
QR_CAN_WRITE_CANONICAL_DIRECTLY = NO
```

`PuantajController::upsert` QR write path deÄŸildir.

Eksik scan: otomatik 8 saat **yok**; interval INCOMPLETE; self-service â€œEksik Ã§Ä±kÄ±ÅŸ kaydÄ±â€; operasyon correction/revision.

GeÃ§/erken / FM / UBGT: Phase B/S1 mevcut motorlar reuse; QR yalnÄ±z actual interval kanÄ±tÄ±.

Cross-midnight / vardiya tablosu yok â†’ **D karar** (CROSS_MIDNIGHT_POLICY_DECISION_REQUIRED).

### Missing scan review

Proven yakÄ±n ownerâ€™lar:

- `puantaj.olay_karar.decide` â†’ **BOLUM_YONETICISI** (geÃ§/erken karar)
- Revizyon `PUANTAJ_GIRIS_CIKIS_DUZELTME` / correction layer â†’ BA create/submit; approve **GENEL_YONETICI**
- Dedicated `EKSIK_OKUTMA` tip: **yok**

```
MISSING_SCAN_REVIEW_OWNER_PROVEN = partial (olay_karar + giris/cikis revizyon)
MISSING_SCAN_DECISION_REQUIRED = YES (QR-specific tip mi, mevcut GIRIS_CIKIS_DUZELTME mi?)
```

---

## 12. Scope / ÅŸube

`SubeScope` personel eriÅŸimini **gÃ¼ncel `personeller.sube_id`** ile doÄŸrular.

GeÃ§ici gÃ¶revlendirme / multi-sube assignment tablosu: **YOK** (`GOREVLENDIRME` yalnÄ±z sÃ¼reÃ§ label).

```
CROSS_BRANCH_SCAN_POLICY_PROVEN = NO (model yok)
CROSS_BRANCH_DECISION_REQUIRED = YES
RECOMMENDED_CROSS_BRANCH_POLICY = DENY
```

Transfer: binding userâ€™da kalÄ±r; QR site check gÃ¼ncel personel ÅŸubesinden; historical event event-time `sube_id` korur.

---

## 13. Retention / legal hold

Phase C kategorileri (`RetentionCategories`): period `PUANTAJ`, â€¦; termination `ISE_GIRIS_CIKIS`, `OLAY`, â€¦

QR raw = attendance evidence â†’ retention dÄ±ÅŸÄ±nda â€œsonsuzâ€ kalamaz.

```
QR_RETENTION_INTEGRATION = S3C ile aynÄ± PRâ€™da kategori baÄŸlama Ã¶nerilir
  (aday: PUANTAJ period-closure veya ISE_GIRIS_CIKIS â€” kesin seÃ§im karar)
QR_RETENTION_DECISION_REQUIRED = YES (kategori + trigger eÅŸlemesi)
LEGAL_HOLD_INTEGRATION =
  LegalHoldService + RetentionSchemaGate fail-closed;
  destruction eligibility personel/domain hold ile QR evidenceâ€™Ä± da kapsamalÄ±
```

Binding audit + QR event audit: yeni append-only / audit owner S3B/S3Câ€™de.

---

## 14. Migration phasing (yalnÄ±z plan)

Collision: tip **055**; next **056+**. 052â€“055 dokunulmaz.

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
| **S3D** | Raw â†’ interval derivation |
| **S3E** | Interval â†’ puantaj candidate/source integration |

Tek mega migration/PR Ã¶nerilmez. Raw attendance evidence productionâ€™a retention owner olmadan Ã§Ä±kmamalÄ±.

`PRODUCTION_BINDING_BACKFILL = NO`
`SELF_REGISTRATION = NO`

Provisioning: YÃ¶netim â†’ user create â†’ PERSONEL â†’ baÄŸlÄ± personel seÃ§ â†’ credentials. Existing login/password reuse; QR login deÄŸildir.

---

## 15. Locked decisions (D1â€“D6) â€” baÄŸlayÄ±cÄ±

S3B decision blockers: **NONE**. QR implementation remains S3C+.

### D1 â€” QR tipi â€” LOCKED

```
D1_QR_MODEL = DYNAMIC_SIGNED
```

### D2 â€” Hareket yÃ¶nÃ¼ â€” LOCKED

```
D2_EVENT_DIRECTION = EXPLICIT_GIRIS_CIKIS
```

Scan â†’ token validate â†’ user explicitly chooses GÄ°RÄ°Å or Ã‡IKIÅ.
AUTO_TOGGLE = NO. TWO_QR = NO.

### D3 â€” BaÅŸka ÅŸube scan â€” LOCKED

```
D3_CROSS_BRANCH = DENY
```

v1: personel may only use their current ÅŸube QR. Temporary assignment model may change this later.

### D4 â€” Ä°ÅŸten ayrÄ±lan / PASIF personel self-service â€” LOCKED

```
D4_TERMINATED_SELF_SERVICE = DENY_ALL
```

`personeller.aktif_durum != AKTIF` â†’ self-service data DENY + future QR DENY.
Binding may remain in DB for audit; no â€œeski Ã§alÄ±ÅŸan portalÄ±â€ in S3.

### D5 â€” Missing scan correction â€” LOCKED

```
D5_MISSING_SCAN_CORRECTION = REUSE_GIRIS_CIKIS_DUZELTME_REVIZYON
```

No parallel correction workflow. S3E may add QR provenance metadata onto existing revision records.

### D6 â€” QR display / TTL â€” LOCKED

```
D6_DISPLAY_MODEL = AUTHENTICATED_KIOSK
D6_TTL_DEFAULT_SECONDS = 60
D6_TTL_CONFIGURABLE_RANGE = 30-120
```

TTL via server config. Secret public rotating URL = NO (v1). Static printed QR = NO (v1).
**S3B does not implement QR code** â€” decisions recorded for S3C only.

---

## 16. Recommended defaults (kullanÄ±cÄ±ya tekrar sorulmayan teknik kilitler)

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
| APP_TIMEZONE | Connectionâ€™da explicit set yok |
| BUSINESS_TIMEZONE | Ad hoc `Europe/Istanbul` (bazÄ± controllerâ€™lar) |
| QR_TIMESTAMP_RECOMMENDATION | `server_occurred_at` UTC store; UI business local (`Europe/Istanbul`) mevcut puantaj gÃ¼n ownerâ€™Ä±na hizalÄ± |

### Camera / PWA QR

| Platform | Strateji |
|----------|----------|
| Android Chrome | `BarcodeDetector` varsa native; yoksa hafif fallback lib (S3C) |
| iOS Safari/PWA | BarcodeDetector desteÄŸi sÄ±nÄ±rlÄ± â†’ fallback lib muhtemel |
| FALLBACK_LIBRARY_REQUIRED | **LIKELY YES** (S3Aâ€™da dependency ekleme yok) |
| MANUAL_TOKEN_ENTRY | NO |
| ACCESSIBILITY_FALLBACK_DECISION_REQUIRED | YES (kart/NFC/turnike out of scope) |

### QR display owner options

- A) Authenticated kiosk session
- B) Public display URL + rotating signed QR; configuration secret URL ile korunur
- Attendance identity displayâ€™de oluÅŸmaz; yalnÄ±z presence token

```
RECOMMENDED_QR_DISPLAY_OWNER = technical config (SISTEM_YONETICISI / yonetim-paneli.manage)
  + display route ayrÄ± (loginâ€™siz token yayÄ±n)
```

---

## 17. Security checklist (S3 implementasyon kapÄ±larÄ±)

- IDOR: `/me` only; arbitrary personel id yok
- Role escalation: self_service.* broad `personeller.*` vermez
- QR endpoint: auth + permission + bound + AKTIF user + AKTIF personel + valid signed QR + expiry + workplace + event type + server time
- No anonymous QR attendance
- No manager-for-employee QR via scan endpoint
- QR token â‰  user JWT

---

## 18. Out of scope (S3A)

Face/fingerprint/GPS/NFC/turnstile; payroll finalization changes; automatic discipline/OT approval; production personel import; self-registration; push; native apps; background location; offline attendance writes; migration files; production writes; merge/deploy.

---

## 19. Evidence index (read-only)

- `api/migrations/001_initial_schema.sql` â€” users, personeller, gunluk_puantaj
- `api/migrations/048_*` â€” actor_identities (non-binding)
- `api/migrations/051_*` â€” varsayilan_sube_id
- `api/migrations/052â€“055` â€” tip; dokunulmaz
- `api/src/Auth/{AuthMiddleware,LoginController,RolePermissions,Jwt}.php`
- `api/src/Controllers/YonetimController.php` â€” `personel_id: null` hardcoded
- `api/src/Scope/SubeScope.php`
- `api/src/Services/BildirimPuantajEtkiApplyService.php`
- `api/src/Services/Retention/*`
- `src/app/routes.tsx` â€” PERSONEL placeholder
- `src/lib/authorization/role-permissions.ts` â€” `PERSONEL: []`
- `docs/guncel/103-s1-canonical-role-consolidation-checkpoint.md`

---

## 20. Stop gate

```
S3A = discovery doc + draft PR + exact-head CI
Implementation / migration 056 / merge / deploy = STOP
NEXT = user review of D1â€“D6 (+ retention category)
```
