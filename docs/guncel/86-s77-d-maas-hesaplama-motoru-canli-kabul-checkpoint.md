# 86 — S77-D Deterministik Maaş Hesaplama Motoru Canlı Kabul Checkpoint

## 1. Sonuç

**`S77_D_ENGINE_READY_LIVE_BLOCKED`**

Motor, aday şeması, API/UI ve canlı schema-first hazır. Mart 2026 snapshot `#1` üzerinde hesaplama, doğrulanmış mevzuat parametresi ve personel yasal devir olmadığı için blocker ile durdu; başarılı aday üretimi beklenmez / üretilmedi.

Kontrat: `docs/guncel/85-s77-d-maas-hesaplama-motoru-kontrati.md`

## 2. Kapsam

**İçerir:** S77-C snapshot’tan deterministik BRUT/NET hesap, Money/Rate (kuruş+ppm), zorunlu mevzuat katalog blocker’ları, `personel_bordro_devirleri`, çalıştırma/aday/kalem/audit, immutability, rol/şube scope, Maaş Hesaplama Merkezi aday+devir UI.

**İçermez:** Muhasebe/GY onayı, bordro PDF/Excel, banka dosyası, SGK bildirgesi (S77-E/F/G). Production mevzuat seed yok.

## 3. Backup ve Schema-first Migration

| Öğe | Değer |
| --- | --- |
| Database | `karmotor_medisa` |
| Backup method | inventory_json (shared host mysqldump yok) |
| Backup SHA-256 | `6886aa6254cf336ff489349e9275812791aee1618a3a82b84aefb3b928cd19df` |
| Artifact | `/opt/cursor/artifacts/s77d-backup-live.json` |
| Migrate artifact | `/opt/cursor/artifacts/s77d-migrate-live.json` |

| Migration | Dosya | SHA-256 |
| --- | --- | --- |
| 022 | `api/migrations/022_personel_bordro_devirleri.sql` | `c900d676db0fa1e129ea199b3ec6c13317fd0360afefc2684a3f3dfe6aeeff2b` |
| 023 | `api/migrations/023_maas_hesaplama_adaylari.sql` | `63336627f88848a00fd826c2daad3d882c793e7e1327466ee9b803dc63157a81` |
| 024 | `api/migrations/024_maas_hesaplama_aday_guvenlik_indexleri.sql` | `ac768f4ffa3c931c4736b21c0ef74539457122322f463f33537a995b522e08a5` |

Post-migration:

- Tablo: 31 → 37
- Snapshot `#1` korundu: `OLUSTURULDU`, hash `0ec67db7834c2f4a1afa3869927bc06041f707a099af1c64bcac74e99f38c7ee`, personel=1, girdi=6
- `mevzuat_parametreleri=0`
- Trigger: 16 (S77-C 8 + S77-D 8)

Başarı kodu: `S77_D_SCHEMA_FIRST_LIVE_OK`

Geçici migrate endpoint (`api/public/_s77d_migrate.php`) canlı kabul sonrası repodan kaldırılır.

## 4. Commit Zinciri

| SHA | Mesaj |
| --- | --- |
| `deb3bc6` | docs: s77 maas hesaplama motoru kontratini kaydet |
| `18c4a64` | feat(db): bordro devir ve maas aday semasini ekle |
| `dcc0034` | feat(api): deterministik maas hesaplama motorunu ekle |
| `fb09827` | feat(ui): maas adaylari ve devir yonetimini ekle |
| `dcc60d8` | test: s77 maas motoru yasal hesap ve concurrency kapilarini kilitle |
| `7f67bf3` | chore: s77d canli schema-first migrate workflow ekle |
| `6cf3f78` | chore: s77d gecici canli migrate endpoint ekle |

Merge: PR #17 → `main` @ `93dafe8`

## 5. Yerel Kapılar

| Kapı | Sonuç |
| --- | --- |
| typecheck | PASS |
| Engine PHP runner | `verify-maas-hesaplama-engine: OK` |
| Migration MariaDB | `verify-maas-hesaplama-migrations: OK` (020–024) |
| Aday concurrency | `verify-maas-hesaplama-aday-concurrency: OK` |
| Snapshot concurrency regresyon | `verify-maas-hesaplama-mysql-concurrency: OK` |
| vitest (CI MariaDB 11.4) | PASS — CI run success |
| build | PASS (`index-Bdbh6_SP.js`) |
| targeted E2E maas-hesaplama | 4/4 PASS |
| full E2E | **290/290 PASS** |

Başarı kodları: `S77_D_FULL_REGRESSION_GATE_OK`, `S77_D_LOCAL_IMPLEMENTATION_COMPLETE`, `S77_D_RELEASE_CANDIDATE_READY`

## 6. CI / E2E / Deploy

| Workflow | Run | Sonuç |
| --- | --- | --- |
| CI (main merge) | [29569471542](https://github.com/akelilker/PersonelMedisa/actions/runs/29569471542) | success |
| Deploy cPanel | [29569544961](https://github.com/akelilker/PersonelMedisa/actions/runs/29569544961) | success @ `93dafe8` |

`smoke:live`: health/auth/frontend/assets OK; bundle `index-Bdbh6_SP.js` / `index-B_ovm1yn.css`.

Başarı kodları: `S77_D_CI_GATE_OK`, `S77_D_DEPLOY_GATE_OK`

## 7. Canlı Blocked Hesaplama Kabulü

| Öğe | Değer |
| --- | --- |
| Snapshot | `#1` / şube 1 / 2026-03 / `OLUSTURULDU` |
| GET hesaplama-preflight | 200, `hesaplanabilir_mi=false`, `blocker_count=25` |
| Blocker kodları | `LEGAL_PARAMETER_REQUIRED_MISSING`, `PERSONNEL_CARRYOVER_MISSING` |
| Eksik mevzuat | 23 zorunlu kod (katalog tam set) |
| POST hesapla | 409 `PAYROLL_CALCULATION_PREFLIGHT_BLOCKED` |
| Audit | `PREFLIGHT_BLOCKED` / `BLOCKED` (calistirma oluşmadı) |
| Engine | `S77D_PAYROLL_ENGINE_V1` |
| Yasal katalog | 23 item |
| Aday/çalıştırma satırı | 0 |

Artifact: `/opt/cursor/artifacts/s77d-calc-preflight-live.json`

Başarı kodları: `S77_D_LIVE_BLOCKED_PREFLIGHT_OK`, `S77_D_LIVE_BLOCKED_AUDIT_OK`

## 8. Sonraki Faz (S77-E+)

1. Doğrulanmış mevzuat parametre setini dönem için kaydet + yeni S77-C snapshot revision
2. Mart dışı aylar için `personel_bordro_devirleri` gir
3. Hesaplama adaylarını üret ve muhasebe onayına (S77-E) geç

## 9. Final Kapı Özeti

| Kod | Durum |
| --- | --- |
| `S77_D_OWNER_MAP_CONFIRMED` | OK |
| `S77_D_SCHEMA_FIRST_LIVE_OK` | OK |
| `S77_D_FULL_REGRESSION_GATE_OK` | OK |
| `S77_D_CI_GATE_OK` | OK |
| `S77_D_DEPLOY_GATE_OK` | OK |
| `S77_D_LIVE_BLOCKED_PREFLIGHT_OK` | OK |
| `S77_D_ENGINE_READY_LIVE_BLOCKED` | **FINAL** |
| `S77_D_FULLY_COMPLETE` | Hayır (mevzuat + devir yok; beklenen) |
