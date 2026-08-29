CODE_MIGRATION_TIP: 078
PRODUCTION_MIGRATION_TIP: 078

# 110 — Canonical Closure / Gap Registry

**Tür:** Güncel durum kaydı ve sonraki iş seçimi için tek referans.
**Güncelleme:** 2026-08-28 (final master closure reconcile)
**Kapsam:** PersonelMedisa teknik ana sistem kapanışı + kullanıcı-gated kalan işlerin net sınıflandırması. Bu turda uygulama kodu / migration dosyası / seed / personel-assignment-rol-SGK-bordro-retention-imha mutasyonu yok.

## Migration durumu

| Alan | Değer | Kanıt / sınır |
| --- | --- | --- |
| CODE_MIGRATION_TIP | **078** | Repodaki son migration: `078_personel_sicil_sequence.sql` |
| PRODUCTION_MIGRATION_TIP | **078** | `078_personel_sicil_sequence.sql` production'a uygulandı; otomatik sicil sequence owner'ı canlı |
| Otomatik sicil owner | **`PersonelSicilAllocator`** | `api/src/Services/Personel/PersonelSicilAllocator.php` + singleton tablo `personel_sicil_sequence` (migration 078); interaktif create'te `sicil_no` gönderilmez, backend tahsis eder |
| Canlı migration doğrulaması | **PASS** | Tip `078`; pending 079+ yok; migration workflow bu turda **tekrar çalıştırılmaz** |

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
| `MG-OPS-ORG-001` | IC kritik organizasyon FK tamamlama | **CLOSED** | Phase1 import sonrası AKTIF `IC_PERSONEL` için kritik org alanları (Şube/Departman/Bölüm/Birim/Görev/Personel Tipi) tamam; kalan telefon kalemi ayrıdır (`MG-OPS-PERSONEL-PHONE-001`). DIS org null’ları IC sayımına **dahil edilmez**. |
| `MG-OPS-PERSONEL-PHONE-001` | 20 IC telefon deferred tamamlaması | **CLOSED_CONFIRMED** | Gerçek kullanıcı verisi ile canonical write owner (authenticated `PUT /personeller/{id}`) üzerinden tamamlandı; direct SQL / import reopen / migration yok. Preflight `MATCHED_RECORD_COUNT = 20`, `DUPLICATE_SICIL_COUNT = 0`, hepsi AKTIF `IC_PERSONEL`. Post-write salt-okunur readback `PHONE_EXPECTED_MATCH_COUNT = 20`, `PHONE_MISSING_COUNT = 0`, `PHONE_MISMATCH_COUNT = 0`, `PERSONEL_IC_PHONE_DEFERRED = 0`. Kapsam dışı mutasyon yok (`UNEXPECTED_PERSONNEL_MUTATION_COUNT = 0`). Numaralar PII olduğu için dokümana yazılmaz. Ayrıca sicil 216 için kullanıcı onaylı tekil isim düzeltmesi uygulandı (`soyad` correction, fail-closed önceki-değer teyidi ile); başka personelin adına dokunulmadı. |

## Kullanıcı verisi / ataması gerektiren (teknik blocker değil)

| ID | Konu | Durum | Not |
| --- | --- | --- | --- |
| `MG-SUBE-YONETICI-001` | `SUBE_YONETICISI` gerçek kullanıcı ataması | **USER_ASSIGNMENT_REQUIRED** | `users.rol = SUBE_YONETICISI` + `user_subeler` explicit kanıtı; tahmin/atama otomasyonu yok. |

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
| `MG-FSC-025-001` | FSC %25 aktif bandı | **INTENTIONAL_DEFER** | |

## Final sınıflandırma özeti

| Sınıf | Sayım |
| --- | ---: |
| **CLOSED_CONFIRMED** | 10 |
| **CLOSED** | 9 |
| **USER_DATA_REQUIRED** | 0 |
| **USER_ASSIGNMENT_REQUIRED** | 1 |
| **READY_FOR_USER_EXECUTION_APPROVAL** | 0 |
| **TECH_DEBT_NON_BLOCKING** | 0 |
| **FUTURE / OUT_OF_SCOPE / INTENTIONAL_DEFER** | 7 |
| **BUG** | **0** |
| **OPS_ROLLOUT** | **0** |

**TEKNIK_ANA_SISTEM:** **KAPALI**

## Özellikle yanlış önceliklendirilmemesi gerekenler

1. **Serbest zaman 6 ay takibi artık açık code-gap / USER_GATED değildir.** Operasyon modeli kapatıldı (`MG-SZ-6M-001` = `CLOSED`); mevcut rapor/uyarı yüzeyi yeterlidir.
2. **Fiziksel imha artık açık backlog değildir.** `MG-RET-PHYS-001` = `CLOSED_CONFIRMED`: politika (minimum 10 yıl), doğru anchor guard, aktif personel/eksik anchor/legal hold fail-closed davranışı ve typed handler'lar canonicaldır. Bu, bugün veri silindiği anlamına gelmez; süresi dolan adayların mevcut güvenli akıştan geçirilmesi normal işletim işidir.
3. **20 IC telefon kalemi kapanmıştır.** `MG-OPS-PERSONEL-PHONE-001` = `CLOSED_CONFIRMED`; `PERSONEL_IC_PHONE_DEFERRED = 0`. Artık açık `USER_DATA_REQUIRED` kalemi değildir ve yeniden açılmaz. DIS org null’ları IC org sayımına karışmaz.
4. **`DIS_KAYNAK` modeli `CLOSED_CONFIRMED` (076).** Import reopen / migration re-apply yok.
5. **Olağanüstü çalışma acil karar bekleyen açık iş değildir** (`INTENTIONAL_DEFER`).
6. **Bordro PDF / banka / SGK filing / FSC / QR-revizyon UX bug veya OPS_ROLLOUT değildir.**

## Kalan güvenli iş sırası (kullanıcı/ops)

1. `SUBE_YONETICISI` explicit kullanıcı–şube atamaları (iş sahibi kararı).
2. Fiziksel imha yalnız ayrı execution onayı + yedek kanıtı + bakım penceresi ile.

> **Bayat madde kaldırıldı:** DIS bölüm/birim zorunluluk kararı (`130` + `076` rollout) kapanmıştır; import reopen maddesi geçersizdir.

## Referans dokümanlar

- `114-retention-physical-destruction-pack3c-final.md`
- `116-serbest-zaman-pack4b-closure.md`
- `117-final-code-gap-pack5.md`
- `118-production-migration-rollout-059-064.md`
- `120-org-structure-pack6.md`
- `124-personnel-import-open-branch-department.md`
- `130-dis-kaynak-operasyonel-finansal-ayrim.md`
