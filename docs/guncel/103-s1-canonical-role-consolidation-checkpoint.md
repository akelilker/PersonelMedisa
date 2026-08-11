# 103 — S1 Canonical Role Consolidation Checkpoint

**Branch:** `feat/canonical-role-consolidation`  
**Date:** 2026-08-11  
**Status:** Implementation complete on branch (no production apply)

## BEFORE_ROLE_MODEL

```
GENEL_YONETICI, BOLUM_YONETICISI, MUHASEBE, BIRIM_AMIRI,
PATRON, IK_BORDRO, SGK_KARAR_ONAY_YETKILISI, IDARI_ISLER,
SISTEM_YONETICISI, AUTH_SMOKE_READONLY
```

## AFTER_ROLE_MODEL

**Human (exact 7):**
PERSONEL, MUHASEBE, IK_SORUMLUSU, BIRIM_AMIRI, BOLUM_YONETICISI, GENEL_YONETICI, SISTEM_YONETICISI

**Technical (exact 1):**
AUTH_SMOKE_READONLY

## LEGACY_ALIASES

| Legacy | Canonical | Mode |
| --- | --- | --- |
| PATRON | GENEL_YONETICI | Safe auto (login/JWT/permissions + migration 054 UPDATE) |
| IK_BORDRO | IK_SORUMLUSU | Safe auto (login/JWT/permissions + migration 054 UPDATE) |
| SGK_KARAR_ONAY_YETKILISI | — | Fail-closed; manual inventory required |
| IDARI_ISLER | — | Fail-closed; manual inventory required |

Owner: `canonicalizeUserRole` (FE) / `RolePermissions::normalizeRole` (BE).

## MUHASEBE_NARROWING

External accountant profile: finalized mali/bordro/rapor **read** + export.  
Removed: personel/surec/bildirim/puantaj write, finans write, SGK prepare/approve, policy manage, legal hold, destruction, revision approve, etki generate/apply, olay/disiplin karar.

## IK_SORUMLUSU_PERMISSIONS

Operational IK successor of IK_BORDRO: personel/surec ops, disiplin review/defense, puantaj hazırlık, maaş hazırlık, SGK **prepare**, arsiv/retention view.  
No: olay decide, disiplin final, SGK approve, bordro kesinleştirme, GY approvals, legal_hold, destruction.

## SGK_OWNER_SPLIT

- `sgk_karar_paketi.prepare` → IK_SORUMLUSU (+ GENEL_YONETICI retained)
- `sgk_karar_paketi.approve` → GENEL_YONETICI only

## SYSTEM_ADMIN_INVARIANT

SISTEM_YONETICISI is **assignable** technical owner (IT Müdürü).

**ALLOW (S1B hardening):**
- Broad troubleshooting READ across personel/süreç/bildirim/puantaj/rapor/finans/bordro/SGK-read surfaces/revizyon/mevzuat/şirket/resmi tatil/ISG/arsiv/retention
- `yonetim-paneli.view` + `yonetim-paneli.manage` for user/role/sube/katalog technical administration  
  (existing key is safe: does **not** gate business policy / approvals)

**DENY:**
- all business `*.approve` / final decisions
- `puantaj.olay_karar.decide`, `disiplin.final_decision`
- `sgk_karar_paketi.prepare|approve`, `bordro_kesinlestirme.approve`
- `revizyon.approve|reject`
- `legal_hold.manage`, `retention.destruction.approve|request`
- `patron_ack.mark_seen`
- `sirket_parametreleri.manage`, `mevzuat_parametreleri.manage`, `resmi_tatil_takvimi.manage`
- domain operational writes (personel/ucret/puantaj/finans/maas manage)

**NEW_PERMISSION_ADDED = NO**  
**WHY_EXISTING_PERMISSION_UNSAFE = N/A** (`yonetim-paneli.manage` only covers users/subeler/referans catalog)

## PERSONEL_ZERO_BUSINESS_ACCESS

PERSONEL added with empty permission matrix.  
UI placeholder: “Personel ekranı henüz aktif değil.”  
No QR / self-service / users↔personel binding in this phase.

## MIGRATION_054_STRATEGY

File: `api/migrations/054_canonical_role_consolidation.sql`

1. Widen ENUM (canonical + keep unresolved legacy values)
2. UPDATE PATRON → GENEL_YONETICI
3. UPDATE IK_BORDRO → IK_SORUMLUSU
4. Do **not** UPDATE SGK_KARAR_ONAY_YETKILISI / IDARI_ISLER
5. Do **not** shrink ENUM (deferred)
6. 052 / 053 untouched

**MIGRATION_APPLIED_PRODUCTION = NO**

## PRODUCTION_LEGACY_INVENTORY_REQUIRED

Before production remap of unresolved roles:
- Count users with `SGK_KARAR_ONAY_YETKILISI`
- Count users with `IDARI_ISLER`
- Human decision per user → target canonical role

## DEFERRED_WORK

- ANNUAL_LEAVE_MANUAL_ENTITLEMENT_OVERRIDE
- PERSONEL_SELF_SERVICE_BINDING
- QR_ATTENDANCE_FOUNDATION
- Final ENUM shrink of unused legacy values (055+)

## ROLLBACK

1. Revert PR / redeploy previous SHA
2. If 054 applied: restore DB from backup (ENUM + role UPDATEs)
3. Do not half-apply ENUM shrink without inventory
