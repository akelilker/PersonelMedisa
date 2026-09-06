CODE_MIGRATION_TIP: 080
PRODUCTION_MIGRATION_TIP: 079

# 129 — Organizasyon eşleme sahipleri: salt-okunur envanter + kontrollü ilk mapping

**Paket:** `MG-SIRKET-SUBE-PROD-MAPPING-001`
**Durum:** ilk production mapping **tamamlandı** (apply run `33342644722`);
şirket/şube/SGK ekseni kapalı, çalışma lokasyonları bilinçli **deferred**.
Bu doküman yeni bir apply onayı **değildir**; eski spec yeniden uygulanmaz.

    10|## 0. Root cause

Migration `079_sirket_sube_hiyerarsisi.sql` production'a uygulandı (apply
`33322972259`, postcheck `33323369963`). Şema hazır, veri değil:
`schema_ready = true`, `data_ready = false`. Bu noktada iki sahip eksikti.

**1. Satır bazlı envanter sahibi yoktu.** Canonical salt-okunur production
toplayıcısı `MigrationPreflightReport` yalnız aggregate yayınlar — sayı,
kolon/index adı, versiyon, checksum. Sözleşmesi bilinçli olarak böyledir ve
apply kapıları, worker ve CI bu sözleşmeyi okur. Eşleme kararı ise tam tersini
    20|gerektirir: her şubenin, SGK işvereninin ve çalışma lokasyonunun exact `id`,
`kod`, `ad`, `durum` ve ilişki değeri. Bu satırları migration preflight'ına
eklemek yayınlanmış bir kontratı bozardı.

**2. Mapping mutation sahibi yoktu.** Public yönetim API'si mevcut bir şubeyi
başka şirkete bağlayamaz — ve bağlayamamalıdır:
`OrganizasyonService::updateSube` yalnız `ad`, `durum`, `sgk_isveren_id` yazar,
`rejectPayloadSirketId` payload'da şirket kabul etmez, nested route
`assertBelongsToSirket` ile şubenin zaten o şirkete ait olmasını şart koşar.
`sirket_id` yalnız `createSube` içinde, route bağlamından yazılır. Ayrıca
    30|`sgk_isverenler.sirket_id` ve `calisma_lokasyonlari.sube_id` için API'de hiçbir
write owner yoktur.

Eksik olan şey bir **özellik** değil, bir **operasyon**du: 079'un NULL bıraktığı
ilişkileri onaylı bir karara göre bir kez doldurmak.

## 1. Owner sınırı

| Soru | Owner | Kontrat |
| --- | --- | --- |
| 079 uygulanabilir mi? | `MigrationPreflightReport` | Aggregate-only. **Değişmedi.** |
    40|| Hiyerarşi kullanılabilir mi? | `OrganizasyonSchema` | `schema_ready` / `data_ready`. **Değişmedi.** |
| Production'da hangi satırlar var? | `OrganizationMappingInventoryReport` | **Yeni.** SELECT-only, satır bazlı, checksum'lı. |
| Onaylı karar nedir? | `OrganizationMappingSpec` | **Yeni.** Typed, allowlist, preimage + checksum pin. |
| Kararı kim uygular? | `OrganizationInitialMappingService` | **Yeni.** Operations-only; controller/route/UI yok. |
| Mapping öncesi yedek? | `MigrationBackupService::createForOrganizationMapping` | Mevcut owner genişletildi; paralel dump sistemi kurulmadı. |

Public API davranışı bu pakette **değişmedi**. `/yonetim/subeler` güvenlik kuralı
gevşetilmedi, `rejectPayloadSirketId` ve nested route ownership koruması yerinde,
kullanıcıya UI/API üzerinden şube taşıma yeteneği verilmedi. Bu owner genel bir
re-parent özelliği değildir ve non-null farklı bir şirket ilişkisini değiştirmeyi
    50|reddeder.

