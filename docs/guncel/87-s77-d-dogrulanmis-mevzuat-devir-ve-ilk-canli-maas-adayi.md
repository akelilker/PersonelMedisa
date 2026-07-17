# 87 — S77-D1 Doğrulanmış Mevzuat, Bordro Devirleri ve İlk Canlı Maaş Adayı

## 1. Final karar

**`S77_D1_ENGINE_DEFECT_BLOCKED`**

Production’a mevzuat/devir yazılmadı. İlk canlı maaş adayı oluşturulmadı.  
`S77_D_FULLY_COMPLETE` **değil**.

Kök sebep (motor): `GUNLUK_CALISMA_SAATI` `paramInt()` ile okunuyor; `"7.5"` → `7` truncate. İş Kanunu’nun yaygın 45s/hafta → 7,5s/gün düzeni doğru parametrelenemiyor; fazla mesai eşiği bozulur. Canlıda workaround yasak.

İkincil bloklar (motor fix sonrası da kalır):

- Medisa şirket çalışma/çarpan politikası onaylanmadı → production write yok
- Personel `#1` için Ocak–Şubat 2026 gerçek bordro/devir kaynağı yok → `PERSONNEL_CARRYOVER_MISSING`

## 2. Başlangıç Git

| Öğe | Değer |
| --- | --- |
| Branch | `main` |
| HEAD | `bf63a30489dd13e7c030b688855b95f837b51fd7` |
| origin/main | aynı |
| ahead/behind | 0 / 0 |
| working tree | clean |

Başarı kodu: `S77_D1_START_GATE_OK`

## 3. Başlangıç canlı envanteri

| Varlık | Değer |
| --- | --- |
| Snapshot `#1` | `OLUSTURULDU`, rev `1` |
| snapshot_hash | `0ec67db7834c2f4a1afa3869927bc06041f707a099af1c64bcac74e99f38c7ee` |
| Hash doğrulama | `dogrulandi=true` |
| personel_sayisi | 1 (`personel_id=1`, Ayşe Yılmaz) |
| girdi_sayisi | 6 |
| donem_kapanis_auditleri (şube1 / 2026-03) | 2 |
| personel_ucret_gecmisi (personel 1) | 2 aktif kayıt |
| mevzuat_parametreleri aktif | 0 |
| personel_bordro_devirleri (2026-03) | 0 |
| maaş çalıştırma/aday | 0 |

Preflight before:

- `hesaplanabilir_mi=false`
- `blocker_count=25`
- `LEGAL_PARAMETER_REQUIRED_MISSING=24` (23 eksik kod + boş set)
- `PERSONNEL_CARRYOVER_MISSING=1`

Başarı kodu: `S77_D1_BASELINE_INVENTORY_OK`

## 4. Test fixture izolasyonu

Production değerleri unit/E2E/concurrency fixture’larından kopyalanmadı.  
Resmî/yasal değerler yalnız birincil/atıf verilen resmî kaynaklardan derlendi; şirket parametreleri tahmin edilmedi.

Başarı kodu: `S77_D1_TEST_FIXTURE_ISOLATION_OK`

## 5. Required parameter catalog (kod owner)

Owner: `MaasHesaplamaLegalParameterCatalog` — `requiredCodes()` = **23**, hepsi zorunlu.

