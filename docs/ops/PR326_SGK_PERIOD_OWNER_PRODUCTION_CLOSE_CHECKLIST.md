# PR #326 — SGK İşveren Bildirim Dönemi Owner — Production Close Checklist

> **POST_PR402 STATUS: CLOSED_APPLIED (historical checklist).**
> PR #326 MERGED (`8e137f2c`, 2026-09-22). Migration **090** Apply run `35781766535` SUCCESS; migration **091** Apply run `35791415567` SUCCESS.
> Active backlog: `docs/guncel/146-post-pr402-canonical-backlog.md`. Do not re-run this checklist as if pending.

Durum: **CLOSED_APPLIED** (eski “henüz production'a uygulanmadı” ifadesi **STALE**)
Kapsam: 090 (canonical factual owner) + 091 (legacy approved consensus reconciliation)
Son çıktı: `CLOSED_PRODUCTION`

## Sabit referanslar (pre-apply archive)

| Alan | Değer |
| --- | --- |
| Branch | `fix/sgk-isveren-bildirim-donemi-owner` |
| Branch HEAD | `eeade81d5ae1ec06de119be628085138232e8918` |
| main referans (pre-merge) | `cd9c6c7e257a97f5964ebd36abb31bc6c8580ec0` |
| Migration 090 | `api/migrations/090_sgk_isveren_bildirim_donemi_owner.sql` |
| Migration 091 | `api/migrations/091_sgk_isveren_bildirim_donemi_reconcile.sql` |
| Production tip (pre-apply expected) | `089` → **applied to 091** (see CURRENT_STATE) |

## Kullanılacak tooling (owner)

| Tool | Rol |
| --- | --- |
| `.github/workflows/ops-migration-worker-diagnostics.yml` | Salt-okunur envanter + `READ_ONLY_MIGRATION_PREFLIGHT` |
| `.github/workflows/apply-cpanel-migrations.yml` | Tek migration apply talebi (canonical worker) |
| `.github/workflows/deploy-cpanel.yml` | main deploy (build + FTP + smoke) |
| ~~`.github/workflows/final-close.yml`~~ (kaldırıldı) + `scripts/ops/final-close-control.py` | Final close paketi (bu PR kapsamı DIŞI; yalnız atıf) |
| `api/bin/migrate.php` | CLI runner (`--verify`) |
| `api/bin/cpanel-migration-cron.php` | Cron migration worker (backup + apply) |

## LOCAL FIRST notu

- `npm run test:ci-fast`, `npm run typecheck`, `npm run build` **lokal** çalıştırılır; PR #326 merge öncesi kanıt buradan gelir.
- GitHub Actions yalnız **merge / deploy / migration** için tetiklenir. Genel CI, boş commit veya gereksiz `workflow_dispatch` ile tetiklenmez.
- **Billing kilidi aktifken Actions tetiklenmez**; kilit kalkmadan hiçbir adım dispatch edilmez.
- Bu checklist yazılırken hiçbir commit/push/PR/merge/deploy/SQL/apply yapılmadı (COMMIT = NONE).

---

## 1) Read-only production preflight

- **Amaç:** Canlı kontrol düzleminin sağlıklı, worker'ın idle ve deploy SHA'sının beklenen ile aynı olduğunu kanıtlamak; hiçbir yazma yapmadan.
- **Tooling:** `ops-migration-worker-diagnostics.yml` — `mode=READ_ONLY_MIGRATION_PREFLIGHT`, `confirmation` aynı mod adı, `deployed_sha=<canlı main SHA>`.
- **Başarı kriteri:** `PREFLIGHT_RESULT=PASS`, `PREFLIGHT_APPLY_READY=YES`, `WORKER_BUSY=NO`, `PENDING_COUNT=0`, `PROCESSING_COUNT=0`, `HEARTBEAT_DEPLOY_SHA_MATCH=YES`, `REMOTE_WORKER_MATCH=YES`.
- **DURDUR:**
  - `PREFLIGHT_RESULT=BLOCKED` veya `PREFLIGHT_APPLY_READY=NO`
  - `MIGRATION_CONTROL_PLANE_BUSY` / `WORKER_BUSY=YES`
  - `HEARTBEAT_STALE`, `DEPLOY_SHA_MISMATCH`, `REMOTE_WORKER_PARITY_MISMATCH`
  - Billing kilidi açık.

## 2) Production tip = 089 doğrula; 090/091 pending

- **Amaç:** Canlı ledger'ın 089'da olduğunu ve sıradaki iki pending'in tam olarak `090` ve `091` olduğunu doğrulamak.
- **Tooling:** Adım 1'in `preflight.json` çıktısı (`PROD_TIP`, `PENDING_MIGRATIONS`, `LEDGER_GAPS`, `LEDGER_CHECKSUM_MISMATCH`).
- **Başarı kriteri:** `PROD_TIP=089`, `PENDING_MIGRATIONS=090_...,091_...`, `LEDGER_GAPS=NONE`, `LEDGER_CHECKSUM_MISMATCH=NONE`, `PENDING_CHECKSUM` 090 dosyasının sha256'sı ile eşleşir.
- **DURDUR:**
  - `PROD_TIP≠089` (özellikle 090 veya 091 zaten uygulanmışsa — çift apply riski)
  - pending listesinde 090/091 dışında bir sürüm varsa
  - `APPLIED_MIGRATION_MODIFIED` / `MIGRATION_LEDGER_GAP` / `MIGRATION_LEDGER_UNKNOWN_VERSION`

## 3) Live subeler → sgk_isveren mapping doğrula

- **Amaç:** 091'in köprülediği branch → SGK işveren ekseninin canlıda tutarlı olduğunu kanıtlamak.
- **Tooling:** Adım 1 preflight guard'ları: `FLAG_BRANCH_TABLE_RESOLVED`, `GUARD_SUBE_ROWS`, `GUARD_SGK_ISVEREN_ROWS`, `FLAG_USER_SGK_ISVERENLER_TABLE_PRESENT`.
- **Başarı kriteri:** `FLAG_BRANCH_TABLE_RESOLVED=true`, `sube_rows == sube_rows_expected_after_round`, `user_rows == user_rows_expected_after_round`, `user_sube_assignment_rows == ..._expected_after_round`.
- **DURDUR:** `BRANCH_TABLE_UNRESOLVED`, `DATA_GUARD_SUBE_ROW_DELTA`, `DATA_GUARD_ASSIGNMENT_ROW_DELTA`, `DATA_GUARD_USER_ROW_DELTA`.

## 4) Legacy approved period consensus (091 BLOCK koşulları)

- **Amaç:** 091'in insert öncesi A–I kapılarının canlı veride sağlanacağını ön doğrulamak (091 all-or-nothing çalışır).
- **Tooling:** Salt-okunur SQL kanıtı + Adım 1 preflight. Referans kapılar (kaynak: `091_sgk_isveren_bildirim_donemi_reconcile.sql`):
  - A) 090 canonical factual şema/kolon/FK/approval-kolon yokluğu
  - B) her relevant aktif işverenin ≥1 authoritative legacy `ONAYLANDI` satırı
  - C) işveren başına DISTINCT `bildirim_donem_tipi` sayısı tam 1
  - D) türetilen tip canonical legal enum üyesi (`AY_1_SON_GUN` / `AY_15_SONRAKI_AY_14`)
  - E) işveren başına tek distinct geçerlilik aralığı
  - F) aynı işveren için çelişkili overlap satırı yok
  - G) `sube → sgk_isveren` mapping non-null ve valid (orphan yok)
  - H) mevcut canonical satır yok ya da tam uyumlu
  - I) kısmi reconciliation imkânsız (employer sayısı = derived satır sayısı)