## 2. Salt-okunur envanter sahibi

Owner: `api/src/Services/Organizasyon/OrganizationMappingInventoryReport.php`.

Her statement bir `SELECT`'tir; tablo adları bu dosyada literaldir; çağıran
taraftan SQL, tablo adı veya filtre kabul edilmez. Kaynak testi
`INSERT/UPDATE/DELETE/ALTER/CREATE/DROP/TRUNCATE/CALL/REPLACE/GRANT`,
`->exec(` ve `beginTransaction` yokluğunu doğrular.

    60|Yayınlanan alanlar:

- **Şube:** `id`, `kod`, `ad`, `durum`, `sirket_id`, `sgk_isveren_id`,
  `personel_count`, `calisma_lokasyonu_count`, `user_sube_assignment_count`.
- **SGK işvereni:** `id`, `kod`, `ad`, `durum`, `sirket_id`, `personel_count`,
  `linked_branch_ids`, `linked_branch_count`.
- **Çalışma lokasyonu:** `id`, `kod`, `ad`, `durum`, `sube_id`, `personel_count`.
- **Küme kontrolü:** `branch_ids`, `baseline_branch_ids`, `expected_branch_ids`,
  `expected_branch_count`, `id_3_present`, `audited_extension_branch_ids`,
  `unaudited_extension_branch_ids`, `duplicate_extension_audit_branch_ids`,
  `mismatched_extension_audit_branch_ids`, `missing_baseline_branch_ids`,
  `branch_create_audit_ready`, `branch_set_valid`, `unexpected_branch_ids`,
  `missing_branch_ids`, orphan/mismatch sayıları (bkz. §2.2).
- **Scope:** `user_sube_total`, rol bazında `assignment_count`,
    70|  `user_sirket_total`, `user_sgk_isveren_total`.

İlişkiler yalnız kanıtlanabilir relation ID'lerinden türetilir: bir SGK
işvereninin şube listesi `subeler.sgk_isveren_id` üzerinden okunur, isimden
hiçbir ilişki üretilmez.

PII politikası: organizasyon satırı referans verisidir. Personel yalnız
**sayılır**, hiçbir personel kolonu seçilmez; kullanıcı kimliği yayınlanmaz, rol
bazında yalnız atama sayısı raporlanır. Değerlendirilemeyen sayım `0` değil `-1`
döner ve `INVENTORY_COUNT_UNEVALUABLE` blocker'ına dönüşür.

### 2.1 Anonim lokasyon × şube personel matrisi (schema version `2`)

`MG-ORG-INVENTORY-LOCATION-BRANCH-MATRIX-001` ile envanter kontratı genişletildi;
yalnız `OrganizationMappingInventoryReport::SCHEMA_VERSION` `1` → `2` oldu.
Mapping spec ve mapping servisinin schema version'ları **değişmedi**.

Root cause: envanter personel sayılarını şube ekseninde ve lokasyon ekseninde
ayrı ayrı veriyordu. Bunlar marjinal sayılardır; marjinal eşitliği mapping kanıtı
**değildir** — bir lokasyondaki kişilerin tek şubede mi, birkaç şubede mi olduğu
yalnız kesişimden görülür. Bu yüzden `personeller.calisma_lokasyonu_id ×
personeller.sube_id` kesişimi yayınlanır.

| Alan | Kapsam | Kontrat |
| --- | --- | --- |
| `personnel_location_branch_matrix` | yalnız `calisma_lokasyonu_id IS NOT NULL` | `calisma_lokasyonu_id`, nullable `sube_id`, `personel_count`; `GROUP BY calisma_lokasyonu_id, sube_id`; `ORDER BY calisma_lokasyonu_id ASC, sube_id ASC` |
| `personnel_without_location_by_branch` | yalnız `calisma_lokasyonu_id IS NULL` | nullable `sube_id`, `personel_count`; `GROUP BY sube_id`; `ORDER BY sube_id ASC` |

