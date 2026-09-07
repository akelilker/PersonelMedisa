CODE_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_TIP: 087

# 110 — Canonical Closure / Gap Registry

**Tür:** Güncel durum kaydı ve sonraki iş seçimi için tek referans.
**Güncelleme:** 2026-09-07 (HOSTING_INCIDENT_LOCAL_COMPLETION_SWEEP; PR #274 MERGED / deploy HELD; A2 CLOSED; A1 PAUSED; mutation=0)
**Kapsam:** PersonelMedisa teknik ana sistem kapanışı + hosting-incident sırasında lokal tamamlanabilir işlerin sınıflandırması. Production write / deploy / secret / FTP / migration apply / A1 apply / personel mutasyonu **yok**.

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
| **BUSINESS_DECISION_REQUIRED** | İş sahibi kararı; teknik blocker değil |
| **INTENTIONAL_DEFER** | Bilinçli erteleme |
| **BUG** | Deterministik teknik hata (final sayımda **0**) |

## A1 / A2 / hosting continuation

| ID | Konu | Durum | Not |
| --- | --- | --- | --- |
| `MG-A1-SGK-PERIOD-12-13` | İzmir/Sakarya SGK period dual-control apply | **PRODUCTION_CONFIG_WAITING** / **PAUSED_PENDING_HOST_RECOVERY_AND_FORMAL_ACTOR_SELECTION** | Locked hashes in `ops/organization-mapping/a1-a2-a3-no-apply-remediation-plan.json`; preparer `sedanurB`; approver TBD 343/220/017/349 after RO probe; **no apply now** |
| `MG-A2-LOCATION-160-211` | Sedanur/Zeynep location fill | **CLOSED** | Do not reopen |
| `MG-A3-PERSONEL-212` | Null branch+location | **BUSINESS_DECISION_REQUIRED** | Decision pack only; no auto target |
| `MG-HOSTING-CONTROL-PLANE` | cPanel/FTP control plane | **EXTERNAL_PROVIDER_INCIDENT** | No credential/FTP mutation yet; blocks PR274 live + A1 apply |

## Kapanan teknik başlıklar (özet — reopen yok)

Önceki CLOSED / CLOSED_CONFIRMED kalemler (migration 087, Medisa location 7/7, Medisa grants, ACL=0 intentional, 202/208, bulk reconciliation, payroll SGK integrity, org mapping, retention, DIS model, QR teknik pipeline, vb.) **aynı kalır**. Tam tarihsel tablo arşivlenmiş önceki registry sürümlerindedir; bu turda reopen yok.

## Kullanıcı / ops bekleyen (teknik blocker değil)

| ID | Konu | Durum |
| --- | --- | --- |
| `MG-SUBE-YONETICI-001` | SUBE_YONETICISI Medisa coverage | **BUSINESS_DECISION_REQUIRED** |
| `MG-PERSONNEL-POST-BULK-ACCOUNT-014` | Post-bulk PERSONEL accounts | **INTENTIONAL_DEFER** |
| `MG-SGK-PERIOD-BRANCH-12-13` | A1 period policy | **PRODUCTION_CONFIG_WAITING** (paused) |
| `MG-NULL-LOCATION-HR-RESIDUAL` | Remaining null locations after A2 | **BUSINESS_DECISION_REQUIRED** / LIVE verify after host recovery |
| `MG-CROSS-COMPANY-120-158-219` | Cross-company axis pairs | **INTENTIONAL_DEFER** / DEFERRED_REVIEW |

## Bilinçli ertelenen

Karyapı / Şenay company rollout, QR self-service/mobile broad, PERSONEL self-service, new upper approval workflow, visual polish, olağanüstü çalışma, bordro PDF / banka / SGK bildirgesi — **INTENTIONAL_DEFER / FUTURE**.

## Final sınıflandırma (bu continuation point)

| Sınıf | Durum |
| --- | --- |
| **LOCAL_TECHNICAL_GAPS** | Closed in this sweep (readiness prepare\|approve + tip-pin/docs/RO packages) |
| **PRODUCTION_CONFIG_GATES** | A1 12/13; sedanurB+approver explicit 12/13 scope |
| **LIVE_VERIFY_GATES** | PR274 live SHA; approver candidate RO probe; residual null-location refresh |
| **BUSINESS_DECISIONS** | personel 212; remaining null-location HR; SUBE_YONETICISI coverage |
| **INTENTIONAL_DEFERS** | Karyapı/Şenay/QR/self-service/polish |
| **CLOSED_ITEMS** | PR271/272/274-code, A2 160/211, 202/208, tip 087, location 7/7, Medisa grants, ACL=0 |
| **BUG** | **0** |
| **TEKNIK_ANA_SISTEM** | **KAPALI** |

## Next exact recovery flow

1. Hosting recovery (provider) — still no secret mutation unless separately approved
2. Deploy/verify PR274 live (`LIVE_SHA == 63f8c905…`)
3. RO approver probe (`ops/sgk/a1-approver-candidate-ro-probe.sql`)
4. User picks approver + explicit config-write approval
5. A1 dual-control sequence in `a1-a2-a3-no-apply-remediation-plan.json`
6. Exact readback → A1 CLOSED

## Referans

- `CURRENT_STATE.md`
- `ops/organization-mapping/a1-a2-a3-no-apply-remediation-plan.json`
- `ops/organization-mapping/personnel-residual-decision-pack.json`
- `ops/sgk/a1-approver-candidate-ro-probe.sql`
- `docs/adr/0001-separate-formal-actor-identity-from-personnel-master.md`
