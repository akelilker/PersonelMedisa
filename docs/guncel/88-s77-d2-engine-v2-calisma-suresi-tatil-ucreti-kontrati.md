# 88 — S77-D2 Engine V2 Çalışma Süresi ve Tatil Ücreti Kontratı

## 1. Başarı kapısı

`S77_D2_OWNER_MAP_CONFIRMED`

Bu faz production mevzuat/devir/aday yazmaz. Motor hardening + test + deploy + canlı blocked kabul.

## 2. Version

| Alan | V1 | V2 |
| --- | --- | --- |
| Engine | `S77D_PAYROLL_ENGINE_V1` | `S77D_PAYROLL_ENGINE_V2` |
| Candidate contract | `S77D_PAYROLL_CANDIDATE_V1` | `S77D_PAYROLL_CANDIDATE_V2` |

Hash ve idempotency `engine_version` içerir; V1/V2 birbirinin idempotent’i değildir.

## 3. Süre modeli (dakika)

- Tüm çalışma süreleri **dakika** ile işlenir.
- `GUNLUK_CALISMA_SAATI` decimal saat kabul eder (`7.5` → `450` dk). `paramInt` truncate **yasak**.
- Saatlik ücret: `sozlesme_baz * 60 / AYLIK_NORMAL_CALISMA_DAKIKA`  
  `AYLIK_NORMAL_CALISMA_SAATI` (ör. `225`) gerçek divisor’dır.
- Günlük ücret: `sozlesme_baz / NORMAL_AY_GUN_SAYISI` (değişmez).

## 4. Haftalık sınıflandırma

ISO hafta: Pazartesi–Pazar.

Yasal haftalık eşik: **45 saat = 2700 dk** (İş Kanunu; sabit).

Sözleşme haftalık dk: `gunluk_dk * HAFTALIK_IS_GUNU_SAYISI` (max 2700).

`Normal_Is_Gunu` net dakikaları haftalık toplanır (HT/UBGT ayrı prim; haftalık OT havuzuna girmez).

| Bant | Aralık | Kalem | Çarpan |
| --- | --- | --- | --- |
| Normal | ≤ sözleşme haftalık | (baz maaşta) | — |
| Fazla sürelerle çalışma | sözleşme…2700 | `FAZLA_SURELERLE_CALISMA_ODEMESI` | `FAZLA_SURELERLE_CALISMA_CARPANI` (tipik 1.25) |
| Fazla çalışma (FM) | > 2700 | `FAZLA_MESAI_ODEMESI` | `FAZLA_MESAI_CARPANI` (tipik 1.5) |

Günlük eşik ile ayrı FM **kaldırılır** (V1 daily OT yok).

## 5. UBGT

`UBGT_HESAP_MODU`:

| Mod | Anlam |
| --- | --- |
| `GUNLUK_ILAVE` | Çalışılmış UBGT gününde `gunluk * UBGT_CARPANI` ARTI (tipik carpan=1 → bir günlük ilave) |
| `SAAT_CARPAN` | V1: `saatlik * saat * UBGT_CARPANI` |
| `GUNLUK_ILAVE_VE_SAAT_CARPAN` | günlük ilave + saat×1.0 (veya carpan saat kısmı) |

Varsayılan kontrat tercihi: `GUNLUK_ILAVE`.

## 6. Hafta tatili

`HAFTA_TATILI_HESAP_MODU`: aynı üç mod; `HAFTA_TATILI_CARPANI` ile.

## 7. Yeni/zorunlu katalog

Mevcutlara ek:

| Kod | Tip | Birim |
| --- | --- | --- |
| `FAZLA_SURELERLE_CALISMA_CARPANI` | SAYISAL | CARPAN |
| `HAFTALIK_IS_GUNU_SAYISI` | SAYISAL | GUN |
| `HAFTA_TATILI_HESAP_MODU` | METIN | MOD |
| `UBGT_HESAP_MODU` | METIN | MOD |

## 8. Owner

- `MaasHesaplamaEngine` (saf)
- `MaasHesaplamaLegalParameterCatalog`
- `MaasHesaplamaAdayService` (version/hash orkestrasyon)
- Paralel motor yok

## 9. Canlı kabul

Mevzuat hâlâ boş → hesaplama preflight blocker; `S77_D2_ENGINE_READY_LIVE_BLOCKED` beklenir (V2 deploy sonrası). Tam aday S77-D1-R2’dedir.

## 10. Faz dışı

Production mevzuat/devir/aday yazımı, S77-D1 tekrarı, S77-E, CSS worktree.