Reconciliation: iki aggregate `personeller` tablosunu "lokasyonu var / yok"
ekseninde tam olarak böler. `personnel_location_matrix_total` +
`personnel_without_location_total` toplamı `row_counts.personeller` ile eşit
olmak zorundadır; eşitse `personnel_matrix_reconciled` true olur, değilse
envanter PASS **vermez** ve `INVENTORY_PERSONNEL_MATRIX_COUNT_MISMATCH`
blocker'ı yayınlanır. Böylece matrisin gerçekten personel tablosunun bir
projeksiyonu olduğu kanıtlanır, varsayılmaz.

PII sınırı: matris yalnız **ilişki ID'leri ve aggregate sayı** taşır. Personel
id'si, ad/soyad, sicil, TC, telefon, IBAN, kullanıcı id/username veya başka
hiçbir personel alanı ne seçilir ne yayınlanır — matris bir kombinasyonda kaç
kişi olduğunu söyler, kimin olduğunu söylemez. Envanter SELECT-only kalır:
mutation statement, `->exec(`, transaction ve caller-supplied SQL/filter yoktur.

Yayın sınırı: matris **log'a basılmaz**. `ops-organization-inventory.yml`
canonical JSON'un tamamını private repository artifact'ı olarak taşır; matris
için `echo`, `jq emit_*` veya step summary çıktısı eklenmedi ve workflow bu
turda değiştirilmedi.

    80|Determinizm: satırlar primary key sırasına göre, JSON recursive key-sorted,
checksum yalnız `data` bölümü üzerinde. `generated_at` bilinçli olarak
checksum'ın **dışındadır**; aksi halde değişmeyen bir veritabanı her okumada yeni
checksum üretir ve hiçbir spec onu pinleyemezdi.

### 2.2 Baseline + audited şube extension modeli (schema version `3`)

`MG-ORG-INVENTORY-AUDITED-BRANCH-EXTENSIONS-001` ile envanter kontratı yeniden
genişletildi; yalnız `OrganizationMappingInventoryReport::SCHEMA_VERSION` `2` →
`3` oldu. Mapping spec ve mapping servisinin schema version'ları ve tamamlanmış
mapping operasyonunun tarihsel 10-şube beklentisi **değişmedi**.

Root cause: envanter, ilk organizasyon mapping turundaki 10 şube ID'sini tüm
gelecek için değişmez izinli liste kabul ediyordu. Canonical audited
branch-create owner ile oluşturulan şubeler (production'da `MDS-IZM` id 12 ve
`MDS-SAK` id 13) bu yüzden `UNEXPECTED_BRANCH_IDS_PRESENT` üretiyordu. Listeye
12 ve 13 eklemek aynı hatayı bir sonraki meşru şubede tekrarlardı, bu yüzden
kural ID listesinden **kanıta** taşındı.

| Sınıf | Kanıt | Sonuç |
| --- | --- | --- |
| Baseline şube (`1,2,4,5,6,7,8,9,10,11`) | migration 079 production postcheck kanıtı | audit gerekmez; **kaybolması blocker** (`DOCUMENTED_BRANCH_IDS_MISSING`) |
| Audited extension | `sube_olusturma_auditleri` içinde **tek** create satırı; `sube_id` ve değişmez `kod` canlı satırla eşleşir; `actor_user_id` mevcut | geçerli; `expected_branch_ids` ve `expected_branch_count` bu şubeyi kapsar |
| Audit'siz extension | kanıt yok | blocker (`UNEXPECTED_BRANCH_IDS_PRESENT`, `BRANCH_SET_NOT_PROVABLE`) |
| Duplicate create audit | aynı şube için >1 satır | blocker (`DUPLICATE_BRANCH_CREATE_AUDIT`) |
| Kimliği uyuşmayan audit | audit `kod` ≠ canlı `kod` | blocker (`BRANCH_CREATE_AUDIT_MISMATCH`) |
| Audit owner okunamıyor + extension var | kanıt değerlendirilemez | blocker (`BRANCH_CREATE_AUDIT_UNREADABLE`) |
| ID 3 | tarihsel olarak yok | audit olsa bile blocker (`UNEXPECTED_BRANCH_ID_3_PRESENT`) |

