# S87 — Payroll Compliance Critical Gaps Kapanış

**Durum:** Otoriter motor (`MaasHesaplamaEngine`) + preflight (`PayrollComplianceGuard` / `MaasHesaplamaAdayService` / snapshot) + write-path guard’lar ile kritik uyum boşlukları kapatıldı. Migration `043` additive; canlıya bu PR kapsamında uygulanmaz.

## Karar matrisi

| Konu | Etiket | Karar |
|------|--------|--------|
| Serbest zaman (FM ücret bastırma, 1.5x dönüşüm, imzalı talep kanıtı) | **SIRKET_KARARI** + **TEKNIK_GUARD** | `SERBEST_ZAMAN` seçildiyse FM `ARTI` üretilmez (`SERBEST_ZAMAN_FM_UCRET_SUPPRESSED` BILGI). Kanıt eksikse API/preflight blocker. Çift etki fail-closed. |
| Normal hastalık ilk 2 gün işveren ödemez | **SIRKET_KARARI** | `gun_sirasi <= 2` + firma ödemez / politika HAYIR → EKSI `NORMAL_HASTALIK_ILK_2_GUN_ODENMEDI`. İş kazası / meslek hastalığı / analık ayrı. Gün 3+ BILGI. |
| Haftalık 45 saat = 2700 dk | **SIRKET_KARARI** + **RESMI_KURAL** | `2700` exact → FM yok; `2701+` → FM. FSC (%25) bandı kapalı. |
| 18 yaş altı FM / gece | **RESMI_KURAL** + **TEKNIK_GUARD** | Write-path hard block (puantaj, kapanış, tercih, SZ oluşum, snapshot, bordro). DOB yoksa `DOGUM_TARIHI_REQUIRED`. |
| Yıllık 270 saat (16200 dk) | **RESMI_KURAL** + **TEKNIK_GUARD** | `evaluateYillikLimit` + `yillik_fazla_calisma_kilitleri`; 16200 izin, 16201 reject, 15600 warning. |
| Devamsızlık + HT hak kaybı | **RESMI_KURAL** (md.46) + UI parity | Ayrı EKSI kalemleri; RAPOR/İZİN HT kaybı üretmez. |
| Zorunlu / olağanüstü çalışma istisna modeli | **BILINCLI_KAPSAM_DISI** | Ayrı istisna modeli yok. |

## Owner’lar

- Motor: `api/src/Services/Payroll/MaasHesaplamaEngine.php`
- Guard: `api/src/Services/Payroll/PayrollComplianceGuard.php`
- Aday/preflight: `api/src/Services/MaasHesaplamaAdayService.php`
- Snapshot: `api/src/Services/MaasHesaplamaSnapshotService.php`
- UI: `FazlaCalismaOdemeTercihiPanel`, `hesaplaDevamsizlikKesintiOzeti`, `hastalik-rapor-politikasi`
- Migration: `api/migrations/043_payroll_compliance_critical_gaps.sql`

## Doğrulama

- `php tests/php/MaasHesaplamaEngineTestRunner.php`
- `php tests/php/PayrollComplianceGuardTestRunner.php`
- `tests/unit/s87-payroll-compliance-043-migration*.test.ts` (+ MariaDB runtime)
- `tests/unit/devamsizlik-hafta-tatili-parity.test.ts`
- `tests/unit/serbest-zaman-bordro-butunlugu.source.test.ts`
- Full: `npm run typecheck && npm run test && npm run build && npm run e2e`
