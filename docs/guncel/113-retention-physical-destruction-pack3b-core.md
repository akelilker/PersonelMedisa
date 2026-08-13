# 113 — Retention Physical Destruction Pack 3B Core

**Tür:** Implementation kapanış (feature flag OFF; production migration apply / write / real destroy YOK)  
**Branch:** `fix/retention-physical-pack3b-core`  
**Baseline main:** `ef67e8363021aaa5bc61eddf98542fb31da47f38`  
**Pack 2:** `112` / PR #151  
**Tarih:** 2026-08-13

---

## Baseline

| Check | Value |
| --- | --- |
| Start branch | `main` |
| `HEAD` / `origin/main` | `ef67e8363021aaa5bc61eddf98542fb31da47f38` |
| Worktree | clean |
| Stash | `stash@{0}: tmp-before-ops` — **dokunulmadı** |

---

## Karar matrisi (kilitli — Pack 3A → 3B)

| ID | Category | Karar |
| --- | --- | --- |
| DECISION_01 | PUANTAJ | **B** — full muhur revision graph `(sube,yil,ay)` |
| DECISION_02 | PUANTAJ | **A** — period `gunluk_puantaj` hard-delete (dependents cleared) |
| DECISION_03 | PUANTAJ × ONAY_AUDIT | **C** — QR ledger blocks; separate typed ONAY_AUDIT first |
| DECISION_04 | BORDRO | **A** — RUN-LEAF DELETE |
| DECISION_05 | BORDRO | **B** — `personel_bordro_devirleri` out of scope |
| DECISION_06 | SGK_EKSIK_GUN | **A** — nested SGK evidence only |
| DECISION_07 | SGK header | **A** — keep `maas_hesaplama_donem_snapshotlari` unchanged |
| DECISION_08 | PUANTAJ × payroll snapshot | **OPTION A** — snapshot-pinned seal/revision headers preserve; seal lines + daily PII destroy |

Generic parent ONAY_AUDIT ve FAZLA_CALISMA / SERBEST_ZAMAN / DISIPLIN / RAPOR / IS_KAZASI bu Pack’te **çözülmedi**.

---

## Implemented handlers

| Category | Handler | Mode |
| --- | --- | --- |
| `PUANTAJ` | `PuantajDestructionHandler` | `DELETE_ROWS` |
| `BORDRO` | `BordroDestructionHandler` | `DELETE_ROWS` |
| `SGK_EKSIK_GUN` | `SgkEksikGunDestructionHandler` | `DELETE_ROWS` |

Registry: `RetentionDestructionHandlerRegistry` — bu üçü executable; diğer policy kategorileri `PolicyRequiredDestructionHandler`.

Canonical owners korunur: `PhysicalDestructionService`, registry, `DestructionHandlerInterface`, `PhysicalDestructionCodes`.

---

## Exact destroy scopes

### PUANTAJ (iki deterministic mode)

Plan, `payroll_snapshot_pin_count` ve operation code ile mode’u açık gösterir.
Evaluate→execute mode/pin drift → `DESTRUCTION_PLAN_CHANGED` (sessiz mode switch yok).

#### Mode 1 — No payroll snapshot pin (`FULL_GRAPH_DELETE`)

Operation: `PUANTAJ_FULL_REVISION_GRAPH_DELETE`

- Tüm period `puantaj_aylik_muhurleri` (+ `_satirlari` CASCADE)
- Period `gunluk_puantaj`
- Period-linked `puantaj_donem_reopen_talepleri` (seal DELETE için gerekli lifecycle)

#### Mode 2 — Payroll snapshot pin (`SNAPSHOT_PINNED_EVIDENCE_HEADER_PRESERVE`)

Operation: `PUANTAJ_SNAPSHOT_PINNED_SEAL_HEADERS_PRESERVE`

Herhangi bir korunması gereken `maas_hesaplama_donem_snapshotlari.muhur_id` period seal graph üyesini pinliyorsa:

**Korunan (evidence / FK bütünlüğü):**
- `puantaj_aylik_muhurleri` header’ları (parent/superseded revision graph)
- `maas_hesaplama_donem_snapshotlari` satırı ve `muhur_id` (NULL/mutate yok)
- `puantaj_donem_reopen_talepleri` (header graph korunduğu için otomatik silinmez)

**Fiziksel imha (policy-approved PII payload):**
- Period `gunluk_puantaj`
- Target seal graph `puantaj_aylik_muhur_satirlari`

Source fingerprint = effective seal id + `created_at` (satır payload’a bağlı değil) → pinned execute sonrası idempotency / integrity bozulmaz; ikinci execute `ALREADY_EXECUTED` / mutation 0.

#### OPTION A — post-destruction lifecycle (follow-up)

Preserved seal headers are **evidence-only** after physical destruction (`DESTROYED_AS_APPROVED` via `retention_imha_executionlari`). They are not live PUANTAJ payload.