| Kod | Tip | Birim | Kullanım (Engine) | Zorunlu |
| --- | --- | --- | --- | ---: |
| ASGARI_UCRET_BRUT | SAYISAL | TRY | Asgari SGK matrahı / istisna zinciri | evet |
| ASGARI_UCRET_GELIR_VERGISI_ISTISNA_MATRAHI | SAYISAL | TRY | **Matrah**; `computeIncomeTax(0, matrah)` → istisna vergi | evet |
| ASGARI_UCRET_DAMGA_VERGISI_ISTISNA_MATRAHI | SAYISAL | TRY | **Matrah**; `matrah * DAMGA_ORANI` → istisna | evet |
| SGK_ISCI_PRIM_ORANI | SAYISAL | ORAN | decimal oran (`0.14`) | evet |
| ISSIZLIK_ISCI_PRIM_ORANI | SAYISAL | ORAN | decimal oran (`0.01`) | evet |
| SGK_GUNLUK_TABAN | SAYISAL | TRY | günlük × `NORMAL_AY_GUN_SAYISI` → aylık taban | evet |
| SGK_GUNLUK_TAVAN | SAYISAL | TRY | günlük × `NORMAL_AY_GUN_SAYISI` → aylık tavan | evet |
| DAMGA_VERGISI_ORANI | SAYISAL | ORAN | decimal (`0.00759` = ‰7,59) | evet |
| GELIR_VERGISI_DILIM_1..4_LIMIT | SAYISAL | TRY | **Kümülatif** üst sınır | evet |
| GELIR_VERGISI_DILIM_1..5_ORAN | SAYISAL | ORAN | dilim oranları; 5. dilim limitsiz | evet |
| NORMAL_AY_GUN_SAYISI | SAYISAL | GUN | prorata + SGK gün çarpanı (`paramInt`) | evet |
| GUNLUK_CALISMA_SAATI | SAYISAL | SAAT | saatlik ücret + FM eşiği (`paramInt` ⚠️) | evet |
| AYLIK_NORMAL_CALISMA_SAATI | SAYISAL | SAAT | resolveParams zorunlu; **formülde kullanılmıyor** | evet |
| FAZLA_MESAI_CARPANI | SAYISAL | CARPAN | OT saat ücreti × carpan (**toplam katsayı**) | evet |
| HAFTA_TATILI_CARPANI | SAYISAL | CARPAN | tatil çalışma saati × carpan (**gross ADD**) | evet |
| UBGT_CARPANI | SAYISAL | CARPAN | UBGT saati × carpan (**gross ADD**) | evet |

Oran scale: `Rate::fromDecimalString` → ppm; `%14` → `0.14` (asla `14` değil).  
Başarı kodu: `S77_D1_REQUIRED_PARAMETER_CATALOG_CONFIRMED`

## 6. Parametre semantiği

### Oran / dilim / istisna

- Oranlar decimal string; `applyRate` = kuruş × ppm / 1e6 half-up.
- GV dilim limitleri kümülatif: bant = `limit_i - limit_{i-1}`.
- GV istisna parametresi **matrah** (vergi tutarı değil).
- Damga istisna parametresi **matrah** (tipik = asgari brüt).

### Çalışma saati (ENGINE DEFECT)

```text
paramInt("7.5") => 7
normalDk = gunlukSaat * 60
```

7,5 saatlik gün doğru temsil edilemez → FM eşiği ve saatlik ücret bozulur.

### FM / HT / UBGT çarpanı

- `tutar = (hourly * dakika/60) * carpan`
- `carpan=1.5` → OT saatinin **toplam** 1,5× ücreti (ilave 0,5 değil).
- HT/UBGT: sözleşme aylık bazına **ek** gross ADD; carpan=2 → o saatler için 2× saatlik **ilave** ödeme (baz maaş zaten içinde). Şirket politikası + yasal tatil ücreti yorumu onaylanmadan production’a yazılamaz.

Başarı kodu: `S77_D1_PARAMETER_SEMANTICS_CONFIRMED`

## 7. Resmî kaynak paketi (yasal sınıf — yazılmadı)

Erişim tarihi: 2026-07-17. Resmî Gazete PDF doğrudan fetch timeout; değerler CSGB / GİB tebliğ atıfları + ikincil çapraz kontrol ile derlendi. Production write yapılmadığı için bu paket “approved for write” değildir.

