CODE_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_TIP: 087

# 110 — Canonical Closure / Gap Registry

**Tür:** Güncel durum kaydı ve sonraki iş seçimi için tek referans.
**Güncelleme:** 2026-09-07 (A1/A2/A3 evidence lock + doc reconcile; PRODUCTION_MUTATION=0)
**Kapsam:** PersonelMedisa teknik ana sistem kapanışı + kullanıcı-gated kalan işlerin net sınıflandırması. Bu turda uygulama kodu / migration apply / personel-assignment-rol-SGK-bordro-retention-imha mutasyonu yok.

## Migration durumu

| Alan | Değer | Kanıt / sınır |
| --- | --- | --- |
| CODE_MIGRATION_TIP | **087** | Repodaki son migration: `087_sube_muhasebe_yetkilileri.sql` — şube bazlı muhasebe görünürlük ACL (`sube_muhasebe_yetkilileri`); relation presence = restriction enabled; additive, data write/backfill yok; idempotent |
| PRODUCTION_MIGRATION_TIP | **087** | 087 production applied (apply run `34033315991`); live tip=087; pending=EMPTY (inventory `orginv-34057486092-1`, preflight PROD_TIP=087) |
| Migration 084 | **APPLIED** | Dosya: `api/migrations/084_gunluk_bildirim_tamamlama_header_summary.sql`. Durum: production applied |
| Migration 085 | **APPLIED** | Dosya: `api/migrations/085_gunluk_bildirim_duzeltme_auditleri.sql`. Durum: production applied |
| Migration 086 | **APPLIED** | Dosya: `api/migrations/086_personel_historical_exit_date_correction_auditleri.sql`. Durum: production applied (apply run `33993971923`) |
| Migration 087 | **APPLIED** | Dosya: `api/migrations/087_sube_muhasebe_yetkilileri.sql`. Durum: production applied; ACL seed rows intentional empty (restriction DISABLED) |
| Organizasyon eşleme durumu | **CLOSED_CONFIRMED** | İlk mapping apply `33342644722` (tarihsel: o turda 10 şube + lokasyon deferred). Canlı: **12 şube**; Medisa work-location catalog mapping **APPLIED** (7/7); Medisa approved user grants **APPLIED**. Karyapı/Şenay company rollout **INTENTIONAL_DEFER**. Display owner = `SubeReadModel` |
| `MATRIX_DEVELOPMENT_BASELINE_SHA` | **`067692bba744808c06b6b7d802797c453859df53`** | Değişmez tarihsel kanıt (ilk mapping apply pin). Güncel live deploy SHA değildir — live = `fcee67186f1ceaec86624ecbe745f51f56330bcd` |
| Otomatik sicil owner | **`PersonelSicilAllocator`** | `api/src/Services/Personel/PersonelSicilAllocator.php` + singleton tablo `personel_sicil_sequence` (migration 078) |
| Canlı migration doğrulaması | **PASS @ 087** | Production tip `087`; pending EMPTY |

## Durum sözlüğü

| Durum | Anlamı |
| --- | --- |
| **CLOSED** | Karar ve kod owner’ı tamam; bu registry kapsamında yeni kod işi yok |
| **CLOSED_CONFIRMED** | Canlı operasyon da doğrulanmış kapanış; yeniden import/apply açılmaz |
| **USER_DATA_REQUIRED** | Gerçek kullanıcı/master-data girdisi gerekir; teknik blocker değildir |
| **USER_ASSIGNMENT_REQUIRED** | Rol/şube/kullanıcı ataması iş sahibi kararı bekler; teknik blocker değildir |
| **READY_FOR_USER_EXECUTION_APPROVAL** | Kod/şema/manifest/guard hazır; yalnız ayrı kullanıcı onayı ile çalıştırılır (destructive ops) |
| **TECH_DEBT_NON_BLOCKING** | Ürün teslimini engellemeyen bakım işi |
| **FUTURE** | Bilinçli sonraki ürün çıktısı; bug / production blocker değildir |
| **OUT_OF_SCOPE** | Ürün kapsamı dışında bırakılmıştır |
| **INTENTIONAL_DEFER** | Bilinçli olarak ertelenmiştir; hata veya yarım kod değildir |
| **BUG** | Deterministik teknik hata (bu registry final sayımında **0**) |
| **OPS_ROLLOUT** | Kod/şema hazır, canlı operasyon aktivasyonu ayrı (final sayımında **0**) |

