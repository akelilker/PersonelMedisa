# 80 — S76-C Canlı Dönem Kapanış Kabul ve CI MariaDB Provisioning Kapanış Checkpoint

---

## 1. Final Karar

S76 dönem kapanış merkezi canlıya schema-first yayınlanmış, GitHub CI MariaDB runtime blokajı S76-C1 ile kök sebebinden çözülmüş ve Mart 2026 kontrollü fixture üzerinde canlı kabul (blocked-close, başarılı mühür, idempotency, etki adayı raporu, CSV, audit bütünlüğü, regresyon smoke) tamamlanmıştır.

**Final etiket:** `S76_FULLY_COMPLETE`

---

## 2. İlk S76-C Migration ve Push Özeti

- Pre-migration canlı backup: `_s76c_pre_migration_20260716-234210.sql` (67.182 bytes, 22 CREATE TABLE, 19 INSERT bloğu, `START TRANSACTION`/`COMMIT` mevcut), repo dışında `Documents\medisa-s76c-backups` altında saklanır.
- Migration 016 (`donem_kapanis_auditleri`, SHA-256 `c7b04a87913ff1f2d9ae97d7038ec19ad84969bb69d8311e580d2c524f90ea91`) ve 017 (6 index, SHA-256 `1b73013b05627d62a4379d6ae52a0df272f6cd86b4552b5f0f60a16f7aa27b5f`) canlı `karmotor_medisa` veritabanına additive uygulandı (tablo 22 → 23, business row count değişimi yok).
- 4 S76 commit'i (`6256418`, `790be6f`, `0fc807e`, `11d8141`) `origin/main`'e push edildi.

## 3. Önceki CI Blokajı ve Kök Sebep

- Başarısız run: CI `29533362550`, job `Unit + Typecheck + Build` (Ubuntu 24.04), step `Run unit and integration tests`.
- Hata: `Error: MariaDB data directory is missing and install helper was not found` (`tests/scripts/disposable-mariadb.mjs`).
- Kök sebep: Disposable MariaDB helper'ı yalnız Windows/local binary provisioning'i biliyor; Ubuntu runner'da MariaDB binary'si ve install helper yok. Helper zaten `MEDISA_TEST_MYSQL_DSN` tanımlıysa external instance'a bağlanıp local provisioning'i atlıyordu; CI'da bu env hiç verilmiyordu.
- Etki: 3 gerçek MariaDB concurrency suite (`donem-kapanis-concurrency`, `bildirim-puantaj-etki-conflict-resolution-mysql`, `puantaj-donem-kilidi-mysql`) `beforeAll`'da fail etti; deploy `workflow_run` kapısı açılmadı (`29533422820` skipped).

## 4. S76-C1 Çözüm Mimarisi

Tek dosya değişti: `.github/workflows/ci.yml` (+51 satır). Ürün kodu, test assertion'ı, harness ve migration'lara dokunulmadı; `disposable-mariadb.mjs` mevcut external-DSN kontratı yeterli olduğundan değiştirilmedi.

