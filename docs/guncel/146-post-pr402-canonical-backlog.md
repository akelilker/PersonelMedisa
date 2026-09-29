# 146 — Post-PR402 Canonical Backlog

**Tür:** Aktif backlog otoritesi (POST_PR402 consolidation; **POST_PR441** pin refresh).
**Baseline:** `origin/main` `0ef844475a3523b3e54215994a053cd27534c575` (PR **#441**); deploy cPanel run `36543499484` SUCCESS (deploy pin only).
**Migration tip:** code **093** / production **093** (pending **0**); migration 093 APPLIED after PR #439 deploy via Apply cPanel migrations run **`36491356202`**; readback `ACTIONS_APPLY_36491356202`.
**Yasaklar bu belgede:** app code · migration apply · production mutation · remote branch delete · #395–#441 reopen.

**Süperseeded active sources:** `CURRENT_STATE.md` (tips/SHA pin only; residual detail → burada), `docs/guncel/110-master-closure-gap-registry.md` (**SUPERSEDED** for active backlog).

Sınıflar: **A** CLOSED_ALREADY_LIVE · **B** SAFE_HOUSEKEEPING · **C** TECHNICAL_GAP · **D** PRODUCT_DECISION · **E** OPERATIONAL_APPROVAL · **F** OBSOLETE_STALE.

---

## A) CLOSED / ALREADY LIVE (reopen yok)

| ID | Konu | Kanıt |
| --- | --- | --- |
| `BL-PR-395-402` | Visual/shell/QR pilot/self-service polish sweep | PRs #395–#402 MERGED; historical deploy @ `5c6ae773` (#402) |
| `BL-PR-405` | QR mobile camera CTA + personel home field UX | PR #405 MERGED `bf07ab07`; Deploy cPanel SUCCESS run `36144289989` |
| `BL-PR-439` | Attendance anomaly scan + unresolved anomaly UX + amir correction flow | PR #439 MERGED; cron `attendance-anomaly-scan.php` |
| `BL-PR-440` | Çalışma Geçmişi aylık onaylı toplam saat / gün bazlı puantaj+QR toplamı | PR #440 MERGED |
| `BL-PR-441` | `MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES` override (tests); prod default **180**; prod cron **no** env | PR #441 MERGED; deploy `36543499484` @ `0ef84447` |
| `BL-PR-446` | Attendance anomaly cron docblock parse | PR #446 MERGED `c6fe3144588ba253aa1d471d66245da317c92d1b` |
| `BL-SELF-HISTORY-APPROVAL-SCOPE` | Self-service aylık onay scope | **CLOSED** — PR #444 MERGED (`990d4d7e`). `aylik_onayli_mi` personel birim amiri scope. |
| `BL-ATTENDANCE-CRON-OBSERVE` | Cron runtime tick | **CLOSED** — PR #446 + production tick `ATTENDANCE_ANOMALY_SCAN personel=1 created=0` EXIT=0 |
| `BL-PR-326` | SGK bildirim dönemi owner → `SGK_ISVEREN` | PR #326 MERGED `8e137f2c` (2026-09-22) |
| `BL-MIG-090` | Factual employer-period owner table | Apply run `35781766535` SUCCESS |
| `BL-MIG-091` | Guarded legacy consensus reconcile | Apply run `35791415567` SUCCESS |
| `BL-MIG-093` | Attendance anomaly notification dedupe (race-safe) | Apply `36491356202`; target `093_attendance_anomaly_notification_dedupe.sql`; worker SUCCEEDED; backup readback VERIFIED |
| `BL-QR-CORE` | QR S3C–S3F + collar entitlement + pilot checklist | Docs 105–109 CLOSED; `docs/ops/QR_ATTENDANCE_PILOT_READINESS_CHECKLIST.md` |
| `BL-QR-ANOMALY` | Hatalı/eksik/çift giriş-çıkış — **core shipped** | #439: live warning + correction request + cron scan + 093 dedupe; personel self-revision yok (amir düzeltir). **Residual gaps → C/D/E** |
| `BL-TERM-CANON` | User-facing Dahili/Harici + Statü + Mavi/Beyaz Yaka | Enum display + create/yonetim labels; source locks on main |
| `BL-A1-12-13` | Branch-specific SGK period 12/13 | **SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION** |
| `BL-LOC5-SET` | Fabrika/Karabük loc5 + 160/211 | APPLIED (historical CURRENT_STATE evidence) |
| `BL-BM-MODEL` | Branch manager ≠ `user_subeler` access | Product rule LOCKED; owner `sube_sorumlu_yoneticiler` (088 APPLIED) |
| `BL-PERSONEL-HOME-INFO-CARDS` | “Ek bilgi kartları” | **SUPERSEDED** — #436–#440 kapsamı CLOSED |
| `BL-SINEM-HALIL-LIVE` | Sinem / Halil live identity verify | Live certify OK (historical) — reopen yok |
| `BL-KAYSERI-KUBRA` | Kayseri manager Kübra Güneş | **CLOSED / APPLIED** |
| `BL-NAME-203` | personel 203 ad/soyad | Live doğru — işlem yok |
| `BL-BM-ASSIGN-WRITE` | Medisa branch-manager apply | **CLOSED / APPLIED** (POST_PR403) |
| `BL-STALE-BRANCH-CLINE` | `origin/cline/fw8ry7m8` | **CLOSED** — confirmed absent on origin (POST_PR403 delete); no active delete gate |

---

## B) SAFE HOUSEKEEPING

| ID | Konu | Not |
| --- | --- | --- |
| `BL-DOC-110-ARCHIVE` | 110 active-backlog authority | SUPERSEDED; tips mirrored POST_PR441 |
| `BL-DOC-PR326-CHECKLIST` | `docs/ops/PR326_…_CLOSE_CHECKLIST.md` | CLOSED_APPLIED banner |

---

## C) TECHNICAL_GAP (product kararı gerekmez)

| ID | Konu | Not |
| --- | --- | --- |
| `BL-SOURCE-LOCK-DRIFT` | Pin testleri eski deploy/tip iddiaları | POST_PR441 + review turunda CURRENT_STATE + 110 + source-lock test hizalandı |
| `BL-NO-EVENT-DAY` | Sıfır QR / expected-worker gün anomaly | **CODE_READY / pending PR + deploy + migration 094 apply.** Local implementation on `feat/attendance-no-event-day`. Schema 093'te NO_EVENT_DAY fail-closed (event anomaly/+180/notification/correction sürer); 094 apply sonrası aynı kod day-key zincirini açar. Sentetik QR yok. Production'da yok — CLOSED/LIVE değil. |

---

## D) PRODUCT DECISION — POST_PR403 recorded

POST_PR403 kullanıcı kararı (2026-09-25). **Non-goals** ve deferred rollout — kodlama yok.

| ID | Konu | Karar | Safe state |
| --- | --- | --- | --- |
| `BL-FORM-HINT` | Orphan `form-hint` | Şimdilik dokunulmayacak | Current UI korunur |
| `BL-QR-DEVICE-BIND` | Device binding | **YAPILMAYACAK** | Device binding yok |
| `BL-QR-OFFLINE` | Offline QR write | **YAPILMAYACAK** — online-only QR | Offline write yok |
| `BL-QR-GEOFENCE` | GPS / geofence attendance | **NON-GOAL** | Out of scope |
| `BL-QR-NFC` | NFC / turnike | **NON-GOAL** | Out of scope (105 discovery) |
| `BL-KARYAPI-SENAY` | Karyapı / Şenay rollout | **DEFERRED — daha sonra** | Grant/assignment uydurma |
| `BL-SERHAN-MEDISA-ACCESS` | Serhan Köse Medisa şube erişimi | Sinem gibi Medisa aktif şubelerde `user_subeler`; Karyapı/Şenay dışı | Karar kaydı |
| `BL-POST-THRESHOLD-REENTRY` | Planlanan çıkış sonrası threshold üstü yeniden giriş | **OPEN — ürün kararı bekliyor** | Teknik default 180 dk (#441); overtime re-entry semantics netleşecek |

`BL-QR-ANOMALY-REV` (POST_PR403): personel self-revision yok — **CLOSED as policy**; implementation = #439 (`BL-QR-ANOMALY`).

---

## E) OPERATIONAL APPROVAL / OBSERVATION

E sınıfı: read-only verify · business truth · production write (write ayrı onay).

| ID | Konu | Not |
| --- | --- | --- |
| `BL-QR-PILOT-OPS` | QR pilot checklist — **sole owner** fiziksel saha ticks | Remote PASS (#405 CTA, HTTPS, kiosk mint). **Kalan (tek ID):** fiziksel iPhone kamera permission · gerçek kiosk QR **GİRİŞ** · gerçek kiosk QR **ÇIKIŞ** · anomaly correction smoke — `QR_ATTENDANCE_PILOT_READINESS_CHECKLIST.md` |
| `BL-CROSS-COMPANY` | 120 / 158 / 219 | Valid defer — **UNTOUCHED** |

---

## F) OBSOLETE / STALE (aktif backlog’dan çıkar)

| ID | Konu | Neden |
| --- | --- | --- |
| `BL-STALE-090-091-PENDING` | “CODE_ONLY_PENDING” | Apply kanıtı ile kapanmış |
| `BL-STALE-093-PENDING` | Production tip 092 / pending 1 | POST_PR441: prod **093**, pending **0** |
| `BL-STALE-PR326-OPEN` | PR_326 OPEN | MERGED 2026-09-22 |
| `BL-STALE-DEPLOY-5c6ae773` | DEPLOY pin #402 as current | Superseded by `0ef84447` / #441 deploy `36543499484` |
| `BL-STALE-ANOMALY-NOT-CODED` | “Anomaly UX bu turda kodlanmaz” (D only) | #439 shipped — policy residual in C/D/E |
| `BL-STALE-PERSONEL-HOME-CARDS-NOT-STARTED` | Next gate “NOT_STARTED” ek kartlar | **SUPERSEDED** → `BL-PERSONEL-HOME-INFO-CARDS` CLOSED |
| `BL-STALE-110-AS-ACTIVE` | 110 “tek referans” | Active owner = bu belge (146) |
| `BL-FIELD-VALIDATION` | Ayrı saha doğrulama ID | **MERGED** into `BL-QR-PILOT-OPS` (fiziksel tick sole owner) |
| `BL-STALE-BRANCH-DELETE` | Remote cline delete gate | Superseded — branch absent on origin → `BL-STALE-BRANCH-CLINE` CLOSED (A) |

---

## Decision registry (özet)

Non-goals / deferred: `BL-FORM-HINT` · `BL-QR-DEVICE-BIND` · `BL-QR-OFFLINE` · `BL-QR-GEOFENCE` · `BL-QR-NFC` · `BL-KARYAPI-SENAY` · `BL-SERHAN-MEDISA-ACCESS`.
Open product: `BL-POST-THRESHOLD-REENTRY`.

---

## Technical gap registry (özet)

C: `BL-NO-EVENT-DAY` (CODE_READY, pending PR + deploy + migration 094 apply).

---

## Operational approval registry (özet)

E (open only): `BL-QR-PILOT-OPS` · `BL-CROSS-COMPANY` (defer).

---

## Next gate

1. `BL-QR-PILOT-OPS` — fiziksel iPhone permission + kiosk QR GİRİŞ/ÇIKIŞ + anomaly correction smoke.
2. `BL-NO-EVENT-DAY` — PR, deploy, migration 094 apply (local CODE_READY; production'da yok).
3. `BL-POST-THRESHOLD-REENTRY` — ürün kararı (D).
4. Karyapı/Şenay + 120/158/219 rollout **DEFERRED**.
