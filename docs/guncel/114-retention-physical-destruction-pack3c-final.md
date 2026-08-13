# 114 — Retention Physical Destruction Pack 3C Final

**Tür:** Implementation kapanış (feature flag OFF; production migration apply / write / real destroy YOK)  
**Branch:** `fix/retention-physical-pack3c-final`  
**Baseline main:** `b6855a044f7839338746b8a5c8c185cd68acb330` (PR #152 merge)  
**Main CI gate:** https://github.com/akelilker/PersonelMedisa/actions/runs/31684762867 **SUCCESS**  
**Pack 2:** `112` / PR #151  
**Pack 3B:** `113` / PR #152  
**Tarih:** 2026-08-13  
**Gap:** `MG-RET-PHYS-001` — **CLOSED**

---

## Summary

| Item | Result |
| --- | --- |
| Remaining policy categories | FAZLA_CALISMA, SERBEST_ZAMAN, DISIPLIN, RAPOR, IS_KAZASI, generic ONAY_AUDIT → **typed executable** |
| Handler version | `RETENTION_PHYSICAL_V1` (unchanged) |
| Feature flag | default **OFF** |
| New migration | **NO** (`059`/`060` IMMUTABLE) |
| Production write / enable / deploy / merge | **NO** |
| Category coverage | **15/15** explicit + executable |
| Unknown ONAY_AUDIT entity | fail-closed `DESTRUCTION_HANDLER_POLICY_UNRESOLVED` |

---

## Locked strategies (schema evidence)

| Category | Mode | Strategy | Scope |
| --- | --- | --- | --- |
| FAZLA_CALISMA | COMPOSITE | DEPENDENCY_GATE → leaf | Gate: no SERBEST events/aktif for kapanis; delete tercih+audit; zero satır FM fields; **preserve** `haftalik_kapanislar` header |
| SERBEST_ZAMAN | DELETE_ROWS | category-owned leaf | OLUSUM (+ hedef chain) + aktif_olusumlar for kapanis; **preserve** shared header/tercih |
| DISIPLIN | DELETE_ROWS | DEPENDENCY_GATE → shell | Require OLAY=`DESTROYED` + SAVUNMA cleared/absent; delete audit→vaka→surec |
| RAPOR | DELETE_ROWS | IZIN-family surec | Delete RAPOR surec; SGK/finans/resmi-etki/disiplin gates |
| IS_KAZASI | DELETE_ROWS | IZIN-family surec | Same gates; no BELGE cascade |
| ONAY_AUDIT typed | DELETE_ROWS | unchanged Pack2 | `qr_pc_decision` chain leaf-first |
| ONAY_AUDIT generic | DELETE_ROWS | no-op overlay | `puantaj`/`bordro` entity → 0 physical rows; parent destroy separate |
| ONAY_AUDIT unknown | POLICY | fail-closed | policy_blocker |

Shared-source rule: one category never hard-deletes another category’s canonical material. FAZLA×SERBEST share identity but destroy **leaves only**. DISIPLIN waits for OLAY/SAVUNMA field clearance.

Pack2/Pack3B handlers unchanged in semantics (PUANTAJ OPTION A + reopen gate preserved).

---

## Final 15-category matrix

| Category | Handler | Executable | Mode | Dependency gate | Files | Tests |
| --- | --- | --- | --- | --- | --- | --- |
| PERSONEL_OZLUK | PersonelOzluk | Y | ANONYMIZE | last-stage | — | Pack2 |
| ISE_GIRIS_CIKIS | IseGirisCikis | Y | DELETE_ROWS | — | — | Pack2 |
| PERSONEL_BELGE | PersonelBelge | Y | DELETE_FILE_AND_METADATA | — | Y | Pack2 |
| PUANTAJ | Puantaj | Y | DELETE_ROWS | QR/open-reopen/snapshot-pin | — | Pack3B |
| BORDRO | Bordro | Y | DELETE_ROWS | run-leaf / `060` | — | Pack3B |
| SGK_EKSIK_GUN | SgkEksikGun | Y | DELETE_ROWS | nested / header preserve | — | Pack3B |
| FAZLA_CALISMA | FazlaCalisma | Y | COMPOSITE | SERBEST cleared | — | Pack3C |
| SERBEST_ZAMAN | SerbestZaman | Y | DELETE_ROWS | — | — | Pack3C |
| ONAY_AUDIT | OnayAudit | Y | DELETE_ROWS | typed chain / generic no-op | — | Pack2+3C |
| IZIN | Izin | Y | DELETE_ROWS | SGK/disiplin | — | Pack2 |
| RAPOR | Rapor | Y | DELETE_ROWS | SGK/finans/etki/disiplin | — | Pack3C |
| IS_KAZASI | IsKazasi | Y | DELETE_ROWS | same | — | Pack3C |
| DISIPLIN | Disiplin | Y | DELETE_ROWS | OLAY+SAVUNMA | — | Pack3C |
| OLAY | Olay | Y | ANONYMIZE | shared vaka | — | Pack2 |
| SAVUNMA | Savunma | Y | COMPOSITE | shared vaka + belge | Y | Pack2 |

---

## Ops (NOT executed)

1. Exact SHA + CI green  
2. Production DB backup  
3. Approve apply `059`/`060` if not applied  
4. Feature remains OFF until separate dual-control enable  
5. Real business destruction only after enable  

---

## Test evidence

| Suite | Result |
| --- | --- |
| Pack 2 MariaDB | PASS |
| Pack 3B MariaDB | PASS |
| Pack 3C MariaDB | PASS |
| Feature flag default OFF | PASS |

`NEW_MIGRATION = NO`
