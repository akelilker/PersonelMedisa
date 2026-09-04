CODE_MIGRATION_TIP: 085
PRODUCTION_MIGRATION_TIP: 083
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: a17da8a57c7d6d1945d1327c6f42b90acc61f103

# Final non-visual closeout inventory (2026-09-03, SELECT-only API; mutation=0)
PERSONEL_TOTAL: 153
PERSONEL_AKTIF: 144
PERSONEL_PASIF_ARCHIVE: 9
PERSONEL_IC: 134
PERSONEL_DIS: 19
USERS_TOTAL: 147
PERSONEL_ROLE_USERS: 122 (all bound; unbound PERSONEL role = 0; duplicate bindings = 0)
MANAGER_ONLY_BOUND_AKTIF: 17 (identity via non-PERSONEL users.personel_id — not a PERSONEL-role gap)
UNBOUND_AKTIF_PERSONEL: 14 (ids 213–226 post-bulk creates; PERSONEL account provisioning DATA_REQUIRED / QR-adjacent — do not reopen 136 rollout)
AKTIF_IC_MISSING_SGK: 0
LEGACY_ROLE_ASSIGNED_REAL_USER_COUNT: 0
USER_SIRKET_GRANTS: 0
USER_SGK_ISVEREN_GRANTS: 0

MG_PERSONNEL_BULK_RECONCILIATION_PRODUCTION: CLOSED_CONFIRMED (BASE/deploy SHA 06bbe03 create/exit apply; live now 153 toplam / 144 aktif / 9 pasif; 202+208 are PASIF in archive but cikis_tarihi=NULL → DATA_INCONSISTENCY residual, not pending bulk-create reopen)
MG_PERSONNEL_BULK_POSTCHECK_DYNAMIC_CONTRACT_001: CLOSED (merged PR #234; PersonelLifecycleBulkPostcheck dynamic contract live)
MG_PERSONNEL_BULK_DRY_RUN_APPLY_PARITY_001: CLOSED (merged PR #232; PersonelLifecycleBulkMutationPlanner live)
MG_PERSONNEL_DEFERRED_EXIT_CIKIS_DATE: DATA_REQUIRED (personel_id 202 Ahmed Khalil Alsamar + 208 Sefine Özcan — PASIF/archive; set real cikis_tarihi via employment-exit owner after approval; production mutation gated)

ORG_HIERARCHY_SCHEMA_READY: true
ORG_HIERARCHY_DATA_READY: true
MG_SIRKET_SUBE_PROD_MAPPING_001: CLOSED_CONFIRMED (apply run 33342644722; 3 şirket + ilk 10 şube + 3 SGK eşlendi; postcheck PASS; canlıda ek Medisa şubeleri 12/İzmir + 13/Sakarya; toplam 12 şube; `subeler.ad` = kısa ad; görünen ad = SubeReadModel.tam_ad (DB kolonu değil); 7 çalışma lokasyonu deferred sube_id=NULL; eski mapping spec yeniden uygulanmaz)
ORG_MAPPING_INVENTORY_OWNER: OrganizationMappingInventoryReport (SELECT-only, checksum'lı, PII'siz) + `ops-organization-inventory.yml`
ORG_MAPPING_EXECUTION_OWNER: OrganizationInitialMappingService (operations-only; public API/UI'da re-parent YOK) + `apply-organization-mapping.yml`
ORG_MAPPING_SPEC_OWNER: OrganizationMappingSpec (allowlist + preimage + inventory checksum pin; `ops/organization-mapping/*.json` = historical preimage artifact, canlı truth değil)
ORG_BRANCH_DISPLAY_OWNER: SubeReadModel (kısa `ad` + türetilmiş `tam_ad`; frontend concat owner YOK)
ORG_USER_SCOPE_ROLLOUT: CODE_READY (owners complete — merged PR #240; production user_sirketler/user_sgk_isverenler grants still 0; mutation gate separate — docs/guncel/140)
ORG_LOCATION_SUBE_MAPPING: DEFERRED (calisma_lokasyonlari.sube_id bilinçli NULL)
ORG_BRANCH_NAME_DB_HARDENING: CLOSED_NOT_NEEDED (tam_ad bilerek kolon değil; SubeReadModel owner)
AYLIK_KAPANIS_SGK_REDESIGN: CLOSED_CONFIRMED (period-close/snapshot execution key remains (sube_id,yil,ay) — muhür/attendance is branch-operational; MaasHesaplamaSnapshotService freezes personeller.sgk_isveren_id + company labels into personel JSON and branch company/default-SGK into header; personel.sube_id may diverge from employer; missing SGK fail-closed at snapshot preflight; no employer-keyed close product)
PAYROLL_SGK_INTEGRITY: CLOSED_CONFIRMED (merged PR #242; PersonelSgkCompanyConsistency live; aktif IC missing SGK = 0; personel_id=1 PASIF+cikis_tarihi=NULL = DATA_INCONSISTENCY deferred — resolver employment-overlap unchanged, PASIF shortcut YOK)

PERSONEL_IMPORT_ROLLOUT: CLOSED_CONFIRMED
PERSONEL_IMPORT_PHASE1_IC: 122 / CLOSED_PASS
PERSONEL_IMPORT_PHASE2_DIS: 11 / CLOSED_PASS
PERSONEL_DIS_CANONICAL_TARGET: 11
PERSONEL_DIS_EXCLUDED_TERMINATED: MUHAMMAT FAWAZ, MUSTAFA HAMID (EXCLUDED_USER_CONFIRMED_TERMINATED — historical import exclusion lock; live id 226 sicil 487 "Muhammat Fawaz" is a separate post-bulk AKTIF DIS row — do not reopen Phase2 import; business confirm if duplicate/rehire)
PERSONEL_IC_PHONE_DEFERRED: 0 / CLOSED_CONFIRMED
MG_OPS_PERSONEL_PHONE_001: CLOSED_CONFIRMED (20/20 gerçek kullanıcı verisi; canonical write owner authenticated PUT /personeller/{id}; post-write readback match=20 missing=0 mismatch=0; sicil 216 tekil isim düzeltmesi dahil)
PERSONEL_USER_BINDING_ROLLOUT_136: CLOSED_CONFIRMED (historical 136 PERSONEL provision+bind; do not restart)
PERSONEL_ACCOUNT_ONBOARDING_FOR_POST_BULK_14: DATA_REQUIRED (ids 213–226; canonical owner PersonelAccountOnboardingService / secure onboarding — separate approval; QR-adjacent handoff)
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
SGK_PERIOD_BRANCHES_1_4_5_6_7_8_9_10_11: AY_1_SON_GUN / ONAYLANDI
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
SUBE_YONETICISI_REAL_ASSIGNMENT_STATUS: PARTIAL (2 AKTIF assigned — username 381/Bora Bayazıt sube 4 + 040/Halil Şenay sube 2; remaining branches still USER_ASSIGNMENT_REQUIRED)
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
