# 82 — S77-B Ücret Geçmişi ve Mevzuat Parametre Canlı Kabul Checkpoint

---

## 1. Final Karar

S77-B ücret geçmişi ve mevzuat parametre altyapısı schema-first canlıya yayınlandı; CI (MariaDB 11.4 concurrency dahil), Deploy cPanel, canlı rol/scope/overlap/audit/warning kabulü ve S76 veri koruması doğrulandı.

**Final etiket:** `S77_B_FULLY_COMPLETE`

---

## 2. Başlangıç Git

| Alan | Değer |
| --- | --- |
| branch | `main` |
| HEAD | `10802ce782e443e2f2b262be4c419cd5a5275f1e` |
| origin/main | aynı |
| ahead/behind | 0 / 0 |
| working tree | clean |
| diff check | clean |

Başarı kodu: `S77_B_START_GATE_OK`

---

## 3. Owner Analizi (özet)

| Alan | Eski owner | Yeni owner | Karar |
| --- | --- | --- | --- |
| Maaş tutarı | `personeller.maas_tutari` | `personel_ucret_gecmisi` | Legacy korunur; kanonik geçmiş |
| Brüt/net | Belgesel net girişi | `ucret_turu` `BRUT`/`NET` | Create varsayılanı `NET` |
| Geçerlilik | Yok | inclusive başlangıç/bitiş | Çakışma engelli |
| Audit | Yok | `personel_ucret_auditleri` | CREATE/CLOSE/CANCEL/MIGRATE |
| Rol/scope | Response’ta herkese maaş | `personeller.ucret.view/manage` | BA/BY göremez |
| Mevzuat | Yok | `mevzuat_parametreleri` | Seed yok |

Kontrat: `docs/guncel/81-s77-b-ucret-gecmisi-mevzuat-parametre-altyapisi.md`

---

## 4. Legacy Maaş Verisi

