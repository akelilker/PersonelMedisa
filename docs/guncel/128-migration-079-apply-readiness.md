CODE_MIGRATION_TIP: 079
PRODUCTION_MIGRATION_TIP: 078

# Migration 079 apply readiness — control plane, preflight, backup, recovery

Bu doküman migration `079_aylik_kapanis_sube_scope_and_actor.sql` için kontrol
düzlemi sözleşmesini tanımlar. 079 bu doküman yazıldığında production'a
uygulanmamıştır ve bu doküman apply onayı **değildir**.

## Neden gerekliydi

Önceki preflight turunda 079 APPLY_READY verilemedi, çünkü kontrol düzleminde üç
şey yoktu: production ledger/şema/veri durumunu okuyabilen bir read-only owner,
apply öncesi zorunlu ve doğrulanabilir bir backup owner'ı, ve `aylik_kapanis_state`
üzerindeki unique anahtar dönüşümünün hiçbir anda korumasız kalmadığını garanti
eden bir sıra. Üçü de bu pakette kodda karşılandı.

## 1. Read-only production preflight

Owner: `.github/workflows/ops-migration-worker-diagnostics.yml`, `mode` girişi
`READ_ONLY_MIGRATION_PREFLIGHT`. Workflow FTP'den başka bir şeye erişemez, bu
yüzden DB tarafını yine canonical worker üretir:

1. Workflow kontrol düzlemine `mode: READ_ONLY_PREFLIGHT` taşıyan tek bir
   `request.pending.*` bırakır.
2. `api/bin/cpanel-migration-cron.php` bu modda `PREFLIGHT` stage'ini çalıştırır:
   `MigrationPreflightReport::collect` yalnız `SELECT` çalıştırır, sonucu
   `api/runtime/migration-control/preflight.json` olarak yayınlar. Bu yolda apply,
   backup ve ledger yazımı yoktur.
3. Workflow raporu geri okur ve allowlist'lenmiş alanları basar.

Rapor sözleşmesi: yalnız sayı, kolon/index adı, migration versiyonu, checksum ve
reason code. Personel/bordro satırı, isim, TCKN, ücret, token veya credential
raporlanmaz; `MigrationControlPlanePreflightMysqlTestRunner` bunu fixture
değerlerini raporda arayarak doğrular.

Blocker reason code'ları: `MIGRATION_LEDGER_MISSING`, `MIGRATION_CHECKSUM_MISMATCH`,
`MIGRATION_LEDGER_GAP`, `TARGET_TABLE_MISSING`, `BRANCH_TABLE_UNRESOLVED`,
`CODE_TIP_UNEXPECTED`, `APPLIED_TIP_NOT_078`, `PENDING_NOT_ONLY_079`,
`PENDING_CHECKSUM_UNRESOLVED`, `PREIMAGE_ACTOR_COLUMNS_ALREADY_PRESENT`,
`PREIMAGE_LEGACY_UNIQUE_MISSING`, `STATE_DUPLICATE_AY_BLOCKS_COMPOSITE_KEY`.
Değerlendirilemeyen bir guard `0` değil `-1` döner.

## 2. 079 DDL sırası

Anahtar geçişi şu sırada ve her adımı `information_schema` guard'lı:

1. `aylik_kapanis_state.sube_id INT UNSIGNED NOT NULL DEFAULT 0` eklenir.
2. `uq_aylik_kapanis_state_ay_sube (ay, sube_id)` **eklenir** — legacy
   `UNIQUE(ay)` hâlâ yerinde. Legacy anahtar ay başına en fazla bir satır
   garanti ettiği için `(ay, 0)` çakışması imkânsızdır.
3. Composite anahtarın gerçekten var, gerçekten `NON_UNIQUE = 0` ve gerçekten
   `(ay, sube_id)` üzerinde olduğu assert edilir; değilse `PACK079_BLOCKER` ile
   fail-closed durur.