> Tarihsel terimler (`USER_GATED`, `OPS_ROLLOUT`, `DECISION_REQUIRED`, `USER_GATED_DATA_COMPLETION`) eski pack satırlarında kalabilir; **aktif final açık işler** yukarıdaki net sınıfları kullanır.

## Kapanan teknik başlıklar

| ID | Konu | Durum | Owner / kanıt |
| --- | --- | --- | --- |
| `MG-OT-YEAR-POL-001` | Yıl değişen fazla çalışma 270 saat politikası | **CLOSED** | `ROLLING_12_MONTH_ACTUAL_DATE_V1`. Bkz. `117-final-code-gap-pack5.md`. |
| `MG-OT-YEAR-PATH-001` | 270 saat limitinin tek teknik owner’ı | **CLOSED** | `FazlaCalismaYillikLimitService`. |
| `MG-SZ-6M-001` | Serbest zaman 6 ay deadline + operasyon sahipliği | **CLOSED** | Takip sahibi: `IK_SORUMLUSU`. Operasyonel sorumlu: `BIRIM_AMIRI` / `BOLUM_YONETICISI`. Eskalasyon: `GENEL_YONETICI`. `SerbestZamanDeadlineService`, `GET /serbest-zaman/deadline-takip`, `SerbestZamanTakipPage`, 30 gün uyarı; payroll hard block **yok**. Bkz. `116-serbest-zaman-pack4b-closure.md`. |
| `MG-ORG-ATTR-001` | Bölüm / Birim / Pozisyon canonical personel modeli | **CLOSED** | Bkz. `120-org-structure-pack6.md`. |
| `MG-IMPORT-MAP-001` | Şube-Departman import kontratı | **CLOSED** | Bkz. `124-personnel-import-open-branch-department.md`. |
| `MG-OPS-QR-001` | QR attendance teknik pipeline | **CLOSED** | Ürün kodu tamam. |
| `MG-MIG-071-076` | Org hierarchy … DIS geçici görevlendirme şeması | **CLOSED** | Production migration ucu `076`; `071`–`076` uygulanmıştır. |
| `MG-OPS-PERSONEL-001` | Canonical personel import rollout (Phase1 IC + Phase2 DIS) | **CLOSED_CONFIRMED** | 122 IC + 11 DIS inserted; import reopen yok. |
| `MG-EXT-ORG-DATA-001` | Dış kaynak import org/görev kararları (Phase2) | **CLOSED_CONFIRMED** | 11 DIS production apply; terminated exclusion kilitli. |
| `MG-OPS-DIS-ORG-COMPLETE-001` | DIS bölüm/birim completeness | **CLOSED** | Karar (130): DIS permanent org opsiyonel; otomatik assignment yok. |
| `MG-OPS-DIS-OPS-MODEL-001` | DIS operasyonel/non-financial + geçici görevlendirme | **CLOSED_CONFIRMED** | Migration `076` production applied; SGK/payroll/banka fail-closed. |
| `MG-RET-PHYS-001` | Saklama politikası + fiziksel imha mekanizması | **CLOSED_CONFIRMED** | `RETENTION_POLICY = MINIMUM_10_YEARS`; `SHORTER_CATEGORY_RETENTION = DISABLED`; personel-bağlı anchor = işten ayrılış tarihi veya daha geç uygulanabilir anchor; aktif personel imhası **PROHIBITED**; eksik anchor **FAIL_CLOSED**; legal hold override **ENABLED**; daha uzun mevzuat süresi kısaltılmaz. 15/15 typed handler; schema `059`–`064` production ready; GM çift kontrol + plan hash + idempotency korunur; feature flag varsayılan **OFF**. Bkz. `114-retention-physical-destruction-pack3c-final.md`, `118-production-migration-rollout-059-064.md`. Süresi dolan adayların mevcut request/approval/execution akışından geçirilmesi normal işletim işidir; açık backlog değildir. |
| `MG-OPS-SGK-CAT-001` | SGK canlı katalog/politika salt-okunur doğrulaması | **CLOSED_CONFIRMED** | 2026-08-27 canlı GET doğrulandı. |
| `MG-OPS-UBGT-001` | UBGT canlı takvim/projeksiyon salt-okunur doğrulaması | **CLOSED_CONFIRMED** | 2026-08-27 canlı GET doğrulandı. |
| `MG-ROLE-ENUM-DEBT-001` | Legacy `users.rol` ENUM şema daraltması | **CLOSED_CONFIRMED** | Kod tarafı kapalı: legacy alias yok, authorization fail-closed, legacy rol seçilemez. Şema daraltması `077_legacy_role_enum_shrink.sql` production'a uygulandı (apply `33169230032` / request `33169230032-1` / worker `SUCCEEDED`); migration'ın kendi guard'ları legacy rol atanmış kullanıcı **0** ve canonical ENUM readback assert'i ile korunmuştur. `LEGACY_ROLE_ENUM_SCHEMA_COUNT = 0`. Bkz. `docs/guncel/131-legacy-role-enum-canonical-cleanup.md`. |
| `MG-OPS-CRON-WORKER-001` | cPanel migration worker kontrol düzlemi | **CLOSED_CONFIRMED** | Canlı `api/bin/cpanel-migration-cron.php`, salt-okunur envanter workflow'unun geçici wrapper'ı olarak kalmış ve require ettiği `impl` dosyası sunucuda bulunmadığı için her cron tick'i PHP fatal ile bitiyordu. Wrapper'ı yazan workflow kaldırıldı, worker canonical hale getirildi, kalıcı `worker-heartbeat.json` eklendi ve apply workflow'unun fail-open busy guard'ı dizin listelemesine çevrildi. |
| `MG-SIRKET-SUBE-PROD-MAPPING-001` | Şirket / şube / SGK ilk production eşlemesi | **CLOSED_CONFIRMED** | Apply run `33342644722`, deploy SHA `067692bba744808c06b6b7d802797c453859df53`; 3 şirket + 10 şube + 3 SGK eşlendi, postcheck PASS, `data_ready = true`, backup verified. 7 çalışma lokasyonu deferred; ID 3 oluşturulmadı; personel ve user scope satırları byte-identical korundu. Bkz. `129-organization-mapping-owners.md`. |
| `MG-ORG-INVENTORY-LOCATION-BRANCH-MATRIX-001` | Envanterde anonim lokasyon × şube personel matrisi | **CLOSED** | `OrganizationMappingInventoryReport` schema version `2`: `personnel_location_branch_matrix` + `personnel_without_location_by_branch`, deterministic GROUP BY/ORDER BY, `personnel_matrix_reconciled` guard'ı ve `INVENTORY_PERSONNEL_MATRIX_COUNT_MISMATCH` blocker'ı. Yalnız ilişki ID'si + COUNT yayınlanır; matris log'a değil private artifact'a gider. Mapping/spec owner davranışı değişmedi. |
| `MG-ORG-INVENTORY-AUDITED-BRANCH-EXTENSIONS-001` | Envanterin baseline dışı meşru şubeleri tanıması | **CLOSED** | `OrganizationMappingInventoryReport` schema version `3`: 079 şube ID kümesi artık izinli liste değil **tarihsel baseline**; baseline dışı şube ancak `sube_olusturma_auditleri` içinde tekil, kimliği eşleşen canonical create audit'i varsa geçerli audited extension olur. Yeni alanlar: `baseline_branch_ids`, `audited_extension_branch_ids`, `unaudited_extension_branch_ids`, `duplicate_extension_audit_branch_ids`, `mismatched_extension_audit_branch_ids`, `missing_baseline_branch_ids`, `expected_branch_count`, `branch_set_valid`. Baseline kaybı, audit'siz extension, duplicate/uyuşmayan audit ve ID 3 blocker olarak kalır; şirket/SGK/orphan/mismatch guard'ları ve tamamlanmış mapping spec beklentisi değişmedi. |
| `MG-CI-ACTIONS-NODE24-001` | GitHub Actions Node 24 runtime temizliği | **CLOSED** | Control-plane workflow'ları `actions/checkout@v6` ve `actions/upload-artifact@v6` ile pinli; v4/v5 kullanımı kaynak testiyle yasaklı. Açık deprecation kalemi yok. |
| `MG-OPS-ORG-001` | IC kritik organizasyon FK tamamlama | **CLOSED** | Phase1 import sonrası AKTIF `IC_PERSONEL` için kritik org alanları (Şube/Departman/Bölüm/Birim/Görev/Personel Tipi) tamam; kalan telefon kalemi ayrıdır (`MG-OPS-PERSONEL-PHONE-001`). DIS org null’ları IC sayımına **dahil edilmez**. |
| `MG-OPS-PERSONEL-PHONE-001` | 20 IC telefon deferred tamamlaması | **CLOSED_CONFIRMED** | Gerçek kullanıcı verisi ile canonical write owner (authenticated `PUT /personeller/{id}`) üzerinden tamamlandı; direct SQL / import reopen / migration yok. Preflight `MATCHED_RECORD_COUNT = 20`, `DUPLICATE_SICIL_COUNT = 0`, hepsi AKTIF `IC_PERSONEL`. Post-write salt-okunur readback `PHONE_EXPECTED_MATCH_COUNT = 20`, `PHONE_MISSING_COUNT = 0`, `PHONE_MISMATCH_COUNT = 0`, `PERSONEL_IC_PHONE_DEFERRED = 0`. Kapsam dışı mutasyon yok (`UNEXPECTED_PERSONNEL_MUTATION_COUNT = 0`). Numaralar PII olduğu için dokümana yazılmaz. Ayrıca sicil 216 için kullanıcı onaylı tekil isim düzeltmesi uygulandı (`soyad` correction, fail-closed önceki-değer teyidi ile); başka personelin adına dokunulmadı. |
| `MG-PERSONNEL-BULK-RECONCILIATION-PRODUCTION` | Onaylı bulk lifecycle reconciliation (production) | **CLOSED_CONFIRMED** | Deploy SHA `06bbe03` apply; canlı 2026-09-03: **153 toplam / 144 aktif / 9 pasif**. 202 + 208 artık PASIF/archive; `cikis_tarihi` hâlâ NULL → ayrı `DATA_REQUIRED` exit-date remediation (bulk reopen yok). |
| `MG-PERSONNEL-BULK-POSTCHECK-DYNAMIC-CONTRACT-001` | Bulk lifecycle postcheck dinamik contract | **CLOSED** | Merged PR #234; `PersonelLifecycleBulkPostcheck` dinamik contract canlı. |
| `MG-PERSONNEL-BULK-DRY-RUN-APPLY-PARITY-001` | Bulk dry-run / apply org hierarchy parity | **CLOSED** | Merged PR #232; `PersonelLifecycleBulkMutationPlanner` canlı. |
| `MG-PAYROLL-SGK-INTEGRITY-001` | Canonical payroll SGK / same-company integrity | **CLOSED_CONFIRMED** | Merged PR #242; `PersonelSgkCompanyConsistency`; aktif IC missing SGK = 0. personel_id=1 PASIF+null exit residual ayrı data gate. |
| `MG-FINAL-NONVISUAL-CLOSEOUT-001` | QR hariç son geniş teknik kapanış envanteri | **CLOSED** | Live deploy `a17da8a`; product-code MUST_FIX = 0; kalan = data/ops + optional/future + QR deferred. |

