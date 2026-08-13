# 110 — Master Closure Gap Registry

**Amaç:** PersonelMedisa başlangıçtan bugüne tek canonical açık/kapalı kayıt.
**Tür:** Audit + reconciliation (runtime feature / migration / production write yok).
**Audit tarihi:** 2026-08-12
**Classification hardening:** 2026-08-12 (PR #149 follow-up)
**Audit branch:** `chore/master-closure-audit`
**Baseline main / origin/main:** `72818720ae9dad9a77c31c933806a72acdc7bafd`
**S3F merge SHA:** `9e1b5c85049d5f2aada84ae59b2be926f0bc6441` (PR #148)
**Docs closure SHA (baseline):** `72818720ae9dad9a77c31c933806a72acdc7bafd`
**Faz adı uydurulmadı:** Roadmap zinciri S3A→S3F; `S3G`/`S4` repo’da yok. Sonraki ürün aşaması = görsel sistem + bu registry üzerinden kapanış.

**Statü sözlüğü (zorunlu tek değer — çift statü yasak):**
`CLOSED` · `CODE_GAP` · `BUSINESS_DECISION_REQUIRED` · `OPS_ROLLOUT` · `INTENTIONAL_DEFER` · `NOT_APPLICABLE` · `DOC_STALE`

**Metadata (statü değildir):** `CONDITIONAL_SCOPE` · `USER_GATED` · `USER_GATED_DATA_COMPLETION` · `VERIFY_REQUIRED` · `HISTORICAL_SNAPSHOT_PRESERVED` · `NO_PII_COMMITTED`

**Public repo:** Exact real-personnel row counts, missing-field tallies, and person-level source data stay **out of** canonical docs. Safe flags only.

---

## 1. Audit baseline

| Alan | Değer |
| --- | --- |
| LOCAL_HEAD_BEFORE | `72818720ae9dad9a77c31c933806a72acdc7bafd` |
| ORIGIN_MAIN_BEFORE | `72818720ae9dad9a77c31c933806a72acdc7bafd` |
| WORKTREE_BEFORE | clean |
| STASH_BEFORE | `stash@{0}: On fix/payroll-compliance-critical-gaps: tmp-before-ops` (**dokunulmadı**) |
| Branch | `main` → `chore/master-closure-audit` |
| Expected tip (code) | migration `058` last file |
| Production tip (docs/S3F) | **058** (`109`) |
| Scan roots | `api/src`, `src`, `tests`, `api/migrations` 001–058, `docs/guncel`, `ops`, `scripts`, `README`, `CURRENT_STATE`, PR/commit history (merged QR/S3F) |

**Yöntem:** Eski belgede “açık” yazması tek başına OPEN sayılmaz. Her aday CURRENT MAIN koduyla yeniden doğrulandı.

---

## 2. Production baseline (invariants — değiştirilmez)

| Invariant | Değer |
| --- | --- |
| PRODUCTION_MIGRATION_TIP | **058** |
| S3F | **CLOSED_PRODUCTION** |
| QR_PIPELINE | S3C–S3F **CLOSED** |
| QR algorithms | `QR_INTERVAL_V1`, `QR_PUANTAJ_CANDIDATE_V1`, `QR_PUANTAJ_DECISION_V1`, `QR_CANDIDATE_HASH_V2` |
| REAL_REFERENCE_DATA | USER_GATED / NOT_YET_ROLLED_OUT |
| REAL_PERSONNEL_DATASET | USER_GATED |
| REAL_PERSONNEL_IMPORTED | **NO** |
| SOURCE_DATA_REQUIRES_COMPLETION | yes (ops; details outside public repo) |
| NO_PII_COMMITTED | **YES** |
| PERSONEL_BINDING_REAL_ROLLOUT | NOT_STARTED (schema `056` mevcut) |
| REAL_QR_EMPLOYEE_ROLLOUT | NOT_STARTED |

Smoke/test personeller korunur; gerçek personel sayılmaz. Yetkisiz eski gerçek personel importu geri alınmıştır. Gerçek personel kullanıcı onayı olmadan import edilmez.

---

## 3. Closed systems (yeniden açma)

Aşağıdakiler CURRENT MAIN’de bozulmuş değilse OPEN yapılmaz:

| Sistem | Kanıt özeti |
| --- | --- |
| Annual leave manual entitlement adjustment | `YillikIzinHakDuzeltmeLedgerService` + mig `055` |
| Default branch persistence | mig `051` `users.varsayilan_sube_id` |
| Canonical runtime role model | `RolePermissions` + mig `054` (ENUM shrink ayrı madde) |
| Half-day UBGT calculation owner | payroll/UBGT owner zinciri |
| Late/early tolerance + discipline | mig `052` |
| Retention policy / trigger / legal hold / request / approval | mig `053` + Retention/* (physical execute ayrı) |
| PERSONEL self-service technical foundation | `MeController` + S3A |
| PERSONEL user-personel binding schema | mig `056` |
| S3C dynamic QR | `106` CLOSED_PRODUCTION |
| S3D QR interval derivation | `107` |
| S3E QR puantaj candidate | `108` |
| S3F reviewed QR candidate decision/apply | `109` / PR #148 |
| Partial-time SGK sealed-hours integration | payroll compliance owners |
| SGK mapping/policy dual control | mig `047`/`048` owners |
| Personel import dry-run / apply / history / reference pack | mig `046` + import services |
| Payroll engine / salary snapshot/revision/preflight/compliance | engine + `99` |
| Holiday/FSC/FM collision calculation owner | payroll owners |
| Role permission narrowing | `RolePermissions` canonical |

---

## 4. CODE_GAP registry

### MG-RET-PHYS-001 — Retention physical destruction executor

| Alan | Değer |
| --- | --- |
| Statü | **CLOSED** |
| Öncelik | **P1** |
| Domain | Retention / KVKK |
| Pack 2 | `112` / PR #151 — framework + evidence `059` + 7 handlers; flag default OFF |
| Pack 3B | `113` / PR #152 — PUANTAJ/BORDRO/SGK + `060` + snapshot-pin OPTION A + post-destroy reopen gate |
| Pack 3C | `114` / `fix/retention-physical-pack3c-final` — FAZLA/SERBEST/DISIPLIN/RAPOR/IS_KAZASI + generic ONAY_AUDIT; 15/15 typed executable |
| Mevcut | Plan/execute + registry + HTTP evaluate/execute; tüm katalog kategorileri typed handler ile executable; unknown ONAY_AUDIT entity fail-closed |
| Beklenen | OPS feature enable ayrı kapı; production migration apply ayrı onay |
| Kanıt | `PhysicalDestructionService`; `RetentionDestructionHandlerRegistry`; `DependentRetentionGate`; mig `059`/`060`; `112`/`113`/`114` |
| Neden kapandı | Mandatory category strategies explicit + executable; shared-source leaf/dependency gates; feature default OFF; Pack3C MariaDB matrix |
| Runtime | Flag OFF → `DESTRUCTION_EXECUTION_DISABLED`; unknown ONAY_AUDIT → `DESTRUCTION_HANDLER_POLICY_UNRESOLVED` |
| Prod veri | Write yok (bu tur) |
| İnsan kararı | Hayır — kalan açıklar OPS enable / production apply |
| Migration | `059`/`060` dosya mevcut; **production apply YOK**; Pack3C NEW_MIGRATION=NO |
| Prod write | Hayır |
| Owner | Retention |
| Acceptance | Onaylı talepte 15 kategori handler çalışır veya unknown fail-closed; audit/evidence; flag default OFF |

### MG-RET-MAN-001 — Archive manifest CREATE/LIFECYCLE wiring (13 kategori)

| Alan | Değer |
| --- | --- |
| Statü | **CLOSED** |
| Öncelik | **P1** |
| Domain | Retention |
| Kapanış | Retention Integrity Pack 1 (`fix/retention-manifest-integrity`) |
| Mevcut | `coverageMap`: resolver/fingerprint/manifest_creator **15/15**; period creators at PUANTAJ mühür/reseal, BORDRO KESINLESTI, SGK snapshot create, haftalık KAPANDI; termination creators expand `createPersonelLifecycleManifests` (BELGE/surec/OLAY/SAVUNMA); ONAY_AUDIT parent mint + S3F typed mint |
| Acceptance | coverageMap `manifest_creator=implemented` tüm katalog + lifecycle/MySQL tests |
| Ayrım | Physical destroy ayrı: `MG-RET-PHYS-001`. Historical backfill: OPS (bu turda production backfill yok). |

### MG-RET-S3F-001 — ONAY_AUDIT fingerprint material vs S3F decision ledger

| Alan | Değer |
| --- | --- |
| Statü | **CLOSED** |
| Öncelik | **P1** |
| Domain | Retention / QR |
| Kapanış | Retention Integrity Pack 1 |
| Mevcut | `resolveOnayAudit` typed path `QR_PUANTAJ_CANDIDATE_DECISION` loads ledger server-side; fingerprint material = immutable ledger fields; decision txn → `createQrPuantajDecisionOnayAuditManifest` |
| Acceptance | Ledger material fingerprint integrity’de; nonce idempotent; parent mismatch fail-closed |
| Ayrım | Physical destroy ayrı: `MG-RET-PHYS-001`. |

### MG-SZ-6M-001 — Serbest zaman 6 aylık deadline compliance / operational follow-up

| Alan | Değer |
| --- | --- |
| Statü | **CODE_GAP** |
| Öncelik | **P1** |
| Domain | Serbest zaman / compliance |
| Mevcut (var) | `son_kullanim_tarihi` persist; 6 ay son tarih hesabı (`hesaplaSonKullanimTarihi`); bakiye + `suresi_dolan_dakika` teknik parçaları |
| Eksik (gap) | Yaklaşan deadline görünürlüğü; overdue operasyonel görünürlük; İK/yönetici yüzeyi; raporlama; kararlaştırılacak compliance warning/block davranışı |
| Beklenen | Operational follow-up + (karar sonrası) compliance gate; dönüşüm owner yeniden yazılmaz |
| Kanıt | `serbest-zaman-event-motoru.ts`; `102` §7/§18; `PayrollComplianceGuard` SZ blocker’ları yalnız kanıt/çift etki |
| Payroll | Vade aşımında otomatik compliance block yok |
| Owner | Serbest zaman / Payroll compliance |
| Acceptance | Lot bazlı yaklaşan/overdue görünür + raporlanır; compliance davranışı kararlı ve testli |

### MG-OT-YEAR-PATH-001 — Yıllık FM yıl ataması owner path uyumsuzluğu

| Alan | Değer |
| --- | --- |
| Statü | **CODE_GAP** |
| Öncelik | **P1** |
| Domain | Fazla çalışma / 270 saat |
| Mevcut (kod yolları) | **A)** `HaftalikKapanisController::create` — 270 saat pre-write guard: `$yil = (int) substr($haftaBaslangic, 0, 4)` → **calendar start year**. **B)** `buildSnapshotSatir` / `hesaplaIsoHaftaNo` — persist `yil` → **ISO week-year** (`format('o')`). **C)** `aggregateYillik` / FE aggregate — öncelikle persisted `s.yil` → **ISO year**. **D)** `PayrollComplianceGuard::loadKapanmisYillikFazlaCalisma` — `hafta_baslangic` BETWEEN `yil-01-01` AND `yil-12-31` → **calendar-start filter**. |
| Örnek | Hafta `2025-12-29`–`2026-01-04`: create guard 2025; snapshot/aggregate ISO 2026; compliance calendar filter path’e göre 2025 veya 2026’dan dışlanma riski |
| Beklenen | Tek tutarlı yıl atama kuralı (policy `MG-OT-YEAR-POL-001` ile aynı) |
| Kanıt | `HaftalikKapanisController.php` (~88, ~553–858); `PayrollComplianceGuard` load SQL; `yillik-fazla-calisma-aggregate.ts` |
| İnsan kararı | Atama politikası ayrı: `MG-OT-YEAR-POL-001` |
| Owner | Haftalık kapanış / Payroll compliance |
| Acceptance | Create guard, persisted `yil`, aggregate ve compliance load aynı haftayı aynı yıla sayar |

### MG-ORG-LOC-001 — SGK işveren / sistem şubesi / çalışma lokasyonu schema ayrımı

| Alan | Değer |
| --- | --- |
| Statü | **CODE_GAP** |
| Öncelik | **P1** |
| Domain | Organizasyon / SGK |
| Mevcut | `subeler` + `personeller.sube_id`; ayrı `sgk_isveren` / `lokasyon` entity yok |
| Beklenen | Kilitli iş modeline (`MG-ORG-MODEL-001` CLOSED) uygun ayrı temsil: SGK işveren, sistem şubesi, çalışma lokasyonu |
| Kanıt | `001_initial_schema.sql`; import contract’ta lokasyon yok |
| Runtime | Çalışma lokasyonu sistem şubesi / SGK merkezi ile karışabilir |
| Migration | Evet (implementation turu) |
| Owner | Org / Personel |
| Acceptance | Personel kaydı üç kavramı kaybetmeden taşıyabilir |

---

## 5. BUSINESS_DECISION_REQUIRED registry

### MG-OT-YEAR-POL-001 — Yıl değiştiren haftada FM dakikalarının yılı

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** |
| Öncelik | **P1** |
| Domain | 270 saat |
| Soru | Günlük takvim yılına split mi, yoksa tüm hafta hafta-başı/ISO yıla mı? |
| Mevcut kod | Bütün haftalık FM tek `fazla_calisma_dakika`; path’ler tutarsız → `MG-OT-YEAR-PATH-001` |
| Acceptance | Yazılı şirket/İK kararı + tek owner kuralı |

### MG-SGK-1514-001 — Ücret/SGK çalışma dönemi 1–son vs 15–14

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** |
| Metadata | `CONDITIONAL_SCOPE` |
| Öncelik | **P1** |
| Domain | SGK |
| Soru | Medisa/Karyapı/Şenay için dönem ayın 1–son mu, 15–sonraki ay 14 mü? Sigortalı bazında 15–14 var mı? |
| Mevcut | `SgkKatalogPreviewService`: `preview_modu=BLOCKER_ONLY`, `aktif_edildi_mi=false`, dönem null; motor tip string kabul eder ama preview aktive etmez |
| Not | Generic 15–14 motor yazılmaz; ihtiyaç doğrulanana kadar scope conditional |
| Kanıt | `SgkKatalogPreviewService.php`; `94` madde 6 |

### MG-ZORUNLU-001 — Zorunlu / olağanüstü çalışma istisna modeli ihtiyacı

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** |
| Öncelik | **P2** |
| Domain | Fazla çalışma |
| Mevcut | Ayrı exception model yok (`99` BILINCLI_KAPSAM_DISI; `102` §18) |
| Soru | Medisa için gerçek ihtiyaç var mı? |
| Not | İhtiyaç yok denirse aynı canonical konu `NOT_APPLICABLE` veya `INTENTIONAL_DEFER`’e geçer. Aynı anda hem karar bekleyen hem intentional defer olamaz. |

### MG-ORG-ATTR-001 — Bölüm / Birim / Pozisyon native alan kararı

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** |
| Öncelik | **P2** |
| Domain | Personel org model |
| Mevcut | Native: `departman`, `gorev`, `personel_tipi`, `sube`, `bagli_amir_id`. Bölüm / birim / pozisyon native yok |
| Soru | Bu üç seviye native canonical org alanı mı tutulacak, yoksa mevcut Departman / Görev / Personel Tipi mapping yeterli mi? |
| Not | Çalışma **lokasyonu** kilitli gereksinim → `MG-ORG-LOC-001` CODE_GAP. Bu ID lokasyonu kapsamaz. |
| Kanıt | `001_initial_schema.sql`; `src/types/personel.ts`; import columns |
| Acceptance | Yazılı karar; gerekirse sonra CODE_GAP’e çevrilir |

### MG-IMPORT-MAP-001 — Kaynak Excel → import contract eşlemesi

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** |
| Öncelik | **P1** |
| Domain | Personel import |
| Sorular | Kaynak sıra/no alanı sicil mi? Ad Soyad split kuralı? Departman→Departman / Unvan→Görev / Grup→Personel Tipi onay mı? Eksik şirket listeleri nasıl tamamlanır? |
| Yasak | Validator gevşetme; sicil uydurma; güvenilmez auto-split; telefon/doğum uydurma; ücret/SGK’yı master import’a zorlama; PII’nin public repo’ya yazılması |
| Kanıt contract | `PersonelImportDryRunService` REQUIRED: tc, sicil, ad, soyad, dogum_tarihi, telefon, ise_giris, sube, departman, gorev, personel_tipi; OPTIONAL: dogum_yeri, kan_grubu, acil_*; FORBIDDEN: ücret/SGK/devir kolonları |

---

## 6. OPS_ROLLOUT registry

| ID | Başlık | Öncelik | Metadata | Durum özeti | Owner |
| --- | --- | --- | --- | --- | --- |
| MG-OPS-PERSONEL-001 | Gerçek personel import | P0 | `USER_GATED` | Onay olmadan import yok; `REAL_PERSONNEL_IMPORTED=NO` | Ops + kullanıcı |
| MG-IMPORT-DATA-001 | Kaynak personel dataset completion | P1 | `USER_GATED_DATA_COMPLETION` | Required alanlar kaynakta tamamlanır; validator gevşetilmez; veri uydurulmaz; exact tallies public repo dışı | Ops + İK |
| MG-OPS-ORG-001 | Gerçek org/şube/referans rollout | P0 | `USER_GATED` | NOT_YET | Ops |
| MG-OPS-BIND-001 | PERSONEL binding gerçek rollout | P1 | `USER_GATED` | Schema `056` var; rollout NOT_STARTED | Ops / İK |
| MG-OPS-QR-001 | Gerçek çalışan QR rollout | P1 | `USER_GATED` | Pipeline CLOSED; employee rollout NOT_STARTED | Ops |
| MG-OPS-SGK-CAT-001 | SGK resmi katalog / DOGRULANMIS_TAM / şirket politikası | P0 | `VERIFY_REQUIRED` | Code fail-closed; prod state repo’dan doğrulanmaz | Ops + `94`/`95` |
| MG-OPS-UBGT-001 | UBGT authoritative calendar seed | P0 | `USER_GATED` | Engine/schema var (`039` seedless); gerçek tatil tarihi ops gate | Ops |
| MG-OPS-POLICY-001 | Bordro çalışma politikası canlı parametre onayı | P1 | `USER_GATED` | `91` form + `95` runbook | Ops / yönetim |
| MG-OPS-ENUM-INV-001 | Legacy role production inventory (shrink öncesi) | P2 | `VERIFY_REQUIRED` | `SGK_KARAR_ONAY_YETKILISI` / `IDARI_ISLER` inventory | Ops + DBA |
| MG-OPS-DEPLOY-001 | Exact-SHA cPanel yayın kanıtı / manuel upload | P2 | — | FTP/sunucu tarihsel blocker; ürün beyni değil | Ops |

---

## 7. INTENTIONAL_DEFER registry

| ID | Başlık | Öncelik | Kanıt | Not |
| --- | --- | --- | --- | --- |
| MG-DEF-QR-CORR-001 | QR anomaly → GIRIS_CIKIS_DUZELTME kontrollü UX köprüsü | P2 | `106` D5 later; `107` discovery-only; UI hint only | Yeni correction engine yok |
| MG-DEF-PAY-SELF-001 | PERSONEL maaş/bordro self-view | P3 | `105` OUT_OF_SCOPE; `RolePermissions` PERSONEL | CODE_GAP değil |
| MG-DEF-I13-001 | User convenience: telefon / kullanici_tipi / notlar | P3 | `101` DEFER_WITH_GATE; `kullanici-api-contract.ts` | `051` varsayılan şube ile karıştırma |
| MG-DEF-ENUM-001 | Legacy role ENUM shrink | P2 | `054` “Does NOT shrink”; 055–058 dokunmaz | Runtime canonical doğru; teknik borç |
| MG-DEF-FSC-001 | FSC %25 aktif bant | P3 | S87 kapalı | — |
| MG-DEF-PAY-OUT-001 | Bordro PDF / banka / SGK bildirgesi çıktısı | P3 | `100` / `102` FUTURE | Kısmi CSV var |
| MG-DEF-RET-HTTP-001 | Destruction evaluate/execute HTTP | P3 | `GET .../evaluate`, `POST .../execute` Pack 2 | **CLOSED** (`112`) |

---

## 8. NOT_APPLICABLE registry

| ID | Başlık | Neden |
| --- | --- | --- |
| MG-NA-SECOND-ENGINE | İkinci bordro motoru | Yasak (`102`) |
| MG-NA-AUTO-QR-CORR | Missing-scan auto correction | S3C–S3F non-goal |
| MG-NA-AUTO-APPLY | QR AUTO_APPLY / CREATE_PUANTAJ | S3F non-goal |
| MG-NA-IMPORT-UCRET | Master import’tan ücret/SGK yazımı | Forbidden columns by design |

---

## 9. CLOSED registry (docs / decisions — DOC_STALE yok)

| ID | Konu | Statü | Metadata | Not |
| --- | --- | --- | --- | --- |
| MG-ORG-MODEL-001 | Şirket / SGK / sistem şubesi / çalışma lokasyonu işletme modeli | **CLOSED** | — | Canonical business decision locked 2026-08-12. Schema uygulaması `MG-ORG-LOC-001`. |
| MG-DOC-CS-001 | `CURRENT_STATE.md` reconciliation | **CLOSED** | — | PR #149 ile güncellendi |
| MG-DOC-102-001 | `102` tip/QR/S3F header reconciliation | **CLOSED** | — | PR #149 ile güncellendi |
| MG-DOC-101-001 | `101` I13 checkpoint | **CLOSED** | `HISTORICAL_SNAPSHOT_PRESERVED` | Bugün SoT değil; bilinçli tarihsel |
| MG-DOC-103-001 | `103` role consolidation checkpoint | **CLOSED** | `HISTORICAL_SNAPSHOT_PRESERVED` | Bugün SoT değil |
| MG-DOC-105-TIP | `105` S3A discovery header tip | **CLOSED** | `HISTORICAL_SNAPSHOT_PRESERVED` | Faz anı korunur |
| MG-RET-MAN-001 | Archive manifest CREATE/LIFECYCLE 15/15 | **CLOSED** | — | Integrity Pack 1 |
| MG-RET-S3F-001 | S3F ledger → ONAY_AUDIT fingerprint | **CLOSED** | — | Integrity Pack 1 |

**CANONICAL_DOC_STALE = 0.** Historical snapshot’larda eski bilgi final completion blocker değildir.

### MG-ORG-MODEL-001 — kilitli karar özeti (CLOSED)

**SGK / bordro ana yapılar:**
1. Medisa — SGK merkezi = Karabük
2. Karyapı — SGK merkezi = Konya
3. Şenay Mobilya — kendi SGK yapısı

**Operasyonel sistem şubeleri:**
MEDİSA: Merkez Karabük, Kayseri, Giresun, Ankara, İstanbul
KARYAPI: Merkez Konya, Ankara, Kayseri, İstanbul
DİĞER: Şenay Mobilya

**Çalışma lokasyonu ayrı kavramdır** (ör. İzmir çalışır / SGK Karabük / sistem şubesi Merkez Karabük). Sakarya ayrı SGK işyeri veya otomatik ayrı sistem şubesi değildir.

---

## 10. Personel / org rollout dependencies

```text
MG-ORG-MODEL-001 (CLOSED — karar kilitli)
  → MG-ORG-LOC-001 (CODE_GAP — schema)
  → MG-ORG-ATTR-001 (BUSINESS_DECISION_REQUIRED — bölüm/birim/pozisyon)
  → MG-OPS-ORG-001 (referans seed, USER_GATED)
  → MG-IMPORT-MAP-001 (BUSINESS_DECISION_REQUIRED)
  → MG-IMPORT-DATA-001 (OPS_ROLLOUT — USER_GATED_DATA_COMPLETION)
  → MG-OPS-PERSONEL-001 (real personnel import, USER_GATED)
  → MG-OPS-BIND-001 → MG-OPS-QR-001
```

Import contract (kod, değişmedi):

| | Alanlar |
| --- | --- |
| REQUIRED | tc_kimlik_no, sicil_no, ad, soyad, dogum_tarihi, telefon, ise_giris_tarihi, sube, departman, gorev, personel_tipi |
| OPTIONAL | dogum_yeri, kan_grubu, acil_durum_kisi, acil_durum_telefon |
| FORBIDDEN | maaş/ücret/SGK/devir kolonları |

---

## 11. Production gates

1. CODE_GAP = 0 olmadan “ürün tamam” denmez.
2. `CANONICAL_DOC_STALE = 0` (sağlandı).
3. Gerçek personel/org/SGK/UBGT write yalnız `95` + kullanıcı onayı.
4. Migration tip production = 058; yeni migration yalnız ayrı onay.
5. Physical destruction yalnız manifest + S3F fingerprint coverage + handler + legal review sonrası.
6. Stash / force-push / hard reset yasak (audit protokolü).
7. Public repo’ya PII / exact personnel tallies yazılmaz.

---

## 12. Recommended closure order

1. Canonical docs (PR #149): `CURRENT_STATE` + `102` + bu registry
2. Business inputs: `MG-SGK-1514`, `MG-OT-YEAR-POL`, `MG-IMPORT-MAP`, `MG-ORG-ATTR`, `MG-ZORUNLU`
3. Code P1: `MG-OT-YEAR-PATH-001` (policy sonrası veya tutarlılık fix)
4. Code P1: `MG-SZ-6M-001`
5. Code P1: `MG-RET-PHYS-001` — **CLOSED** (Pack 2+3B+3C / `112`–`114`)
6. Code P1: `MG-ORG-LOC-001`
7. Ops: UBGT seed, SGK catalog, org seed, dataset completion, personel import, binding, QR employee
8. Defer pack: QR correction UX, I13 fields, ENUM shrink, payroll self-view, pay outputs

---

## 13. Final completion definition

**“PersonelMedisa tamamlandı” yalnız şu halde:**

- `CODE_GAP = 0`
- `UNVERIFIED_CRITICAL = 0`
- `CANONICAL_DOC_STALE = 0`
- Kalan maddeler yalnız: `CLOSED` · `NOT_APPLICABLE` · `INTENTIONAL_DEFER` · `USER_GATED` `OPS_ROLLOUT`

Historical snapshot belgeleri (`HISTORICAL_SNAPSHOT_PRESERVED`) blocker sayılmaz.

**“Bu faz bitti”** yalnız o fazın kendi acceptance’ı için kullanılır (ör. S3F CLOSED_PRODUCTION).

---

## 14. Coverage matrix — retention categories

Destroy eligibility: Pack 3C (`114`) — **15/15 typed executable** (feature flag default OFF). Manifest creator: Pack 1 CLOSED (`MG-RET-MAN-001`).

| Category | Resolver | Fingerprint | Manifest creator | Lifecycle trigger | Legal hold | Destroy eligibility |
| --- | --- | --- | --- | --- | --- | --- |
| PERSONEL_OZLUK | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |
| ISE_GIRIS_CIKIS | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |
| PERSONEL_BELGE | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |
| PUANTAJ | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3B) |
| BORDRO | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3B) |
| SGK_EKSIK_GUN | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3B) |
| FAZLA_CALISMA | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3C) |
| SERBEST_ZAMAN | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3C) |
| ONAY_AUDIT | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2 typed + Pack3C generic) |
| IZIN | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |
| RAPOR | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3C) |
| IS_KAZASI | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3C) |
| DISIPLIN | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3C) |
| OLAY | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |
| SAVUNMA | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |

---

## 15. Audit counts (classification hardening)

| Statü | Adet (unique registry ID) | IDs |
| --- | --- | --- |
| **CODE_GAP** | **3** | SZ-6M, OT-YEAR-PATH, ORG-LOC |
| **BUSINESS_DECISION_REQUIRED** | **5** | OT-YEAR-POL, SGK-1514, ZORUNLU, ORG-ATTR, IMPORT-MAP |
| **OPS_ROLLOUT** | **10** | OPS-PERSONEL, IMPORT-DATA, OPS-ORG, OPS-BIND, OPS-QR, OPS-SGK-CAT, OPS-UBGT, OPS-POLICY, OPS-ENUM-INV, OPS-DEPLOY |
| **INTENTIONAL_DEFER** | **7** | QR-CORR, PAY-SELF, I13, ENUM, FSC, PAY-OUT, RET-HTTP |
| **NOT_APPLICABLE** | **4** | SECOND-ENGINE, AUTO-QR-CORR, AUTO-APPLY, IMPORT-UCRET |
| **DOC_STALE** | **0** | — |
| **CLOSED** (registry section 9 + retention pack) | **9** | ORG-MODEL, DOC-CS, DOC-102, DOC-101, DOC-103, DOC-105, RET-MAN, RET-S3F, **RET-PHYS** |
| Closed systems (section 3) | 18 | yeniden açılmadı |

**MUST_FIX_NOW (= CODE_GAP P1 listesi):**
1. `MG-SZ-6M-001`
2. `MG-OT-YEAR-PATH-001`
3. `MG-ORG-LOC-001`

P2 BUSINESS (`MG-ORG-ATTR`, `MG-ZORUNLU`) final completion için açık kalabilir ama CODE_GAP değildir; karar sonrası gerekirse CODE_GAP’e çevrilir.

**UNVERIFIED_CRITICAL = 0**
**CANONICAL_DOC_STALE = 0**

---

## 16. Cross-links

- `CURRENT_STATE.md` — tek güncel durum
- `102-hesaplama-cevap-haritasi.md` — hesap okuma haritası
- `109-s3f-…` — S3F production baseline
- `95-s96-release-ops-runbook.md` — ops protokol
- `94-…` — SGK manuel kanıt
- `101` / `105` — I13 / S3A historical decisions (`HISTORICAL_SNAPSHOT_PRESERVED`)
