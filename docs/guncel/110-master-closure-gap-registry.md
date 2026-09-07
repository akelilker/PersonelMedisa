CODE_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_TIP: 087

# 110 — Canonical Closure / Gap Registry

**Tür:** Güncel durum kaydı ve sonraki iş seçimi için tek referans.
**Güncelleme:** 2026-09-07 (`BUSINESS_TRUTH_AND_BRANCH_MANAGER_MODEL_LOCK`; A1 pair locked to sedanurB + Sinem; loc5 truth locked; BM model ALREADY_SUPPORTED; mutation=0)
**Kapsam:** PersonelMedisa teknik ana sistem kapanışı + business-truth / branch-manager model kilidi. Production write / deploy / secret / FTP / migration apply / A1 apply / personel mutasyonu / branch-manager write **yok**.

## Migration durumu

| Alan | Değer | Kanıt / sınır |
| --- | --- | --- |
| CODE_MIGRATION_TIP | **087** | Repodaki son migration: `087_sube_muhasebe_yetkilileri.sql` |
| PRODUCTION_MIGRATION_TIP | **087** | Live tip **087**, pending **EMPTY** (last authoritative inventory). Hosting incident does not reopen migration work. |
| Migration 085 | **APPLIED** | `085_gunluk_bildirim_duzeltme_auditleri.sql` |
| Migration 086 | **APPLIED** | `086_personel_historical_exit_date_correction_auditleri.sql` |
| Migration 087 | **APPLIED** | Schema-only ACL table; empty rows = restriction disabled (intentional ACL=0) |
| CODE_MAIN_SHA | **`63f8c9052ca15c3f311e158b41b0afda09d2b874`** | PR **#274 MERGED** / CI PASS |
| PRODUCTION_DEPLOY_SHA (LIVE) | **`9b4aac7919100c5421544824b0401e5046ab621e`** | Last successful deploy. PR274 head **not live** — DEPLOY_HELD on EXTERNAL_PROVIDER_INCIDENT / CONTROL_PLANE_DEGRADED |
| PR #271 | **CLOSED** | Do not reopen |
| PR #272 | **CLOSED** | Do not reopen |
| PR #274 | **MERGED / DEPLOY_HELD** | Formal SGK scope from DB `user_subeler`; reopen only on concrete regression evidence |
| Canlı migration doğrulaması | **PASS @ 087** | pending EMPTY |

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

| ID | Konu | Durum | Not |
| --- | --- | --- | --- |
| `MG-A1-SGK-PERIOD-12-13` | İzmir/Sakarya SGK period dual-control apply | **PRODUCTION_CONFIG_WAITING** / **PAUSED_PENDING_HOST_RECOVERY_AND_SINEM_LIVE_VERIFY** | Preparer `sedanurB`; approver **Sinem Hamaloğlu** (BOLUM_YONETICISI model; temporary); live identity VERIFY_LIVE_REQUIRED; **no apply now** |
| `MG-A2-LOCATION-160-211` | Sedanur/Zeynep location fill | **CLOSED** | Do not reopen |
| `MG-LOC5-ACTIVE-SET` | 200/201/203/204/205/206/209/210/212/217 → loc5 | **BUSINESS_TRUTH_RESOLVED_NO_APPLY** | Preimage plan ready; production write=0 |
| `MG-NAME-203` | Muhammed Mahmud name correction | **BUSINESS_TRUTH_RESOLVED_NO_APPLY** | Only `ad`/`soyad`; no write |
| `MG-BRANCH-MANAGER-MODEL` | Manager ≠ transfer product rule | **CLOSED** (product rule) / **ALREADY_SUPPORTED** (tech) | No runtime fix required |
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
| `MG-SGK-PERIOD-BRANCH-12-13` | A1 period policy | **PRODUCTION_CONFIG_WAITING** (paused) |
| `MG-NULL-LOCATION-HR-RESIDUAL` | Other null locations not in locked set | do not force loc5; RO refresh after host recovery |
| `MG-CROSS-COMPANY-120-158-219` | Cross-company axis pairs | **CROSS_COMPANY_SEMANTICALLY_VALID_DEFER** |

## Bilinçli ertelenen

Karyapı / Şenay company rollout, QR self-service/mobile broad, PERSONEL self-service, new upper approval workflow, visual polish, olağanüstü çalışma, bordro PDF / banka / SGK bildirgesi — **INTENTIONAL_DEFER / FUTURE**.

## Final sınıflandırma (bu continuation point)

| Sınıf | Durum |
| --- | --- |
| **LOCAL_TECHNICAL_GAPS** | Branch-manager runtime = ALREADY_SUPPORTED; no code change this phase |
| **PRODUCTION_CONFIG_GATES** | A1 12/13; sedanurB + Sinem explicit 12/13; loc5 apply; BM grants; 203 name |
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
