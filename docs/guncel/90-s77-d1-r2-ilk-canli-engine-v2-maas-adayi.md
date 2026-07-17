# 90 — S77-D1-R2 İlk Canlı Engine V2 Maaş Adayı Checkpoint

## Final karar

**`S77_D1_R2_COMPANY_POLICY_BLOCKED`**
**`S77_D1_R2_CARRYOVER_SOURCE_BLOCKED`**

`S77_D_FULLY_COMPLETE` **değil**. Production mevzuat / bordro devri / maaş adayı yazılmadı. S77-E’ye geçilmedi.

Engine V2 (S77-D2) kusuru giderilmiş kabul edilir; bu fazda yeni motor defect görülmedi.

## 1. Başlangıç Git

| Öğe | Değer |
| --- | --- |
| Branch start | `main` |
| HEAD / origin/main | `50385aedd2d88042be096644ec1f9a5efd25382d` |
| ahead/behind | 0 / 0 |
| working tree | clean |
| Çalışma branch | `Cursor-s77d1-r2-ilk-canli-aday-ab62` |

Başarı: `S77_D1_R2_START_GATE_OK`

## 2. Başlangıç canlı envanteri

| Varlık | Değer |
| --- | --- |
| Snapshot `#1` | `OLUSTURULDU`, rev `1` |
| snapshot_hash | `0ec67db7834c2f4a1afa3869927bc06041f707a099af1c64bcac74e99f38c7ee` |
| personel_sayisi / girdi_sayisi | 1 / 6 |
| engine / contract (preflight) | `S77D_PAYROLL_ENGINE_V2` / `S77D_PAYROLL_CANDIDATE_V2` |
| aktif mevzuat | 0 |
| Mart 2026 bordro devri | 0 (`GET …/devirler?yil=2026&ay=3&sube_id=1` → items=[]) |
| maaş çalıştırması / aday | 0 |

Preflight before:

- `hesaplanabilir_mi=false`
- `blocker_count=29`
- `LEGAL_PARAMETER_REQUIRED_MISSING=28` (27 eksik + boş set)
- `PERSONNEL_CARRYOVER_MISSING=1`

Başarı: `S77_D1_R2_BASELINE_CONFIRMED`

## 3. Engine V2 katalog (27)

Owner: `MaasHesaplamaLegalParameterCatalog::requiredCodes()` = **27**. Repo contract ile birebir.

Başarı: `S77_D1_R2_CATALOG_27_CONFIRMED`

## 4. Resmî/yasal parametre paketi (taslak — yazılmadı)

Erişim: 2026-07-17. Birincil RG PDF fetch timeout; değerler CSGB/GİB tebliğ + çapraz kontrol. **Production write yapılmadı.**

| Kod | Değer | Birim | Kaynak |
| --- | ---: | --- | --- |
| ASGARI_UCRET_BRUT | 33030.00 | TRY | AUTK; RG 26.12.2025 / 33119 |
| ASGARI_UCRET_GELIR_VERGISI_ISTISNA_MATRAHI | 28075.50 | TRY | Asgari GV matrahı (= brüt−SGK%14−işsizlik%1); matrah semantiği |
| ASGARI_UCRET_DAMGA_VERGISI_ISTISNA_MATRAHI | 33030.00 | TRY | Asgari brüt |
| SGK_ISCI_PRIM_ORANI | 0.14 | ORAN | 5510 |
| ISSIZLIK_ISCI_PRIM_ORANI | 0.01 | ORAN | 4447 |
| SGK_GUNLUK_TABAN | 1101.00 | TRY | Günlük asgari / SGK 2026/2 |
| SGK_GUNLUK_TAVAN | 9909.00 | TRY | Aylık 297270 / 30 |
| DAMGA_VERGISI_ORANI | 0.00759 | ORAN | DVK ekli tablo; Damga GT Seri No: 71; ücret ‰7,59 |
| GELIR_VERGISI_DILIM_1_LIMIT | 190000.00 | TRY | GVK GT 332; RG 31.12.2025 33124 (5. Mük.) — ücret |
| GELIR_VERGISI_DILIM_2_LIMIT | 400000.00 | TRY | aynı |
| GELIR_VERGISI_DILIM_3_LIMIT | 1500000.00 | TRY | ücret 3. dilim |
| GELIR_VERGISI_DILIM_4_LIMIT | 5300000.00 | TRY | aynı |
| GELIR_VERGISI_DILIM_*_ORAN | 0.15/0.20/0.27/0.35/0.40 | ORAN | aynı |
| FAZLA_SURELERLE_CALISMA_CARPANI | 1.25 | CARPAN | 4857 İş K. fazla sürelerle çalışma |
| FAZLA_MESAI_CARPANI | 1.50 | CARPAN | 4857 İş K. fazla çalışma |
| UBGT_HESAP_MODU | GUNLUK_ILAVE | MOD | Engine V2 kontrat tercihi (yasal uyum) |
| UBGT_CARPANI | 1.00 | CARPAN | günlük ilave |