## Kullanıcı verisi / ataması gerektiren (teknik blocker değil)

| ID | Konu | Durum | Not |
| --- | --- | --- | --- |
| `MG-SUBE-YONETICI-001` | `SUBE_YONETICISI` gerçek kullanıcı ataması | **USER_ASSIGNMENT_REQUIRED** (PARTIAL) / Priority B | Canlıda 2 AKTIF: `381` → sube 4; `040` → sube 2. Medisa eksik: 1,5,6,12,13. Data cleanup ile karıştırılmaz. |
| `MG-PERSONNEL-POST-BULK-ACCOUNT-014` | Bulk sonrası 14 AKTIF personel için PERSONEL hesabı | **INTENTIONAL_DEFER** (Priority C) | Exact ids 213–226; historical 136 reopen yok; QR/self-service late-phase. |
| `MG-PERSONNEL-EXIT-DATE-202-208` | 202/208 historical exit dates | **CLOSED_CONFIRMED** | Historical correction applied; reopen yok. |
| `MG-MANAGER-ALPER-SUNGUR` | Alper Sungur uygulama kullanıcısı | **USER_DATA_REQUIRED** | Production’da eşleşen user yok; kaynak olmadan hesap yaratılmaz. |
| `MG-SGK-PERIOD-SUBE-12-13` | İzmir/Sakarya SGK bildirim dönemi | **OPS_ROLLOUT_ACTIVE** / PRODUCTION_CONFIG / **READY_FOR_EXPLICIT_PRODUCTION_APPROVAL** | Runtime currently `NO_APPROVED_POLICY` for 12/13, but existing owner `SgkSirketPolitikaWriteService` import→submit→approve (dual-control) can create explicit `AY_1_SON_GUN`+`ONAYLANDI`. NOT a TECHNICAL_GAP. NO-APPLY targets in `a1-a2-a3-no-apply-remediation-plan.json`. |
| `MG-PERSONNEL-NULL-LOCATION-016` | `calisma_lokasyonu_id` NULL | **DATA + BUSINESS** (Priority A) | Exact 16. AUTO=2 (160,211 → loc 5) NO-APPLY plan; 14 = HR_BUSINESS_DECISION_REQUIRED. |
| `MG-PERSONNEL-NULL-BRANCH-001` | `sube_id` NULL | **BUSINESS_DECISION_REQUIRED** (Priority A) | Exact 1: personel_id 212. CAN_APPLY=NO. |