- Owner: `PuantajPhysicalDestructionGate` — period destroyed = EXECUTED PUANTAJ evidence for `(canonical_sube_id, period_yil, period_ay)`
- Reopen create / approve / reseal → `PUANTAJ_PERIOD_PHYSICALLY_DESTROYED` (HTTP 409)
- Open reopen (`ONAY_BEKLIYOR` | `ONAYLANDI`) → physical destroy fail-closed `PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS` (plan `open_reopen_talep_count`)
- Terminal reopen (`REDDEDILDI` | `UYGULANDI`) does not block destroy; may remain as historical evidence in pinned mode
- Lock order: destruction request `FOR UPDATE` → `PuantajDonemKilidiService::acquire(period)` → scope re-read / mutate. Reopen path: period lock only. Prevents destroy+reopen resurrection race.
- New destruction request for already-destroyed period → `SOURCE_ALREADY_DESTROYED_AS_APPROVED` (no second request row). Same talep retry → `ALREADY_EXECUTED`.

### BORDRO (run-leaf)
- `maas_hesaplama_aday_kalemleri` → `maas_hesaplama_adaylari` → run-scoped `maas_hesaplama_auditleri` → `maas_hesaplama_calistirmalari`

### SGK_EKSIK_GUN (nested)
- `maas_hesaplama_sgk_snapshotlari` (donem_snapshot_id)
- `sgk_hesap_auditleri` (donem_snapshot_id)

---

## Exact keep scopes

- `maas_hesaplama_donem_snapshotlari` header (BORDRO + SGK)
- SGK nested when destroying BORDRO; BORDRO run tree when destroying SGK
- `personel_bordro_devirleri`
- `sgk_eksik_gun_belgeleri`, `personel_belge_*`, SGK catalogs/masters/policy/source manifests
- Retention infra / archive manifests / imha talepleri / execution evidence
- Typed `qr_puantaj_candidate_decision_ledger` (PUANTAJ asla silmez)
- Owner-unclear RESTRICT children (etki aday/çakışma, donem_kapanis / snapshot-audit on full-delete path) → **fail-closed**, auto-destroy yok
- Payroll snapshot pin → hard block değil; Mode 2 (header preserve)

Fail-closed code: `PUANTAJ_BLOCKED_BY_QR_ONAY_AUDIT`

---

## Trigger exception modeli

Migration **`060_retention_physical_destroy_trigger_gate.sql`** (additive; `059` dokunulmadı):

1. Table `retention_physical_destroy_gates` — `UNIQUE(connection_id)`, FK to PREPARED execution
2. BORDRO/SGK `BEFORE DELETE` triggers replaced with gated variants:
   - Allow DELETE only when `CONNECTION_ID()` has open gate for matching category **and** linked `retention_imha_executionlari.execution_state='PREPARED'`
3. `RetentionPhysicalDestroyGate::open/close` — only from `PhysicalDestructionService` after feature flag + eligibility + PREPARED insert
4. Gate closed in `finally` before commit; rollback clears gate; connection reuse cannot leak bypass

**Normal direct DELETE** still SIGNAL immutable. Feature OFF / no approved PREPARED path → no bypass.

---

## Test evidence

| Suite | Result |
| --- | --- |
| Pack 2 `RetentionPhysicalDestructionMysqlTestRunner` | PASS |
| Pack 3B `RetentionPhysicalPack3bMysqlTestRunner` | PASS |
| `npm run typecheck` | PASS |
| `npm run test` | **1747** passed / 240 files |
| `npm run build` | PASS |
| `git diff --check` | PASS |

Pack 3B matrix covers: PUANTAJ no-snapshot full graph / daily delete / QR block / QR then success / snapshot-pin effective + historical header preserve / lines+daily delete / snapshot+muhur_id unchanged / graph integrity / pinned+QR block then ONAY_AUDIT retry / pin mid-flight fail-closed / pinned idempotency / post-destroy reopen gate / open-reopen blocks destroy / terminal reopen allows destroy / duplicate request SOURCE_ALREADY_DESTROYED / legal hold / plan hash / missing target / unrelated period; destroy-vs-reopen concurrency; BORDRO run-leaf / snapshot+SGK+devir preserve / direct DELETE blocked / child RESTRICT / idempotency; SGK nested delete / header preserve / run tree preserve / catalogs present / direct DELETE blocked / gated execute / hold / idempotency.

---

## Production state

| Item | Value |
| --- | --- |
| Feature `retention_physical_destruction_enabled` | **OFF** (default) |
| Production migration applied | **NO** |
| Production write / real destroy | **NO** |
| Deploy | **NO** |

---

## Remaining policy blockers (`MG-RET-PHYS-001` still CODE_GAP)

- `FAZLA_CALISMA`
- `SERBEST_ZAMAN`
- `DISIPLIN`
- `RAPOR`
- `IS_KAZASI`
- Generic parent `ONAY_AUDIT`

Typed `ONAY_AUDIT` (`qr_pc_decision`) Pack 2’den executable kalır; approve/execute context re-hydration Pack 3B’de düzeltildi (Decision C zinciri).