Yalnız `sube_id` ve `kod` karşılaştırılır: `ad`, `durum`, `sirket_id` ve
`sgk_isveren_id` oluşturmadan sonra meşru şekilde değişebilir, bunları eşitlik
şartı yapmak yasal bir güncellemeyi sahte bir tamper sinyaline çevirirdi. Audit
satırının `gerekce` ve `request_hash` alanları envantere **girmez**.

Şube count beklentisi sabit değildir: `expected_branch_count` =
baseline + doğrulanmış audited extension sayısı. `branch_set_valid` bu dört
koşulun tamamıdır: eksik baseline yok, beklenmeyen ID yok, duplicate audit yok,
kimlik uyuşmazlığı yok ve ID 3 yok. Live şube tarafındaki
şirket/SGK/orphan/mismatch kontrolleri aynen korunur.

GATE 3 sonrası beklenen production değerleri: baseline 10, audited extension
`12,13`, unaudited/duplicate/missing yok, toplam şube 12, `branch_set_valid`
true, envanter PASS, blocker listesi boş.

## 3. Yeni control-plane modu

`api/bin/cpanel-migration-cron.php` artık beş mod tanır:
`APPLY`, `READ_ONLY_PREFLIGHT`, `READ_ONLY_ORGANIZATION_INVENTORY`,
`ORGANIZATION_MAPPING_PREFLIGHT`, `ORGANIZATION_MAPPING_APPLY`.

    90|`READ_ONLY_ORGANIZATION_INVENTORY`:

- exact deploy SHA'ya `hash_equals` ile pinlenir (mevcut ortak kapı),
- worker `flock` idle/concurrency guard'ını kullanır,
- yalnız inventory collector'ı çağırır,
- `organization-inventory.json` yayınlar,
- backup/apply/mutation aşamalarına **girmeden** `exit(0)` yapar,
- migration preflight ve mapping modlarıyla karışmaz,
- request'te SQL veya tablo adı alanı yoktur.

Request idempotent/replay-safe'tir: pending dosya bir kez `processing`'e rename
   100|edilir, sonuç `request.completed.<id>` / `request.failed.<id>` olarak arşivlenir.

Yeni reason code'lar: `ORGANIZATION_INVENTORY_FAILED`,
`ORGANIZATION_MAPPING_PREFLIGHT_FAILED`, `ORGANIZATION_MAPPING_BACKUP_FAILED`,
`ORGANIZATION_MAPPING_APPLY_FAILED`, `ORGANIZATION_MAPPING_POSTCHECK_FAILED`.
`OrganizationMappingFailure` reason'ları generic classifier'a düşürülmez.

## 4. Mapping spec kontratı

Owner: `api/src/Services/Organizasyon/OrganizationMappingSpec.php`.
Production değerleri **kodda değildir**; spec operatörün sağladığı, repo içinde
   110|review edilmiş bir dosyadır (`ops/organization-mapping/*.json`).

Bölümler: `metadata` (authorized deploy SHA, expected production tip, inventory
checksum, inventory generated_at, expected row counts, expected branch IDs,
operation ID), `companies`, `sgk_mappings`, `branch_mappings`,
`location_mappings` (opsiyonel), `preservation`.

Kurallar ve reason code'ları:

- bilinmeyen alan → `SPEC_UNKNOWN_FIELD`,
- duplicate şirket kodu/adı, SGK/şube/lokasyon mapping'i → `SPEC_DUPLICATE_*`,
   120|- eksik/fazla şube kararı → `SPEC_BRANCH_MAPPING_INCOMPLETE` /
  `SPEC_BRANCH_MAPPING_UNEXPECTED`,