## Teknik borç (non-blocking)

Açık teknik borç kalemi yoktur.

## Bilinçli ertelenen / kapsam dışı / future (bug değil)

| ID | Konu | Durum | Not |
| --- | --- | --- | --- |
| `MG-EXC-WORK-001` | Zorunlu / olağanüstü çalışma istisna modeli | **INTENTIONAL_DEFER** | Kullanıcı kararı: sonraya bırakıldı; acil açık iş değildir. |
| `MG-PAY-PDF-001` | Bordro PDF çıktısı | **FUTURE** | |
| `MG-BANK-FILE-001` | Banka ödeme dosyası | **FUTURE** | |
| `MG-SGK-BILDIRGE-001` | SGK bildirgesi çıktısı | **FUTURE** | |
| `MG-SELF-PAY-001` | PERSONEL maaş/bordro self-view | **OUT_OF_SCOPE** | |
| `MG-QR-REV-UX-001` | QR anomaly → kontrollü revizyon UX | **INTENTIONAL_DEFER** | |
| `MG-ORG-LOCATION-BRANCH-MAP-001` | 7 çalışma lokasyonunun şube eşlemesi | **CLOSED_CONFIRMED** (catalog) | Canlıda 7/7 lokasyon `sube_id` mapped (inventory orphan_lokasyon=0). Personel `calisma_lokasyonu_id` fill ayrı (`MG-PERSONNEL-NULL-LOCATION-016`). Tarihsel: ilk mapping turunda deferred idi — live truth değildir. |
| `MG-FSC-025-001` | FSC %25 aktif bandı | **INTENTIONAL_DEFER** | |

