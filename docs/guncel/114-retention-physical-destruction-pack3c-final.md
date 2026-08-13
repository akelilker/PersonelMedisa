# 114 â€” Retention Physical Destruction Pack 3C Final

**TÃ¼r:** Implementation + merge-blocker hardening (feature flag OFF; production migration apply / write / real destroy YOK)
**Branch:** `fix/retention-physical-pack3c-final`
**PR:** #153
**Baseline main:** `b6855a044f7839338746b8a5c8c185cd68acb330` (PR #152 merge)
**Pack 2:** `112` / PR #151
**Pack 3B:** `113` / PR #152
**Tarih:** 2026-08-13
**Gap:** `MG-RET-PHYS-001` â€” **CODE_GAP** (fail-closed gates shipped; SERBEST used-entitlement provenance unresolved)

---

## Summary

| Item | Result |
| --- | --- |
| Remaining policy categories | FAZLA_CALISMA, SERBEST_ZAMAN, DISIPLIN, RAPOR, IS_KAZASI, generic ONAY_AUDIT â†’ **typed handlers** |
| Handler version | `RETENTION_PHYSICAL_V1` (unchanged) |
| Feature flag | default **OFF** |
| New migration | **NO** (`059`/`060` IMMUTABLE; `061` DESIGN only â€” not written) |
| Production write / enable / deploy / merge | **NO** |
| Category coverage | **15/15** typed + registry-present |
| Fully executable (safe mutation) | **not 15/15** â€” SERBEST with unallocated KULLANIM is fail-closed |
| Unknown ONAY_AUDIT entity | fail-closed `DESTRUCTION_HANDLER_POLICY_UNRESOLVED` |

**Do not claim 15/15 fully executable while SERBEST lot-allocation remains unsupported.**

---

## Merge-blocker hardening (this revision)

### Blocker A â€” SERBEST_ZAMAN usage provenance

| Fact | Evidence |
| --- | --- |
| OLUSUM has `kaynak_snapshot_id` + `kaynak_odeme_tercihi_id` | migration `029` |
| KULLANIM forces those FKs **NULL** (+ `hedef_event_id` NULL) | `029` `chk_sz_kullanim_fields` |
| Canonical balance = global pool | `Î£ OLUSUM âˆ’ Î£ KULLANIM` (`45`, `SerbestZamanController`) |
| FIFO/LIFO / lot allocation | **NOT** a canonical contract |

**Minimum safe fix (shipped):** `SerbestZamanDestructionHandler` fail-closes with
`SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED` when any affected personel has unallocated
KULLANIM (or KULLANIM DUZELTME/IPTAL chain). **NO MUTATION.**
Unused OLUSUM-only week graphs still destroy.

### Root model (full closure â€” decision required)

**ALLOCATION_POLICY_ALREADY_EXISTED = NO**

| Option | Description |
| --- | --- |
| **OPTION_A (preferred)** | Explicit entitlement-lot allocation: `KULLANIM â†’ one/more OLUSUM + allocated_dakika` (new table; candidate migration **061** DESIGN) |
| **OPTION_B** | No other canonical lot model exists in repo/docs |

**061 DESIGN sketch (NOT applied this PR):**

```text
serbest_zaman_kullanim_tahsisleri (
  id, kullanim_event_id â†’ serbest_zaman_events(KULLANIM),
  olusum_event_id â†’ serbest_zaman_events(OLUSUM),
  allocated_dakika > 0,
  UNIQUE / CHECK Î£(allocated) = KULLANIM.dakika
)
```

| Historical backfill | Verdict |
| --- | --- |
| Deterministic, non-invented | **NO** â€” global pool has no provenance; FIFO/LIFO would invent facts |
| AUTO BACKFILL | **FORBIDDEN** |

Related: `MG-SZ-6M-001` expiry may also need lot consumption once OPTION_A lands.

### Blocker B â€” FAZLA `notlar_json`

`HaftalikKapanisController::buildSnapshotSatir` writes `notlar_json` for **missing weekly attendance/completeness**, not FM.
FAZLA handler zeros only `fazla_calisma_dakika` / `fazla_surelerle_calisma_dakika`.
**Preserved:** `notlar_json`, `toplam_net_dakika`, `normal_calisma_dakika`, `tam_hafta_verisi`, compliance fields, `kaynak_gun_sayisi`.

### Blocker C â€” RAPOR / IS_KAZASI Ã— PERSONEL_BELGE

Migration `038`: `personel_belge_* .surec_id â†’ surecler.id ON DELETE RESTRICT`.
`DependentRetentionGate::assertPersonelBelgeDependentsClear` â†’ typed `PERSONEL_BELGE_REMAINS`.
No belge cascade inside RAPOR/IS_KAZASI handlers.

### Generic ONAY_AUDIT

No-op overlay unchanged: `NO_PHYSICAL_ROWS` â‰  parent destroyed. Evidence closes virtual obligation only. Unknown entity remains fail-closed.

---

## Locked strategies (schema evidence)

| Category | Mode | Strategy | Scope |
| --- | --- | --- | --- |
| FAZLA_CALISMA | COMPOSITE | DEPENDENCY_GATE â†’ leaf | Gate: no SERBEST events/aktif; delete tercih+audit; zero FM minutes only; preserve shared weekly notes/compliance |
| SERBEST_ZAMAN | DELETE_ROWS | category-owned leaf + usage gate | OLUSUM (+ hedef chain) + aktif; **block** if unallocated KULLANIM; preserve shared header/tercih |
| DISIPLIN | DELETE_ROWS | DEPENDENCY_GATE â†’ shell | OLAY=`DESTROYED` + SAVUNMA cleared |
| RAPOR | DELETE_ROWS | IZIN-family surec | SGK/finans/etki/disiplin + **PERSONEL_BELGE** |
| IS_KAZASI | DELETE_ROWS | IZIN-family surec | same; no BELGE cascade |
| ONAY_AUDIT typed | DELETE_ROWS | Pack2 | `qr_pc_decision` chain |
| ONAY_AUDIT generic | DELETE_ROWS | no-op overlay | 0 physical rows; parent separate |
| ONAY_AUDIT unknown | POLICY | fail-closed | policy_blocker |

---

## Coverage honesty

| Bucket | Count |
| --- | --- |
| Category typed coverage | 15/15 |
| Fully executable safe mutation | &lt;15 â€” SERBEST used-entitlement states fail-closed |
| Fail-closed unresolved | SERBEST usage allocation (`SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED`) |

`MG-RET-PHYS-001` may become **CLOSED** only when SERBEST used-entitlement provenance is genuinely solved (OPTION_A + write-path + destroy), not merely gated.

---

## Ops (NOT executed)

1. Exact SHA + CI green
2. Production DB backup
3. Approve apply `059`/`060` if not applied
4. Feature remains OFF until separate dual-control enable
5. Real business destruction only after enable
6. **061** not written / not applied

---

## Test evidence

| Suite / assert | Result |
| --- | --- |
| Pack 2 MariaDB | PASS |
| Pack 3B MariaDB | PASS |
| Pack 3C MariaDB | PASS |
| `SERBEST_UNALLOCATED_USAGE_BLOCK` | PASS |
| `SERBEST_BALANCE_UNCHANGED_ON_BLOCK` | PASS |
| `FAZLA_SHARED_NOTES_PRESERVED` | PASS |
| `RAPOR_PERSONEL_BELGE_GATE` | PASS |
| `IS_KAZASI_PERSONEL_BELGE_GATE` | PASS |
| Feature flag default OFF | PASS |

`NEW_MIGRATION = NO`
