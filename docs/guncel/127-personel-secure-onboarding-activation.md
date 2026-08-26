# Personel Secure Account Onboarding & Activation

**Status:** source-implemented / production-not-yet-applied  
**Migration:** `075_personel_account_activation.sql`  
**Owner service:** `PersonelAccountOnboardingService`

## Username policy

| Rule | Value |
|------|--------|
| Canonical source | `personel.sicil_no` (trim) |
| Missing sicil | fail closed `PERSONEL_SICIL_REQUIRED_FOR_ACCOUNT` |
| Collision (other user) | fail closed `PERSONEL_SICIL_USERNAME_COLLISION` |
| Same personnel already bound | `ALREADY_PROVISIONED` (no duplicate) |
| Username source metadata | `users.username_source`: `SICIL_CANONICAL` \| `MANUAL` \| `SYSTEM` |
| New onboarding accounts | `SICIL_CANONICAL` |
| Existing accounts | default `MANUAL` — **no production backfill / reinterpretation** |

Sicil change for a bound `SICIL_CANONICAL` user updates `users.username` atomically in the same transaction as the personel update. Collision fails the entire sicil change. `MANUAL` / `SYSTEM` accounts are never silently renamed.

## Flow (future onboarding only)

1. Yönetici/İK: **Personel Hesabı Oluştur** (no password field; username read-only = sicil)
2. Server creates `PERSONEL` user with unusable random internal credential (hash only; never returned)
3. `activation_required=1`, `must_change_password=1`
4. One-time activation invitation: **SHA-256 hash only** stored; raw token returned once as URL
5. Personnel opens `/personel-aktivasyon#token=…`, chooses password
6. Token consumed; `activation_required=0`, `must_change_password=0`

Admin never chooses or sees the personnel password. They may see the one-time **activation link** once.

## Activation security

- Token entropy ≥ 256 bits (`random_bytes(32)` → hex)
- TTL owner: `medisa_config('personel_activation_ttl_minutes')` (default **1440**)
- Public URL owner: `medisa_config('app_public_url')` (never trust Host header)
- Fragment transport (`#token=`); page clears fragment immediately
- Issue/reissue responses: `Cache-Control: no-store` + `Referrer-Policy: no-referrer`
- Max one live invitation per pending user (transactional revoke + insert)
- Concurrent redeem: row locks → exactly one success

## Existing 133 accounts

Grandfathered. Migration adds columns with safe defaults only:

- does **not** rename usernames, reset passwords, set activation-pending, change roles/bindings, or issue invitations

## DIS_KAYNAK

Same technical onboarding/activation allowed.  
`PersonelMobileCapabilityService` continues to disable business capabilities with:

> Yapım Aşamasındadır. Onay Bekleyen Kapsamlar Tamamlandığında Kullanıma Açılacaktır.

## Generic create bypass

`POST /yonetim/kullanicilar` with `rol=PERSONEL` + `personel_id` → `PERSONEL_USE_SECURE_ONBOARDING`.  
Non-personnel management/system user creation remains unchanged.

## API

| Method | Path | Auth |
|--------|------|------|
| POST | `/yonetim/personeller/{id}/hesap-onboarding` | `yonetim-paneli.manage` |
| POST | `/yonetim/kullanicilar/{id}/aktivasyon-yenile` | `yonetim-paneli.manage` |
| GET | `/yonetim/kullanicilar/{id}/aktivasyon-meta` | `yonetim-paneli.manage` |
| POST | `/auth/personel-activation/status` | public (token) |
| POST | `/auth/personel-activation/complete` | public (token) |

## Production rollout gate

- `PRODUCTION_MIGRATION_APPLY = NO` until explicit ops approval
- Do not bulk reprovision existing active roster
- Do not deploy this source as live until migration + config (`app_public_url`) are ready

## Audit events

`PERSONEL_ACCOUNT_CREATED`, `PERSONEL_ACCOUNT_BOUND`, `ACTIVATION_LINK_ISSUED`, `ACTIVATION_LINK_REISSUED`, `ACTIVATION_COMPLETED`, `ACTIVATION_REVOKED`, `USERNAME_SYNCED_FROM_SICIL`  

Never audit plaintext password, password hash, raw token, or full activation URL.