## Final sınıflandırma özeti

| Sınıf | Sayım |
| --- | ---: |
| **CLOSED_CONFIRMED** | 13 |
| **CLOSED** | 15 |
| **USER_DATA_REQUIRED** | 3 |
| **USER_ASSIGNMENT_REQUIRED** | 1 (PARTIAL) |
| **READY_FOR_USER_EXECUTION_APPROVAL** | 0 |
| **TECH_DEBT_NON_BLOCKING** | 0 |
| **FUTURE / OUT_OF_SCOPE / INTENTIONAL_DEFER** | 8 |
| **BUG** | **0** |
| **OPS_ROLLOUT** | **0** |
| **MUST_FIX_BEFORE_UI_POLISH** | **0** |

**TEKNIK_ANA_SISTEM:** **KAPALI**
**UI_POLISH_READY (QR hariç):** **YES** (data/ops + optional/future kalabilir)

## Özellikle yanlış önceliklendirilmemesi gerekenler

1. **Serbest zaman 6 ay takibi artık açık code-gap / USER_GATED değildir.** Operasyon modeli kapatıldı (`MG-SZ-6M-001` = `CLOSED`); mevcut rapor/uyarı yüzeyi yeterlidir.
2. **Fiziksel imha artık açık backlog değildir.** `MG-RET-PHYS-001` = `CLOSED_CONFIRMED`: politika (minimum 10 yıl), doğru anchor guard, aktif personel/eksik anchor/legal hold fail-closed davranışı ve typed handler'lar canonicaldır. Bu, bugün veri silindiği anlamına gelmez; süresi dolan adayların mevcut güvenli akıştan geçirilmesi normal işletim işidir.
3. **20 IC telefon kalemi kapanmıştır.** `MG-OPS-PERSONEL-PHONE-001` = `CLOSED_CONFIRMED`; `PERSONEL_IC_PHONE_DEFERRED = 0`. Artık açık `USER_DATA_REQUIRED` kalemi değildir ve yeniden açılmaz. DIS org null’ları IC org sayımına karışmaz.
4. **`DIS_KAYNAK` modeli `CLOSED_CONFIRMED` (076).** Import reopen / migration re-apply yok.
5. **Olağanüstü çalışma acil karar bekleyen açık iş değildir** (`INTENTIONAL_DEFER`).
6. **Bordro PDF / banka / SGK filing / FSC / QR-revizyon UX bug veya OPS_ROLLOUT değildir.**
7. **Organizasyon eşlemesi ve Medisa lokasyon katalog mapping kapanmıştır.** İlk şirket/şube/SGK mapping + sonraki Medisa location→branch apply live; eski deferred-location dili bayattır. Kalan Priority A = SGK period 12/13 + personel location/branch residuals (bkz. `CURRENT_STATE.md`, `ops/organization-mapping/a1-a2-a3-no-apply-remediation-plan.json`).
8. **202/208 exit-date residual kapanmıştır** — Priority listesine girmez.
9. **PR #271 / migration 087 / Medisa grants kapanmıştır** — yeni contradiction olmadan reopen yok.