| Kod | Sistem değeri (taslak) | Birim | Geçerlilik | Kurum / belge | Doğrulama |
| --- | ---: | --- | --- | --- | --- |
| ASGARI_UCRET_BRUT | 33030.00 | TRY | 2026-01-01…2026-12-31 | Asgari Ücret Tespit Komisyonu Kararı 2025/1; RG 26.12.2025 / 33119; CSGB e-bülten | günlük 1101 × 30 |
| SGK_GUNLUK_TABAN | 1101.00 | TRY | 2026 | Aynı karar (günlük asgari) | OK |
| SGK_GUNLUK_TAVAN | 9909.00 | TRY | 2026 | Aylık tavan 297270 / 30 (asgari×9) | çapraz kontrol |
| SGK_ISCI_PRIM_ORANI | 0.14 | ORAN | yürürlükte | 5510 sayılı Kanun | OK |
| ISSIZLIK_ISCI_PRIM_ORANI | 0.01 | ORAN | yürürlükte | 4447 / işsizlik sigortası | OK |
| DAMGA_VERGISI_ORANI | 0.00759 | ORAN | 2026 | 488 sayılı Kanun ekli tablo; 71 sn. Damga GT (ücret ‰7,59) | OK |
| GELIR_VERGISI_DILIM_1_LIMIT | 190000 | TRY | 2026 | GVK GT Seri No: 332; RG 31.12.2025 33124 (5. Mük.) — **ücret** tarifesi | OK |
| GELIR_VERGISI_DILIM_2_LIMIT | 400000 | TRY | 2026 | aynı | OK |
| GELIR_VERGISI_DILIM_3_LIMIT | 1500000 | TRY | 2026 | ücret 3. dilim (ücret-dışı 1.000.000 değil) | OK |
| GELIR_VERGISI_DILIM_4_LIMIT | 5300000 | TRY | 2026 | aynı | OK |
| GELIR_VERGISI_DILIM_1..5_ORAN | 0.15 / 0.20 / 0.27 / 0.35 / 0.40 | ORAN | 2026 | aynı | OK |
| ASGARI_UCRET_GELIR_VERGISI_ISTISNA_MATRAHI | 28075.50 | TRY | 2026 | Asgari brüt − SGK%14 − işsizlik%1 (= GV matrahı); net asgari 28075,50 ile tutarlı | türev |
| ASGARI_UCRET_DAMGA_VERGISI_ISTISNA_MATRAHI | 33030.00 | TRY | 2026 | Asgari brüt = damga istisna matrahı | türev |

Şirket sınıfı (yazılmadı — onay yok):

| Kod | Durum |
| --- | --- |
| NORMAL_AY_GUN_SAYISI | Medisa puantaj/ücret politikası gerekli |
| GUNLUK_CALISMA_SAATI | Medisa + engine integer defect |
| AYLIK_NORMAL_CALISMA_SAATI | Medisa; motor formülünde unused |
| FAZLA_MESAI_CARPANI | Medisa + toplam-katsayı semantiği |
| HAFTA_TATILI_CARPANI | Medisa + gross-ADD semantiği |
| UBGT_CARPANI | Medisa + gross-ADD semantiği |

Başarı kodları: `S77_D1_OFFICIAL_SOURCE_PACKET_COMPLETE` (yasal taslak), `S77_D1_MARCH_2026_PARAMETER_MATRIX_APPROVED` **hayır** (şirket + engine nedeniyle write-onay yok)

## 8. Bordro devir

| Öğe | Değer |
| --- | --- |
| Owner tablo | `personel_bordro_devirleri` |
| Owner API | `POST/GET /maas-hesaplama/devirler` → `PersonelBordroDevirService` |
| Snapshot personel | `#1` Ayşe Yılmaz, dönem 2026-03 |
| Gerekli | Ocak+Şubat 2026 kümülatif GV matrah/vergi (tahmin yasak) |
| Kaynak | Gerçek bordro / muhasebe pusulası **bulunamadı** (repo/API’de yok) |

Başarı kodları: `S77_D1_CARRYOVER_OWNER_CONFIRMED`, `S77_D1_MARCH_CARRYOVER_VERIFIED` **hayır** → kaynak blok

