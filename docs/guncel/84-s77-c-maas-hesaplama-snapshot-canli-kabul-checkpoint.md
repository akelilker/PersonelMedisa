# 84 — S77-C Maaş Hesaplama Snapshot Canlı Kabul Checkpoint

---

## 1. Final Karar

S77-C mühürlü puantajdan maaş hesaplama preflight’ı, kanonik kaynak çözümleme ve değişmez girdi snapshot hattı schema-first canlıya yayınlandı. CI (MariaDB 11.4 concurrency dahil), Deploy cPanel, E2E, canlı blocked/success/idempotency/immutability/rol-scope kabulü ve S74–S77-B veri koruması doğrulandı.

**Bu faz maaş hesaplamaz**; yalnız hesap girdilerini dondurur.

**Final etiket:** `S77_C_FULLY_COMPLETE`

---

## 2. Başlangıç Git

| Alan | Değer |
| --- | --- |
| branch | `main` |
| HEAD | `96665ed8ff5b570efd16250bc61b3b21a3b7df15` |
| origin/main | aynı |
| ahead/behind | 0 / 0 |
| working tree | clean |
| diff check | clean |

Başarı kodu: `S77_C_START_GATE_OK`

---

## 3. Owner Analizi (özet)

| Alan | Owner | Karar |
| --- | --- | --- |
| Ücret | `personel_ucret_gecmisi` / `PersonelUcretService::resolveSalaryForDate` | Dönem kesişiminde segment coverage |
| Mevzuat | `mevzuat_parametreleri` / `MevzuatParametreService` | Boş set → warning (S77-C); seed yok |
| Mühür | `puantaj_aylik_muhurleri` + dönem kilidi | Snapshot yalnız mühürlü dönemden |
| Finans/etki | `ek_odeme_kesinti` + S75 çözüm kayıtları | Çözülmemiş aday blocker |
| Snapshot | `MaasHesaplamaSnapshotService` | Tek domain owner |
| UI | Raporlar → `?panel=maas-hesaplama` | Ana menü 3 buton kuralı korunur |
| Legacy maaş | `personeller.maas_tutari` | Kanonik değil; compatibility warning |

Başarı kodu: `S77_C_OWNER_MAP_CONFIRMED`

Kontrat: `docs/guncel/83-s77-c-maas-hesaplama-snapshot-kontrati.md`

---

## 4. Domain Sınırı

**İçerir:** dönem/şube, mühür, personel kimlik/organizasyon, istihdam kesişimi, ücret segmentleri, mühürlü puantaj, kesinleşmiş etki adayları, ek ödeme/kesinti, mevcut mevzuat parametreleri, hash/fingerprint, audit.

**İçermez:** brüt/net, SGK, vergi, damga, işsizlik, ödenecek toplam, bordro PDF/Excel, banka dosyası.

Hesap motoru: S77-D kapsamı; bu fazda yok.

---

## 5. Backup ve Schema-first Migration

| Öğe | Değer |
| --- | --- |
| Database | `karmotor_medisa` |
| Backup dosya | `_s77c_pre_migration_20260717-074024.sql` |
| Lokal konum | `Documents\medisa-s77c-backups` (repo/web root dışı) |
| Boyut | 106.329 bytes |
| SHA-256 | `FF56D0978100E67EB46DA8BBFD3249CF422F2EE4FB0FC2F810D380017BA9551A` |
| Doğrulama | `CREATE TABLE`, `INSERT INTO`, `START TRANSACTION`/`COMMIT`, `karmotor_medisa` |
| Sunucu kopyası | FTP sonrası silindi |

| Migration | Dosya | SHA-256 | İçerik |
| --- | --- | --- | --- |
| 020 | `api/migrations/020_maas_hesaplama_snapshotlari.sql` | `AEFDC26D701314CF67D607E2DF08C79F5B19F6A12A07627D15BF00458751916F` | 4 snapshot tablosu |
| 021 | `api/migrations/021_maas_hesaplama_snapshot_guvenlik_indexleri.sql` | `03C5E74256AA933CBC4F555F4157907450A149A146D5EFC223CE5DDF611B2150` | index + 8 immutability trigger |