- ID 3 → `SPEC_FORBIDDEN_BRANCH_ID`,
- tanımsız şirket referansı → `SPEC_UNKNOWN_COMPANY_REFERENCE`,
- şube ile SGK işvereninin şirketi çelişiyorsa →
  `SPEC_BRANCH_SGK_COMPANY_CONFLICT` (commit sonunda
  `sube_sgk_sirket_mismatch_count` sıfır olmak zorundadır),
- şirket kodları global unique ve immutable; şirket `kod` ile adreslenir, id ile
  değil.

   130|Spec içinde `personel_*`, `user_*`, `tam_ad` ve şube `kod` değişikliği alanı
**yoktur**; `approved_ad` yoksa mevcut ad korunur (ID 7 ve 11 durumu),
`target_sube_id` null ise lokasyon deferred kalır.

Repoda tutulan tek spec dosyası
`tests/fixtures/organization-mapping-spec.test-only.json` fixture'ıdır ve
kodları açıkça test-only'dir. İlk production mapping'i çalıştıran onaylı spec
operatör kaydındadır; gerçek satır preimage'ları, checksum'ı ve row-level
production değerleri bu dokümana taşınmaz.

## 5. Operations-only mapping owner

Owner: `api/src/Services/Organizasyon/OrganizationInitialMappingService.php`.
   140|Controller, route ve UI girişi yoktur; yalnız canonical worker çağırır.

### Preflight

exact deploy SHA (`hash_equals`), production tip exact `079`, pending migration
sayısı `0`, worker idle (control plane), inventory checksum'ın **payload'dan
yeniden hesaplanması**, `schema_ready`, row count ve preservation sayıları,
şube ID kümesi, her şube/SGK/lokasyon satırının exact preimage'ı, şirket kod/ad
çakışması, mismatch üretecek mapping yokluğu. Preflight hiçbir satır yazmaz ve
`spec_checksum` yayınlar; apply aynı checksum'ı taşıyan spec ile çalışır.

   150|### Backup

`MigrationBackupService::createForOrganizationMapping`. Kapsam: `sirketler`,
`subeler`, `sgk_isverenler`, `calisma_lokasyonlari`, `user_subeler`,
`user_sirketler`, `user_sgk_isverenler`, `medisa_schema_migrations` — artı
manifest'te index/FK metadata ve row counts. Webroot dışı, mode 0600, SHA256,
diskten readback. Manifest `operation`, `operation_id`, `authorized_deploy_sha`,
`inventory_checksum`, `spec_checksum` pinler; absolute path yayınlanmaz. Backup
doğrulanmazsa apply **başlamaz** (`MAPPING_BACKUP_EVIDENCE_MISSING`,
`MAPPING_BACKUP_NOT_VERIFIED`).

   160|### Transactional apply

