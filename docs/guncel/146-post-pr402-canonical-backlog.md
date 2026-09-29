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
| `BL-NO-EVENT-DAY` | Sıfır QR / expected-worker gün anomaly | **OPEN — TECHNICAL/SCHEMA GAP.** Ürün sonucu largely locked: beklenen çalışan + sıfır QR → anomaly oluştur; Talepler yolu; personel doğrudan puantaj düzenlemez; **sentetik QR yasak**; izin/rapor/iş kazası günlerinde false-positive’ten kaçın. Eksik: canonical **gün bazlı** anomaly kimliği + correction correlation. _(Opsiyonel alt not: sıfır-event kesinleşme zamanı hâlâ net değilse ayrıca ürün zamanlaması.)_ |
| `BL-SELF-HISTORY-APPROVAL-SCOPE` | #440 self-service `aylik_onayli_mi` vs onay şeması | **OPEN — P1/P2 technical correctness.** `SelfPuantajReadService::isAylikOnayli`: `WHERE sube_id=:sube_id AND ay=:ay AND state='TAMAMLANDI' LIMIT 1` (birim amiri / personel filtresi yok). Canonical `aylik_bildirim_onaylari` unique: `(sube_id, birim_amiri_user_id, ay)`. **Risk:** bir birim amirinin şubede `TAMAMLANDI` kaydı, başka birimin personel self-service’inde “Onaylı” gösterebilir — approval scope mismatch; canonical olarak sessizce kabul edilmez. **Known behavior (ürün kararı sonra):** aylık toplam onaysız/QR fallback kullanıldığında geç/erken/fazla mesai satırları puantaj domain’inden gelmeye devam edebilir. |

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
| `BL-ATTENDANCE-CRON-OBSERVE` | Cron runtime tick | Installed `*/5` with **`ea-php81`** → `api/bin/attendance-anomaly-scan.php`; observe production ticks/logs (prod cron env yok; threshold default 180). **Ops diagnostics:** GitHub Actions `Cancelled` = not PASS; tek başına FAILURE değil; kanıt yoksa **INCONCLUSIVE** — server-side status/preflight/completion evidence gerekir. |
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

C: `BL-NO-EVENT-DAY` · `BL-SELF-HISTORY-APPROVAL-SCOPE`.

---

## Operational approval registry (özet)

E (open only): `BL-QR-PILOT-OPS` · `BL-ATTENDANCE-CRON-OBSERVE` · `BL-CROSS-COMPANY` (defer).

---

## Next gate

1. `BL-QR-PILOT-OPS` — fiziksel iPhone permission + kiosk QR GİRİŞ/ÇIKIŞ + anomaly correction smoke.
2. `BL-ATTENDANCE-CRON-OBSERVE` — `ea-php81` cron tick/log doğrulama.
3. `BL-SELF-HISTORY-APPROVAL-SCOPE` · `BL-NO-EVENT-DAY` — technical correctness (C).
4. `BL-POST-THRESHOLD-REENTRY` — ürün kararı (D).
5. Karyapı/Şenay + 120/158/219 rollout **DEFERRED**.