| Bileşen | Değer |
|---|---|
| Service image | `mariadb:11.4` (sabit major/minor; canlı kabul logunda `11.4.12-MariaDB-ubu2404`) |
| Port | `3306:3306` (host job → `127.0.0.1:3306`) |
| Health check | `healthcheck.sh --connect --innodb_initialized` (interval 10s, timeout 5s, retries 12, start-period 30s) |
| DSN | `MEDISA_TEST_MYSQL_DSN=mysql:host=127.0.0.1;port=3306;dbname=medisa_ci;charset=utf8mb4` |
| User | `MEDISA_TEST_MYSQL_USER=root` (yalnız CI'a özgü test parolası; secret değil, production credential değil) |
| Readiness | `Setup PHP` (8.3 + pdo_mysql) sonrası PDO ile `SELECT VERSION()` / `SELECT 1` retry döngüsü; parola loglanmıyor |
| İzolasyon | Her runner kendi database'ini oluşturur/drop eder: `medisa_s74_period_lock_test`, `medisa_s75_conflict_resolution_test`, `medisa_s76_period_close_test` (utf8mb4_unicode_ci); root grant CREATE/DROP DATABASE kontratını karşılar |
| Windows | Env tanımsızken mevcut disposable local MariaDB 12.3 davranışı aynen korunur |

## 5. S76-C1 Doğrulama Sonuçları

- Commit: `6a3e851` — `ci: mariadb concurrency testlerini ubuntu runnerda calistir`
- Yerel (Windows, disposable MariaDB 12.3): typecheck PASS, vitest **1048/1048** (S74/S75/S76 MariaDB concurrency gerçek koştu, skip 0), build PASS.
- Yeni CI run `29534802677` (`6a3e851`, Ubuntu): MariaDB readiness `MariaDB ready: version=11.4.12-MariaDB-ubu2404 select1=1`, typecheck PASS, **105 dosya / 1048 test passed, skip 0**, üç MariaDB suite gerçek bağlantıyla çalıştı, build PASS, conclusion **success**.
- E2E run `29534996992` (`6a3e851`): **283/283 passed, skip 0, retry 0**, conclusion success.
- Deploy cPanel run `29534878572` (`workflow_run`, `6a3e851`): upload + health check PASS, conclusion **success**. Canlı bundle `index-DIszJTJC.js` / `index-B_ovm1yn.css`; `smoke:live` OK.

## 6. Canlı Migration Yeniden Doğrulaması (Mutation'sız)

- Migration 016/017 tekrar çalıştırılmadı, rollback yapılmadı.
- `donem_kapanis_auditleri` canlıda işler durumda (audit list endpoint 200; blocked/success audit yazımı çalıştı).
- Preflight/rapor endpoint'leri 017 indexleriyle 200 döndü; business row count'lar korunmuş doğrulandı (personel 4, bildirim 9, aday #6/#7, S75 conflict audit 2, kabul öncesi mühür 0).

## 7. Canlı Kabul — Rol ve Scope

- `GENEL_YONETICI` (user 1): preflight/items/CSV/audit/rapor tüm yüzeyler 200.
- `BIRIM_AMIRI` (user 3, şube 1): kendi scope preflight ve etki adayı raporu 200; `sube_id=2` denemesi **403** (`Secili sube icin yetkiniz yok.`); preflight CSV export **403** (export yetkisi yok).

## 8. Canlı Kabul — Blocked Close

- Fixture: Şube 1 / MRK, dönem 2026-03; puantaj #6 (2026-03-02) ve #7 (2026-03-03) `kontrol_durumu=BEKLIYOR`.
- Preflight: `kapanabilir_mi=false`, BLOCKER `PUANTAJ_CONTROL_PENDING` (2 kayıt: record_id 6/7), WARNING `FINANCE_SALARY_MISSING` + `FINANCE_OPEN_AFTER_SEAL_RISK`, 4 INFO.
- `POST /puantaj/muhurle` → **409 `PERIOD_CLOSE_BLOCKED`**; mühür oluşmadı (`muhur_state=ACIK`, `muhur_id=null`), business count değişmedi.
- Blocked audit #1: `CLOSE_ATTEMPT_BLOCKED / BLOCKED`, blocker_count 1, warning_count 2, preflight/request/result hash dolu.
- Aynı isteğin tekrarı **aynı audit satırını** döndürdü (id 1, request_hash `651141…bcfbd`); duplicate blocked audit oluşmadı.

## 9. Canlı Kabul — Blocker Çözümü ve Başarılı Mühür

- Blocker yalnız doğal ürün akışıyla çözüldü: BIRIM_AMIRI `PUT /gunluk-puantaj/1/{tarih}` `{"kontrol_durumu":"AMIR_KONTROL_ETTI"}` (puantaj #6/#7). Diğer tüm alanların (saatler, dakikalar, dayanak, açıklama) before/after snapshot ile korunduğu doğrulandı.
- Preflight yeniden: `blocker_count=0`, `kapanabilir_mi=true`, WARNING'ler kontrata göre engel değil.
- `POST /puantaj/muhurle` → **200**: `muhur_id=1`, `durum=MUHURLENDI`, `muhurlenen_kayit_sayisi=2`, actor user 1 (GENEL_YONETICI), şube 1, dönem 2026-03.
- Success audit #2: `CLOSE_SUCCESS / SEALED`, `muhur_id=1`, blocker 0, warning 2, hash'ler dolu.

## 10. Canlı Kabul — Idempotency

- Aynı mühür çağrısının tekrarı **200** ve aynı `muhur_id=1` döndürdü; ikinci mühür satırı, duplicate transition ve business kayıt artışı oluşmadı.
- Mühürlü döneme puantaj yazma denemesi **409 `PERIOD_LOCKED`** (S74 kilidi canlıda enforced).
- 2026-04 dönemi etkilenmedi (`donem_state=ACIK`, `muhur_id=null`).
- Yerel + CI gerçek MariaDB concurrency sonuçları (parallel close tek mühür, blocked audit tek satır) canlı idempotent tekrar ile birlikte delil sayılmıştır.

## 11. Canlı Kabul — Etki Adayı Raporu ve CSV

- Rapor (`?sube_id=1&yil=2026&ay=3`): aday #6 (`YOK_SAYILDI`, `MEVCUT_PUANTAJI_KORU`) ve #7 (`UYGULANDI`, `ADAY_ETKISIYLE_REVIZE_ET`), summary `toplam=2, uygulandi=1, yok_sayildi=1, koru=1, revize=1, toplam_devamsizlik_gun=1`. UI/API aynı owner query service'i kullanır.
- BIRIM_AMIRI aynı raporu yalnız kendi şubesi için görür; `sube_id=2` → 403 (scope sızıntısı yok).
- CSV: HTTP 200, `text/csv; charset=utf-8`, `attachment; filename="bildirim-etki-adaylari-rapor-2026-03-sube-1.csv"`, 16 kolon başlık + 2 veri satırı, maskeli ad (`A*** Y***`) ve Türkçe içerik doğru, formül injection'a açık hücre yok.
- Mühür sonrası rapor/CSV yeniden doğrulandı: aday state ve `karar_zamani` değişmedi.

## 12. Canlı Kabul — Audit Bütünlüğü

- `donem_kapanis_auditleri` (şube 1, 2026-03): 2 satır — id 1 `CLOSE_ATTEMPT_BLOCKED/BLOCKED` (muhur_id null), id 2 `CLOSE_SUCCESS/SEALED` (muhur_id 1); actor, hash üçlüsü, blocker/warning sayıları ve created_at tutarlı.
- S75 conflict resolution audit'leri korunmuş: aday #6/#7 çözüm kayıtları (koru/revize, karar_zamani 2026-07-16) değişmedi; başlangıç count 2 = final count 2.

## 13. Canlı Regresyon Smoke

- `/health` 200, login 4 rol için 200, personeller/bildirimler/haftalık özet/aylık onay özet/GY onay özet/etki adayları/etki özet/preflight/audit yüzeyleri 200.
- 500 yok, beklenmeyen 403 yok, SQL exception yok, stale bundle yok (`smoke:live` asset kontrolü OK).
- S74 (dönem kilidi 409) ve S75 (conflict audit) regresyonsuz; diğer şube/dönem etkilenmedi.

## 14. Final Git Durumu

- Branch `main`; S76 zinciri: `6256418` → `790be6f` → `0fc807e` → `11d8141` → `6a3e851` (CI fix) → docs commit (bu dosya).
- Amend/rebase/force-push yapılmadı; working tree clean.

## 15. Başarı Kodları

`S76_C1_START_GATE_OK`, `S76_C1_LOCAL_WINDOWS_MARIADB_OK`, `S76_C1_LOCAL_REGRESSION_OK`, `S76_C1_DIFF_SCOPE_OK`, `S76_C1_FIX_COMMITTED`, `S76_C1_PUSH_OK`, `S76_C1_CI_MARIADB_SERVICE_OK`, `S76_C1_ALL_CONCURRENCY_CI_OK`, `S76_C1_CI_GATE_OK`, `S76_C1_E2E_GATE_OK`, `S76_C1_DEPLOY_GATE_OK`, `S76_EXISTING_LIVE_MIGRATIONS_REVERIFIED`, `S76_LIVE_ROLE_SCOPE_OK`, `S76_LIVE_BLOCKED_CLOSE_OK`, `S76_BLOCKED_AUDIT_OK`, `S76_LIVE_SUCCESSFUL_SEAL_OK`, `S76_SUCCESS_AUDIT_OK`, `S76_LIVE_IDEMPOTENCY_OK`, `S76_LIVE_IMPACT_REPORT_OK`, `S76_LIVE_CSV_EXPORT_OK`, `S76_LIVE_AUDIT_INTEGRITY_OK`, `S76_LIVE_REGRESSION_SMOKE_OK`, `S76_FINAL_DOCS_COMPLETE`, `S76_FULLY_COMPLETE`

Önceki korunmuş kodlar: `S76_MIGRATIONS_LIVE_OK`, `S76_BIRIM_AMIRI_E2E_STABLE`, `S76_MARIADB_CONCURRENCY_OK`, `S76_ALL_CONCURRENCY_RUNNERS_OK`, `S76_FULL_E2E_GATE_OK`, `S76_FULL_REGRESSION_GATE_OK`, `S76_LOCAL_IMPLEMENTATION_COMPLETE`, `S76_RELEASE_CANDIDATE_READY`