Tek transaction, doğru sırada: şirketler (aynı kod+ad varsa no-op) → SGK
işverenlerinin yalnız NULL `sirket_id` değerleri → şubelerin yalnız NULL
`sirket_id` değerleri → yalnız onaylı short ad değişiklikleri (beklenen ad
guard'lı) → mevcutsa lokasyonların yalnız NULL `sube_id` değerleri → transaction
içi readback → commit.

Aynı gate transaction **içinde tekrar** çalışır (`MAPPING_STATE_CHANGED`): okuma
ile yazma arasında başka bir yazar satırı değiştirdiyse operasyon fail-closed
   170|durur.

Fail-closed kuralları: NULL olmayan ilişki aynı target ise idempotent no-op,
farklı target ise `MAPPING_*_COMPANY_CONFLICT`; mevcut şirket kodu aynı fakat ad
farklıysa `MAPPING_COMPANY_CODE_NAME_CONFLICT`; ad başka kodda kullanılıyorsa
`MAPPING_COMPANY_NAME_TAKEN`; preimage farklıysa `MAPPING_*_PREIMAGE_MISMATCH`;
eksik/beklenmeyen ID `MAPPING_BRANCH_*`; transaction hatasında rollback. Kör
retry yoktur.

### Postcheck

   180|spec checksum, company rows/codes, şube ve SGK şirket eşlemeleri, opsiyonel
lokasyon eşlemeleri, onaylı short adlar, row counts, ID koruması,
personel/user scope koruması, readiness (`schema_ready`, `data_ready`, blocker
sayısı, mismatch sayısı), `unexpected_deltas` ve backup referansı.

### Readiness sözleşmesi

`OrganizasyonSchema` kontratına göre `data_ready` ancak şirket kaydı varsa, **tüm**
şubeler ve **tüm** SGK işverenleri eşlenmişse, orphan yoksa ve
`sube_sgk_sirket_mismatch_count = 0` ise true olur. Çalışma lokasyonunun NULL
   190|kalması blocker **değildir**: deferred lokasyonlar readiness'i bloke etmez.
MariaDB testi bunu doğrudan doğrular (üç lokasyon eşlenmemişken `data_ready` true).

## 6. Workflow ayrımı

| Workflow | Yetki |
| --- | --- |
| `.github/workflows/ops-organization-inventory.yml` | Yalnız envanter. Tek mod, mutation yetkisi yok, mapping request'i yazamaz. Satır verisi log'a değil artifact'a gider. |
| `.github/workflows/apply-organization-mapping.yml` | Preflight veya apply. Explicit confirmation, exact authorized SHA, spec path allowlist'i, inventory checksum pin'i. Apply yolunda zorunlu backup + postcheck. |

### Publication boundary (repository visibility)

Satır verisinin artifact'a gitmesi **tek başına** gizlilik sağlamaz: GitHub
Actions artifact'ı public repository'de signed-in herhangi bir kullanıcı
tarafından indirilebilir. Aynı şekilde mapping spec'i repo içinde review edilmiş
bir dosya olduğu için public repository'de spec'in taşıdığı satır preimage'ları
da açıktır. Bu yüzden "artifact private'dır" mutlak bir iddia **değildir**;
gizlilik tamamen repository visibility'sine bağlıdır.

Her iki workflow da bunu fail-closed uygular. Guard, GitHub'ın canonical
repository context'ini (`github.event.repository.private`) okur — input'la
override edilemez — ve repository private değilse operasyon checkout'tan,
herhangi bir FTP temasından ve control-plane request'inden **önce** durur:

| Workflow | Public repository davranışı | Reason code |
| --- | --- | --- |
| `ops-organization-inventory.yml` | Envanter operasyonu hiç başlamaz; production'dan satır verisi istenmez, artifact upload adımına erişilemez. | `PUBLIC_REPOSITORY_ARTIFACT_EXPOSURE` |
| `apply-organization-mapping.yml` | Mapping operasyonu hiç başlamaz; spec preimage'ları production'a gitmez, evidence artifact yayınlanmaz. | `PUBLIC_REPOSITORY_SPEC_TRANSPORT_UNSAFE` |

Guard yalnız upload adımını atlamaz, tüm operasyonu durdurur. `retention-days`
düşürmek, artifact adını gizlemek veya yalnızca uyarmak güvenlik kontrolü olarak
kabul **edilmez**; artifact upload adımlarının `always()` koşulu da aynı
visibility şartıyla birlikte değerlendirilir, böylece guard fail ettikten sonra
upload çalışmaz. Private repository'de mevcut kontrollü artifact yolu
değişmeden korunur.

Bu repository şu anda public olduğu için her iki workflow da bu guard ile
durur; yeniden ham envanter üretilemez. Bu tur öncesinde `33332497486` deploy
SHA'sı ile üretilmiş envanter artifact'ı public erişime açık olduğu için
**silindi** (GitHub artifact silme geri alınamaz). Envanter satır değerleri ve
checksum'ı repository dokümanına taşınmaz; operatör kaydında kalır.

   200|İkisi de tek `cpanel-canonical-migration-control` concurrency grubunu paylaşır ve
busy guard uygular. Mapping workflow'u şu koşullarda çalışamaz: kod deploy
edilmemişse (`DEPLOY_SHA_MISMATCH`, `REMOTE_WORKER_PARITY_MISMATCH`), envanter
yayınlanmamışsa (`INVENTORY_ARTIFACT_MISSING`) veya spec'in pinlediği checksum
production'daki envanterle eşleşmiyorsa (`INVENTORY_CHECKSUM_MISMATCH`,
`SPEC_INVENTORY_CHECKSUM_MISMATCH`).