## Kalan güvenli iş sırası (kullanıcı/ops)

**Priority A**
1. Medisa sube 12/13 SGK period: explicit dual-control import/submit/approve `AY_1_SON_GUN` (OPS_ROLLOUT_ACTIVE — existing owner; explicit production approval required).
2. Personel location remediation: 2 AUTO candidate (160, 211) ayrı apply onayı; 14 HR kararı.
3. Personel 212 `sube_id` (+ location) HR kararı (no auto target).

**Priority B**
4. `SUBE_YONETICISI` remaining Medisa branches (1,5,6,12,13).
5. Cross-company location/branch pairs 120/158/219 = DEFERRED_REVIEW (axes independent; not DATA_CONTRADICTION).

**Priority C**
6. Post-bulk PERSONEL accounts 213–226 / QR self-service INTENTIONAL_DEFER.
7. Karyapı / Şenay company rollout INTENTIONAL_DEFER.
8. Fiziksel imha yalnız ayrı execution onayı + yedek kanıtı + bakım penceresi ile.

> **Bayat madde kaldırıldı:** DIS bölüm/birim zorunluluk kararı (`130` + `076` rollout) kapanmıştır; import reopen maddesi geçersizdir.

## Referans dokümanlar

- `114-retention-physical-destruction-pack3c-final.md`
- `116-serbest-zaman-pack4b-closure.md`
- `117-final-code-gap-pack5.md`
- `118-production-migration-rollout-059-064.md`
- `120-org-structure-pack6.md`
- `124-personnel-import-open-branch-department.md`
- `130-dis-kaynak-operasyonel-finansal-ayrim.md`
