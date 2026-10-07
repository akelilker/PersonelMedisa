# 146 — Post-PR402 Canonical Backlog

**Tür:** Aktif backlog otoritesi (POST_PR402 consolidation; **POST_PR512** pin refresh).
**Product baseline (SHA pin):** `c23000544718e1b3bdfa06c133479c6ffbd6cbe3` (PR **#512** last product merge+deploy); Deploy cPanel **#1245** run `37677662532` SUCCESS. Docs-only canonical PRs do not advance `LAST_MERGED_PR` / `CODE_MAIN_SHA` / `PRODUCTION_DEPLOY_SHA` (see `CURRENT_STATE.md` `PIN_SHA_BASELINE`).
**Migration tip:** code **097** / production **097** (pending **0**); migration **097** (`097_qr_attendance_location_audit.sql`) **APPLIED** (Apply cPanel migrations **#56** run `37391130248`; readback Ops migration worker diagnostics **#90** run `37391796990` PROD_TIP **097**); migration **096** (`096_personel_bordro_okumalari.sql`) **APPLIED** (canonical apply `37021807281`; readback `37022115475` PROD_TIP **096**); migration **095** (`095_personel_cinsiyet.sql`) **APPLIED**; migration **094** (`094_attendance_no_event_day.sql`) **APPLIED** (production'da geçmiş).
**Yasaklar bu belgede:** app code · migration apply · production mutation · remote branch delete · #395–#470 reopen.

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
| `BL-PR-470` | UI correction batch (Level 2+ back nav · Personel Kartı missing-info gateway · duplicate CTA cleanup · Görev/Organizasyon Vazgeç fix · /self title/photo/reverse gateway polish) | PR #470 MERGED `b01216d5e94a3269dc57eca0f316703aaf26d54c`; Deploy cPanel #1201 run `36913900426` SUCCESS |
| `BL-PR-472` | Self-service closure (PR470 follow-up; bordro okundu readiness) | PR #472 MERGED `6b7dbba6e54886c503c04438d9e4ecd60faae3e8`; Deploy cPanel #1203 run `37018359986` SUCCESS |
| `BL-PR-475` | `bugun personel durumu` şube seçimi grid + sade şube detayı | PR #475 MERGED `939c5f87ec80fb5f742d81f23f0523ce8399c313`; Deploy cPanel #1206 run `37069634704` SUCCESS |
| `BL-PR-476` | `/self` PERSONEL görsel shell (AppShell) + 120/158 `calisan_kapsami` `DIS_KAYNAK` canlı düzeltme + Harici Personel terminoloji + canonical kapanış | PR #476 MERGED `817a1e7230273878daa0e96a359b866671ad0711`; Deploy cPanel #1207 run `37077642885` SUCCESS |
| `BL-PR-496` | QR taramada GPS geofence konum denetimi (v1, non-blocking; bilgi amaçlı, QR kaydını engellemez) + migration 097 | PR #496 MERGED `14dc7fa9bebe35533d6e1659eae795417ebea574`; Deploy cPanel #1228 run `37365978799` SUCCESS |
| `BL-PR-507` | Anlık personel durumu UX, şube kart çerçeveleri, tek süreç araması, footer MEDİSA ince ayarı (görsel kapanış) | PR #507 MERGED `83a517011fcf32967adb87d4f44c1bcc833a45a8`; Deploy cPanel #1240 SUCCESS |
| `BL-PR-508` | Personel kapanış: ad kaydı yazıldığı gibi (yalnız trim + boşluk sadeleştirme) · personel seçim listesi 250 sınırı kaldırıldı (sayfalı çekim) · anlık durum sayımı doğrulandı (kod değişmedi) · Ankara Finans-Risk salt-okunur not | PR #508 MERGED `6c9498f1ae4716975695cf68bef8e97a4f715e59`; Deploy cPanel #1241 run `37556582165` SUCCESS |
| `BL-PR-509` | iOS 27 PWA footer safe-area (login + ana ekran; Taşıt PR541 modeli) | PR #509 MERGED (squash) `719425021d9fabb1b904dc3ad99694c7eecc9472`; Deploy cPanel #1242 run `37587074563` SUCCESS |
| `BL-PR-510` | Anlık Personel Durumu: birim adıyla aynı bölüm adı ikinci satır olarak tekrarlanmaz | PR #510 MERGED `ab3745eb6608a5b3bfa45a21db1fe4d697e005a8`; Deploy cPanel #1243 run `37594047570` SUCCESS |
| `BL-PR-511` | Anlık Personel Durumu sadeleştirme: Toplam Personel / Gelen (Geldi + Geç Geldi + Erken Çıktı) / Gelmeyen (Gelmedi + İzinli + Raporlu + Görevde) / Henüz Değerlendirilmedi · Statü drill-down Mavi Yaka / Beyaz Yaka / Statüsüz (yalnız > 0 ise) · doğrudan kişi listesi "Ad SOYAD — Durum" · geri satırı geometri düzeltmesi · özet kartları yatay + dikey ortalı | PR #511 MERGED `7851d62401857950f32c40589be3a0bd40c05c24`; Deploy cPanel #1244 run `37676310896` SUCCESS |
| `BL-PR-512` | CI: WebKit mobil layout regression Fast CI'dan ayrıldı → yalnız manuel (`workflow_dispatch`) **Visual WebKit Regression** (`.github/workflows/visual-webkit-regression.yml`); testler silinmedi | PR #512 MERGED `c23000544718e1b3bdfa06c133479c6ffbd6cbe3`; Deploy cPanel #1245 run `37677662532` SUCCESS |
| `BL-BUSINESS-TRUTH-120-158-219` | 120 / 158 Harici Personel + 219 Medisa transfer business truth | **CLOSED** — canlı readback + mutation 2026-10-03: 120/158 canlı `calisan_kapsami` `IC_PERSONEL` idi → `DIS_KAYNAK` düzeltildi (readback doğrulandı); şube **11 Şenay Mobilya** + SGK **3 Şenay Mobilya** + çalışma lokasyonu **5 Fabrika/Karabük** korundu. 219 şube **6 Medisa İstanbul** + SGK **1 Medisa** + lokasyon **3 İstanbul** (mutation yok). Production mutation = **2** (120 + 158) |
| `BL-SERHAN-KOSE-LIVE` | `serhan.kose` (user 9) canlı yetki | **CLOSED** — canlı readback 2026-10-03: `rol: GENEL_YONETICI` + `durum: AKTIF` (`sinemH` user 110 ile aynı model). Tek tek şube/company grant üretilmedi; mutation = 0 |
| `BL-SELF-SHELL-PERSONEL` | `/self` yüzeyinde PERSONEL görsel shell (bağlı yönetici) | **CLOSED** — owner `src/app/AppShell.tsx`; `isPersonelShellVisual` (rol PERSONEL veya `/self` yüzeyi) ile `/self` + `/self/...` compact header/shell alır. Rol/izin/route/backend değişmedi; ayrı panel/helper/CSS override yok. Focused source test (`personel-self-service-ux-v2`) güncellendi |
| `BL-SELF-HISTORY-APPROVAL-SCOPE` | Self-service aylık onay scope | **CLOSED** — PR #444 MERGED (`990d4d7e`). `aylik_onayli_mi` personel birim amiri scope. |
| `BL-ATTENDANCE-CRON-OBSERVE` | Cron runtime tick | **CLOSED** — PR #446 + production tick `ATTENDANCE_ANOMALY_SCAN personel=1 created=0` EXIT=0 |
| `BL-PR-326` | SGK bildirim dönemi owner → `SGK_ISVEREN` | PR #326 MERGED `8e137f2c` (2026-09-22) |
| `BL-MIG-090` | Factual employer-period owner table | Apply run `35781766535` SUCCESS |
| `BL-MIG-091` | Guarded legacy consensus reconcile | Apply run `35791415567` SUCCESS |
| `BL-MIG-093` | Attendance anomaly notification dedupe (race-safe) | Apply `36491356202`; target `093_attendance_anomaly_notification_dedupe.sql`; worker SUCCEEDED; backup readback VERIFIED |
| `BL-MIG-094` | NO_EVENT_DAY day-key identity schema | Migration **094** (`094_attendance_no_event_day.sql`) **APPLIED** — production'da geçmiş, sonrasında 095 |
| `BL-MIG-095` | Personel cinsiyet | Migration **095** (`095_personel_cinsiyet.sql`) **APPLIED / SUCCESS** |
| `BL-MIG-096` | Personel self-service bordro "Okudum" audit | Migration **096** (`096_personel_bordro_okumalari.sql`) **APPLIED / SUCCESS** — apply `37021807281`; backup readback VERIFIED; readback `37022115475` PROD_TIP **096** |
| `BL-MIG-097` | QR attendance konum denetim kolonları | Migration **097** (`097_qr_attendance_location_audit.sql`) **APPLIED / SUCCESS** — apply `37391130248` (#56); backup readback VERIFIED; readback `37391796990` (Ops #90) PROD_TIP **097**, pending 0 |
| `BL-QR-CORE` | QR S3C–S3F + collar entitlement + pilot checklist | Docs 105–109 CLOSED; `docs/ops/QR_ATTENDANCE_PILOT_READINESS_CHECKLIST.md` |
| `BL-QR-ANOMALY` | Hatalı/eksik/çift giriş-çıkış — **core shipped** | #439: live warning + correction request + cron scan + 093 dedupe; personel self-revision yok (amir düzeltir). **Residual gaps → C/D/E** |
| `BL-NO-EVENT-DAY` | Sıfır QR / expected-worker gün anomaly | **CLOSED / LIVE** — migration **094** APPLIED (production'da geçmiş, sonrasında 095); day-key chain live |
| `BL-QR-PILOT-OPS` | QR pilot checklist — fiziksel saha ticks | **PASS / CLOSED** — saha GİRİŞ/ÇIKIŞ/history FIELD_PASS; `PILOT_GATE_CLOSED`; phase CLOSED |
| `BL-TERM-CANON` | User-facing Dahili/Harici + Statü + Mavi/Beyaz Yaka | Enum display + create/yonetim labels; source locks on main. **Tek canonical terim: Harici Personel** (karşılığı Dahili Personel); "Dış Kaynak" / "External Worker" kullanıcıya yansımaz; internal `DIS_KAYNAK` enum identifier uyumluluk için korunur |
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

**Tamamlandı — açık housekeeping işi YOK.**

| ID | Konu | Not |
| --- | --- | --- |
| `BL-DOC-110-ARCHIVE` | 110 active-backlog authority | **CLOSED** — SUPERSEDED; tips mirrored POST_PR441 |
| `BL-DOC-PR326-CHECKLIST` | `docs/ops/PR326_…_CLOSE_CHECKLIST.md` | **CLOSED** — CLOSED_APPLIED banner |

---

## C) TECHNICAL_GAP (product kararı gerekmez)

Açık teknik gap: **NONE**.

| ID | Konu | Not |
| --- | --- | --- |
| `BL-SOURCE-LOCK-DRIFT` | Pin testleri eski deploy/tip iddiaları | **CLOSED / HISTORICAL** — POST_PR512 refresh: CURRENT_STATE + source-lock test (`LAST_MERGED_PR: 512`, SHA `c2300054…`, Deploy #1245, tip **097**, `DOCS_ONLY_CLOSURE_THIS_PIN: NO`) hizalandı; artık aktif teknik gap DEĞİL |

---

## D) PRODUCT DECISION — POST_PR403 recorded

POST_PR403 kullanıcı kararı (2026-09-25) + 2026-10-03 kapanış. **Non-goals** ve kapanmış kararlar — kodlama yok, aktif pending yok.

| ID | Konu | Karar | Safe state |
| --- | --- | --- | --- |
| `BL-QR-DEVICE-BIND` | Device binding | **CLOSED NON-GOAL — YAPILMAYACAK** | Device binding yok; next gate'e çıkmaz |
| `BL-QR-OFFLINE` | Offline QR write | **CLOSED NON-GOAL — YAPILMAYACAK** — online-only QR | Offline write yok; next gate'e çıkmaz |
| `BL-QR-GEOFENCE` | GPS / geofence attendance | **Blocking geofence = CLOSED NON-GOAL — YAPILMAYACAK**; non-blocking GPS konum denetimi **#496** ile **CANLI** (v1, yarıçap **300 m**, `location_geofence_key = v1_factory_entrance_300m`); migration **097** APPLIED; raw lat/lng **persist edilmez** (yalnız durum / mesafe / accuracy / geofence key saklanır) | Blocking geofence out of scope; non-blocking audit live ve QR kaydını engellemez; next gate'e çıkmaz |
| `BL-QR-NFC` | NFC / turnike | **CLOSED NON-GOAL — YAPILMAYACAK** | Out of scope (105 discovery); next gate'e çıkmaz |
| `BL-KARYAPI-SENAY` | Karyapı / Şenay rollout | **NOT OUR ROLLOUT / CLOSED** — bizim bekleyen rollout işimiz değil; program teslim edildiğinde firma sahipleri kendi kullanıcı/personel/şube kayıtlarını kendileri girer | Grant/assignment uydurma; next gate üretmez |
| `BL-SERHAN-MEDISA-ACCESS` | Serhan Köse canlı yetki | **CLOSED** — hedef `GENEL_YONETICI` / tam yetki; canlı readback 2026-10-03 ile doğrulandı (`sinemH` ile aynı model). `GENEL_YONETICI` için tek tek şube/company grant gerekmez | Karar kaydı; "daha sonra karar verilecek" YOK |
| `BL-POST-THRESHOLD-REENTRY` | Planlanan çıkış sonrası threshold üstü yeniden giriş | **CLOSED (code)** | Ürün kararı (2026-09-29): tamamlanmış GIRIS→CIKIS sonrası yeni GIRIS bağımsız oturum; canlı açık GIRIS ikinci GIRIS’i engeller; planned exit+180 geçmiş stale açık GIRIS engellemez ve CIKIS’e bağlanmaz; cross-midnight plan kazanır. Owner: `QrAttendanceEventService::resolveOpenShiftState` + `QrAttendanceUnresolvedAnomalyService::openGirisBlocksNextGiris` / `matchWindow`. +180 ve NO_EVENT +30 değişmedi. |

`BL-QR-ANOMALY-REV` (POST_PR403): personel self-revision yok — **CLOSED as policy**; implementation = #439 (`BL-QR-ANOMALY`).

---

## E) OPERATIONAL APPROVAL / OBSERVATION

E sınıfı: read-only verify · business truth · production write (write ayrı onay).

| ID | Konu | Not |
| --- | --- | --- |
| `BL-CROSS-COMPANY` | 120 / 158 / 219 | **CLOSED (2026-10-03)** — 120/158 canlı `calisan_kapsami` `DIS_KAYNAK` olarak düzeltildi (bkz. A `BL-BUSINESS-TRUTH-120-158-219`); 219 transfer zaten yansımış. Cross-company kayıt problem/blocker DEĞİL; active pending / next gate üretmez. History olarak kalır. |
| `BL-EKRAN-TURU` | Görsel ekran turu (ekran ekran ince ayar) | **DEVAM EDİYOR (2026-10-07)** — onaylı görseller dondurulmuş; tur bitince CURRENT_STATE + bu belge tek seferde güncellenir. Blocker değil |
| `BL-VISUAL-WEBKIT-FIRST-RUN` | Visual WebKit Regression (`visual-webkit-regression.yml`, yalnız `workflow_dispatch`) | **OPEN (non-blocking)** — #512 ile oluşturuldu, henüz hiç çalıştırılmadı; ilk ciddi görsel PR'da bir kez manuel çalıştırılacak |
| `BL-HARICI-STATU-BOS-SAYIM` | Harici Personel içinde Statü boş kayıt sayısı | **OPEN (read-only verify, non-blocking)** — canlı DB erişimi yok, sayı doğrulanmadı. Ekran mantığı hazır: "Statüsüz" grubu yalnız > 0 ise görünür (#511). Production write yok |

---

## F) OBSOLETE / STALE (aktif backlog’dan çıkar)

| ID | Konu | Neden |
| --- | --- | --- |
| `BL-STALE-090-091-PENDING` | “CODE_ONLY_PENDING” | Apply kanıtı ile kapanmış |
| `BL-STALE-093-PENDING` | Production tip 092 / pending 1 | POST_PR441: prod **093**, pending **0** |
| `BL-STALE-PR326-OPEN` | PR_326 OPEN | MERGED 2026-09-22 |
| `BL-STALE-DEPLOY-5c6ae773` | DEPLOY pin #402 as current | Superseded by `0ef84447` / #441 deploy `36543499484` |
| `BL-STALE-094-PENDING` | “migration 094 apply pending” | 094 APPLIED (production'da geçmiş, sonrasında 095) |
| `BL-STALE-096-PENDING` | “migration 096 apply pending / CODE_ONLY” | 096 APPLIED — canonical apply `37021807281`; production tip **096** |
| `BL-STALE-QR-PILOT-PENDING` | “fiziksel pilot tick bekleniyor” | PILOT_GATE_CLOSED — saha FIELD_PASS; phase CLOSED |
| `BL-STALE-ANOMALY-NOT-CODED` | “Anomaly UX bu turda kodlanmaz” (D only) | #439 shipped — policy residual in C/D/E |
| `BL-STALE-PERSONEL-HOME-CARDS-NOT-STARTED` | Next gate “NOT_STARTED” ek kartlar | **SUPERSEDED** → `BL-PERSONEL-HOME-INFO-CARDS` CLOSED |
| `BL-STALE-110-AS-ACTIVE` | 110 “tek referans” | Active owner = bu belge (146) |
| `BL-FIELD-VALIDATION` | Ayrı saha doğrulama ID | **MERGED** into `BL-QR-PILOT-OPS` (fiziksel tick sole owner) |
| `BL-STALE-BRANCH-DELETE` | Remote cline delete gate | Superseded — branch absent on origin → `BL-STALE-BRANCH-CLINE` CLOSED (A) |
| `BL-FORM-HINT` | Orphan `form-hint` yardımcı yazı | Aktif backlog işi DEĞİL — kullanıcı görsel düzenleme sırasında ilgili ekranı gördüğünde isterse kaldırır; ayrı yapılacak iş yok |

---

## Decision registry (özet)

Closed non-goals (yapılmayacak): `BL-QR-DEVICE-BIND` · `BL-QR-OFFLINE` · `BL-QR-GEOFENCE` · `BL-QR-NFC`.
Closed / not-our-rollout: `BL-KARYAPI-SENAY` (firma sahipleri kendi kullanıcı/personel/şube kayıtlarını girer) · `BL-SERHAN-MEDISA-ACCESS` (`GENEL_YONETICI` canlı doğrulandı) · `BL-FORM-HINT` (aktif iş değil).
Product open = **NONE** (D bölümünde açık karar yok; `BL-POST-THRESHOLD-REENTRY` CLOSED).

---

## Technical gap registry (özet)

Technical open = **NONE** — `BL-SOURCE-LOCK-DRIFT` **CLOSED / HISTORICAL** (POST_PR496); `BL-NO-EVENT-DAY` **CLOSED / LIVE** (migration **094** APPLIED; production'da geçmiş, sonrasında 095).

---

## Operational approval registry (özet)

Operational open = **2 gözlem (non-blocking)** — `BL-VISUAL-WEBKIT-FIRST-RUN` · `BL-HARICI-STATU-BOS-SAYIM`; `BL-EKRAN-TURU` devam ediyor. `BL-CROSS-COMPANY` **CLOSED** (120/158 canlı `DIS_KAYNAK` düzeltmesi 2026-10-03). `BL-QR-PILOT-OPS` **PASS / CLOSED** (PILOT_GATE_CLOSED).

---

## Next gate

Next gate = **NONE** (blocker yok) — Attendance / QR phase **CLOSED** (QR attendance + saha rollout + puantaj doğrulaması + personel go-live tamam). Teknik / ürün / operasyonel açık kapı yok.
- `BL-EKRAN-TURU` **DEVAM EDİYOR** — sıradaki iş; bitince `BL-VISUAL-WEBKIT-FIRST-RUN` + `BL-HARICI-STATU-BOS-SAYIM` kapatılır (E).
- `BL-NO-EVENT-DAY` **CLOSED / LIVE** (migration 094 APPLIED).
- `BL-QR-PILOT-OPS` **PASS / CLOSED** (PILOT_GATE_CLOSED).
- `BL-POST-THRESHOLD-REENTRY` **CLOSED** (code).
- Karyapı/Şenay rollout **NOT OUR ROLLOUT** (firma sahipleri kendi kayıtlarını girer) — next gate değil.
- 120/158/219 `BL-CROSS-COMPANY` **CLOSED** (120/158 canlı `DIS_KAYNAK` düzeltmesi 2026-10-03) — next gate değil.
