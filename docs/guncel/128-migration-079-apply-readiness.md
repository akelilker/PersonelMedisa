CODE_MIGRATION_TIP: 079
PRODUCTION_MIGRATION_TIP: 078

# Migration 079 apply readiness — control plane, preflight, backup, recovery

Bu doküman migration `079_sirket_sube_hiyerarsisi.sql` için kontrol düzlemi
sözleşmesini tanımlar. 079 bu doküman yazıldığında production'a uygulanmamıştır
ve bu doküman apply onayı **değildir**.

## 0. Geri çekilen eski 079

079 slotu daha önce `079_aylik_kapanis_sube_scope_and_actor.sql` tarafından
kullanılıyordu. O migration teknik olarak preflight PASS üretmiş olsa da iş
modeli yanlıştı (aylık kapanış kapsamını fiziksel şubeden türetiyordu; doğru
eksen SGK/bordro sahibidir), bu yüzden **geri çekildi**:

- Dosya canonical migration kaynağından (`api/migrations/`) tamamen çıkarıldı.
- Hiçbir koşulda uygulanmaz, deploy edilmez, yeni pakete taşınmaz.
- `MigrationPreflightReport` kaynakta varlığını `WITHDRAWN_079_PRESENT_IN_SOURCE`,
  production şemasında izini `WITHDRAWN_079_STRUCTURE_PRESENT` ile blocker sayar.
- Apply workflow'u ayrıca `WITHDRAWN_079_STILL_IN_SOURCE` /
  `WITHDRAWN_079_PENDING` kapılarıyla durur.

SGK/bordro sahibi üzerinden yeniden tasarlanmış aylık kapanış migration'ı ayrı ve
sonraki bir iştir.

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
raporlanmaz.

Blocker reason code'ları: `MIGRATION_LEDGER_MISSING`, `MIGRATION_CHECKSUM_MISMATCH`,
`MIGRATION_LEDGER_GAP`, `TARGET_TABLE_MISSING`, `BRANCH_TABLE_UNRESOLVED`,
`CODE_TIP_UNEXPECTED`, `APPLIED_TIP_NOT_078`, `PENDING_NOT_ONLY_079`,
`PENDING_NAME_UNEXPECTED`, `PENDING_CHECKSUM_UNRESOLVED`,
`WITHDRAWN_079_PRESENT_IN_SOURCE`, `WITHDRAWN_079_STRUCTURE_PRESENT`,
`PREIMAGE_OWNER_TABLE_MISSING`, `PREIMAGE_RELATION_COLUMN_INCOMPATIBLE`.
Uyarılar: `PREIMAGE_PARTIAL_HIERARCHY_PRESENT`, `SIRKET_ROWS_ALREADY_PRESENT`.
Değerlendirilemeyen bir guard `0` değil `-1` döner.

## 2. 079 DDL sırası

Migration additive'dir; hiçbir mevcut kolonu, anahtarı veya satırı değiştirmez.
Her adım `information_schema` guard'lıdır:

1. Structural guard: `users`, `subeler`, `sgk_isverenler`, `calisma_lokasyonlari`
   var olmalı.
2. Drift guard: `subeler.sirket_id`, `sgk_isverenler.sirket_id`,
   `calisma_lokasyonlari.sube_id` zaten varsa tam olarak nullable `INT UNSIGNED`
   olmalı; değilse `PACK079_BLOCKER` ile fail-closed durur.
3. Drift guard: bu kolonlar üzerindeki mevcut bir FK yanlış parent'a bakıyorsa
   durur.
4. `sirketler` tablosu (`kod`/`ad` unique) oluşturulur; aynı adla uyumsuz bir
   tablo varsa durur.
5. Üç ilişki kolonu + index + FK (`RESTRICT`) eklenir.
6. `user_sirketler`, `user_sgk_isverenler` kapsam tabloları oluşturulur.
7. Readback assert: 3 ilişki kolonu, 3 yeni tablo, 7 FK.

Yarıda kalma durumu uyumlu partial state'tir ve rerun devam eder. 079 hiçbir
`INSERT/UPDATE/DELETE` içermez: şirket seed'i, şube eşlemesi, şube adı değişikliği,
user scope kopyası, personel taşıması ve `tam_ad` kolonu **yoktur**. Beklenen
dönüşüm bu yüzden `COUNT(*) before == after`tır.

### Bilinçli olarak bu migration'da olmayan hardening

Şube kısa adının şirket içinde benzersizliği bu pakette domain/API katmanında
zorlanır. DB seviyesindeki `(sirket_id, normalized(ad))` composite constraint'i
**ayrı bir migration**dır ve ancak production şirket/şube eşlemesi tamamlanıp
doğrulandıktan sonra uygulanabilir; şu anda satırlar eşlenmemiş ve mevcut adlar
tam ad içerdiği için erken uygulanması production verisini riske atardı.

## 3. Backup owner ve kapsam

Owner: `api/src/Database/MigrationBackupService.php`, worker'da `BACKUP` stage'i
olarak `APPLY`'dan **önce** çalışır. cPanel'de shell ve `mysqldump` yoktur, bu
yüzden uygulanabilir güvenli owner PDO tabanlı dump'tır.

- Kapsam: 079'un dokunduğu owner'lar — `subeler`, `sgk_isverenler`,
  `calisma_lokasyonlari`, `user_subeler` (schema + data) artı
  `medisa_schema_migrations` ledger preimage'i. 079'un **oluşturduğu** tablolar
  (`sirketler`, `user_sirketler`, `user_sgk_isverenler`) yedeklenmez: preimage'de
  yoklar, onların rollback'i `DROP`tur. Tam DB dump'ı denenmez.