Post-migration inventory (pre-snapshot):

- Tablo: 27 → 31
- `personeller=4`, `gunluk_puantaj=7`, `puantaj_aylik_muhurleri=1`, `donem_kapanis_auditleri=2`, S75 conflict=2
- `personel_ucret_gecmisi=2`, `personel_ucret_auditleri=3`, `mevzuat_parametreleri=0`
- Snapshot tabloları boş (0)
- Mart 2026 mühür: `id=1`, `sube_id=1`, `muhurlenen_kayit_sayisi=2`
- Trigger: 8 (`trg_mhps_*`, `trg_mhgs_*`, `trg_mhds_*`, `trg_mhsa_*`)

Geçici ops / `.htaccess` rewrite kaldırıldı; orijinal API rewrite restore edildi.

Başarı kodu: `S77_C_SCHEMA_FIRST_LIVE_OK`

---

## 6. Commit Zinciri

| SHA | Mesaj | Kapsam |
| --- | --- | --- |
| `8da0301` | docs: s77 maas hesaplama snapshot kontratini kaydet | docs 83 |
| `75f6e7b` | feat(db): maas hesaplama snapshot semasini ekle | migration 020/021 |
| `96099bb` | feat(api): maas hesaplama preflight ve snapshot servislerini ekle | backend/API/rol |
| `7aa531b` | feat(ui): maas hesaplama merkezini ekle | frontend + mock |
| `3e7773a` | test: s77 snapshot rol idempotency ve concurrency kapilarini kilitle | unit/E2E/concurrency |
| `0e26982` | test: s77 snapshot sonrasi migration sira kontratinı guncelle | migration sıra |

Push: `96665ed..0e26982` → `origin/main`

---

## 7. Yerel Kapılar

| Kapı | Sonuç |
| --- | --- |
| typecheck | PASS |
| vitest (unit/integration + MariaDB S74/S75/S76/S77-B/S77-C) | **116 files / 1075 tests**, skip 0 |
| build | PASS |
| targeted E2E `maas-hesaplama-merkezi.spec.ts` | 4/4 PASS |
| full E2E | **290/290 PASS**, skip 0 |

Başarı kodları: `S77_C_FULL_REGRESSION_GATE_OK`, `S77_C_LOCAL_IMPLEMENTATION_COMPLETE`, `S77_C_RELEASE_CANDIDATE_READY`

---

## 8. CI / E2E / Deploy

