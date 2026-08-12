# 110 — Master Closure Gap Registry

**Amaç:** PersonelMedisa başlangıçtan bugüne tek canonical açık/kapalı kayıt.  
**Tür:** Audit + reconciliation (runtime feature / migration / production write yok).  
**Audit tarihi:** 2026-08-12  
**Audit branch:** `chore/master-closure-audit`  
**Baseline main / origin/main:** `72818720ae9dad9a77c31c933806a72acdc7bafd`  
**S3F merge SHA:** `9e1b5c85049d5f2aada84ae59b2be926f0bc6441` (PR #148)  
**Docs closure SHA (baseline):** `72818720ae9dad9a77c31c933806a72acdc7bafd`  
**Faz adı uydurulmadı:** Roadmap zinciri S3A→S3F; `S3G`/`S4` repo’da yok. Sonraki ürün aşaması = görsel sistem + bu registry üzerinden kapanış.

**Statü sözlüğü (zorunlu tek değer):**  
`CLOSED` · `CODE_GAP` · `BUSINESS_DECISION_REQUIRED` · `OPS_ROLLOUT` · `INTENTIONAL_DEFER` · `NOT_APPLICABLE` · `DOC_STALE`

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

**Yöntem:** Eski belgede “açık” yazması tek başına OPEN sayılmaz. Her aday CURRENT MAIN koduyla yeniden doğrulandı. Kodlandıysa `CLOSED` / `CLOSED_HISTORICAL`.

---

## 2. Production baseline (invariants — değiştirilmez)

| Invariant | Değer |
| --- | --- |
| PRODUCTION_MIGRATION_TIP | **058** |
| S3F | **CLOSED_PRODUCTION** |
| QR_PIPELINE | S3C–S3F **CLOSED** |
| QR algorithms | `QR_INTERVAL_V1`, `QR_PUANTAJ_CANDIDATE_V1`, `QR_PUANTAJ_DECISION_V1`, `QR_CANDIDATE_HASH_V2` |
| REAL_REFERENCE_DATA | USER_GATED / NOT_YET_ROLLED_OUT |
| REAL_PERSONNEL | USER_GATED / NOT_YET_IMPORTED |
| PERSONEL_BINDING_REAL_ROLLOUT | NOT_STARTED (schema `056` var; gerçek binding 0 / ops) |
| REAL_QR_EMPLOYEE_ROLLOUT | NOT_STARTED |

Smoke personeller 1–4 korunur; gerçek personel sayılmaz. Yetkisiz eski 58-personel import geri alınmıştır. Gerçek personel kullanıcı onayı olmadan import edilmez.

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
| Statü | **CODE_GAP** |
| Öncelik | **P1** |
| Domain | Retention / KVKK |
| Mevcut | Request → GM approve → eligibility CLOSED; `RetentionPolicyService::executeDestruction` her zaman `EXECUTION_HANDLER_NOT_IMPLEMENTED`; HTTP execute route yok |
| Beklenen | Onay sonrası kategoriye özel fiziksel delete/anonymize handler + audit |
| Kanıt | `api/src/Services/Retention/RetentionPolicyService.php` (`CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED`); `DestructionWorkflowService.php`; `RetentionController` / `Router` (eligibility/request/approve only) |
| Neden açık | Altyapı var; fiziksel executor bilinçli stub — ürün kapanışı için hâlâ kod eksiği |
| Runtime | İmha tamamlanamaz (fail-closed) |
| Payroll / SGK | Yok |
| KVKK/retention | Fiziksel imha yok |
| Prod veri | Write yok (güvenli) |
| İnsan kararı | Handler politikası (delete vs anonymize) için evet |
| Migration | Muhtemel audit/evidence tabloları — tasarım sonrası |
| Prod write | Hayır (bu tur) |
| Owner | Retention |
| Kapanış sırası | Manifest coverage sonrası |
| Acceptance | Onaylı talepte kategori handler çalışır; audit yazılır; yanlış kategori fail-closed |

### MG-RET-MAN-001 — Archive manifest creator wiring (13 kategori)

| Alan | Değer |
| --- | --- |
| Statü | **CODE_GAP** |
| Öncelik | **P1** |
| Domain | Retention |
| Mevcut | `coverageMap`: resolver/fingerprint **15/15**; `manifest_creator` yalnız `PERSONEL_OZLUK` + `ISE_GIRIS_CIKIS`; auto mint yalnız `ISTEN_AYRILMA` → `createPersonelLifecycleManifests` |
| Beklenen | Her kategori için lifecycle/period trigger’da manifest persist |
| Kanıt | `RetentionSourceAdapterService::coverageMap`; `ArchiveManifestService::createPersonelLifecycleManifests`; `SureclerController` ISTEN_AYRILMA |
| Eksik kategoriler | PERSONEL_BELGE, PUANTAJ, BORDRO, SGK_EKSIK_GUN, FAZLA_CALISMA, SERBEST_ZAMAN, ONAY_AUDIT, IZIN, RAPOR, IS_KAZASI, DISIPLIN, OLAY, SAVUNMA |
| Runtime | Destruction eligibility integrity fail (`ARCHIVE_MANIFEST_MISSING*`) |
| KVKK | Arşiv bütünlüğü eksik |
| Owner | Retention |
| Kapanış sırası | PHYS-001’den önce veya birlikte |
| Acceptance | coverageMap `manifest_creator=implemented` tüm katalog + lifecycle test |

### MG-RET-S3F-001 — S3F decision ledger ↔ ONAY_AUDIT manifest

| Alan | Değer |
| --- | --- |
| Statü | **CODE_GAP** |
| Öncelik | **P1** |
| Domain | Retention / QR |
| Mevcut | `109` preferred class `ONAY_AUDIT` + parent PUANTAJ; ledger tablosu manifest/fingerprint’e bağlı değil; Qr paketinde Retention wire yok |
| Beklenen | Ledger satırları retention class + fingerprint + manifest |
| Kanıt | `109` RETENTION_GAP; `QrPuantajCandidateDecisionLedgerService`; `RetentionSourceAdapterService::resolveOnayAudit` (parent-derived, ledger-aware değil) |
| Owner | Retention + QR |
| Acceptance | Ledger destruction eligibility integrity geçebilir |

### MG-SZ-6M-001 — Serbest zaman 6 ay kullandırma takibi (ürün)

| Alan | Değer |
| --- | --- |
| Statü | **CODE_GAP** |
| Öncelik | **P1** |
| Domain | Serbest zaman / compliance |
| Mevcut | `son_kullanim_tarihi` + `suresi_dolan_dakika` alan/calc var; approaching-deadline UX, HR görünürlüğü, compliance warning/blocker **yok** |
| Beklenen | Deadline/overdue/yaklaşan uyarı + yönetici/İK görünürlüğü + (karara göre) compliance gate |
| Kanıt | `serbest-zaman-event-motoru.ts` (`hesaplaSonKullanimTarihi`); `102` §7/§18; `PayrollComplianceGuard` SZ blocker’ları yalnız kanıt/çift etki |
| Not | UCRET/SERBEST_ZAMAN dönüşüm owner’ı yeniden yazılmaz |
| Payroll | Vade aşımında otomatik blok yok |
| Owner | Serbest zaman / Payroll compliance |
| Acceptance | Lot bazlı vade görünür; yaklaşan/overdue raporlanır; kararlı compliance davranışı testli |

### MG-OT-YEAR-PATH-001 — Yıllık FM aggregate vs compliance yıl filtresi uyumsuzluğu

| Alan | Değer |
| --- | --- |
| Statü | **CODE_GAP** |
| Öncelik | **P1** |
| Domain | Fazla çalışma / 270 saat |
| Mevcut | Kapanış `yil` = ISO week-year (`format('o')` of `hafta_baslangic`); aggregate `satir.yil` kullanır; `PayrollComplianceGuard::loadKapanmisYillikFazlaCalisma` calendar `hafta_baslangic` ∈ [yil-01-01, yil-12-31] |
| Beklenen | Tek tutarlı yıl atama kuralı (policy ile aynı) |
| Kanıt | `HaftalikKapanisController::hesaplaIsoHaftaNo`; `yillik-fazla-calisma-aggregate.ts`; `PayrollComplianceGuard` load SQL; test “ISO year boundary week counted in 2026” |
| Örnek | 29.12.2025–04.01.2026 → ISO 2026 aggregate’te; calendar filter 2026’dan dışlar |
| İnsan kararı | Atama politikası ayrıca `MG-OT-YEAR-POL-001` |
| Owner | Haftalık kapanış / Payroll compliance |
| Acceptance | Aggregate API ile compliance load aynı haftayı aynı yıla sayar |

### MG-ORG-LOC-001 — SGK işveren / sistem şubesi / çalışma lokasyonu ayrımı

| Alan | Değer |
| --- | --- |
| Statü | **CODE_GAP** *(schema temsil yok; iş kuralı kararı `MG-ORG-MODEL-001`)* |
| Öncelik | **P1** |
| Domain | Organizasyon / SGK |
| Mevcut | `subeler` + `personeller.sube_id`; ayrı `sgk_isveren` / `lokasyon` entity yok |
| Beklenen | Şirket SGK merkezi, sistem şubesi, çalışma lokasyonu ayrı temsil |
| Kanıt | `001_initial_schema.sql` `personeller` / `subeler`; import contract’ta lokasyon yok |
| Runtime | İzmir/Sakarya gibi lokasyonlar şube ile karışabilir |
| Migration | Evet (karar sonrası) |
| Owner | Org / Personel |
| Acceptance | Personel kaydı üç kavramı kaybetmeden taşıyabilir |

### MG-ORG-ATTR-001 — Bölüm / Birim / Pozisyon native alanları yok

| Alan | Değer |
| --- | --- |
| Statü | **CODE_GAP** |
| Öncelik | **P2** |
| Domain | Personel org model |
| Mevcut | Native: `departman`, `gorev`, `personel_tipi`, `sube`, `bagli_amir_id`. Yok: bölüm, birim, pozisyon, lokasyon |
| Beklenen | Kaynak Excel seviyeleri kaybolmadan map veya bilinçli defer |
| Kanıt | `001_initial_schema.sql`; `src/types/personel.ts`; import columns |
| Owner | Personel |
| Acceptance | Mapping kararı + schema/UI veya bilinçli OUT_OF_SCOPE kaydı |

---

## 5. BUSINESS_DECISION_REQUIRED registry

### MG-OT-YEAR-POL-001 — Yıl değiştiren haftada FM dakikalarının yılı

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** |
| Öncelik | **P1** |
| Domain | 270 saat |
| Soru | Günlük takvim yılına split mi, yoksa tüm hafta hafta-başı/ISO yıla mı? |
| Mevcut kod | Bütün haftalık FM tek `fazla_calisma_dakika`; yıl = ISO(`hafta_baslangic`) |
| Acceptance | Yazılı şirket/İK kararı + tek owner kuralı |

### MG-SGK-1514-001 — Ücret/SGK çalışma dönemi 1–son vs 15–14

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** / **CONDITIONAL** |
| Öncelik | **P1** |
| Domain | SGK |
| Soru | Medisa/Karyapı/Şenay için dönem ayın 1–son mu, 15–sonraki ay 14 mü? Sigortalı bazında 15–14 var mı? |
| Mevcut | `SgkKatalogPreviewService`: `preview_modu=BLOCKER_ONLY`, `aktif_edildi_mi=false`, dönem null; motor tip string kabul eder ama preview aktive etmez |
| Not | Generic 15–14 motor yazılmaz; ihtiyaç doğrulanana kadar CONDITIONAL |
| Kanıt | `SgkKatalogPreviewService.php`; `94` madde 6 |

### MG-ZORUNLU-001 — Zorunlu / olağanüstü çalışma istisna modeli ihtiyacı

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** *(uygulama yoksa INTENTIONAL_DEFER ile birlikte)* |
| Öncelik | **P2** |
| Domain | Fazla çalışma |
| Mevcut | Ayrı exception model yok (`99` BILINCLI_KAPSAM_DISI; `102` §18) |
| Soru | Medisa için gerçek ihtiyaç var mı? |

### MG-ORG-MODEL-001 — Kilitli işletme modeli (şirket / SGK / şube / lokasyon)

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** *(canonical karar kaydı; schema uygulaması ayrı)* |
| Öncelik | **P0** (karar kilitli; uygulama kapısı) |
| Domain | Org / SGK |
| Karar (kullanıcı, 2026-08-12 audit) | Aşağıdaki model canonical: |

**SGK / bordro ana yapılar:**
1. Medisa — SGK merkezi = Karabük  
2. Karyapı — SGK merkezi = Konya  
3. Şenay Mobilya — kendi SGK yapısı  

**Operasyonel sistem şubeleri:**  
MEDİSA: Merkez Karabük, Kayseri, Giresun, Ankara, İstanbul  
KARYAPI: Merkez Konya, Ankara, Kayseri, İstanbul  
DİĞER: Şenay Mobilya  

**Çalışma lokasyonu ayrı kavramdır** (ör. İzmir çalışır / SGK Karabük / sistem şubesi Merkez Karabük). Sakarya ayrı SGK işyeri veya otomatik ayrı sistem şubesi değildir.

Schema bu üçlüyü henüz ayıramıyor → uygulama `MG-ORG-LOC-001`.

### MG-IMPORT-MAP-001 — Kaynak Excel → import contract eşlemesi

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** |
| Öncelik | **P1** |
| Domain | Personel import |
| Sorular | `No` = sicil mi? Ad Soyad split kuralı? Departman→Departman / Unvan→Görev / Grup→Personel Tipi onay mı? Karyapı listesi nerede? |
| Yasak | Validator gevşetme; sicil uydurma; güvenilmez auto-split; telefon/doğum uydurma; ücret/SGK’yı master import’a zorlama |
| Kanıt contract | `PersonelImportDryRunService` REQUIRED: tc, sicil, ad, soyad, dogum_tarihi, telefon, ise_giris, sube, departman, gorev, personel_tipi; OPTIONAL: dogum_yeri, kan_grubu, acil_*; FORBIDDEN: ücret/SGK/devir kolonları |

### MG-IMPORT-DATA-001 — Kaynak veri kalitesi (122 kişi)

| Alan | Değer |
| --- | --- |
| Statü | **BUSINESS_DECISION_REQUIRED** / OPS_DATA_GAP |
| Öncelik | **P1** |
| Domain | Personel import |
| Notlar | 15 doğum tarihi eksik; 35 telefon eksik; acil alanlar boş; Ad Soyad birleşik; Karyapı yok; İzmir/Sakarya lokasyon olarak Medisa Karabük SGK altında |
| dogum_tarihi / telefon | Import’ta **REQUIRED** (kod) |
| acil_* | **OPTIONAL** (kod) |

---

## 6. OPS_ROLLOUT registry

| ID | Başlık | Öncelik | Durum özeti | Owner |
| --- | --- | --- | --- | --- |
| MG-OPS-PERSONEL-001 | Gerçek personel import (122) | P0 | USER_GATED; onay olmadan import yok | Ops + kullanıcı |
| MG-OPS-ORG-001 | Gerçek org/şube/referans rollout | P0 | USER_GATED / NOT_YET | Ops |
| MG-OPS-BIND-001 | PERSONEL binding gerçek rollout | P1 | Schema `056` var; gerçek binding NOT_STARTED | Ops / İK |
| MG-OPS-QR-001 | Gerçek çalışan QR rollout | P1 | Pipeline CLOSED; employee rollout NOT_STARTED | Ops |
| MG-OPS-SGK-CAT-001 | SGK resmi katalog / DOGRULANMIS_TAM / şirket politikası | P0 | Code fail-closed; prod state repo’dan VERIFY_REQUIRED | Ops + `94`/`95` |
| MG-OPS-UBGT-001 | UBGT authoritative calendar seed | P0 | Engine/schema var (`039` seedless); gerçek tatil tarihi human/ops gate | Ops |
| MG-OPS-POLICY-001 | Bordro çalışma politikası canlı parametre onayı | P1 | `91` form + `95` runbook | Ops / yönetim |
| MG-OPS-ENUM-INV-001 | Legacy role production inventory (shrink öncesi) | P2 | `SGK_KARAR_ONAY_YETKILISI` / `IDARI_ISLER` inventory | Ops + DBA |
| MG-OPS-DEPLOY-001 | Exact-SHA cPanel yayın kanıtı / manuel upload | P2 | FTP/sunucu tarihsel blocker; ürün beyni değil | Ops |

---

## 7. INTENTIONAL_DEFER registry

| ID | Başlık | Öncelik | Kanıt | Not |
| --- | --- | --- | --- | --- |
| MG-DEF-QR-CORR-001 | QR anomaly → GIRIS_CIKIS_DUZELTME kontrollü UX köprüsü | P2 | `106` D5 later; `107` discovery-only; UI hint only | Yeni correction engine yok |
| MG-DEF-PAY-SELF-001 | PERSONEL maaş/bordro self-view | P3 | `105` OUT_OF_SCOPE; `RolePermissions` PERSONEL | CODE_GAP değil |
| MG-DEF-I13-001 | User convenience: telefon / kullanici_tipi / notlar | P3 | `101` DEFER_WITH_GATE; `kullanici-api-contract.ts` | `051` varsayılan şube ile karıştırma |
| MG-DEF-ENUM-001 | Legacy role ENUM shrink | P2 | `054` “Does NOT shrink”; 055–058 dokunmaz | Runtime canonical doğru; teknik borç |
| MG-DEF-ZORUNLU-001 | Zorunlu/olağanüstü exception model | P2 | `99` / `102` kapsam dışı | İhtiyaç kararı `MG-ZORUNLU-001` |
| MG-DEF-FSC-001 | FSC %25 aktif bant | P3 | S87 kapalı | — |
| MG-DEF-PAY-OUT-001 | Bordro PDF / banka / SGK bildirgesi çıktısı | P3 | `100` / `102` FUTURE | Kısmi CSV var |
| MG-DEF-RET-HTTP-001 | Destruction evaluate HTTP route | P3 | Service-only; test çağırır | PHYS-001 ile birlikte |

---

## 8. NOT_APPLICABLE registry

| ID | Başlık | Neden |
| --- | --- | --- |
| MG-NA-SECOND-ENGINE | İkinci bordro motoru | Yasak (`102`) |
| MG-NA-AUTO-QR-CORR | Missing-scan auto correction | S3C–S3F non-goal |
| MG-NA-AUTO-APPLY | QR AUTO_APPLY / CREATE_PUANTAJ | S3F non-goal |
| MG-NA-IMPORT-UCRET | Master import’tan ücret/SGK yazımı | Forbidden columns by design |

---

## 9. DOC_STALE registry

| ID | Belge | Stale içerik | Öncelik | Bu tur |
| --- | --- | --- | --- | --- |
| MG-DOC-CS-001 | `CURRENT_STATE.md` | Beyin baseline `c6e75fb`; migration “041–050”; S3F/QR/058 yok; REAL_* yok | **P0** | **Düzeltilir** |
| MG-DOC-102-001 | `docs/guncel/102-…` | Tip **056**; “QR henüz yok”; self-service QR stale | **P0** | **Düzeltilir** |
| MG-DOC-101-001 | `101` | `personel_id` defer (S3B/056 ile kapandı) | P2 | Historical; not rewrite as “today” |
| MG-DOC-103-001 | `103` | QR / binding DEFERRED_WORK | P2 | Historical checkpoint |
| MG-DOC-105-TIP | `105` header tip 055 | Historical discovery | P3 | Preserve |

Historical phase docs **korunur**; “bugün böyle” diye yeniden yazılmaz.

---

## 10. Personel / org rollout dependencies

```text
MG-ORG-MODEL-001 (karar kilitli)
  → MG-ORG-LOC-001 + MG-ORG-ATTR-001 (schema/UI)
  → MG-OPS-ORG-001 (referans seed)
  → MG-IMPORT-MAP-001 + MG-IMPORT-DATA-001 (kaynak hazırlık)
  → MG-OPS-PERSONEL-001 (122 import, USER_GATED)
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

1. CODE_GAP P0/P1 kapanmadan “ürün tamam” denmez.  
2. `DOC_STALE` P0 = 0 (canonical docs).  
3. Gerçek personel/org/SGK/UBGT write yalnız `95` + kullanıcı onayı.  
4. Migration tip production = 058; yeni migration yalnız ayrı onay.  
5. Physical destruction yalnız manifest coverage + handler + legal review sonrası.  
6. Stash / force-push / hard reset yasak (audit protokolü).

---

## 12. Recommended closure order

1. Canonical docs (bu PR): `CURRENT_STATE` + `102` + bu registry  
2. Business inputs: `MG-ORG-MODEL` (kayıtlı), `MG-SGK-1514`, `MG-OT-YEAR-POL`, `MG-IMPORT-MAP/DATA`, `MG-ZORUNLU`  
3. Code: `MG-OT-YEAR-PATH-001` (policy sonrası veya tutarlılık fix)  
4. Code: `MG-SZ-6M-001`  
5. Code: `MG-RET-MAN-001` → `MG-RET-S3F-001` → `MG-RET-PHYS-001`  
6. Code: `MG-ORG-LOC-001` / `MG-ORG-ATTR-001` (model kararı sonrası)  
7. Ops: UBGT seed, SGK catalog, org seed, personel import, binding, QR employee  
8. Defer pack: QR correction UX, I13 fields, ENUM shrink, payroll self-view, pay outputs  

---

## 13. Final completion definition

**“PersonelMedisa tamamlandı” yalnız şu halde:**

- `CODE_GAP = 0`
- `UNVERIFIED` critical = 0
- Canonical `DOC_STALE` = 0
- Kalan maddeler yalnız: `NOT_APPLICABLE` · `INTENTIONAL_DEFER` · kullanıcı bilinçli `OPS_ROLLOUT`

**“Bu faz bitti”** yalnız o fazın kendi acceptance’ı için kullanılır (ör. S3F CLOSED_PRODUCTION).

---

## 14. Coverage matrix — retention categories

| Category | Resolver | Fingerprint | Manifest creator | Lifecycle trigger | Legal hold | Destroy eligibility |
| --- | --- | --- | --- | --- | --- | --- |
| PERSONEL_OZLUK | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | through approve; execute stub |
| ISE_GIRIS_CIKIS | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | same |
| PERSONEL_BELGE | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| PUANTAJ | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| BORDRO | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| SGK_EKSIK_GUN | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| FAZLA_CALISMA | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| SERBEST_ZAMAN | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| ONAY_AUDIT | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail (+ S3F ledger unbound) |
| IZIN | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| RAPOR | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| IS_KAZASI | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| DISIPLIN | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| OLAY | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |
| SAVUNMA | CLOSED | CLOSED | **GAP** | CLOSED | CLOSED | integrity fail |

---

## 15. Audit counts (post-doc reconciliation target)

| Statü | Adet (unique ID) |
| --- | --- |
| CODE_GAP | 7 |
| BUSINESS_DECISION_REQUIRED | 6 |
| OPS_ROLLOUT | 9 |
| INTENTIONAL_DEFER | 8 |
| NOT_APPLICABLE | 4 |
| DOC_STALE (historical leftover after CS/102 fix) | 3 (101/103/105 headers; non-blocking) |
| CLOSED systems (section 3) | 20+ (yeniden açılmadı) |

P0: org model karar kaydı + ops personel/org/SGK/UBGT + CS/102 stale (fixed this tour)  
P1: retention phys/manifest/S3F, SZ 6M, OT path, import map/data, SGK 15–14 decision, binding/QR ops  
P2–P3: org attrs, zorunlu model, QR UX, I13, ENUM, pay outputs  

---

## 16. Cross-links

- `CURRENT_STATE.md` — tek güncel durum  
- `102-hesaplama-cevap-haritasi.md` — hesap okuma haritası  
- `109-s3f-…` — S3F production baseline  
- `95-s96-release-ops-runbook.md` — ops protokol  
- `94-…` — SGK manuel kanıt  
- `101` / `105` — I13 / S3A historical decisions  
