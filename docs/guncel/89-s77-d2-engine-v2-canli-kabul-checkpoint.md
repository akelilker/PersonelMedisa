# 89 — S77-D2 Engine V2 Canlı Kabul Checkpoint

## Final karar

**`S77_D2_FULLY_COMPLETE`**

Canlı kabul: **`S77_D2_ENGINE_READY_LIVE_BLOCKED`** (bilinçli; mevzuat/devir yazılmadı).

Production mevzuat / bordro devri / maaş adayı yazılmadı. S77-D1 tekrarlanmadı. S77-E’ye geçilmedi.

## Version

| Alan | Değer |
| --- | --- |
| Engine | `S77D_PAYROLL_ENGINE_V2` |
| Candidate contract | `S77D_PAYROLL_CANDIDATE_V2` |
| Zorunlu katalog | 27 kod |
| Merge | PR [#22](https://github.com/akelilker/PersonelMedisa/pull/22) → `3c61c71` |
| Deploy | [29574005563](https://github.com/akelilker/PersonelMedisa/actions/runs/29574005563) success |
| Canlı bundle | `index-AUfw1l3S.js` / `index-B_ovm1yn.css` |

## V2 hardening özeti

- Kesirli saat → dakika (`7.5` → 450); `paramInt` truncate yok
- Saatlik ücret: `sozlesme_baz * 60 / AYLIK_NORMAL_CALISMA_DAKIKA`
- Haftalık sınıflandırma: fazla sürelerle çalışma vs fazla mesai (>2700 dk)
- V1 günlük OT kaldırıldı
- `UBGT_HESAP_MODU` / `HAFTA_TATILI_HESAP_MODU`: `GUNLUK_ILAVE` | `SAAT_CARPAN` | `GUNLUK_ILAVE_VE_SAAT_CARPAN`
- Hash + idempotency `engine_version` içerir; V1/V2 ayrı

## Canlı blocked kabul

| Öğe | Değer |
| --- | --- |
| Snapshot | `#1` / şube 1 / 2026-03 |
| Yasal katalog | 27 item, `engine_version=S77D_PAYROLL_ENGINE_V2` |
| GET hesaplama-preflight | 200, `hesaplanabilir_mi=false`, `blocker_count=29` |
| Blocker | `LEGAL_PARAMETER_REQUIRED_MISSING` (28 = 27 eksik + boş set) + `PERSONNEL_CARRYOVER_MISSING` (1) |
| `zorunlu_adet` / `bulunan_adet` | 27 / 0 |
| contract_version | `S77D_PAYROLL_CANDIDATE_V2` |
| POST hesapla | 409 `PAYROLL_CALCULATION_PREFLIGHT_BLOCKED` |
| Audit | `PREFLIGHT_BLOCKED` / `BLOCKED` (calistirma oluşmadı) |

Artifacts:

- `/opt/cursor/artifacts/s77d2-yasal-katalog-live.json`
- `/opt/cursor/artifacts/s77d2-calc-preflight-live.json`
- `/opt/cursor/artifacts/s77d2-calc-blocked-live.json`

## Doğrulama

| Kapı | Sonuç |
| --- | --- |
| Engine PHP runner (V2 senaryolar) | PASS |
| Maas hesaplama concurrency + migration | PASS |
| `npm run typecheck` / `npm run build` | PASS |
| E2E `maas-hesaplama-merkezi.spec.ts` | 4/4 PASS |
| CI main | [29573918017](https://github.com/akelilker/PersonelMedisa/actions/runs/29573918017) success |
| Deploy cPanel | success |
| `smoke:live` | OK |
| Canlı blocked preflight | OK |

Not: Lokal `bildirim-puantaj-etki-conflict-resolution-mysql` runner eski MariaDB `transaction_isolation` uyumsuzluğu ile düştü; CI MariaDB 11.4’te yeşil.

## Faz dışı (korundu)

- Production mevzuat yazımı
- Bordro devri yazımı
- Maaş adayı üretme
- S77-D1 tekrarı / S77-E
- CSS worktree

## Sonraki

Ayrı görev: **S77-D1-R2** (doğrulanmış mevzuat + Mart 2026 devir + ilk canlı aday; V2 motor üzerinde).
