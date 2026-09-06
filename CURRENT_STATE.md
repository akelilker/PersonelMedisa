CODE_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: fcee67186f1ceaec86624ecbe745f51f56330bcd

# PR #271 fresh poststate (2026-09-06 inventory run 34062358628; SELECT-only; mutation=0)
# Historical 2026-09-03 non-visual closeout notes remain below where still accurate; stale tip/grant/location pins above are reconciled here.
PERSONEL_TOTAL: 153
PERSONEL_AKTIF: NOT_IN_ORGANIZATION_INVENTORY_OWNER
PERSONEL_PASIF_ARCHIVE: NOT_IN_ORGANIZATION_INVENTORY_OWNER
PERSONEL_IC: 134
PERSONEL_DIS: 19
USERS_TOTAL: 147
PERSONEL_ROLE_USERS: HISTORICAL_2026-09-03 (122 bound; inventory owner does not publish role/binding splits)
MANAGER_ONLY_BOUND_AKTIF: 17 (identity via non-PERSONEL users.personel_id — not a PERSONEL-role gap)
UNBOUND_AKTIF_PERSONEL: INTENTIONAL_DEFER / BUSINESS_DECISION_REQUIRED (post-bulk PERSONEL/mobile/QR late-phase; do not reopen 136; do not treat as Priority A)
AKTIF_IC_MISSING_SGK: 0
LEGACY_ROLE_ASSIGNED_REAL_USER_COUNT: 0
USER_SIRKET_GRANTS: 3
USER_SGK_ISVEREN_GRANTS: 3
USER_SUBE_ASSIGNMENTS: 31
SUBE_MUHASEBE_YETKILILERI_ACL: SCHEMA_LIVE_087 (inventory does not publish ACL row_count; migration 087 writes no ACL rows; restriction disabled when empty; PR271 close ACL=0)
PR271_STATUS: CLOSED
MEDISA_WORK_LOCATION_MAPPING: APPLIED (1→5, 2→2, 3→6, 4→12, 5→1, 6→4, 7→13; apply run 34037103819; fresh inventory checksum d83929ea…56b2)
MEDISA_USER_SCOPE_GRANTS: APPLIED (fresh totals user_sirketler=3 / user_sgk_isverenler=3; MUHASEBE role user_subeler assignments=7; Karyapı/Şenay company grants DEFERRED — no sirket-total inflation)