Yasal paket derlendi; yarım production konfigürasyonu yazılmadığı için write-approved sayılmadı.

## 5. Medisa çalışma politikası — BLOK

Onaysız / kaynak bulunamadı:

| Kod | Teknik öneri | Durum |
| --- | ---: | --- |
| NORMAL_AY_GUN_SAYISI | 30 | GENEL_YONETICI+MUHASEBE+İK karar kaydı yok |
| GUNLUK_CALISMA_SAATI | 7.5 | Net süre / ara dinlenme doğrulanmadı |
| AYLIK_NORMAL_CALISMA_SAATI | 225 | Puantaj matrisinde 225 geçiyor; bordro onayı yok |
| HAFTALIK_IS_GUNU_SAYISI | 5 veya 6 | Vardiya/sözleşme yok → tahmin yasak |
| HAFTA_TATILI_HESAP_MODU | ? | Mod seçimi onaysız |
| HAFTA_TATILI_CARPANI | ? | Mod ile birlikte onaysız |

Başarı: `S77_D1_R2_MEDISA_WORK_POLICY_APPROVED` **hayır** → `S77_D1_R2_COMPANY_POLICY_BLOCKED`

## 6. Bordro devri — BLOK

| Öğe | Değer |
| --- | --- |
| personel_id | 1 (Ayşe Yılmaz) |
| dönem | 2026-03 |
| Gerekli | Ocak+Şubat 2026 kümülatif GV matrah/vergi |
| Kaynak | Gerçek bordro / muhasebe pusulası **yok** |
| Tahmin | yasak |

Başarı: `S77_D1_R2_CARRYOVER_VERIFIED` **hayır** → `S77_D1_R2_CARRYOVER_SOURCE_BLOCKED`

## 7. Production write

| İşlem | Sonuç |
| --- | --- |
| Pre-write backup | gerekmedi (mutation yok) |
| mevzuat_parametreleri | **yazılmadı** |
| personel_bordro_devirleri | **yazılmadı** |
| POST …/hesapla | **yapılmadı** |
| Dry-run / bağımsız mutabakat | üretim verisi olmadığı için atlandı |

Tercih: şirket + devir olmadan yalnız yasal set yazarak yarım production konfig bırakılmadı.

## 8. Preflight after

Değişmedi: blockers=29, `hesaplanabilir_mi=false`, engine V2. Snapshot hash aynı.

## 9. Rol / smoke

| Kontrol | Sonuç |
| --- | --- |
| MUHASEBE preflight | 200 (blocked içerik) |
| BIRIM_AMIRI / BOLUM_YONETICISI | 403 |
| smoke:live | OK (önceki deploy) |

## 10. Kod değişikliği

Yok (data-only faz; engine/API/UI değişmedi). Bu commit yalnız checkpoint docs.

## 11. Açık riskler / sonraki

1. Medisa: günlük net saat, haftalık iş günü, HT modu/çarpan — yazılı onay.
2. Muhasebe: personel `#1` Ocak–Şubat 2026 bordro kümülatifleri.
3. Write öncesi birincil RG PDF paketi yeniden indirilip hash’lenmeli.
4. Onaylar gelince S77-D1-R2 yeniden → hedef `S77_D_FULLY_COMPLETE` → ancak sonra S77-E.

## 12. Başarı kodları

| Kod | Durum |
| --- | --- |
| `S77_D1_R2_START_GATE_OK` | OK |
| `S77_D1_R2_BASELINE_CONFIRMED` | OK |
| `S77_D1_R2_CATALOG_27_CONFIRMED` | OK |
| `S77_D1_R2_OFFICIAL_PARAMETER_PACKET_APPROVED` | taslak derlendi; write yok |
| `S77_D1_R2_MEDISA_WORK_POLICY_APPROVED` | **NO** |
| `S77_D1_R2_CARRYOVER_VERIFIED` | **NO** |
| `S77_D1_R2_COMPANY_POLICY_BLOCKED` | **FINAL** |
| `S77_D1_R2_CARRYOVER_SOURCE_BLOCKED` | **FINAL** |
| `S77_D_FULLY_COMPLETE` | **NO** |
