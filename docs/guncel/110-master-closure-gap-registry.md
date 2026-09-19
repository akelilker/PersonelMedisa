CODE_MIGRATION_TIP: 090
PRODUCTION_MIGRATION_TIP: 087

# 110 — Canonical Closure / Gap Registry

**Tür:** Güncel durum kaydı ve sonraki iş seçimi için tek referans.
**Güncelleme:** 2026-09-19 (`SGK_REPORTING_PERIOD_CANONICAL_OWNER_CORRECTION`; code tip 090; production tip 087 pending 088+089+090; SGK reporting-period owner = `SGK_ISVEREN`; A1 12/13 branch-specific period SUPERSEDED; BM owner = `sube_sorumlu_yoneticiler`; mutation apply gates still explicit)
**Kapsam:** PersonelMedisa teknik ana sistem kapanışı + business-truth / branch-manager model kilidi. Production write / deploy / secret / FTP / migration apply / A1 apply / personel mutasyonu / branch-manager write **yok**.

## Migration durumu

| Alan | Değer | Kanıt / sınır |
| --- | --- | --- |
| CODE_MIGRATION_TIP | **090** | `090_sgk_isveren_bildirim_donemi_owner.sql` — SGK bildirim donemi canonical owner'i SUBE degil SGK_ISVEREN eksenidir (additive, seed yok; A1 12/13 branch-specific period modelini supersede eder) |
| PRODUCTION_MIGRATION_TIP | **087** | Live tip **087**; pending **088** + **089** + **090** (explicit Apply cPanel migrations gerektirir) |
| Migration 085 | **APPLIED** | `085_gunluk_bildirim_duzeltme_auditleri.sql` |
| Migration 086 | **APPLIED** | `086_personel_historical_exit_date_correction_auditleri.sql` |
| Migration 087 | **APPLIED** | `087_sube_muhasebe_yetkilileri.sql` |
| Migration 088 | **CODE_ONLY_PENDING** | No production apply until explicit Apply cPanel migrations |
| Migration 089 | **CODE_ONLY_PENDING** | Legacy PERSONEL hesaplarinin canonical secure activation modeline hizalanmasi; production apply explicit onay ister |
| Migration 090 | **CODE_ONLY_PENDING** | `090_sgk_isveren_bildirim_donemi_owner.sql` — SGK bildirim donemi owner'i SGK_ISVEREN eksenine tasindi; branch-specific period fix'i supersede eder; production apply explicit onay ister |
| CODE_MAIN_SHA | **`f5551160cab7d12b2086d7146c7822f4dfdb469d`** | #274+#275+#276+#277 merged; #278 integrating |
| PRODUCTION_DEPLOY_SHA (LIVE) | **`f5551160cab7d12b2086d7146c7822f4dfdb469d`** | After #277 deploy in recovery sweep |
| PR #271 | **CLOSED** | Do not reopen |
| PR #272 | **CLOSED** | Do not reopen |
| PR #274 | **MERGED / DEPLOYED** | Recovery deploy PASS @ `63f8c905` |
| Canlı migration doğrulaması | **PASS @ 087** / pending **088** + **089** + **090** | Hosting recovered; apply gated |

## Durum sözlüğü

| Durum | Anlamı |
| --- | --- |
| **CLOSED** | Karar ve kod owner’ı tamam; bu registry kapsamında yeni kod işi yok |
| **CLOSED_CONFIRMED** | Canlı operasyon da doğrulanmış kapanış; yeniden import/apply açılmaz |
| **PRODUCTION_CONFIG_WAITING** | Owner hazır; canlı config/apply hosting recovery + explicit approval bekler |
| **LIVE_DATA_VERIFY_WAITING** | Salt-okunur canlı doğrulama host erişilebilir olunca |
| **BUSINESS_TRUTH_RESOLVED_NO_APPLY** | İş gerçeği kilitli; production write henüz yok |
| **BUSINESS_DECISION_REQUIRED** | İş sahibi kararı; teknik blocker değil |
| **BUSINESS_IDENTITY_DECISION_REQUIRED** | Kişi kimliği kesinleşmeden assignment yazılmaz |
| **INTENTIONAL_DEFER** | Bilinçli erteleme |
| **BUG** | Deterministik teknik hata (final sayımda **0**) |

## A1 / A2 / location / branch-manager

A1_SUPERSEDED_BY: SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION
REPORTING_PERIOD_CANONICAL_AXIS: SGK_ISVEREN
BRANCH_SPECIFIC_PERIOD_REQUIRED: NO
A1_12_13_TARGET_FIX: NO — 12/13 branch-specific period rows are NOT the target fix.