4. Ancak bundan sonra legacy `UNIQUE(ay)` düşürülür.
5. Altı actor kolonu (hepsi `NULL`, FK'sız) ve `idx_aylik_ozet_bolum_onay_actor`
   eklenir.
6. Kapanış readback'i: 7 kolon + composite unique var, legacy anahtar yok.

En kötü yarıda kalma durumu **iki unique anahtarın birlikte bulunması**dır;
hiçbir anda ikisinin de bulunmadığı pencere yoktur. Rerun bu ara durumdan devam
eder. 079 hiçbir `INSERT/UPDATE/DELETE` içermez: legacy state satırları sentinel
`sube_id = 0` altında birebir korunur, per-şube satırlar uygulama tarafından
sonradan lazy oluşur. Beklenen dönüşüm bu yüzden `COUNT(*) before == after` ve
`COUNT(*) WHERE sube_id <> 0 == 0`'dır.

## 3. Backup owner ve kapsam

Owner: `api/src/Database/MigrationBackupService.php`, worker'da `BACKUP` stage'i
olarak `APPLY`'dan **önce** çalışır. cPanel'de shell ve `mysqldump` yoktur, bu
yüzden uygulanabilir güvenli owner PDO tabanlı dump'tır.

- Kapsam: `aylik_kapanis_state` ve `aylik_ozet_satirlari` (schema + data) artı
  `medisa_schema_migrations` ledger preimage'i. Tam DB dump'ı denenmez: bir cron
  tick'ine sığmaz ve tek migration'ın rollback'i için gereğinden fazla kişisel
  veri kopyalar.
- Konum: webroot **dışı**. `MEDISA_MIGRATION_BACKUP_DIR` verilmezse `public_html`
  ata dizini bulunur ve dump onun kardeşi olan `medisa-migration-backups`
  dizinine yazılır (mode 0700, dosya 0600). Çözülemezse veya konum webroot içine
  düşerse `BACKUP_LOCATION_UNRESOLVED` / `BACKUP_LOCATION_INSIDE_WEBROOT` ile
  fail-closed durur; sessiz fallback yoktur.
- Ad: `medisa-pre-<tip>-<request_id>-<YYYYMMDD-HHMMSS>.sql`.
- Doğrulama: dosya diskten geri okunur, SHA256 karşılaştırılır, üç tablonun
  `CREATE TABLE` bloğu ve `-- rows(<tablo>): <n>` satırı aranır. Biri eksikse
  `BACKUP_READBACK_INCOMPLETE` / `BACKUP_CHECKSUM_MISMATCH`.
- Yayınlanan metadata: dosya adı, boyut, SHA256, tablo listesi, satır sayıları,
  `readback: VERIFIED`. Sunucudaki absolute path yayınlanmaz; dump'ın yanındaki
  `<dump>.meta.json` içinde tutulur.
- Backup bu owner tarafından **hiçbir zaman silinmez**. Apply sonrası doğrulama
  tamamlanana kadar saklanır.

## 4. Exact restore prosedürü

Ön koşul: elde `<dump>.sql` ve `<dump>.meta.json` var.

1. `sha256sum <dump>.sql` çıktısını `meta.json` içindeki `sha256` ile karşılaştır;
   eşleşmiyorsa restore'a başlama.
2. Uygulamayı yazma trafiğine kapat (bakım) ve cron worker'ı durdur, böylece
   restore sırasında yeni kapanış yazımı gelmez.
3. `mysql <db> < <dump>.sql` — dump kapsamındaki üç tabloyu `DROP` + `CREATE` +
   `INSERT` ile preimage'e döndürür (`FOREIGN_KEY_CHECKS` dump içinde yönetilir).
4. Readback: `aylik_kapanis_state` ve `aylik_ozet_satirlari` satır sayıları
   `meta.json` içindeki `row_counts` ile birebir eşleşmeli.
5. Ledger doğrulaması: `medisa_schema_migrations` içinde tip `078` olmalı, `079`
   satırı bulunmamalı.
6. Anahtar doğrulaması: `aylik_kapanis_state` üzerinde `uq_aylik_kapanis_state_ay`
   geri gelmiş, `uq_aylik_kapanis_state_ay_sube` yok olmalı.
7. `php api/bin/migrate.php --verify` beklendiği gibi pending `079` bildirmeli.
8. Bakımı kaldır.

Rollback hangi koşulda uygulanır: 079 readback assert'i ile durduğunda ve rerun da
aynı blocker'ı verdiğinde, veya apply sonrası doğrulamada satır sayısı paritesi
bozulduğunda. Yalnız kısmi DDL kalmışsa (ör. `sube_id` eklenmiş ama composite
anahtar yok) restore gerekmez: 079 idempotenttir, rerun tamamlar.

## 5. Apply kapıları

`.github/workflows/apply-cpanel-migrations.yml` request yazmadan önce şunları
kanıtlar; biri düşerse migration dosyasına dokunmadan reason code ile durur:
`DEPLOY_SHA_MISMATCH`, `HEARTBEAT_DEPLOY_SHA_MISMATCH`, `HEARTBEAT_STALE`,
`REMOTE_WORKER_PARITY_MISMATCH`, `WORKER_BACKUP_STAGE_MISSING`,
`STALE_PROCESSING_REQUEST`, `MIGRATION_CONTROL_PLANE_BUSY`, `WORKER_BUSY`,
`PREFLIGHT_NOT_PASS`, `PREFLIGHT_SHA_MISMATCH`, `PREFLIGHT_STALE`,
`PROD_TIP_UNEXPECTED`, `PENDING_NOT_ONLY_079`, `PENDING_CHECKSUM_MISMATCH`,
`APPLIED_MIGRATION_MODIFIED`, `MIGRATION_LEDGER_GAP`, `PREFLIGHT_BLOCKERS_PRESENT`,
`DATA_GUARD_ORPHAN_SUBE_ROWS`, `DATA_GUARD_DUPLICATE_ROWS`,
`DATA_GUARD_DUPLICATE_STATE_MONTHS`.

`worker.lock` dosyasının varlığı kapı değildir: worker onu hiç silmez, yalnız
`flock` tutar. Busy-ness `request.processing.*`, `status.json` state'i ve
heartbeat tazeliğinden türetilir.

Apply başarıyla dönerse workflow `status.json` içinden backup kanıtını okur
(`MIGRATION_BACKUP_FILE`, `MIGRATION_BACKUP_SHA256`, `MIGRATION_BACKUP_BYTES`,
`MIGRATION_BACKUP_READBACK`); `VERIFIED` değilse `MIGRATION_BACKUP_EVIDENCE=MISSING`
ile fail-closed durur.

## 6. Apply sonrası salt-okunur doğrulamalar

Production tip `079`; 7 kolon + `uq_aylik_kapanis_state_ay_sube` +
`idx_aylik_ozet_bolum_onay_actor` readback'i; `aylik_kapanis_state` satır sayısı
paritesi (`before == after`, `sube_id <> 0` = 0); orphan/duplicate = 0; aylık özet
`GET` 200; personel/API smoke; auth guard 401; boş 500 = 0.

Production'da deneme amaçlı bölüm onayı veya ay kapatma `POST`'u yapılmaz.
Actor/self-approval davranışının işlevsel kanıtı CI'daki MariaDB runner'larından
alınır (`AylikKapanisSubeScopeMysqlTestRunner`,
`MigrationControlPlanePreflightMysqlTestRunner`).
