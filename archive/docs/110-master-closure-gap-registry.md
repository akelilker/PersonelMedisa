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

## 2026-08-20 credential onboarding production close sync

| Alan | Canonical sonuç |
| --- | --- |
| Deploy SHA | `395bc1c75d653f201eca6e29f778e5c5fc2a19ca` — CI [#32339420748](https://github.com/akelilker/PersonelMedisa/actions/runs/32339420748) + Deploy cPanel [#32339534506](https://github.com/akelilker/PersonelMedisa/actions/runs/32339534506) **success** |
| Migration 069 | **CLOSED_CONFIRMED** — apply worker [#32340345337](https://github.com/akelilker/PersonelMedisa/actions/runs/32340345337) **success** |
| MG-CRED-ONBOARD-001 | **CLOSED** — `/auth/change-password`, `must_change_password`, backend `PASSWORD_CHANGE_REQUIRED` fail-closed |
| PERSONEL activation | **136 AKTIF** + forced first password change; legacy binding user_id=11 unchanged |
| QR employee rollout | **TECHNICAL_ONBOARDING_COMPLETE** — infra READY; **USER_HANDOFF_REQUIRED** for per-user password change |
| Production mutation (this phase) | **YES** — scoped credential assignment + PERSONEL user activation only |

## 2026-08-20 final production rollout execution sync

| Alan | Canonical sonuç |
| --- | --- |
| Deploy SHA | `7a20da3722d90bec0a8195e5934fd3facf07ddf6` — Deploy cPanel [#32312925576](https://github.com/akelilker/PersonelMedisa/actions/runs/32312925576) **success** |
| PERSONEL user provisioning | **CLOSED** — 136 `PASIF` users; username=sicil_no; throwaway passwords not retained |
| PERSONEL binding rollout | **CLOSED** — 136 bound + 1 existing = 137; duplicate `0` |
| QR config / kiosk | **READY** — smoke: token mint OK, PASIF login deny, admin scan deny |
| QR employee rollout | **USER_HANDOFF_REQUIRED** — technical onboarding complete; per-user first password change pending |
| Org FK IC | **CLOSED** — `122/122` |
| Org FK DIS_KAYNAK | **DEFERRED_MISSING_REAL_DATA** — 11 personnel (ids 200–210); source bölüm/birim/pozisyon blanks; no mutation |
| SGK period production | **CLOSED** — branches `1,4,5,6,7,8,9,10,11` `AY_1_SON_GUN` + `ONAYLANDI`; mutation **NOT_REQUIRED** |
| Production mutation (this phase) | **NO** |

## 2026-08-15 final preflight sync

## 2026-08-17 Priority A closure sync

## 2026-08-17 Business decision reconciliation

| Alan | Canonical sonuç |
| --- | --- |
| Retention duration policy | **CLOSED_CONFIRMED** — `RETENTION_POLICY_YEARS=10`; provenance `USER_CONFIRMED_BUSINESS_DECISION` |
| Physical destruction | **INTENTIONAL_DEFER** — feature remains OFF; no execution, request, approval, deletion, or legal-hold change |
| SGK reporting period decision | **CLOSED_CONFIRMED** — all target branches `1,4,5,6,7,8,9,10,11` use runtime enum `AY_1_SON_GUN`; `15_TO_NEXT_MONTH_14` and `MIXED_BY_INSURED` are not used |
| SGK production rollout | **OPS_ROLLOUT** — local authenticated read surface is ready; production read → compare → draft → submit → separate approval awaits release |

| Alan | Canonical sonuç |
| --- | --- |
| Migration067 | **CLOSED_CONFIRMED** (canonical SQL); legacy `migration-067-production-precheck` **RETIRED/REMOVED** |
| Migration068 | **CLOSED_CONFIRMED** (code + production schema); `actor_identity_audits` audit owner |
| Production personnel | **137**; count rollout **CLOSED** (`Phase1=122 CLOSED`, `Phase2=11 CLOSED`); org FK apply **USER_GATED** / `VERIFY_REQUIRED` |
| Canonical Güvenlik | `Üretim → Üretim → Güvenlik` |
| SGK catalog | **CLOSED_CONFIRMED** |
| UBGT | **CLOSED_CONFIRMED** |
| Payroll company policy | **CLOSED_CONFIRMED**; active revision `3`; policy `14/14`; `HAFTA_TATILI_GUNLERI=0` / Pazar |
| Payroll policy preflight | `HAFTA_TATILI_GUNLERI_MISSING_BLOCKER=NO`; `PAYROLL_POLICY_RUNTIME_READY=YES` |
| Dual control | submitter ID `1`; approver `ilkerA` ID `10`; self-approval `NO` |
| MG-OPS-POLICY-001 | **CLOSED_CONFIRMED** |
| Priority A remaining | **NONE** |

| Alan | Canonical sonuç |
| --- | --- |
| Current main / PR base | `7a20da3722d90bec0a8195e5934fd3facf07ddf6` (PR #181) |
| User management (code) | **CLOSED** — unified kullanıcı workspace in `YonetimPaneliPage` |
| Personel binding (code) | **CLOSED** — production rollout **CLOSED** (`MG-OPS-BIND-001`) |
| Real QR employee (code) | **CLOSED** — pipeline S3C–S3F; production technical onboarding **COMPLETE**; per-user password handoff **USER_HANDOFF_REQUIRED** |
| Code migration tip | `070` |
| Production migration tip | **70** — apply worker [#32340345337](https://github.com/akelilker/PersonelMedisa/actions/runs/32340345337) @ deploy `395bc1c…` |
| Migration 067 | **CLOSED_CONFIRMED** — canonical SQL; legacy ops workflow **RETIRED/REMOVED** |
| Migration 068 | **CLOSED_CONFIRMED** — `068_sgk_actor_identity_lifecycle_audit.sql`; production schema applied |
| Migration 069 | **CLOSED_CONFIRMED** — `069_personel_credential_onboarding.sql`; production schema applied |
| Canonical migration owner | `apply-cpanel-migrations.yml` + protected cron worker; SSH migration dependency **NO** |
| Schema 067 readiness | **YES** — production read-back confirmed (superseded by tip **068**) |
| IC / DIS source model | `IC_PERSONEL` internal + first-class directory-only `DIS_KAYNAK` |
| Canonical production tree contract | `Üretim → Üretim → Güvenlik`; legacy `Üretim Genel → Güvenlik` is not canonical; live path verified as legacy and blocked |
| Ownership | Personel Kartı read-only; Kayıt ve Süreç owns personnel writes |
| Source lock | Exact user-authoritative workbook lineage and 122-row field mapping re-locked privately; exact data remains private |
| Auth contract | `AUTH_SMOKE_READONLY` is smoke-only (`ops.auth_smoke.read`); it is insufficient for references, personnel list, schema probe, or import dry-run |
| Real personnel rollout | **CLOSED** (count) — production total `137`; Phase1 `122 CLOSED`, Phase2 `11 CLOSED`; IC org FK **CLOSED**; DIS org FK partial **DEFERRED_MISSING_REAL_DATA** |
| Production mutation | Policy approval and requested personnel rollout are complete; no unrelated mutation performed |
| Public PII policy | Exact source rows, person-level data, and blocker tallies remain private |

Live reference verification found the legacy `Üretim Genel → Güvenlik` branch. The canonical tree check is
**FAIL**; `REFERENCE_MUTATION_REQUIRED=YES`, and no reference mutation was performed.

The latest source reconciliation, candidate preview, and blocker successor workbook are private
artifacts. Historical Pack7F/Pack7G/Pack7H snapshots remain preserved and are not rewritten.

Source reconciliation remains private; raw blanks, candidate values, and importer errors stay
separate. Production personnel rollout is complete.

Business decision (2026-08-15): 20 IC phone values are deferred user data. Missing-info UX
continues to flag the field, while phone absence is non-blocking for import and daily
operations; completion is allowed later through Kayıt ve Süreç. Exact person-level values
remain private.

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

## 2. Production baseline (current invariants)

| Invariant | Değer |
| --- | --- |
| PRODUCTION_MIGRATION_TIP | **70** |
| CODE_MIGRATION_TIP | **069** |
| CANONICAL_MIGRATION_OWNER | `apply-cpanel-migrations.yml` + `cpanel-migration-cron.php` |
| SSH_MIGRATION_DEPENDENCY | **NO** |
| S3F | **CLOSED_PRODUCTION** |
| QR_PIPELINE | S3C–S3F **CLOSED** |
| QR algorithms | `QR_INTERVAL_V1`, `QR_PUANTAJ_CANDIDATE_V1`, `QR_PUANTAJ_DECISION_V1`, `QR_CANDIDATE_HASH_V2` |
| REAL_REFERENCE_DATA | **READY** — SGK/locations/branches/catalogs seeded; personnel org FK apply gated |
| PERSONNEL_ORG_FK_ROLLOUT | **NOT_STARTED** / `VERIFY_REQUIRED` |
| REAL_PERSONNEL_DATASET | **CLOSED** |
| REAL_PERSONNEL_IMPORTED | **YES** — 137 production personnel |
| SOURCE_DATA_REQUIRES_COMPLETION | **NO** for closed personnel rollout |
| NO_PII_COMMITTED | **YES** |
| PERSONEL_BINDING_REAL_ROLLOUT | **CLOSED** |
| REAL_QR_EMPLOYEE_ROLLOUT | **USER_HANDOFF_REQUIRED** (technical onboarding complete; per-user password change) |
| PERSONEL_CREDENTIAL_ONBOARDING | **CLOSED** (`MG-CRED-ONBOARD-001`) |
| RETENTION_PHYSICAL_SCHEMA | PRODUCTION_READY (`059`/`060`/`062`); feature **OFF**; real destruction **NO** |
| SERBEST_ZAMAN_ALLOCATION_SCHEMA | PRODUCTION_READY (`061`/`062`) |
| OT_ACTUAL_DATE_PROVENANCE_SCHEMA | PRODUCTION_READY (`063`); legacy backfill **NO** |
| ORG_LOCATION_SCHEMA | **CLOSED** — production references resolved; personnel org FK apply **USER_GATED** / `VERIFY_REQUIRED` |

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
| SGK formal actor identity lifecycle (code) | `ActorIdentityService` + mig `068` `actor_identity_audits`; create/verify/bind/readback/audit **CLOSED** |
| Personel import dry-run / apply / history / reference pack | mig `046` + import services |
| Payroll engine / salary snapshot/revision/preflight/compliance | engine + `99` |
| Holiday/FSC/FM collision calculation owner | payroll owners |
| Role permission narrowing | `RolePermissions` canonical |

---

## 4. CODE_GAP registry

*(MG-CRED-ONBOARD-001 moved to CLOSED — see §3 / 2026-08-20 credential onboarding production close sync.)*

### MG-RET-PHYS-001 — Retention physical destruction executor

| Alan | Değer |
| --- | --- |
| Statü | **INTENTIONAL_DEFER** |
| Metadata | — |
| Öncelik | **P1** |
| Domain | Retention / KVKK |
| Pack 2 | `112` / PR #151 — framework + evidence `059` + 7 handlers; flag default OFF |
| Pack 3B | `113` / PR #152 — PUANTAJ/BORDRO/SGK + `060` + snapshot-pin OPTION A + post-destroy reopen gate |
| Pack 3C | `114` / PR #153 — FAZLA/SERBEST/DISIPLIN/RAPOR/IS_KAZASI + generic ONAY_AUDIT; merge-blocker hardening |
| Pack 4A | `115` — OPTION_A lot ledger foundation (`061` + write-path) |
| Pack 4B | `116` — allocation-aware SERBEST destroy + `062` retention-gated tahsis DELETE + scope fingerprint |
| Mevcut | Plan/execute + registry + HTTP; 15/15 typed handlers; SERBEST allocation-aware destroy (legacy/cross-scope/invariant fail-closed); Pack 4A append-only tahsis ledger; Pack 4B gated DELETE |
| Beklenen | OPS: feature enable (ayrı kapı) + gerçek destroy yalnız onaylı talepte; schema `059`/`060`/`061`/`062` production-applied (`118`) |
| Kanıt | `PhysicalDestructionService`; `SerbestZamanDestructionHandler`; `RetentionPhysicalDestroyGate`; mig `059`/`060`/`061`/`062`; `112`/`113`/`114`/`115`/`116`; rollout `118` |
| Neden OPS | Code + production schema hazır; feature flag OFF; real destruction henüz yok |
| Runtime | Flag OFF → `DESTRUCTION_EXECUTION_DISABLED`; legacy unallocated → `SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED`; cross-scope → `SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS` |
| Prod veri | Write yok (rollout mutation yok); execution rows = 0; open destroy gates = 0 |
| Migration | `059`/`060`/`061`/`062` **production-applied** (`118`); production tip **068** |
| Prod write | Schema-only rollout; feature enable / real destroy **NO** |
| Owner | Retention |
| Acceptance | Onaylı talepte güvenli kategoriler imha edilir; SERBEST lot-provenance destroy veya legacy fail-closed; audit/evidence; flag default OFF |

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
| Statü | **OPS_ROLLOUT_ACTIVE** |
| Metadata | `USER_GATED` |
| Öncelik | **P1** |
| Domain | Serbest zaman / compliance |
| Pack 4A | `115` — lot projection foundation on bakiye (`lot_based_*`, expiry_state) |
| Pack 4B | `116` — `SerbestZamanDeadlineService` + `GET /serbest-zaman/deadline-takip` + Raporlar `serbest-zaman-takip` |
| Mevcut (var) | `son_kullanim_tarihi`; 6 ay hesabı; bakiye; Pack 4A lots; Pack 4B ops surface (warning 30g, EXPIRED visibility, ALLOCATION_UNRESOLVED) |
| Compliance | `WARNING_AND_OPERATIONAL_FOLLOWUP`; **PAYROLL_HARD_BLOCK = NO** |
| Beklenen | OPS: İK operasyonel follow-up kullanımı; production allocation schema `061`/`062` **COMPLETE** (`118`) |
| Kanıt | `SerbestZamanDeadlineService`; `SerbestZamanTakipPage`; `102` §7; `116`; rollout `118` |
| Payroll | Vade aşımında otomatik compliance block yok |
| Owner | Serbest zaman / Payroll compliance |
| Acceptance | Lot bazlı yaklaşan/overdue görünür + raporlanır; compliance warning/operational; payroll hard block yok |

### MG-OT-YEAR-PATH-001 — Yıllık FM yıl ataması owner path uyumsuzluğu

| Alan | Değer |
| --- | --- |
| Statü | **CLOSED** |
| Öncelik | **P1** (closed) |
| Domain | Fazla çalışma / 270 saat |
| Karar / policy | `ROLLING_12_MONTH_ACTUAL_DATE_V1` (`MG-OT-YEAR-POL-001`) |
| Owner | `FazlaCalismaYillikLimitService` |
| Persist | Migration `063` — `fazla_calisma_tarih_dagilimi_json` + policy (nullable; legacy rewrite yok); production-applied (`118`) |
| Hard guard | Rolling 12 month; ISO/`yil` display only |
| Kanıt | `117-final-code-gap-pack5.md`; Pack5 MariaDB A1–A10; rollout `118` |
| Acceptance | Create/compliance/payroll paths aynı rolling owner; whole-week ISO assignment yok |

### MG-ORG-LOC-001 — SGK işveren / sistem şubesi / çalışma lokasyonu schema ayrımı

| Alan | Değer |
| --- | --- |
| Statü | **OPS_ROLLOUT** |
| Metadata | `USER_GATED` |
| Öncelik | **P1** |
| Domain | Organizasyon / SGK |
| Code/schema | Migration `064` — `sgk_isverenler`, `calisma_lokasyonlari`; `personeller` nullable FKs; `sube_id` korunur |
| Pre-064 | Explicit new-field write → `409 ORG_LOCATION_SCHEMA_NOT_READY` |
| Production | tip **068**; schema applied; SGK + verified location refs seeded (`119`); personnel org FK bulk apply **NO** / `VERIFY_REQUIRED` |
| Kanıt | `117-final-code-gap-pack5.md`; Pack5 MariaDB B1–B11; rollout `118`; org seed `119` |
| Acceptance (code) | Üç kavram bağımsız persist; SubeScope `sube_id` |
| Remaining ops | Personnel org FK apply (`sgk_isveren_id`, `calisma_lokasyonu_id`, `bolum_id`, `birim_id`, `pozisyon_id`) = `USER_GATED` / `VERIFY_REQUIRED`; locked branch + catalog reference rollout completed (`121`/`122`) |

---

## 5. BUSINESS_DECISION_REQUIRED registry

### MG-OT-YEAR-POL-001 — Yıl değiştiren haftada FM dakikalarının yılı

| Alan | Değer |
| --- | --- |
| Statü | **CLOSED** |
| Öncelik | **P1** (closed) |
| Domain | 270 saat |
| Karar | **ROLLING_12_MONTH_ACTUAL_DATE_V1** |
| ISO_WEEK_YEAR_AS_270H_OWNER | **NO** |
| WHOLE_CROSS_YEAR_WEEK_ASSIGNMENT | **NO** |
| ACTUAL_DATE_PROVENANCE | **YES** |
| ROLLING_12_MONTH_HARD_GUARD | **YES** |
| LEGAL_CHARACTER | **CONSERVATIVE_COMPANY_COMPLIANCE_POLICY** |
| Kanıt | `117-final-code-gap-pack5.md` |
| Path owner | `MG-OT-YEAR-PATH-001` CLOSED |

### MG-SGK-1514-001 — Ücret/SGK çalışma dönemi 1–son vs 15–14

| Alan | Değer |
| --- | --- |
| Statü | **CLOSED_CONFIRMED** |
| Metadata | `USER_CONFIRMED_BUSINESS_DECISION`; production rollout `OPS_ROLLOUT` |
| Öncelik | **closed; release gate remains** |
| Domain | SGK |
| Soru | Medisa/Karyapı/Şenay için dönem ayın 1–son mu, 15–sonraki ay 14 mü? Sigortalı bazında 15–14 var mı? |
| Mevcut | `SgkKatalogPreviewService`: `preview_modu=BLOCKER_ONLY`, `aktif_edildi_mi=false`, dönem null; motor tip string kabul eder ama preview aktive etmez |
| Not | User decision is `1_TO_MONTH_END` for all three companies; runtime enum is `AY_1_SON_GUN`; `15_TO_NEXT_MONTH_14` and `MIXED_BY_INSURED` are not used. New canonical per-`sube_id` read surface is local and production rollout remains pending release/apply. |
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
| Statü | **CLOSED** |
| Öncelik | **P2** → closed |
| Domain | Personel org model |
| Karar | Native fields required (no lossy collapse). Unvan owner = existing `gorev_id`. |
| Implementation | Pack6 code/schema `065` (`120`). Production apply tracked by `MG-ORG-ATTR-ROLL-001`. |
| Kanıt | user business decision 2026-08-14; `120`; `065_personel_org_structure.sql` |

### MG-ORG-ATTR-ROLL-001 — Pack6 schema/ops rollout

| Alan | Değer |
| --- | --- |
| Statü | **CLOSED_REFERENCE_ROLLOUT** |
| Metadata | production schema + canonical reference catalogs complete; personnel FK apply `USER_GATED` |
| Domain | Personel org model |
| Mevcut | Production tip `065` (`121`); canonical Departman/Unvan/PersonelTipi/Bölüm/Birim/Pozisyon complete from 122-row workbook (`122`); locked 10-branch model + MRK=`Medisa` + ownership complete; personnel org FKs still NULL |
| Acceptance | Explicit authorization for personnel exact FK apply / real import remains gated |
| Kanıt | `121`; `122-canonical-hr-catalog-completion.md` |

### MG-IMPORT-MAP-001 — Kaynak Excel → import contract eşlemesi

| Alan | Değer |
| --- | --- |
| Statü | **CLOSED** (business mapping) — remaining gaps → `MG-IMPORT-DATA-001` |
| Öncelik | **P1** |
| Domain | Personel import |
| Closed mapping | Departman→departman; Bölüm→bolum; Birim→birim; Unvan→gorev; Pozisyon→pozisyon; central MRK display **Medisa** |
| Remaining data work | Phase 1 prepares 122 `IC_PERSONEL`; 20 missing phone values are deferred/non-blocking; candidate-level Sicil and birth-date blockers are 0. 13 `DIS_KAYNAK` rows remain Phase 2 / `DEFERRED_REFERENCE_DECISION` |
| Yasak | Validator gevşetme; sicil uydurma; güvenilmez auto-split; telefon/doğum uydurma; ücret/SGK’yı master import’a zorlama; PII’nin public repo’ya yazılması |
| Kanıt contract | `PersonelImportDryRunService` REQUIRED unchanged; OPTIONAL adds `bolum`/`birim`/`pozisyon` (blank-safe pre-065) |

---

## 6. OPS_ROLLOUT registry

| ID | Başlık | Öncelik | Metadata | Durum özeti | Owner |
| --- | --- | --- | --- | --- | --- |
| MG-OPS-PERSONEL-001 | Gerçek personel import | P0 | **CLOSED** | Production total `137`; personnel rollout closed | Ops + kullanıcı |
| MG-IMPORT-DATA-001 | Kaynak personel dataset completion | P1 | **CLOSED** | Phase1 `122 CLOSED`; Phase2 `11 CLOSED`; deferred user data remains non-blocking | Ops + İK |
| MG-OPS-ORG-001 | Gerçek org/şube/referans rollout | P0 | **CLOSED** | Canonical catalogs and production references resolved; binding rollout remains separate | Ops |
| MG-OPS-BIND-001 | PERSONEL binding gerçek rollout | P1 | **CLOSED** | 136 bound + 1 existing = 137 @ `7a20da3…` | Ops / İK |
| MG-OPS-QR-001 | Gerçek çalışan QR rollout | P1 | **USER_HANDOFF_REQUIRED** | Pipeline + config READY; technical onboarding complete; per-user password handoff pending | Ops / İK |
| MG-OPS-SGK-CAT-001 | SGK resmi katalog / DOGRULANMIS_TAM / şirket politikası | P0 | **CLOSED_CONFIRMED** | Production catalog confirmed | Ops + `94`/`95` |
| MG-OPS-UBGT-001 | UBGT authoritative calendar seed | P0 | **CLOSED_CONFIRMED** | Production calendar confirmed | Ops |
| MG-OPS-POLICY-001 | Bordro çalışma politikası canlı parametre onayı | P1 | **CLOSED_CONFIRMED** | Active revision `3`; 14/14; Pazar (`0`); dual control passed | Ops / yönetim |
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
| MG-DOC-CS-001 | `CURRENT_STATE.md` reconciliation | **CLOSED** | — | PR #180 + final reversible closure |
| MG-DOC-102-001 | `102` tip/QR/S3F header reconciliation | **CLOSED** | — | PR #149 ile güncellendi |
| MG-DOC-101-001 | `101` I13 checkpoint | **CLOSED** | `HISTORICAL_SNAPSHOT_PRESERVED` | Bugün SoT değil; bilinçli tarihsel |
| MG-DOC-103-001 | `103` role consolidation checkpoint | **CLOSED** | `HISTORICAL_SNAPSHOT_PRESERVED` | Bugün SoT değil |
| MG-DOC-105-TIP | `105` S3A discovery header tip | **CLOSED** | `HISTORICAL_SNAPSHOT_PRESERVED` | Faz anı korunur |
| MG-RET-POLICY-001 | Medisa retention duration policy | **CLOSED_CONFIRMED** | — | `RETENTION_POLICY_YEARS=10`; user-confirmed company policy; physical destruction remains `MG-RET-PHYS-001` |
| MG-RET-MAN-001 | Archive manifest CREATE/LIFECYCLE 15/15 | **CLOSED** | — | Integrity Pack 1 |
| MG-RET-S3F-001 | S3F ledger → ONAY_AUDIT fingerprint | **CLOSED** | — | Integrity Pack 1 |

**CANONICAL_DOC_STALE = 0.** Historical snapshot’larda eski bilgi final completion blocker değildir.

### MG-ORG-MODEL-001 — kilitli karar özeti (CLOSED)

**SGK / bordro ana yapılar:**
1. Medisa — SGK merkezi = Karabük
2. Karyapı — SGK merkezi = Konya
3. Şenay Mobilya — kendi SGK yapısı

**Operasyonel sistem şubeleri (hedef model — Pack6 `120`; production rename henüz uygulanmadı):**
MEDİSA: Medisa (`MRK`), Medisa Kayseri, Giresun, Medisa Ankara, Medisa İstanbul
KARYAPI: Karyapı, Karyapı Ankara, Karyapı Kayseri, Karyapı İstanbul
DİĞER: Şenay Mobilya

**Çalışma lokasyonu ayrı kavramdır** (ör. İzmir çalışır / SGK Karabük / sistem şubesi Medisa). Sakarya ayrı SGK işyeri veya otomatik ayrı sistem şubesi değildir.

---

## 10. Personel / org rollout dependencies

```text
MG-ORG-MODEL-001 (CLOSED — karar kilitli)
  → MG-ORG-LOC-001 (OPS_ROLLOUT — schema `064`/`118`; refs seeded `119`; personnel mapping USER_GATED)
  → MG-ORG-ATTR-001 (CLOSED — native Bölüm/Birim/Pozisyon + Unvan=gorev; `120`)
  → MG-ORG-ATTR-ROLL-001 (CLOSED_REFERENCE_ROLLOUT — schema `065`/`121` + catalogs `122`; personnel FK apply USER_GATED)
  → MG-OPS-ORG-001 (CLOSED_REFERENCE_ROLLOUT — catalogs+branches complete `121`/`122`; personnel mapping separate)
  → MG-IMPORT-MAP-001 (CLOSED business mapping — remaining → MG-IMPORT-DATA-001)
  → MG-IMPORT-DATA-001 (OPS_ROLLOUT — USER_GATED_DATA_COMPLETION; identity blockers only after `122`)
  → MG-OPS-PERSONEL-001 (real personnel import, USER_GATED)
  → MG-OPS-BIND-001 → MG-OPS-QR-001
```

Import contract (kod):

| | Alanlar |
| --- | --- |
| REQUIRED | tc_kimlik_no, sicil_no, ad, soyad, dogum_tarihi, telefon, ise_giris_tarihi, sube, departman, gorev, personel_tipi |
| OPTIONAL | dogum_yeri, kan_grubu, acil_durum_kisi, acil_durum_telefon, sgk_isveren, calisma_lokasyonu, bolum, birim, pozisyon |
| FORBIDDEN | maaş/ücret/devir kolonları |

---

## 11. Production gates

1. CODE_GAP = 0 olmadan “ürün tamam” denmez.
2. `CANONICAL_DOC_STALE = 0` (sağlandı).
3. Gerçek personel/org/SGK/UBGT write yalnız `95` + kullanıcı onayı.
4. Migration tip production = **70** (run [#32217771186](https://github.com/akelilker/PersonelMedisa/actions/runs/32217771186) @ `cd92d24…`; bundle marker `068` + worker verify pass). Legacy `migration-067-production-precheck` **RETIRED/REMOVED**.
5. Physical destruction yalnız feature enable + manifest + S3F fingerprint coverage + handler + legal review sonrası (schema ready; flag OFF).
6. Stash / force-push / hard reset yasak (audit protokolü).
7. Public repo’ya PII / exact personnel tallies yazılmaz.

---

## 12. Recommended closure order

1. Canonical docs (PR #149): `CURRENT_STATE` + `102` + bu registry
2. Business inputs: `MG-SGK-1514`, `MG-OT-YEAR-POL`, `MG-IMPORT-MAP`, `MG-ORG-ATTR`, `MG-ZORUNLU`
3. Code P1: `MG-OT-YEAR-PATH-001` (policy sonrası veya tutarlılık fix)
4. Ops P1: `MG-SZ-6M-001` — **OPS_ROLLOUT** / `USER_GATED` (schema `061`/`062` production-ready `118`; İK ops follow-up açık)
5. Ops P1: `MG-RET-PHYS-001` — **OPS_ROLLOUT** / `USER_GATED` (schema `059`/`060`/`062` production-ready `118`; feature enable + real destroy açık)
6. Ops P1: `MG-ORG-LOC-001` — **OPS_ROLLOUT** / `USER_GATED` (schema `064`/`118`; refs seeded `119`; personnel mapping açık)
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

Destroy eligibility: Pack 3C (`114`) — **15/15 typed handlers**; SERBEST used-entitlement **fail-closed** (not fully executable). Feature flag default OFF. Manifest creator: Pack 1 CLOSED (`MG-RET-MAN-001`).

| Category | Resolver | Fingerprint | Manifest creator | Lifecycle trigger | Legal hold | Destroy eligibility |
| --- | --- | --- | --- | --- | --- | --- |
| PERSONEL_OZLUK | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |
| ISE_GIRIS_CIKIS | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |
| PERSONEL_BELGE | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |
| PUANTAJ | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3B) |
| BORDRO | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3B) |
| SGK_EKSIK_GUN | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3B) |
| FAZLA_CALISMA | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3C; shared notes preserved) |
| SERBEST_ZAMAN | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | **OPS_ROLLOUT** Pack4B allocation-aware destroy code (`116`); schema `061`/`062` production-ready (`118`); legacy/cross-scope fail-closed; flag OFF; real destroy YOK |
| ONAY_AUDIT | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2 typed + Pack3C generic no-op) |
| IZIN | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |
| RAPOR | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3C + PERSONEL_BELGE gate) |
| IS_KAZASI | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3C + PERSONEL_BELGE gate) |
| DISIPLIN | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack3C) |
| OLAY | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |
| SAVUNMA | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED | CLOSED (Pack2) |