| ID | Konu | Durum | Not |
| --- | --- | --- | --- |
| `MG-A1-SGK-PERIOD-12-13` | İzmir/Sakarya SGK period dual-control apply | **SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION** | Historical: Preparer `sedanurB`; approver **Sinem Hamaloğlu**; 12/13 ayni `sgk_isveren_id=1` eksenindedir → branch-specific period row hedef fix degil; **no apply now**. Eski kanit arsiv olarak kalir. |
| `MG-A2-LOCATION-160-211` | Sedanur/Zeynep location fill | **CLOSED** | Do not reopen |
| `MG-LOC5-ACTIVE-SET` | 200/201/203/204/205/206/209/210/212/217 → loc5 | **BUSINESS_TRUTH_RESOLVED_NO_APPLY** | Preimage plan ready; production write=0 |
| `MG-NAME-203` | Muhammed Mahmud name correction | **BUSINESS_TRUTH_RESOLVED_NO_APPLY** | Only `ad`/`soyad`; no write |
| `MG-BRANCH-MANAGER-MODEL` | Manager ≠ transfer / ≠ `user_subeler` access | **CLOSED** (product rule) / **TECHNICAL_GAP_LOCAL_FIXABLE** (tech) | Runtime owner = PR **#278** (`sube_sorumlu_yoneticiler`) |
| `MG-BRANCH-MANAGER-MAP` | Medisa manager business map | **BUSINESS_TRUTH_RESOLVED_NO_APPLY** | Kayseri Kübra surname unresolved |
| `MG-HOSTING-CONTROL-PLANE` | cPanel/FTP control plane | **EXTERNAL_PROVIDER_INCIDENT** | No credential/FTP mutation yet; blocks PR274 live + A1/loc/BM apply |

## Kapanan teknik başlıklar (özet — reopen yok)

Önceki CLOSED / CLOSED_CONFIRMED kalemler (migration 087, Medisa location 7/7, Medisa grants, ACL=0 intentional, 202/208, bulk reconciliation, payroll SGK integrity, org mapping, retention, DIS model, QR teknik pipeline, vb.) **aynı kalır**. Tam tarihsel tablo arşivlenmiş önceki registry sürümlerindedir; bu turda reopen yok.

## Kullanıcı / ops bekleyen (teknik blocker değil)

| ID | Konu | Durum |
| --- | --- | --- |
| `MG-SUBE-YONETICI-001` | SUBE_YONETICISI Medisa coverage apply | **BUSINESS_TRUTH_RESOLVED_NO_APPLY** (map locked; write=0) |
| `MG-KAYSERI-KUBRA-IDENTITY` | Kayseri manager Kübra surname | **BUSINESS_IDENTITY_DECISION_REQUIRED** |
| `MG-SINEM-LIVE-VERIFY` | Sinem account/role/actor/scopes | **LIVE_DATA_VERIFY_WAITING** |
| `MG-PERSONNEL-POST-BULK-ACCOUNT-014` | Post-bulk PERSONEL accounts | **INTENTIONAL_DEFER** |
| `MG-SGK-PERIOD-BRANCH-12-13` | A1 period policy | **SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION** (branch-specific period hedef fix degil) |
| `MG-NULL-LOCATION-HR-RESIDUAL` | Other null locations not in locked set | do not force loc5; RO refresh after host recovery |
| `MG-CROSS-COMPANY-120-158-219` | Cross-company axis pairs | **CROSS_COMPANY_SEMANTICALLY_VALID_DEFER** |

## Bilinçli ertelenen

Karyapı / Şenay company rollout, QR self-service/mobile broad, PERSONEL self-service, new upper approval workflow, visual polish, olağanüstü çalışma, bordro PDF / banka / SGK bildirgesi — **INTENTIONAL_DEFER / FUTURE**.

## Final sınıflandırma (bu continuation point)

| Sınıf | Durum |
| --- | --- |
| **LOCAL_TECHNICAL_GAPS** | Branch-manager durable owner shipping in #278 (088); apply + manager rows after deploy |
| **PRODUCTION_CONFIG_GATES** | A1 12/13 branch-specific period **SUPERSEDED** (owner correction); loc5 apply; BM grants; 203 name |
| **LIVE_VERIFY_GATES** | PR274 live SHA; Sinem identity; Halil managed branches; loc5 preimages |
| **BUSINESS_DECISIONS** | Kayseri Kübra surname |
| **INTENTIONAL_DEFERS** | Karyapı/Şenay/QR/self-service/polish; 120/158/219 |
| **CLOSED_ITEMS** | PR271/272/274-code, A2 160/211, 202/208, tip 087, location 7/7, Medisa grants, ACL=0, BM product model |
| **BUG** | **0** |
| **TEKNIK_ANA_SISTEM** | **KAPALI** |

## Next exact recovery flow

1. Hosting recovery (provider) — still no secret mutation unless separately approved
2. Deploy/verify PR274 live (`LIVE_SHA == 63f8c905…`)
3. Live verify Sinem exact identity + BOLUM_YONETICISI formal SGK eligibility
4. Explicit user approval for actor/scope / loc5 / BM / name writes
5. A1 dual-control sequence in `a1-a2-a3-no-apply-remediation-plan.json`
6. Future loc5 + name203 + BM assignment applies with preimage guards
7. Exact readback → A1 CLOSED (other applies separately tracked)

## Referans

- `CURRENT_STATE.md`
- `docs/guncel/141-business-truth-and-branch-manager-model-lock.md`
- `ops/organization-mapping/a1-a2-a3-no-apply-remediation-plan.json`
- `ops/organization-mapping/fabrika-karabuk-loc5-no-apply-preimage-plan.json`
- `ops/organization-mapping/personel-203-name-correction-no-apply.json`
- `ops/organization-mapping/branch-manager-assignment-no-apply-plan.json`
- `ops/organization-mapping/personnel-residual-decision-pack.json`
- `ops/sgk/a1-approver-candidate-ro-probe.sql`
- `docs/adr/0001-separate-formal-actor-identity-from-personnel-master.md`