## 9. Production data write

| İşlem | Sonuç |
| --- | --- |
| mevzuat_parametreleri INSERT | **yapılmadı** |
| personel_bordro_devirleri INSERT | **yapılmadı** |
| POST …/hesapla | **yapılmadı** |
| Backup (pre-write) | gerekmedi (mutation yok) |

## 10. Preflight after (değişmedi)

Aynı: blocker 25, `hesaplanabilir_mi=false`.  
S77-C snapshot hash değişmedi.

## 11. Engine defect kaydı

| Alan | Değer |
| --- | --- |
| Hatalı nokta | `MaasHesaplamaEngine::paramInt` + `GUNLUK_CALISMA_SAATI` |
| Beklenen | 7,5 saat (veya şirket onayıyla doğru kesir) |
| Gerçek | integer truncate → 7 |
| Resmî dayanak | 4857 İş Kanunu md. 63 (haftalık 45 saat) — yaygın 7,5s/gün |
| Etkilenen | saatlik ücret, FM eşiği, HT/UBGT saat tutarı |
| Production aday | oluşmadı |
| İptal | gerekmez |
| Önerilen fix | `paramRate`/`Money` ile kesirli saat; veya dakika bazlı parametre; tam regression + S77-D1 yeniden |

## 12. Rol / smoke (regresyon)

| Kontrol | Sonuç |
| --- | --- |
| MUHASEBE hesaplama-preflight | 200 (blocked içerik) |
| BIRIM_AMIRI | 403 |
| migrate stub | 410 Gone |
| smoke:live | OK |

## 13. Final Git / CI

Bu checkpoint yalnız docs commit’idir.  
Kod/motor değişikliği yok (fix talimatı).

## 14. Açık riskler

1. Engine kesirli saat desteklemiyor.
2. HT/UBGT çarpanının gross-ADD + tam aylık baz ile birlikte yasal “çift ücret” yorumu şirket onayı ister.
3. `AYLIK_NORMAL_CALISMA_SAATI` zorunlu ama unused — catalog/engine uyumsuzluğu.
4. Ocak–Şubat 2026 bordro devri olmadan Mart adayı üretilemez.
5. Resmî Gazete PDF bu ortamdan timeout; write öncesi birincil PDF doğrulaması tekrarlanmalı.

## 15. Başarı kodları

| Kod | Durum |
| --- | --- |
| `S77_D1_START_GATE_OK` | OK |
| `S77_D1_BASELINE_INVENTORY_OK` | OK |
| `S77_D1_TEST_FIXTURE_ISOLATION_OK` | OK |
| `S77_D1_REQUIRED_PARAMETER_CATALOG_CONFIRMED` | OK |
| `S77_D1_PARAMETER_SEMANTICS_CONFIRMED` | OK |
| `S77_D1_OFFICIAL_SOURCE_PACKET_COMPLETE` | OK (yasal taslak; write yok) |
| `S77_D1_MARCH_2026_PARAMETER_MATRIX_APPROVED` | **NO** |
| `S77_D1_CARRYOVER_OWNER_CONFIRMED` | OK |
| `S77_D1_MARCH_CARRYOVER_VERIFIED` | **NO** |
| `S77_D1_ENGINE_DEFECT_BLOCKED` | **FINAL** |
| `S77_D_FULLY_COMPLETE` | **NO** |

## 16. Sonraki adım

1. S77-D fix: kesirli `GUNLUK_CALISMA_SAATI` (veya dakika parametresi) + HT/UBGT semantik netleştirme + `AYLIK_NORMAL` unused kararı.
2. Medisa: günlük saat, ay günü, FM/HT/UBGT çarpan onayı.
3. Muhasebe: personel `#1` Ocak–Şubat 2026 bordro kümülatifleri.
4. S77-D1’i yeniden çalıştır → hedef `S77_D_FULLY_COMPLETE` → ancak sonra S77-E.