---

## 15. Audit counts (classification hardening)

| Statü | Adet (unique registry ID) | IDs |
| --- | --- | --- |
| **CODE_GAP** | **0** | — |
| **BUSINESS_DECISION_REQUIRED** | **1** | ZORUNLU |
| **OPS_ROLLOUT** | **13** | OPS-PERSONEL, IMPORT-DATA, OPS-ORG, OPS-BIND, OPS-QR, OPS-SGK-CAT, OPS-UBGT, OPS-POLICY, OPS-ENUM-INV, OPS-DEPLOY, **RET-PHYS**, **SZ-6M**, **ORG-LOC** |
| **INTENTIONAL_DEFER** | **7** | QR-CORR, PAY-SELF, I13, ENUM, FSC, PAY-OUT, RET-HTTP |
| **NOT_APPLICABLE** | **4** | SECOND-ENGINE, AUTO-QR-CORR, AUTO-APPLY, IMPORT-UCRET |
| **DOC_STALE** | **0** | — |
| **CLOSED** (registry section 9 + retention + Pack5 OT + user mgmt) | **11** | ORG-MODEL, DOC-CS, DOC-102, DOC-101, DOC-103, DOC-105, RET-MAN, RET-S3F, **OT-YEAR-POL**, **OT-YEAR-PATH**, **USER-MGMT** |
| Closed systems (section 3) | 18 | yeniden açılmadı |

