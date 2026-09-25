# 146 — Post-PR402 Canonical Backlog

**Tür:** Aktif backlog otoritesi (POST_PR402 consolidation).
**Baseline:** `origin/main` / production deploy `5c6ae7734d394022ea1ff65c5683da91c095e0eb` (PR **#402**).
**Migration tip:** code **091** / production **091** (last verified apply evidence: Actions runs `35781766535` → 090, `35791415567` → 091).
**Yasaklar bu belgede:** app code · migration apply · production mutation · remote branch delete · #395–#402 reopen.

**Süperseeded active sources:** `CURRENT_STATE.md` (tips/SHA pin only; residual detail → burada), `docs/guncel/110-master-closure-gap-registry.md` (**SUPERSEDED** for active backlog).

Sınıflar: **A** CLOSED_ALREADY_LIVE · **B** SAFE_HOUSEKEEPING · **C** TECHNICAL_GAP · **D** PRODUCT_DECISION · **E** OPERATIONAL_APPROVAL · **F** OBSOLETE_STALE.

---

## A) CLOSED / ALREADY LIVE (reopen yok)

| ID | Konu | Kanıt |
| --- | --- | --- |
| `BL-PR-395-402` | Visual/shell/QR pilot/self-service polish sweep | PRs #395–#402 MERGED; Deploy cPanel SUCCESS @ `5c6ae773` (#402) |
| `BL-PR-326` | SGK bildirim dönemi owner → `SGK_ISVEREN` | PR #326 MERGED `8e137f2c` (2026-09-22) |
| `BL-MIG-090` | Factual employer-period owner table | Apply run `35781766535` SUCCESS; 091 preflight `GATE_PROD_TIP=090` |
| `BL-MIG-091` | Guarded legacy consensus reconcile | Apply run `35791415567` SUCCESS; backup `medisa-pre-091-…` VERIFIED |
| `BL-QR-CORE` | QR S3C–S3F + collar entitlement + pilot checklist | Docs 105–109 CLOSED; `docs/ops/QR_ATTENDANCE_PILOT_READINESS_CHECKLIST.md`; PR #400 |
| `BL-TERM-CANON` | User-facing Dahili/Harici + Statü + Mavi/Beyaz Yaka | Enum display + create/yonetim labels; source locks on main |
| `BL-A1-12-13` | Branch-specific SGK period 12/13 | **SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION** — hedef fix değil |
| `BL-LOC5-SET` | Fabrika/Karabük loc5 + 160/211 | APPLIED (historical CURRENT_STATE evidence); reopen yok |
| `BL-BM-MODEL` | Branch manager ≠ `user_subeler` access | Product rule LOCKED; owner `sube_sorumlu_yoneticiler` (088 APPLIED) |

---

## B) SAFE HOUSEKEEPING

| ID | Konu | Not |
| --- | --- | --- |
| `BL-STALE-BRANCH-CLINE` | `origin/cline/fw8ry7m8` | Tip = ancestor of `origin/main` (`072c9a0c`); unique commits vs main = **0**; open PR = none. **STALE_SAFE_TO_DELETE=YES** — delete yalnız explicit ops onayı ile |
| `BL-DOC-110-ARCHIVE` | 110 active-backlog authority | Bu turda SUPERSEDED işaretlendi; tarihsel tablo arşiv |
| `BL-DOC-PR326-CHECKLIST` | `docs/ops/PR326_…_CLOSE_CHECKLIST.md` | "henüz uygulanmadı" ifadesi stale → CLOSED_APPLIED banner |

---

## C) TECHNICAL GAP (product kararı gerekmez)

| ID | Konu | Not |
| --- | --- | --- |
| `BL-SOURCE-LOCK-DRIFT` | Pin testleri eski 089/pending=2/d96182a2 iddia ediyordu | Bu turda docs + ilgili source-lock test hizalandı |
| _(none else queued)_ | — | Yeni feature / paralel UI / Personel Detay redesign bu backlog’da **yok** |

---

## D) PRODUCT DECISION REQUIRED

| ID | Konu | Neden | Default safe |
| --- | --- | --- | --- |
| `BL-FORM-HINT` | Orphan `form-hint` class (CSS yok; canonical aday `.form-help`) | Bug vs tasarım tercihi; remap #397-benzeri mi, bilerek unstyled mı | Dokunma; metin görünür kalır |
| `BL-QR-DEVICE-BIND` | Device binding | Security/product; foundation yok (threat model: opsiyonel gelecek) | Binding yok |
| `BL-QR-OFFLINE` | Offline QR write | Explicit NO in S3C/pilot; spoof riski | Offline write yok |
| `BL-QR-ANOMALY-REV` | Anomaly → revizyon kontrollü UX | Intentional defer (hint only); candidate/decision foundation var, kontrollü UX yok | Hint-only / mevcut revision ayrı |
| `BL-KAYSERI-KUBRA` | Kayseri manager Kübra soyadı | Business identity | Assignment yazma |
| `BL-NAME-203` | personel 203 ad/soyad düzeltmesi | Business truth plan-only | No apply |
| `BL-KARYAPI-SENAY` | Karyapı / Şenay company rollout | Intentional defer | Grant uydurma |

---

## E) OPERATIONAL APPROVAL REQUIRED

| ID | Konu | Not |
| --- | --- | --- |
| `BL-QR-PILOT-OPS` | QR pilot checklist maddeleri (secret/HTTPS/roster/smoke) | Kod hazır; saha/ops tick gerekir — `QR_ATTENDANCE_PILOT_READINESS_CHECKLIST.md` |
| `BL-SINEM-HALIL-LIVE` | Sinem / Halil live identity & managed-branch verify | Salt-okunur live verify; write yok |
| `BL-BM-ASSIGN-WRITE` | Medisa branch-manager assignment rows | Model CLOSED; production row write ayrı onay + preimage |
| `BL-CROSS-COMPANY` | 120 / 158 / 219 | Semantically valid defer; mutate etme |
| `BL-STALE-BRANCH-DELETE` | Remote `cline/fw8ry7m8` delete | Safe=YES; yine de explicit delete onayı |

---

## F) OBSOLETE / STALE (aktif backlog’dan çıkar)

| ID | Konu | Neden |
| --- | --- | --- |
| `BL-STALE-090-091-PENDING` | CURRENT_STATE / 110 “CODE_ONLY_PENDING” | Apply Actions kanıtı ile kapanmış |
| `BL-STALE-PR326-OPEN` | “PR_326: OPEN / merge waiting” | MERGED 2026-09-22 |
| `BL-STALE-DEPLOY-d96182a2` | PRODUCTION_DEPLOY_SHA pin #301 | Live tip #402 @ `5c6ae773` |
| `BL-STALE-BILLING-BLOCK` | Fresh readback BLOCKED_EXTERNAL as current gate | 090/091 apply sonrası Actions recovered; pin artık apply evidence |
| `BL-STALE-QR-SELF-DEFER-AS-CORE` | “QR self-service INTENTIONAL_DEFER” as core gap | Core + pilot readiness LIVE; kalan = device/offline/anomaly UX (D) + pilot ops (E) |
| `BL-STALE-110-AS-ACTIVE` | 110 “tek referans” claim | Active owner = bu belge (146) |

---

## Decision registry (özet)

Ayrıntılı `DECISION / WHY / OPTIONS / DEFAULT_SAFE_STATE` final raporda; burada yalnız ID listesi:
`BL-FORM-HINT` · `BL-QR-DEVICE-BIND` · `BL-QR-OFFLINE` · `BL-QR-ANOMALY-REV` · `BL-KAYSERI-KUBRA` · `BL-NAME-203` · `BL-KARYAPI-SENAY`.

---

## Next gate

1. Kullanıcı: D maddeleri (özellikle form-hint + QR future üçlüsü).
2. Ops: E maddeleri (QR pilot tick, identity verify, BM write, stale branch delete).
3. Safe housekeeping closure: B maddeleri explicit onayla.