MG_PERSONNEL_BULK_RECONCILIATION_PRODUCTION: CLOSED_CONFIRMED (BASE/deploy SHA 06bbe03 create/exit apply; fresh inventory TOTAL=153; AKTIF/PASIF split not republished by organization inventory)
MG_PERSONNEL_BULK_POSTCHECK_DYNAMIC_CONTRACT_001: CLOSED (merged PR #234; PersonelLifecycleBulkPostcheck dynamic contract live)
MG_PERSONNEL_BULK_DRY_RUN_APPLY_PARITY_001: CLOSED (merged PR #232; PersonelLifecycleBulkMutationPlanner live)
MG_PERSONNEL_EXIT_DATE_202_208: CLOSED_CONFIRMED (do not reopen; do not run historical exit correction; surec 38/39 untouched; 2026-07-30 is not truth)

ORG_HIERARCHY_SCHEMA_READY: true
ORG_HIERARCHY_DATA_READY: true
MG_SIRKET_SUBE_PROD_MAPPING_001: CLOSED_CONFIRMED (apply run 33342644722; 3 şirket + ilk 10 şube + 3 SGK eşlendi; postcheck PASS; canlıda ek Medisa şubeleri 12/İzmir + 13/Sakarya; toplam 12 şube; `subeler.ad` = kısa ad; görünen ad = SubeReadModel.tam_ad (DB kolonu değil); eski mapping spec yeniden uygulanmaz)
ORG_MAPPING_INVENTORY_OWNER: OrganizationMappingInventoryReport (SELECT-only, checksum'lı, PII'siz) + `ops-organization-inventory.yml`
ORG_MAPPING_EXECUTION_OWNER: OrganizationInitialMappingService (operations-only; public API/UI'da re-parent YOK) + `apply-organization-mapping.yml`
ORG_MAPPING_SPEC_OWNER: OrganizationMappingSpec (allowlist + preimage + inventory checksum pin; `ops/organization-mapping/*.json` = historical preimage artifact, canlı truth değil)
ORG_BRANCH_DISPLAY_OWNER: SubeReadModel (kısa `ad` + türetilmiş `tam_ad`; frontend concat owner YOK)
ORG_USER_SCOPE_ROLLOUT: APPLIED_MEDISA (PR #271; docs/guncel/140; fresh inventory user_sirketler=3 / user_sgk_isverenler=3; Karyapı/Şenay DEFERRED)
ORG_LOCATION_SUBE_MAPPING: APPLIED (Medisa work-location map; fresh inventory work_locations exact 1→5|2→2|3→6|4→12|5→1|6→4|7→13; orphan_lokasyon_sube_count=0)
ORG_BRANCH_NAME_DB_HARDENING: CLOSED_NOT_NEEDED (tam_ad bilerek kolon değil; SubeReadModel owner)
AYLIK_KAPANIS_SGK_REDESIGN: CLOSED_CONFIRMED (period-close/snapshot execution key remains (sube_id,yil,ay) — muhür/attendance is branch-operational; MaasHesaplamaSnapshotService freezes personeller.sgk_isveren_id + company labels into personel JSON and branch company/default-SGK into header; personel.sube_id may diverge from employer; missing SGK fail-closed at snapshot preflight; no employer-keyed close product)
PAYROLL_SGK_INTEGRITY: CLOSED_CONFIRMED (merged PR #242; PersonelSgkCompanyConsistency live; aktif IC missing SGK = 0)
SGK_PERIOD_BRANCH_12_13: BUSINESS_DECISION_REQUIRED (owner SgkSirketPolitikaReadService is per-sube explicit rows in sgk_sirket_politika_surumleri — not company inheritance; organization inventory does not publish branch 12/13 period policy; do not invent CLOSED)

PERSONEL_IMPORT_ROLLOUT: CLOSED_CONFIRMED
PERSONEL_IMPORT_PHASE1_IC: 122 / CLOSED_PASS
PERSONEL_IMPORT_PHASE2_DIS: 11 / CLOSED_PASS
PERSONEL_DIS_CANONICAL_TARGET: 11
PERSONEL_DIS_EXCLUDED_TERMINATED: MUHAMMAT FAWAZ, MUSTAFA HAMID (EXCLUDED_USER_CONFIRMED_TERMINATED — historical import exclusion lock; live id 226 sicil 487 "Muhammat Fawaz" is a separate post-bulk AKTIF DIS row — do not reopen Phase2 import; business confirm if duplicate/rehire)
PERSONEL_IC_PHONE_DEFERRED: 0 / CLOSED_CONFIRMED
MG_OPS_PERSONEL_PHONE_001: CLOSED_CONFIRMED (20/20 gerçek kullanıcı verisi; canonical write owner authenticated PUT /personeller/{id}; post-write readback match=20 missing=0 mismatch=0; sicil 216 tekil isim düzeltmesi dahil)
PERSONEL_USER_BINDING_ROLLOUT_136: CLOSED_CONFIRMED (historical 136 PERSONEL provision+bind; do not restart)
PERSONEL_ACCOUNT_ONBOARDING_FOR_POST_BULK_14: INTENTIONAL_DEFER / BUSINESS_DECISION_REQUIRED (canonical owner PersonelAccountOnboardingService / secure onboarding + QR late-phase; not an operations blocker today; do not Priority-A)
DIS_KAYNAK_MODEL: OPERASYONEL_NON_FINANCIAL (docs/guncel/130; migration 076 production applied; schema ready; assignment table live; production assignment count=0)
DIS_ORG_OPTIONAL: YES
DIS_EFFECTIVE_ORG_ASSIGNMENT_AWARE: YES
DIS_REAL_PAYROLL_ELIGIBLE: HAYIR
DIS_SGK_ELIGIBLE: HAYIR
DIS_BANK_EXPORT_ELIGIBLE: HAYIR
MG_OPS_DIS_ORG_COMPLETE_001: CLOSED
MG_OPS_DIS_OPS_MODEL_001: CLOSED_CONFIRMED
DIS_GERCEK_GOREVLENDIRME_ROLLOUT: AYRI_INSAN_OPERASYON_KARARI (teknik kapanışı bloke etmez)

SGK_CATALOG_LIVE_VERIFY: CLOSED_CONFIRMED
SGK_CATALOG_TAMLIK: RESMI_KAYNAKLI_KISITLI / ONAYLANDI / kod_sayisi=19
SGK_PERIOD_BRANCHES_1_4_5_6_7_8_9_10_11: AY_1_SON_GUN / ONAYLANDI (historical 2026-08-27 live verify)
SGK_PERIOD_BRANCHES_12_13: BUSINESS_DECISION_REQUIRED (live per-sube policy not in organization inventory; owner = SgkSirketPolitikaReadService)
UBGT_CALENDAR_LIVE_VERIFY: CLOSED_CONFIRMED
UBGT_2026_AKTIF: 17 (TAM_GUN=14, YARIM_GUN=3, dup/conflict=0)
PAYROLL_POLICY_REVISION: 3 / 14/14 / HAFTA_TATILI_GUNLERI=0

SERBEST_ZAMAN_OPERASYON_OWNER: IK_SORUMLUSU
SERBEST_ZAMAN_OPERATIONAL_CHAIN: BIRIM_AMIRI/BOLUM_YONETICISI → GENEL_YONETICI
MG_SZ_6M_001: CLOSED

RETENTION_POLICY: MINIMUM_10_YEARS
MIN_RETENTION_YEARS: 10
SHORTER_CATEGORY_RETENTION: DISABLED
RETENTION_CATEGORY_COUNT: 15
RETENTION_CATEGORY_LT_10_COUNT: 0
PERSONNEL_RETENTION_ANCHOR: EMPLOYMENT_END_DATE_OR_LATER_APPLICABLE_ANCHOR
ACTIVE_EMPLOYEE_DESTRUCTION: PROHIBITED
MISSING_ANCHOR: FAIL_CLOSED
LEGAL_HOLD_OVERRIDE: ENABLED
LONGER_LEGAL_RETENTION_WINS: YES
RETENTION_EXAMPLE_2010_START_2026_END_EARLIEST: 2036
RETENTION_PHYSICAL_STATUS: CLOSED_CONFIRMED
MG_RET_PHYS_001: CLOSED_CONFIRMED

ARCHIVE_LIFECYCLE: CLOSED_CONFIRMED (PR #243/#244; aktif list vs archive/read-only; create path cannot mint PASIF; confirmed demo fixture purge fail-closed; rows 1–4 remain PASIF residuals pending shared-data-safe purge evidence)
SUBE_YONETICISI_REAL_ASSIGNMENT_STATUS: BUSINESS_DECISION_REQUIRED (fresh inventory SUBE_YONETICISI user_subeler assignment_count=2; Medisa branches 1,2,4,5,6,12,13; automatic assignment YOK; historical note: 381→4 + 040→2)
MANAGER_USERS_VERIFIED: ilkerA GENEL_YONETICI AKTIF; serhan.kose GENEL_YONETICI AKTIF; sedanurB IK_SORUMLUSU AKTIF (no new drift); Savaş Şenay = PERSONEL user 452 bound personel_id 159 (not manager role); Alper Sungur = NO_PRODUCTION_USER (SOURCE_DATA_REQUIRED — do not invent)

LEGACY_ROLE_AUTHORIZATION_ACTIVE: HAYIR
LEGACY_ROLE_CLEANUP_REQUIRED: NO
LEGACY_ROLE_SELECTABLE_COUNT: 0
CANONICAL_AUTH_ROLE_COUNT: 9 (8 pre-081 + IK_PERSONELI via migration 081; production IK_PERSONELI assigned = 0)
SYSTEM_TEST_ROLE_COUNT: 1 (AUTH_SMOKE_READONLY — teknik salt-okuma aktörü, personel rol borcu değildir)
LEGACY_ROLE_ENUM_SCHEMA_COUNT: 0
LEGACY_ROLE_ENUM_SCHEMA_SHRINK: MIGRATION_077_PRODUCTION_APPLIED
MG_ROLE_ENUM_DEBT_001: CLOSED_CONFIRMED
TECH_DEBT_NON_BLOCKING_COUNT: 0

OLAGANUSTU_CALISMA: INTENTIONAL_DEFER
MG_EXC_WORK_001: INTENTIONAL_DEFER
QR_EMPLOYEE_ROLLOUT: DEFERRED_BY_USER

FINAL_NON_VISUAL_CLOSEOUT: CLOSED_DOC_RECONCILE (no product-code MUST_FIX before UI polish; remaining = data/ops + optional/future + QR deferred)
UI_POLISH_READY: YES (after this doc reconcile merges)

TEKNIK_ANA_SISTEM: KAPALI
BUG_COUNT: 0
OPS_ROLLOUT_COUNT: 0
MUST_FIX_BEFORE_UI_POLISH_COUNT: 0