**MUST_FIX_NOW (= CODE_GAP P1 listesi):** *(empty — Pack5 closed code gaps)*

**OPS_ROLLOUT (code closed, prod gated):**
- `MG-RET-PHYS-001` — Pack 4B code + schema production-ready (`116`/`118`); feature enable + real destroy `USER_GATED`
- `MG-SZ-6M-001` — Pack 4B ops surface (`116`); allocation schema production-ready (`118`); İK follow-up `USER_GATED`
- `MG-ORG-LOC-001` — Pack5 schema production-ready (`117`/`118`); refs seeded (`119`); personnel mapping `USER_GATED`

P2 BUSINESS (`MG-ZORUNLU`) final completion için açık kalabilir ama CODE_GAP değildir.

### MG-USER-MGMT-001 — Unified kullanıcı yönetimi workspace

| Alan | Değer |
| --- | --- |
| Statü | **CLOSED** |
| Owner | `YonetimPaneliPage` + `YonetimController` |
| Mevcut | Tek modal workspace: kimlik/rol özeti, şube kapsamı (`YonetimSubeScopeField`), personel bağlantısı, actor lifecycle readback + create/verify/bind |
| Kanıt | `KullaniciRoleSummaryPanel`, `KullaniciActorIdentityPanel`, `tests/unit/kullanici-role-summary.test.ts` |
| Ayrım | Actor formal identity ayrı owner (ADR-0001); authorization weakening yok |

**UNVERIFIED_CRITICAL = 0**
**CANONICAL_DOC_STALE = 0**

---

## 16. Cross-links

- `CURRENT_STATE.md` — tek güncel durum
- `102-hesaplama-cevap-haritasi.md` — hesap okuma haritası
- `109-s3f-…` — S3F production baseline
- `95-s96-release-ops-runbook.md` — ops protokol
- `94-…` — SGK manuel kanıt
- `116-serbest-zaman-pack4b-closure.md` — Pack 4B code closure evidence