Bu turda (matrix kontratı turu) hiçbir workflow dispatch **edilmedi** ve
production'da hiçbir mutation yapılmadı.

## 6.1 İlk production mapping durumu

| Alan | Değer |
| --- | --- |
| Apply run | `33342644722` |
| Sonuç | postcheck PASS, `data_ready` **true** |
| Kapsam | 3 şirket, 10 şube, 3 SGK işvereni eşlendi |
| Çalışma lokasyonu | **APPLIED (catalog)** — canlı 7/7 lokasyon `sube_id` mapped. Tarihsel ilk mapping turunda deferred idi (historical pin only). Personel location NULL fill = ayrı Priority A residual |
| Yeniden apply | **yok** — eski spec yeniden uygulanmaz |

Lokasyon kararı (kayıt): İzmir ve Sakarya çalışma lokasyonları **MEDISA**'ya
aittir; şubeler `12`/`13` oluşturuldu ve catalog mapping **APPLIED**.
Personel `calisma_lokasyonu_id` NULL satırları (16) ayrı remediation / HR kararıdır
(`ops/organization-mapping/a1-a2-a3-no-apply-remediation-plan.json`).

## 7. Recovery

   210|Mapping backup'ı tek başına yeterli restore artifact'ıdır: dump kapsamındaki her
tabloyu `DROP` + `CREATE` + `INSERT` ile preimage'e döndürür.

1. `sha256sum <dump>.sql` çıktısını `<dump>.meta.json` içindeki `sha256` ile
   karşılaştır; eşleşmiyorsa restore'a başlama.
2. Uygulamayı yazma trafiğine kapat, cron worker'ı durdur.
3. `mysql <db> < <dump>.sql`.
4. Readback: her tablonun satır sayısı manifest'teki `row_counts` ile birebir
   eşleşmeli; `sirketler` preimage'de `0` satırdır.
5. `GET /yonetim/organizasyon-readiness` yeniden `schema_ready=true`,
   220|   `data_ready=false` bildirmeli.
6. Ledger tip `079` olmalı; migration 079 **geri alınmaz**.

Rollback hangi koşulda gerekir: apply commit sonrası postcheck `BLOCKED`
dönerse. Apply transaction içinde durduysa restore gerekmez — hiçbir şey
commit edilmemiştir.

## 8. Sonraki gate'ler

- Yeni schema version `3` envanterini salt-okunur çalıştırma → anonim
  lokasyon × şube matrisi + baseline/audited extension sınıflandırması (ayrı
  dispatch onayı ile).
- SGK işvereni → şirket eşlemesinin iş anlamıyla doğrulanması.
   230|- 7 deferred çalışma lokasyonunun matris sonucuna dayalı kararı; İzmir ve
  Sakarya için MEDISA altında şube oluşturma ayrı onay gerektirir.
- Şube kısa adı DB uniqueness hardening (ayrı migration, eşleme sonrası).
- User scope rollout (`user_sirketler`, `user_sgk_isverenler`).
- SGK/bordro sahibi üzerinden yeniden tasarlanmış aylık kapanış işi.