- **Başarı kriteri:** Yukarıdaki kapıların tümü canlı veride temiz (091'de `PACK091_BLOCKER` çıkmayacak).
- **DURDUR:** Herhangi bir kapı ihlali → **091 apply ETME**; `PACK091_BLOCKER: ... zero canonical inserts` beklenir. İşveren başına tip/aralık drift'i çözülmeden tekrar denenmez.

## 5) PR #326 merge

- **Amaç:** Doğrulanmış branch'i main'e almak.
- **Tooling:** GitHub PR #326 (owner onayı); lokal kanıt: `git status --short`, `git diff --check`, `npm run test:ci-fast`, `npm run typecheck`, `npm run build`.
- **Başarı kriteri:** PR merge edilir, main yeni SHA'ya ilerler, `090`/`091` main'de yer alır.
- **DURDUR:** Lokal test/typecheck/build kırmızı; branch HEAD beklenen SHA'dan sapmış; billing kilidi açık.

## 6) cPanel deploy (main)

- **Amaç:** main commit'ini production'a (frontend + PHP API + canonical migration bundle) taşımak.
- **Tooling:** `deploy-cpanel.yml` (CI main success `workflow_run` ile otomatik veya gerekirse `workflow_dispatch`).
- **Başarı kriteri:** `FINAL_SHA_GET=SUCCESS`, `FINAL_SHA_PARITY` PASS, `API_UPLOAD_PARITY=SUCCESS`, `REMOTE_BUNDLE_PARITY` PASS, `npm run smoke:live` PASS.
- **DURDUR:** `FTP_READBACK_PREFLIGHT=FAIL`, `REFUSING_BULK_UPLOAD=YES`, `API_FILE_PARITY=FAIL`, `FINAL_SHA_PARITY=FAIL`, smoke FAIL.

## 7) Migration 090 apply

- **Amaç:** Canonical factual employer-period owner tablosunu oluşturmak (additive, veri yazmaz).
- **Tooling:** `apply-cpanel-migrations.yml` — `deployed_sha=<adım 6 SHA>`, `target_migration=090_sgk_isveren_bildirim_donemi_owner.sql`, `confirmation=APPLY_CANONICAL_MIGRATIONS`.
- **Başarı kriteri:** `MIGRATION_GATE_RESULT=PASS`, worker `state=SUCCEEDED`, `MIGRATION_BACKUP_EVIDENCE=VERIFIED` (`backup_readback=VERIFIED`), canonical tablo mevcut, `GATE_TARGET_MIGRATION=090...`.
- **DURDUR:**
  - `TARGET_NOT_NEXT_PENDING`, `NO_PENDING_MIGRATIONS`, `PENDING_CHECKSUM_MISMATCH`
  - `MIGRATION_WORKER_STATE=FAILED` (özellikle `PACK090_BLOCKER: ... canonical factual shape/column/FK drift`)
  - `MIGRATION_BACKUP_EVIDENCE=MISSING`
  - Aynı 090 için ikinci apply talebi açma.

## 8) Migration 091 apply

- **Amaç:** Legacy approved branch policy consensus'unu canonical işveren gerçeğine tek transaction'da, all-or-nothing reconcile etmek.
- **Tooling:** `apply-cpanel-migrations.yml` — `deployed_sha=<adım 6 SHA>`, `target_migration=091_sgk_isveren_bildirim_donemi_reconcile.sql`, `confirmation=APPLY_CANONICAL_MIGRATIONS`.
- **Başarı kriteri:** `MIGRATION_GATE_RESULT=PASS`, worker `state=SUCCEEDED`, `MIGRATION_BACKUP_EVIDENCE=VERIFIED`, `GATE_TARGET_MIGRATION=091...`.
- **DURDUR:**
  - `MIGRATION_WORKER_STATE=FAILED` → `PACK091_BLOCKER` (A–I kapılarından biri); **zero canonical insert** beklenir, kısmi veri yok.
  - `MIGRATION_BACKUP_EVIDENCE=MISSING`, `TARGET_NOT_NEXT_PENDING`, `MIGRATION_CONTROL_PLANE_BUSY`.
  - Başarısız 091 için kör retry yok; önce adım 4 kapıları yeniden incelenir.

## 9) Production postcheck (DOGRULANDI period, fail-closed, İzmir/Sakarya branch-specific YOK)

- **Amaç:** Canonical runtime gerçeğinin beklendiği gibi olduğunu kanıtlamak.
- **Tooling:** Salt-okunur production sorguları + `ops-migration-worker-diagnostics.yml` (`PROD_TIP`, `LEDGER_*`) + uygulama okuma yolu.
- **Başarı kriteri:**
  - `PROD_TIP=091`, `PENDING_MIGRATIONS=NONE`, gap/mismatch yok.
  - Her relevant işveren için **tam olarak bir effective `DOGRULANDI`** satır; reader CONFLICT üretmiyor.
  - Fail-closed davranış: eksik/çelişkili durumda dönem **tahmin edilmiyor**, açıkça fail-closed kalıyor.
  - **İzmir/Sakarya için branch-specific (`sube_id` bazlı) dönem satırı YOK** — dönem yalnız işveren ekseninde.
  - `090` tablosunda approval-workflow kolonu yok (`hazirlayan_id`/`onaylayan_id`/`onay_zamani` = 0).
- **DURDUR:** Çoklu `DOGRULANDI` / CONFLICT, branch-specific dönem satırı tespiti, approval kolonu sızması, dönem tipinin tahmin edildiği herhangi bir okuma yolu.

## 10) CLOSED_PRODUCTION

- **Amaç:** Kanıtla kapatmak.
- **Tooling:** Adım 1–9 çıktılarının toplanması (yalnız bounded/sayısal kanıt; personel/bordro satırı, isim, TCKN, ücret, credential loglanmaz).
- **Başarı kriteri:** Tüm adımlar PASS; `PROD_TIP=091`; `DOGRULANDI` tekilliği; fail-closed; branch-specific dönem yok; deploy SHA ve migration bundle parity kanıtlı.
- **DURDUR:** Herhangi bir adım açık/başarısız kaldıysa `CLOSED_PRODUCTION` yazılmaz; kalan tek adım açıkça belirtilir.

---

## Kapsam dışı (bu checklist ile DOKUNULMAZ)

- Ücret tipi / `MaasHesaplamaEngine`
- Doğu / 125 rollout
- QR yaka
- İzmir–Sakarya A1
- `final-close.yml` paketi (PERSONELMEDISA_FINAL_CLOSE) — workflow kaldırıldı; script/owner atıfı kalır.

## Kısa rapor (bu checklist üretilirken)

- HEAD: `eeade81d5ae1ec06de119be628085138232e8918` (beklenen ile aynı)
- Branch: `fix/sgk-isveren-bildirim-donemi-owner`
- Working tree: dirty **YOK** (`git status -sb` temiz)
- 090: **VAR**, 091: **VAR**
- Checklist path: `docs/ops/PR326_SGK_PERIOD_OWNER_PRODUCTION_CLOSE_CHECKLIST.md`
- COMMIT / PUSH / PR / MERGE / DEPLOY / PRODUCTION SQL / ACTIONS: **NONE**
