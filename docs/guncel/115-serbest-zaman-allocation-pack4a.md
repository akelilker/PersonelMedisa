# 115 — Serbest Zaman Allocation Pack 4A

**Tür:** Foundation (ledger + write-path + tests); **gaps not closed**  
**Branch:** `fix/serbest-zaman-allocation-pack4a`  
**Tarih:** 2026-08-13  
**Gaps:** `MG-RET-PHYS-001` = **CODE_GAP** · `MG-SZ-6M-001` = **CODE_GAP**

---

## Summary

Pack 4A ships the OPTION_A entitlement-lot allocation foundation:

| Item | Result |
| --- | --- |
| Migration | `061_serbest_zaman_kullanim_tahsisleri.sql` (additive; **production apply YOK**) |
| Service | `SerbestZamanAllocationService` |
| Write-path | `SerbestZamanController` kullanim / duzeltme / iptal / bakiye wired |
| Auto backfill | **FORBIDDEN** (`NO_AUTO_BACKFILL`) |
| Ledger model | **APPEND_ONLY_DELTA_LEDGER** (positive consume + negative release; no UPDATE of prior rows) |
| Retention destroy of allocations | **Pack 4B** (gate category `SERBEST_ZAMAN` extended; destroy not implemented here) |

**Do not claim `MG-RET-PHYS-001` or `MG-SZ-6M-001` CLOSED.** CODE_GAP count remains **4**.

---

## POLICY

| Direction | Code |
| --- | --- |
| Consume (new / increase KULLANIM) | `EARLIEST_EXPIRY_FIRST_V1` |
| Release (reduce / cancel) | `REVERSE_EARLIEST_EXPIRY_FIRST_V1` |

Ordering within equal `son_kullanim_tarihi`: older `event_tarihi`, then lower `olusum_event_id`.

Expiry boundary (canonical, unchanged): lot is usable while `$referans <= $son_kullanim_tarihi`; expired when `$referans > $son_kullanim_tarihi`.

FIFO/LIFO as named product policies are **not** canonical contracts and are not used for historical invention.

---

## LEGACY / `NO_AUTO_BACKFILL`

Pre-061 `SERBEST_ZAMAN_KULLANIM` rows without matching tahsis rows are **`LEGACY_UNALLOCATED`**.

- New KULLANIM / allocation writes fail-closed: `SERBEST_ZAMAN_LEGACY_ALLOCATION_REQUIRED`
- Migration `061` performs **no** event or allocation INSERT for existing data
- No deterministic auto-backfill (global pool has no provenance; inventing lots would be false history)

Pack 3C SERBEST physical-destroy fail-closed for unallocated usage (`SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED`) remains in force and is **not** weakened by Pack 4A.

---

## `APPEND_ONLY_DELTA_LEDGER`

Table `serbest_zaman_kullanim_tahsisleri`:

- Positive `tahsis_delta_dakika` = consume from an OLUSUM lot
- Negative delta = release (correction down / IPTAL)
- Unique `(kaynak_event_id, olusum_event_id)` ties each delta row to the write event that caused it
- Triggers forbid ordinary UPDATE/DELETE (`SERBEST_ZAMAN_ALLOCATION_IMMUTABLE`)
- Pack 4A: DELETE is hard-blocked; gate CHECK already allows category `SERBEST_ZAMAN`
- Pack 4B: replace DELETE trigger with retention-gated variant (same `retention_physical_destroy_gates` owner)

Net allocated for a KULLANIM must equal effective dakika after IPTAL/DUZELTME chain.

---

## Gap status (explicit non-closure)

| Gap | Statü after Pack 4A | Why still open |
| --- | --- | --- |
| `MG-RET-PHYS-001` | **CODE_GAP** | Lot ledger + write-path exist; safe SERBEST **destroy** of used entitlements / Pack 4B gate usage still open; production apply/enable YOK |
| `MG-SZ-6M-001` | **CODE_GAP** | Lot projection foundation on bakiye (`lot_based_*`, expiry_state) shipped; ops/İK yaklaşan-overdue yüzey + compliance gate still open |

---

## Pack 4B remaining (not this pack)

1. Retention physical destroy path that consumes allocation provenance safely (open `SERBEST_ZAMAN` destroy gate intentionally)
2. Ops/compliance surfaces for 6-month lot deadline follow-up (`MG-SZ-6M-001`)
3. Any approved **manual** legacy remediation tool (still no auto-backfill)
4. Production migration apply / feature rollout decisions (separate ops gates)

---

## Tests / owners

- `tests/php/SerbestZamanAllocationPack4aMysqlTestRunner.php` → `verify-serbest-zaman-allocation-pack4a-mysql: OK`
- `tests/unit/serbest-zaman-allocation-pack4a-mysql.php-runtime.test.ts`
- `tests/unit/serbest-zaman-allocation-service.source.test.ts`
- Existing `SerbestZamanMysqlTestRunner` asserts tahsis rows after first successful kullanim

---

## Related docs

- `114` Pack 3C — SERBEST fail-closed + OPTION_A design note  
- `110` master gap registry — both gaps stay CODE_GAP  
- `CURRENT_STATE.md` — Pack 4A foundation mentioned; CODE_GAP=4
