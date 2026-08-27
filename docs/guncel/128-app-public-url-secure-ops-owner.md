# App public URL secure OPS owner (SOURCE)

**Status:** source-ready / production-not-dispatched  
**Workflow:** `.github/workflows/set-cpanel-app-public-url.yml`  
**Helper:** `scripts/ops/app-public-url-config-ops.php`  
**Remote file:** `public_html/personelmedisa/api/config.local.php` (FTP path hard-lock: `api/config.local.php`)

## Purpose

Provide a narrow, manually authorized GitHub Actions owner that can privacy-safely:

1. **GET** remote `config.local.php` into runner temp only  
2. **PATCH** only `app_public_url`  
3. **PUT** the patched file back via existing FTP/FTPS secrets  
4. **READBACK** only `app_public_url`  
5. **ROLLBACK** from runner-local pre-write backup if upload/readback/integrity fails  

This closes the previous blocker: `SAFE_READBACK_OWNER_NOT_AVAILABLE` / `OPS_EXECUTION_OWNER = NOT_AVAILABLE`.

## Authorization boundary

- Trigger: `workflow_dispatch` only  
- Confirmation must equal `SET_APP_PUBLIC_URL`  
- Input value: exact authorized HTTPS URL (no trailing slash)  
- **No** generic config key input  
- **No** remote path input  
- **No** SSH / cPanel terminal / public config HTTP endpoint  
- Reuses existing `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_PORT` secrets  

## Privacy rules

- Full `config.local.php` is never printed, never artifact-uploaded, never committed  
- Helper stdout emits only `APP_PUBLIC_URL=…` and status flags  
- Sibling secrets (`db_password`, `jwt_secret`, …) stay in runner temp and are deleted on exit  
- Unrelated key delta must remain `0` or the job fails closed (and rolls back after a write)

## Manual dispatch (production — separate approval)

Do **not** run from this SOURCE phase. When explicitly authorized later:

1. Actions → **Set cPanel app_public_url**  
2. `confirmation` = `SET_APP_PUBLIC_URL`  
3. `app_public_url` = `https://www.karmotors.com.tr/personelmedisa`  
4. Confirm readback `READBACK_MATCH=YES` and `UNRELATED_CONFIG_KEYS_CHANGED=0`

## Out of scope

- Production workflow dispatch from this SOURCE commit alone  
- Migration 075 apply  
- Feature merge / deploy  
- Activation link issue  
- User / personel / binding / password mutation  

## Tests

- `tests/unit/app-public-url-secure-ops.source.test.ts` — YAML/security invariants  
- `tests/unit/app-public-url-config-ops.php-runtime.test.ts` — helper get/patch/privacy runtime  