- Konum: webroot **dışı**. `MEDISA_MIGRATION_BACKUP_DIR` verilmezse `public_html`
  ata dizini bulunur ve dump onun kardeşi olan `medisa-migration-backups`
  dizinine yazılır (mode 0700, dosya 0600). Çözülemezse veya konum webroot içine
  düşerse `BACKUP_LOCATION_UNRESOLVED` / `BACKUP_LOCATION_INSIDE_WEBROOT` ile
  fail-closed durur; sessiz fallback yoktur.
- Ad: `medisa-pre-<tip>-<request_id>-<YYYYMMDD-HHMMSS>.sql`.
- Doğrulama: dosya diskten geri okunur, SHA256 karşılaştırılır, her tablonun
  `CREATE TABLE` bloğu ve `-- rows(<tablo>): <n>` satırı aranır. Biri eksikse
  `BACKUP_READBACK_INCOMPLETE` / `BACKUP_CHECKSUM_MISMATCH`.
- Yayınlanan metadata: dosya adı, boyut, SHA256, tablo listesi, satır sayıları,
  `readback: VERIFIED`. Sunucudaki absolute path yayınlanmaz.
- Backup bu owner tarafından **hiçbir zaman silinmez**.

## 4. Exact restore prosedürü

Ön koşul: elde `<dump>.sql` ve `<dump>.meta.json` var.

1. `sha256sum <dump>.sql` çıktısını `meta.json` içindeki `sha256` ile karşılaştır;
   eşleşmiyorsa restore'a başlama.
2. Uygulamayı yazma trafiğine kapat (bakım) ve cron worker'ı durdur.
3. 079'un oluşturduğu tabloları düşür:
   `DROP TABLE IF EXISTS user_sgk_isverenler, user_sirketler;` ardından ilişki
   FK'leri kalkınca `DROP TABLE IF EXISTS sirketler;`.
4. `mysql <db> < <dump>.sql` — dump kapsamındaki tabloları `DROP` + `CREATE` +
   `INSERT` ile preimage'e döndürür (`FOREIGN_KEY_CHECKS` dump içinde yönetilir).
5. Readback: dump kapsamındaki her tablonun satır sayısı `meta.json` içindeki
   `row_counts` ile birebir eşleşmeli.
6. Ledger doğrulaması: `medisa_schema_migrations` içinde tip `078` olmalı, `079`
   satırı bulunmamalı.
7. Şema doğrulaması: `subeler.sirket_id`, `sgk_isverenler.sirket_id`,
   `calisma_lokasyonlari.sube_id` yok olmalı.
8. `php api/bin/migrate.php --verify` beklendiği gibi pending `079` bildirmeli.
9. Bakımı kaldır.

Rollback hangi koşulda uygulanır: 079 readback assert'i ile durduğunda ve rerun da
aynı blocker'ı verdiğinde. Yalnız kısmi DDL kalmışsa restore gerekmez: 079
idempotenttir, rerun tamamlar.

## 5. Apply kapıları

`.github/workflows/apply-cpanel-migrations.yml` request yazmadan önce şunları
kanıtlar; biri düşerse migration dosyasına dokunmadan reason code ile durur:
`DEPLOY_SHA_MISMATCH`, `HEARTBEAT_DEPLOY_SHA_MISMATCH`, `HEARTBEAT_STALE`,
`REMOTE_WORKER_PARITY_MISMATCH`, `WORKER_BACKUP_STAGE_MISSING`,
`STALE_PROCESSING_REQUEST`, `MIGRATION_CONTROL_PLANE_BUSY`, `WORKER_BUSY`,
`PREFLIGHT_NOT_PASS`, `PREFLIGHT_SHA_MISMATCH`, `PREFLIGHT_STALE`,
`PROD_TIP_UNEXPECTED`, `PENDING_NOT_ONLY_079`, `PENDING_CHECKSUM_MISMATCH`,
`APPLIED_MIGRATION_MODIFIED`, `MIGRATION_LEDGER_GAP`, `PREFLIGHT_BLOCKERS_PRESENT`,
`WITHDRAWN_079_STILL_IN_SOURCE`, `WITHDRAWN_079_PENDING`,
`BRANCH_TABLE_UNRESOLVED`, `DATA_GUARD_SUBE_ROW_DELTA`,
`DATA_GUARD_ASSIGNMENT_ROW_DELTA`.

`worker.lock` dosyasının varlığı kapı değildir: worker onu hiç silmez, yalnız
`flock` tutar. Busy-ness `request.processing.*`, `status.json` state'i ve
heartbeat tazeliğinden türetilir.

Apply başarıyla dönerse workflow `status.json` içinden backup kanıtını okur
(`MIGRATION_BACKUP_FILE`, `MIGRATION_BACKUP_SHA256`, `MIGRATION_BACKUP_BYTES`,
`MIGRATION_BACKUP_READBACK`); `VERIFIED` değilse `MIGRATION_BACKUP_EVIDENCE=MISSING`
ile fail-closed durur.

## 6. Apply sonrası salt-okunur doğrulamalar

Production tip `079`; üç ilişki kolonu + üç yeni tablo + yedi FK readback'i;
`subeler` ve `user_subeler` satır sayısı paritesi (`before == after`);
`sirketler` satır sayısı `0` (migration seed etmez); `GET /yonetim/subeler` 200 ve
legacy davranışta; `GET /yonetim/organizasyon-readiness` `schema_ready=true`,
`data_ready=false` (eşleme henüz yapılmadı); personel/API smoke; auth guard 401;
boş 500 = 0.

Production'da şirket kaydı oluşturma, şube eşleme veya şube adı değiştirme
**yapılmaz**; bunlar ayrı onaylı mapping operasyonudur.