| Workflow | Run | SHA | Sonuç |
| --- | --- | --- | --- |
| CI (Unit + Typecheck + Build, MariaDB 11.4) | [29563859554](https://github.com/akelilker/PersonelMedisa/actions/runs/29563859554) | `0e26982` | success — 116 files / 1075 tests |
| E2E (workflow_dispatch) | [29563966104](https://github.com/akelilker/PersonelMedisa/actions/runs/29563966104) | `0e26982` | success — **290 passed** (7.2m), skip 0 |
| Deploy cPanel | [29563938916](https://github.com/akelilker/PersonelMedisa/actions/runs/29563938916) | `0e26982` | success |

`smoke:live` (`SMOKE_BASE_URL=https://www.karmotors.com.tr`): health/auth guard/frontend/assets OK; bundle `index-BbmdkGFM.js` / `index-B_ovm1yn.css` (bundle içinde `maas-hesaplama` var).

Başarı kodları: `S77_C_CI_GATE_OK`, `S77_C_E2E_REMOTE_GATE_OK`, `S77_C_DEPLOY_GATE_OK`

---

## 9. Canlı Blocked Preflight Kabulü

| Öğe | Değer |
| --- | --- |
| Dönem | Şube 1 / 2026-04 (mühürsüz) |
| Preflight | 200, `blocker_count=1`, `PERIOD_NOT_SEALED`, `snapshot_olusturulabilir_mi=false` |
| POST create | 409 `PAYROLL_PERIOD_NOT_SEALED` |
| Tekrar create | 409 aynı kod |
| Blocked audit | tek `PREFLIGHT_BLOCKED` / `BLOCKED` (duplicate blocked audit yok) |
| Snapshot child | oluşmadı |
| Business mutation | yok (yeni eksik veri üretilmedi) |

Başarı kodları: `S77_C_LIVE_BLOCKED_PREFLIGHT_OK`, `S77_C_LIVE_BLOCKED_AUDIT_OK`

---

## 10. Canlı Başarılı Snapshot Kabulü

| Öğe | Değer |
| --- | --- |
| Fixture | Şube 1 / Merkez / 2026-03 / mühür `#1` |
| Preflight | 200, blocker 0, warning 1 (`LEGAL_PARAMETER_SET_EMPTY`) |
| Create | 201, `snapshot_id=1`, `revision_no=1` |
| Personel | 1 (`Ayse Yilmaz`, personel_id=1) |
| Girdi | 6 — `UCRET:1`, `PUANTAJ:2`, `ETKI_ADAYI:2`, `MUHUR:1` |
| Puantaj kaynak_id | `1`, `2` (mühür `muhurlenen_kayit_sayisi=2` ile uyumlu) |
| Etki adayı kaynak_id | `6`, `7` (S75 fixture ile uyumlu) |
| source_hash | `5b19724c38a22144a4c2c5fa5be45b81c2d8a5eadecac79ac69ac1524e8c5245` |
| snapshot_hash | `0ec67db7834c2f4a1afa3869927bc06041f707a099af1c64bcac74e99f38c7ee` |
| Hash doğrulama | `hash_dogrulama.dogrulandi=true` |
| Success audit | `SNAPSHOT_CREATE` / `CREATED` |

Başarı kodları: `S77_C_LIVE_SNAPSHOT_CREATE_OK`, `S77_C_LIVE_SOURCE_HASH_OK`, `S77_C_LIVE_AUDIT_OK`

---

## 11. Idempotency ve Immutability

### Idempotency

- Aynı preflight/source ile ikinci create → 200, aynı `snapshot_id=1`, aynı `snapshot_hash`
- Audit: `SNAPSHOT_CREATE_IDEMPOTENT` / `EXISTING`
- Duplicate child row yok (`personel_snaps=1`, `girdi_snaps=6`)

### Immutability (transaction + rollback)

| Deneme | Sonuç |
| --- | --- |
| personel snapshot UPDATE | 45000 `PAYROLL_SNAPSHOT_IMMUTABLE` |
| personel snapshot DELETE | 45000 engellendi |
| girdi snapshot UPDATE | 45000 engellendi |
| girdi snapshot DELETE | 45000 engellendi |
| root alan UPDATE (state dışı) | 45000 yalnız `OLUSTURULDU→IPTAL` |
| API PUT/PATCH edit route | 404 `NOT_FOUND` |
| post hash | değişmedi |

Başarı kodları: `S77_C_LIVE_IDEMPOTENCY_OK`, `S77_C_LIVE_IMMUTABILITY_OK`

---

## 12. Canlı Rol / Scope

| Rol | Sonuç |
| --- | --- |
| MUHASEBE | preflight/list/detail 200 |
| GENEL_YONETICI | preflight/create/detail/audit 200 |
| BIRIM_AMIRI | preflight 403 `PAYROLL_ACCESS_FORBIDDEN`; detail ID bypass 403 |
| BOLUM_YONETICISI | preflight 403 |
| Muhasebe yabancı şube (`sube_id=999`) | 403 |

Başarı kodu: `S77_C_LIVE_ROLE_SCOPE_OK`

---

## 13. Regresyon Koruma

Post-snapshot inventory:

| Tablo | Count |
| --- | --- |
| personeller | 4 |
| gunluk_puantaj | 7 |
| puantaj_aylik_muhurleri | 1 |
| donem_kapanis_auditleri | 2 |
| bildirim_puantaj_etki_cakisma_cozumleri | 2 |
| personel_ucret_gecmisi | 2 |
| personel_ucret_auditleri | 3 |
| Mart 2026 mühür id | 1 (korundu) |

Smoke: health OK, dönem kapanış preflight 200, `/personeller/1/ucretler` 200 (2 kayıt), mevzuat 200, frontend bundle yeni.

Başarı kodu: `S77_C_LIVE_REGRESSION_SMOKE_OK`

---

## 14. Açık Riskler

1. Mevzuat parametre seti canlıda boş — S77-C warning; S77-D hesap motorunda blocker olacak.
2. Snapshot personel kümesi mühürlü puantaj kesişimine bağlı; Mart fixture’ta 1 personel (beklenen fixture ölçeği).
3. Stale `expected_preflight_hash`, aktif snapshot + aynı `source_hash` varken idempotent `EXISTING` döner (source-first idempotency; TOCTOU stale kontrolü ilk create yolunda).
4. Canlı `gunluk_puantaj` kaynak id’leri `1/2` (görev metnindeki `#6/#7` ifade etki adayları için geçerlidir; puantaj satır id’leri DB’de `1/2`).

---

## 15. Final Git

| Alan | Değer |
| --- | --- |
| branch | `main` |
| HEAD (implementation) | `0e26982948f65ac9da8b24b3a272faa83244ae42` |
| origin/main | aynı (docs commit sonrası güncellenir) |
| working tree | docs commit öncesi clean |

---

## 16. Başarı Kodları

```text
S77_C_START_GATE_OK
S77_C_OWNER_MAP_CONFIRMED
S77_C_SCHEMA_DESIGN_COMPLETE
S77_C_IMMUTABILITY_CONTRACT_COMPLETE
S77_C_PERSONNEL_RESOLUTION_COMPLETE
S77_C_SALARY_SEGMENT_RESOLUTION_COMPLETE
S77_C_SEALED_ATTENDANCE_RESOLUTION_COMPLETE
S77_C_FINANCE_SOURCE_RESOLUTION_COMPLETE
S77_C_LEGAL_PARAMETER_SNAPSHOT_COMPLETE
S77_C_SOURCE_FINGERPRINT_COMPLETE
S77_C_SNAPSHOT_DOMAIN_OWNER_COMPLETE
S77_C_IDEMPOTENCY_REVISION_CONTRACT_COMPLETE
S77_C_API_CONTRACT_COMPLETE
S77_C_ROLE_SCOPE_COMPLETE
S77_C_FRONTEND_CENTER_COMPLETE
S77_C_MOCK_LIVE_PARITY_OK
S77_C_AUDIT_PRIVACY_COMPLETE
S77_C_IMMUTABILITY_GATE_OK
S77_C_MARIADB_CONCURRENCY_OK
S77_C_ALL_CONCURRENCY_RUNNERS_OK
S77_C_UNIT_INTEGRATION_GATE_OK
S77_C_E2E_GATE_OK
S77_C_QUERY_PERFORMANCE_GATE_OK
S77_C_COMMIT_CHAIN_READY
S77_C_FULL_REGRESSION_GATE_OK
S77_C_LOCAL_IMPLEMENTATION_COMPLETE
S77_C_RELEASE_CANDIDATE_READY
S77_C_PRE_PUSH_GATE_OK
S77_C_SCHEMA_FIRST_LIVE_OK
S77_C_CI_GATE_OK
S77_C_E2E_REMOTE_GATE_OK
S77_C_DEPLOY_GATE_OK
S77_C_LIVE_BLOCKED_PREFLIGHT_OK
S77_C_LIVE_BLOCKED_AUDIT_OK
S77_C_LIVE_SNAPSHOT_CREATE_OK
S77_C_LIVE_SOURCE_HASH_OK
S77_C_LIVE_AUDIT_OK
S77_C_LIVE_IDEMPOTENCY_OK
S77_C_LIVE_IMMUTABILITY_OK
S77_C_LIVE_ROLE_SCOPE_OK
S77_C_LIVE_REGRESSION_SMOKE_OK
S77_C_FINAL_DOCS_COMPLETE
S77_C_FULLY_COMPLETE
```