- Kolon: `personeller.maas_tutari DECIMAL(12,2) NULL` (canlıda korunuyor)
- Pre-migration: canlı personellerde dolu legacy maaş yoktu (`maas_tutari` null)
- Backfill: yapılmadı (körlemesine dönüşüm yok)
- Compatibility: `resolveSalaryForDate` → history yok + legacy > 0 ise sanal `NET` (`PERSONEL_KAYDI_MIGRASYON`)
- Yazma sonrası `syncLegacySalary` aktif tutarı legacy kolona yansıtır (canlı personel #1 → `25000.50`)

---

## 5. Backup ve Schema-first Migration

| Öğe | Değer |
| --- | --- |
| Database | `karmotor_medisa` |
| Backup dosya | `_s77b_pre_migration_20260717-051618.sql` |
| Lokal konum | `Documents\medisa-s77b-backups` (repo/web root dışı) |
| Boyut | 77.640 bytes |
| SHA-256 | `E1AF04AED2851AFA993CE850067493D19A6D95ED9FA7219D4BE4DB93FAFD5E04` |
| Doğrulama | `CREATE TABLE` (23), `INSERT`, `START TRANSACTION`/`COMMIT`, `maas_tutari` mevcut |
| Sunucu kopyası | FTP sonrası silindi |

| Migration | Dosya | SHA-256 | Sonuç |
| --- | --- | --- | --- |
| 018 | `api/migrations/018_personel_ucret_gecmisi.sql` | `AC21009EAB23C2C4664C7BBA07F80880E2248CA509DFE6ECC957F2BACB01684D` | `personel_ucret_gecmisi` + `personel_ucret_auditleri` |
| 019 | `api/migrations/019_mevzuat_parametreleri.sql` | `2D51BABC6BF59F6F0F7766ED3A24259E01201CD820AE74DF17299B19D1F31D36` | `mevzuat_parametreleri` + `mevzuat_parametre_auditleri` |

Post-migration inventory:

- Tablo sayısı: 23 → 27
- `personeller=4`, `gunluk_puantaj=7`, `puantaj_aylik_muhurleri=1`, `donem_kapanis_auditleri=2`, S75 conflict çözümleri=2
- `personeller_maas_column=true`
- Geçici ops / `.htaccess` rewrite kaldırıldı; orijinal API rewrite restore edildi

Başarı kodu: `S77_B_SCHEMA_FIRST_LIVE_OK`

---

## 6. Commit Zinciri

| SHA | Mesaj |
| --- | --- |
| `cbe7de3` | docs: s77 ucret gecmisi ve mevzuat parametre kontratini kaydet |
| `7a2cbe8` | feat(db): ucret gecmisi ve mevzuat parametre semasini ekle |
| `06fa5ca` | feat(api): ucret gecmisi ve mevzuat parametre servislerini ekle |
| `3404b95` | feat(ui): personel ucret gecmisi ve mevzuat yonetimini ekle |
| `68aa04e` | test: s77 ucret gecmisi rol ve concurrency kapilarini kilitle |

Push: `10802ce..68aa04e` → `origin/main`

---

## 7. Yerel Kapılar

| Kapı | Sonuç |
| --- | --- |
| typecheck | PASS |
| vitest (unit/integration + MariaDB S74/S75/S76/S77) | **1070/1070**, skip 0 |
| build | PASS (önceden kabul edilmiş chunk-size warning) |
| targeted E2E `personel-ucret-gecmisi.spec.ts` | 3/3 PASS |
| full E2E | **286/286 PASS**, skip 0, ~6.2m |

---

## 8. CI / E2E / Deploy

| Workflow | Run | SHA | Sonuç |
| --- | --- | --- | --- |
| CI (Unit + Typecheck + Build, MariaDB 11.4) | [29549615394](https://github.com/akelilker/PersonelMedisa/actions/runs/29549615394) | `68aa04e` | success (`MariaDB ready: 11.4.12`) |
| E2E (workflow_dispatch) | [29549649908](https://github.com/akelilker/PersonelMedisa/actions/runs/29549649908) | `68aa04e` | success |
| Deploy cPanel | [29549670191](https://github.com/akelilker/PersonelMedisa/actions/runs/29549670191) | `68aa04e` | success |

`smoke:live` (`SMOKE_BASE_URL=https://www.karmotors.com.tr`): health/auth guard/frontend/assets OK; bundle `index-CW2A8jbM.js` / `index-B_ovm1yn.css`.

---

## 9. Canlı Kabul

### Ücret geçmişi

- Personel #1: ilk ücret `NET 25000.50` (2026-01-01, açık uçlu) → gelecek ücret `27500.00` (2026-09-01)
- Önceki kayıt otomatik kapatıldı: bitiş `2026-08-31`, `revision_no=2`
- Geçmiş liste 2 kayıt; aktif çözümleme bugün için id=1
- Çakışan aralık ve duplicate gelecek başlangıç → **HTTP 409**
- Legacy `maas_tutari` sync: `25000.5`

### Rol / scope

- `BIRIM_AMIRI` / `BOLUM_YONETICISI` → `/personeller/{id}/ucretler` **403** `SALARY_ACCESS_FORBIDDEN`
- BA personel detail’de pozitif `maas_tutari` yok
- `MUHASEBE` history 200 (2 kayıt)
- Mevzuat: BA **403**, GY boş liste **200** (sentetik parametre seed edilmedi)

### Warning (`FINANCE_SALARY_MISSING`)

- Şube 2 / 2026-07: warning count=3, record_ids `[2,3,4]` (ücret geçmişi yok)
- Şube 1 / 2026-07: salary missing yok (personel #1 çözümlendi)
- Şube 1 / 2025-12: salary missing warning (tarih aralığı dışı)

### Audit / veri koruma

- `donem_kapanis_auditleri` Mart 2026: audit #1 `CLOSE_ATTEMPT_BLOCKED`, #2 `CLOSE_SUCCESS` / `muhur_id=1` korunmuş
- `bildirim_puantaj_etki_cakisma_cozumleri` count=2 korunmuş
- Personel sayısı 4; puantaj/mühür satır sayıları migration öncesi ile uyumlu
- Gerçek mevzuat oranı production’a yazılmadı

### Idempotency

- Aynı gelecek başlangıçla ikinci create → 409 (duplicate ücret yok)
- Local/CI MariaDB S77 concurrency runner delili

---

## 10. Açık Riskler

- Legacy dolu maaşlı personel için otomatik tarihçe backfill yok; ilk ücret kaydında MIGRATE yolu kullanılır.
- Bordro snapshot henüz yok; ileride canlı tablodan okunmamalıdır.
- Mevzuat parametre UI boş state ile kabul edildi; gerçek oranlar kullanıcı doğrulaması olmadan eklenmemelidir.

---

## 11. Final Git (docs commit öncesi zincir)

| Alan | Değer |
| --- | --- |
| branch | `main` |
| ürün HEAD | `68aa04e18dc51857a58b2a6a952a9e831591ab28` |
| origin/main | push sonrası senkron |

---

## 12. Başarı Kodları

```text
S77_B_START_GATE_OK
S77_B_OWNER_CONFIRMED
S77_B_SALARY_AUDIT_CONTRACT_DEFINED
S77_B_LEGAL_PARAMETER_CONTRACT_DEFINED
S77_B_SCHEMA_DESIGN_COMPLETE
S77_B_LEGACY_SALARY_COMPATIBILITY_DEFINED
S77_B_SALARY_DOMAIN_OWNER_COMPLETE
S77_B_API_CONTRACT_COMPLETE
S77_B_ROLE_SCOPE_COMPLETE
S77_B_PERSONNEL_INTEGRATION_COMPLETE
S77_B_FRONTEND_SALARY_HISTORY_COMPLETE
S77_B_LEGAL_PARAMETER_UI_COMPLETE
S77_B_FINANCE_SALARY_WARNING_REOWNED
S77_B_MARIADB_CONCURRENCY_OK
S77_B_UNIT_INTEGRATION_GATE_OK
S77_B_E2E_GATE_OK
S77_B_MOCK_LIVE_PARITY_OK
S77_B_MIGRATION_TEST_GATE_OK
S77_B_FULL_REGRESSION_GATE_OK
S77_B_ALL_CONCURRENCY_RUNNERS_OK
S77_B_LOCAL_DOCS_COMPLETE
S77_B_COMMIT_CHAIN_READY
S77_B_PRE_PUSH_GATE_OK
S77_B_SCHEMA_FIRST_LIVE_OK
S77_B_CI_GATE_OK
S77_B_DEPLOY_GATE_OK
S77_B_LIVE_SALARY_HISTORY_OK
S77_B_LIVE_ROLE_SCOPE_OK
S77_B_LIVE_AUDIT_OK
S77_B_LIVE_WARNING_OK
S77_B_LIVE_DATA_PRESERVATION_OK
S77_B_LIVE_IDEMPOTENCY_OK
S77_B_FINAL_DOCS_COMPLETE
S77_B_FULLY_COMPLETE
```
