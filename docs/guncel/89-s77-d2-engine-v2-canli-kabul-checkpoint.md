# 89 — S77-D2 Engine V2 Canlı Kabul Checkpoint

## Karar

`S77_D2_ENGINE_READY_LIVE_BLOCKED`

Production mevzuat / bordro devri / maaş adayı yazılmadı. S77-D1-R2 ayrı fazdır.

## Version

| Alan | Değer |
| --- | --- |
| Engine | `S77D_PAYROLL_ENGINE_V2` |
| Candidate contract | `S77D_PAYROLL_CANDIDATE_V2` |
| Zorunlu katalog | 27 kod |

## V2 hardening özeti

- Kesirli saat → dakika (`7.5` → 450); `paramInt` truncate yok
- Saatlik ücret: `sozlesme_baz * 60 / AYLIK_NORMAL_CALISMA_DAKIKA`
- Haftalık sınıflandırma: fazla sürelerle çalışma (1.25 band) vs fazla mesai (>2700 dk)
- V1 günlük OT kaldırıldı
- `UBGT_HESAP_MODU` / `HAFTA_TATILI_HESAP_MODU`: `GUNLUK_ILAVE` | `SAAT_CARPAN` | `GUNLUK_ILAVE_VE_SAAT_CARPAN`
- Hash + idempotency `engine_version` içerir; V1/V2 ayrı

## Canlı beklenen durum

Snapshot `#1` mevzuat seti boş kaldığı için hesaplama preflight:

- `hesaplanabilir_mi=false`
- Blocker: `LEGAL_PARAMETER_REQUIRED_MISSING` (27 zorunlu kod) + `PERSONNEL_CARRYOVER_MISSING` (Mart 2026)
- `engine_version` yanıtı: `S77D_PAYROLL_ENGINE_V2`

Bu blocked kabul **bilinçlidir**; tam aday üretimi S77-D1-R2’dedir.

## Doğrulama

- PHP engine runner (V2 senaryoları)
- Maas hesaplama concurrency + migration runners
- `npm run typecheck` / `npm run test` (kapsam dışı lokal MariaDB uyumsuzluğu hariç) / `npm run build`
- E2E `maas-hesaplama-merkezi.spec.ts`
- CI + cPanel deploy sonrası canlı preflight

## Faz dışı (korundu)

- Production mevzuat yazımı
- Bordro devri yazımı
- Maaş adayı üretme
- S77-D1 tekrarı / S77-E
- CSS worktree

## Sonraki

`S77_D2_FULLY_COMPLETE` → ardından ayrı görev: **S77-D1-R2**
