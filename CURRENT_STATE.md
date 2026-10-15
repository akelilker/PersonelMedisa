CODE_MIGRATION_TIP: 100
PRODUCTION_MIGRATION_TIP: 100
LAST_VERIFIED_PRODUCTION_MIGRATION_TIP: 100
LAST_VERIFIED_PRODUCTION_MIGRATION_EVIDENCE: APPLY_100_38222514124 backup readback VERIFIED; diagnostics 38226423992 ledger PROD_TIP=100 PENDING=NONE (preflight job NO_PENDING_MIGRATIONS yüzünden kırmızı; tip okuması 100)
FRESH_PRODUCTION_MIGRATION_READBACK: MIGRATION_100_APPLY_38222514124_LEDGER_PROD_TIP_100
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: 0d8f3ddf59696b5c5b4ed70cf8b9560282854121
CODE_MAIN_SHA: 0d8f3ddf59696b5c5b4ed70cf8b9560282854121
LAST_MERGED_PR: 565
ACTIVE_BACKLOG_OWNER: docs/guncel/146-post-pr402-canonical-backlog.md
PIN_SHA_BASELINE: LAST_PRODUCT_MERGE_DEPLOY
DOCS_ONLY_CLOSURE_THIS_PIN: NO

# PersonelMedisa — Canonical State Pin (POST_PR565 + migration 100 closure)

**Aktif residual backlog otoritesi:** [`docs/guncel/146-post-pr402-canonical-backlog.md`](docs/guncel/146-post-pr402-canonical-backlog.md)
**110:** SUPERSEDED for active backlog (historical archive only).
**Do not reopen** PRs #395–#402 without new concrete contradiction.

## Live / code pin (2026-10-15 POST_PR565)

| Alan | Değer | Kanıt |
| --- | --- | --- |
| CODE / PROD migration tip | **100 / 100** | Migration **100** (`100_user_yetki_istisnalari.sql`) **APPLIED**. Apply cPanel migrations run `38222514124` (2026-10-12, SHA `cb3d9c0d…`) SUCCESS; preflight `GATE_PROD_TIP=099`, `GATE_PENDING=100_user_yetki_istisnalari.sql`; backup `medisa-pre-100-38222514124-1-20261012-083007.sql` `MIGRATION_BACKUP_READBACK=VERIFIED`. Sonraki read-only diagnostics run `38226423992` ledger `PROD_TIP=100`, `PENDING_MIGRATIONS=NONE`, `PENDING_COUNT=0`. Bu diagnostics job'ı `PREFLIGHT_BLOCKERS=NO_PENDING_MIGRATIONS,PENDING_CHECKSUM_UNRESOLVED` ile kırmızı bitti; bekleyen migration kalmadığı için preflight apply'a hazır değil. **PENDING: 0**. Önceki 099 apply run `37911294869` tarihsel kayıttır. |
| Migration 094 | **APPLIED** | `094_attendance_no_event_day.sql` production'da geçmiş |
| Migration 095 | **APPLIED** | `095_personel_cinsiyet.sql` production APPLIED |
| Migration 096 | **APPLIED / SUCCESS** | `096_personel_bordro_okumalari.sql` — additive bordro "Okudum" audit (seed/backfill yok); canonical apply run `37021807281` SUCCESS; worker SUCCEEDED; backup `medisa-pre-096-370***807281-1-20261002-144504.sql` readback VERIFIED; post-apply readback run `37022115475` PROD_TIP **096** |
| Migration 097 | **APPLIED / SUCCESS** | `097_qr_attendance_location_audit.sql` — `qr_attendance_events` konum denetim kolonları (#496); pre-apply preflight Ops migration worker diagnostics **#89** run `37389148523` PASS (PROD_TIP 096, pending 097); Apply cPanel migrations **#56** run `37391130248` SUCCESS; worker completed; backup `medisa-pre-097-37391130248-1-20261006-000005.sql` readback VERIFIED; post-apply readback Ops migration worker diagnostics **#90** run `37391796990` PROD_TIP **097**, `PREFLIGHT_BLOCKERS=NO_PENDING_MIGRATIONS` (beklenen: uygulanacak migration kalmadı) |
| Migration 098 | **APPLIED / SUCCESS** | `098` birim 27/28 ad düzeltmesi (PR #515) — Apply cPanel migrations **#57** run `37686359952` SUCCESS (`098` — PR #515 birim 27/28 ad düzeltmesi @ `be6dc016…`); sonraki preflight run `37899949011` PROD_TIP **098** ile doğrulandı |
| Migration 099 | **APPLIED / SUCCESS** | `099_user_kalici_silme_auditleri.sql` (Kalıcı Sil audit + korunan hesaplar 10/9) — APPLY run `37911294869` (Apply 099 kalici sil migration #1; environment `kalici-sil-099-apply` onayı akelilker) worker SUCCEEDED, backup `medisa-pre-kalicisil-099-kalici-apply-37911294869-1-20261009-094506.sql` readback VERIFIED, postcheck PASS; ön koşul preflight Ops migration worker diagnostics **#96** run `37899949011` PASS (PROD_TIP **098**, pending yalnız **099**); standart Apply cPanel migrations kullanılmadı |
| Migration 100 | **APPLIED** | `100_user_yetki_istisnalari.sql` — Apply run `38222514124` SUCCESS, backup readback VERIFIED; diagnostics `38226423992` ledger PROD_TIP **100**, pending NONE. Tablo yoksa okuma hâlâ boş döner; bu kurulumda tablo uygulanmış durumda. |
| LAST_VERIFIED production tip | **100** | Apply `38222514124` + diagnostics `38226423992` ledger `PROD_TIP=100`. Diagnostics workflow sonucu BLOCKED / exit 1: uygulanacak migration kalmadığı için (`NO_PENDING_MIGRATIONS`). Ayrı yeşil postcheck job'ı yok. |
| PRODUCTION_DEPLOY_SHA | `0d8f3ddf…` | Last **product** deploy marker — Deploy cPanel run `38710786000` **SUCCESS** (merge **#565**); önceki #564 deploy run `38623837084`; önceki #563 deploy run `38617921090`; önceki #560 deploy run `38519660078`: log `CURRENT_DEPLOY_SHA=fb1e3708…`, `FINAL_SHA_GET=SUCCESS`, `Deploy SHA finalization verified`, `API_UPLOAD_PARITY=SUCCESS`. Smoke: API health, auth guard, frontend, assets, kimlikli `GET /api/auth/smoke-read` (yazma yok). Canlı `.deploy-sha` public okunamıyor (403) |
| CODE_MAIN_SHA | `0d8f3ddf…` | GitHub `refs/heads/main` merge **#565** = doğrulanan **PRODUCTION_DEPLOY_SHA**. Docs-only pin güncellemesi bu ürün SHA'sını değiştirmez |
| PR #470 | **MERGED / DEPLOYED** | UI correction batch (Level 2+ back nav, Personel Kartı missing-info gateway, duplicate CTA cleanup, Vazgeç fix, /self gateway polish) |
| PR #472 | **MERGED / DEPLOYED** | Self-service closure (PR470 follow-up) — `6b7dbba6…`; Deploy cPanel **#1203** run `37018359986` SUCCESS |
| PR #475 | **MERGED / DEPLOYED** | `fix(ui): bugun personel durumu sube secimi grid + sade sube detayi` — `939c5f87…`; Deploy cPanel **#1206** run `37069634704` SUCCESS |
| PR #476 | **MERGED / DEPLOYED** | `/self` PERSONEL görsel shell (AppShell) + 120/158 `calisan_kapsami` `DIS_KAYNAK` canlı düzeltme + Harici Personel terminoloji + canonical kapanış — `817a1e72…`; Deploy cPanel **#1207** run `37077642885` SUCCESS |
| PR #496 | **MERGED / DEPLOYED** | `feat(qr): GPS geofence audit on QR scan (v1, non-blocking)` + migration **097** — `14dc7fa9…`; Deploy cPanel **#1228** run `37365978799` SUCCESS |
| PR #507 | **MERGED / DEPLOYED** | UX: Anlık personel durumu, şube çerçeveleri, tek süreç araması, footer — `83a51701…`; Deploy cPanel **#1240** SUCCESS |
| PR #508 | **MERGED / DEPLOYED** | Personel kapanış: ad kaydı yazıldığı gibi (yalnız trim + boşluk sadeleştirme), personel seçim listesi 250 sınırı kaldırıldı (sayfalı çekim), anlık durum sayımı doğrulandı (kod değişmedi), Ankara Finans-Risk salt-okunur not — `6c9498f1…`; Deploy cPanel **#1241** run `37556582165` SUCCESS |
| PR #509 | **MERGED / DEPLOYED** | iOS 27 PWA footer safe-area (login + ana ekran; Taşıt PR541 modeli) — squash `71942502…`; Deploy cPanel **#1242** run `37587074563` SUCCESS |
| PR #510 | **MERGED / DEPLOYED** | Anlık Personel Durumu: birim adıyla aynı bölüm adı ikinci satır olarak tekrarlanmaz — `ab3745eb…`; Deploy cPanel **#1243** run `37594047570` SUCCESS |
| PR #511 | **MERGED / DEPLOYED** | Anlık Personel Durumu sadeleştirme: Toplam Personel / Gelen (Geldi + Geç Geldi + Erken Çıktı) / Gelmeyen (Gelmedi + İzinli + Raporlu + Görevde) / Henüz Değerlendirilmedi; Statü drill-down Mavi Yaka / Beyaz Yaka / Statüsüz (yalnız > 0 ise); doğrudan kişi listesi "Ad SOYAD — Durum"; geri satırı geometri düzeltmesi; özet kartları yatay + dikey ortalı — `7851d624…`; Deploy cPanel **#1244** run `37676310896` SUCCESS |
| PR #512 | **MERGED / DEPLOYED** | CI: WebKit mobil layout regression Fast CI'dan ayrıldı → yalnız manuel (`workflow_dispatch`) **Visual WebKit Regression** (`.github/workflows/visual-webkit-regression.yml`); testler silinmedi — `c2300054…`; Deploy cPanel **#1245** run `37677662532` SUCCESS |
| PR #513 | **MERGED (docs-only)** | `docs(state): CURRENT_STATE + 146 POST_PR512 pin` — `1556d6e6…`; Deploy cPanel **#1246** run `37681105639` SUCCESS; pin ilerletmez |
| PR #514 | **MERGED / DEPLOYED** | Anlık Personel Durumu — masaüstü ince ayar (şube grid, özet, org başlık) — `47a6105d…`; Deploy cPanel **#1247** run `37683707220` SUCCESS |
| PR #515 | **MERGED / DEPLOYED** | `data(org): birim 27/28 ad düzeltmesi (migration 098)` — `be6dc016…`; Deploy cPanel **#1248** run `37684855873` SUCCESS; migration **098** Apply **#57** run `37686359952` SUCCESS |
| PR #516 | **MERGED / DEPLOYED** | Geri satırı tüm modal ekranlarında header altı + sola yaslı (tek sahip) — `fabfaaa9…`; Deploy cPanel **#1249** run `37699523374` SUCCESS |
| PR #517 | **MERGED / DEPLOYED** | Gizli test personeline bağlı hesaplar Kullanıcı Yönetimi listesinden çıkar; DESTROYED PERSONEL görünen ad olmaz — `75879cec…`; Deploy cPanel **#1250** run `37701680400` SUCCESS |
| PR #518 | **MERGED / DEPLOYED** | Kalıcı Sil + 099 migration hazırlığı ve Kullanıcı Yönetimi düzeltmeleri — `9d921d7e…`; Deploy cPanel **#1251** run `37871444030` SUCCESS |
| PR #519 | **MERGED / DEPLOYED** | ops: 099 Kalıcı Sil PREFLIGHT/APPLY workflow'ları — `1a151fee…`; Deploy cPanel **#1252** run `37896873521` SUCCESS; 099 preflight run `37899949011` + APPLY run `37911294869` bu SHA üzerinde |
| PR #520 | **MERGED / DEPLOYED** | `fix(yonetim): pm_smoke_ro_production hesabini listeden cikar` — `f14ddbe8…`; Deploy cPanel **#1253** run `37968702102` SUCCESS (`FINAL_SHA_GET=SUCCESS`, smoke OK) |
| PR #560 | **MERGED / DEPLOYED** | Kişisel DENY personel yazma kapısı ve açık oturum etkin izinleri — `fb1e3708…`; Deploy cPanel run `38519660078` SUCCESS (`FINAL_SHA_GET=SUCCESS`, smoke OK) |
| PR #561 | **MERGED / DEPLOYED** | docs: dinamik yetki deploy SHA pin'i — `343f1dda…`; Deploy cPanel run `38557436026` SUCCESS |
| PR #562 | **MERGED / DEPLOYED** | `fix(auth): expire login ALLOW before first permission refresh` — `b3e84c61…`; Deploy cPanel run `38559772162` SUCCESS |
| PR #563 | **MERGED / DEPLOYED** | t900 A: Yeniden Aktif ekranı, yazma rolü daraltma, modül gelen kutusu, durum pin kontrolü — `188bcbdb…`; Deploy cPanel run `38617921090` SUCCESS |
| PR #565 | **MERGED / DEPLOYED** | t900 C: Geri yükleme planı (uygulanmadı), Muhasebe yan etki kararı, #564 pin — `0d8f3ddf…`; Deploy cPanel run `38710786000` SUCCESS |
| PR #564 | **MERGED / DEPLOYED** | t900 B: Duyuru yönetimi, Muhasebe yazma varsayılanı, mükerrer hesap raporu — `919bacdc…`; Deploy cPanel run `38623837084` SUCCESS |
| Attendance / QR phase | **CLOSED** | QR attendance + saha rollout + puantaj doğrulaması + personel go-live tamamlandı |

PRODUCTION_MUTATION_THIS_PIN: migration-only — **098** (Apply #57 run `37686359952`, birim 27/28 ad düzeltmesi) ve **099** (APPLY run `37911294869`; şema + korunan hesap kaydı 10/9) ayrı onaylı run'larla; bu pin kapsamında kayıtlı başka personel/yetki veri mutation yok. Önceki POST_PR512 pin: 0. Önceki POST_PR476 pin: 2 (personel 120 + 158 `calisan_kapsami` `IC_PERSONEL` → `DIS_KAYNAK` canlı düzeltme; readback doğrulandı) — aşağıdaki business truth tablosu geçerli.

## Hard-closed (do not reopen without new contradiction)

- PR #271 / #272 / #274 / #299 / #300 / #301: CLOSED (historical deploy pins kept in older archive notes only)
- PR #326: MERGED + migrations 090/091 APPLIED — do not treat as OPEN/pending
- PR #395–#402: MERGED / CI PASS / DEPLOYED as applicable — visual/QR pilot/self-service sweep CLOSED
- PR #439–#441: MERGED / DEPLOYED — attendance anomaly/correction UX, çalışma geçmişi toplamları, anomaly threshold env override CLOSED
- PR #470: MERGED / DEPLOYED (Deploy #1201) — UI correction batch CLOSED
- PR #472: MERGED / DEPLOYED (Deploy #1203) — self-service closure CLOSED
- PR #475: MERGED / DEPLOYED (Deploy #1206) — "bugun personel durumu" şube seçimi grid + sade şube detayı CLOSED
- PR #476: MERGED / DEPLOYED (Deploy #1207) — `/self` PERSONEL görsel shell + 120/158 `DIS_KAYNAK` canlı düzeltme + Harici Personel terminoloji CLOSED
- PR #507–#512: MERGED / DEPLOYED (Deploy #1240–#1245) — anlık personel durumu UX + sadeleştirme, personel kapanış, iOS 27 footer safe-area, WebKit regression ayrımı CLOSED
- PR #513–#520: MERGED / DEPLOYED (Deploy #1246–#1253; #513 docs-only) — anlık durum masaüstü ince ayar, migration 098, modal geri satırı, Kullanıcı Yönetimi gizli test/etiket düzeltmeleri, Kalıcı Sil + 099 workflow'ları, `pm_smoke_ro_production` listeden çıkarma CLOSED
- A1 12/13 branch-specific SGK period: **SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION**
- REPORTING_PERIOD_CANONICAL_AXIS: SGK_ISVEREN
- BRANCH_SPECIFIC_PERIOD_REQUIRED: NO
- 12/13 branch-specific period rows are NOT the target fix.
- Loc5 / 160/211 / personnel master remediation APPLIED sets: CLOSED
- Branch-manager product model: LOCKED (`USER_SUBELER_SEMANTIC: ACCESS_SCOPE_ONLY`; `BRANCH_MANAGER_OWNER: sube_sorumlu_yoneticiler`)
- QR core S3C–S3F + pilot readiness doc: CLOSED
- BL-QR-ANOMALY core (unresolved anomaly read + amir correction + cron scan + migration 093 dedupe): **CLOSED** (residuals → 146 only)
- BL-NO-EVENT-DAY: **CLOSED / LIVE** — migration 094 APPLIED (production'da geçmiş)
- BL-QR-PILOT-OPS: **PASS / CLOSED** — fiziksel pilot + saha rollout tamam; Attendance/QR phase CLOSED

## Residual registry (owner: 146 only)

Do **not** track open work in this file. Residual statuses are owned by 146:

- D) `BL-FORM-HINT` → **active backlog'dan çıkarıldı** — ayrı yapılacak iş değil; kullanıcı görsel düzenleme sırasında ilgili ekranı gördüğünde isterse yardımcı yazıyı kaldırır.
- D) Device binding / Offline QR / GPS-geofence / NFC → **CLOSED NON-GOAL** — bekleyen/deferred iş değil; next gate'e çıkmaz.
- Karyapı / Şenay → **bizim bekleyen rollout işimiz DEĞİL** — program teslim edildiğinde firma sahipleri kendi kullanıcı/personel/şube kayıtlarını kendileri girecek.
- E) `BL-CROSS-COMPANY` (120 / 158 / 219) → **CLOSED** — güncel canlı/business truth ile hizalandı (aşağı bakınız); next gate üretmez.

## Business truth lock (canlı readback 2026-10-03)

| Personel / kişi | Canlı sonuç | Karar |
| --- | --- | --- |
| 120 İsmail Özcan | Harici Personel; şube **11 Şenay Mobilya** + SGK işveren **3 Şenay Mobilya** + çalışma lokasyonu **5 Fabrika/Karabük** | **DÜZELTİLDİ** — canlı `calisan_kapsami` `IC_PERSONEL` idi; `DIS_KAYNAK` olarak düzeltildi (readback doğrulandı). Şube/SGK/lokasyon/AKTIF değişmedi |
| 158 Salih Efe | Harici Personel; şube **11 Şenay Mobilya** + SGK işveren **3 Şenay Mobilya** + çalışma lokasyonu **5 Fabrika/Karabük** | **DÜZELTİLDİ** — canlı `calisan_kapsami` `IC_PERSONEL` idi; `DIS_KAYNAK` olarak düzeltildi (readback doğrulandı). Şube/SGK/lokasyon/AKTIF değişmedi |
| 219 Doğu Berkan Atmaca | Medisa personeli; şube **6 Medisa İstanbul** + SGK işveren **1 Medisa** + çalışma lokasyonu **3 İstanbul** | **NO ACTION** — Karyapı'dan Medisa'ya transfer zaten yansımış; Karyapı state'i yok |
| `serhan.kose` (user 9) | `rol: GENEL_YONETICI`, `durum: AKTIF` — `sinemH` (user 110) ile aynı yetki modeli | **NO ACTION** — hedef rol/seviye zaten canlıda; tek tek şube/company grant üretilmedi |

219 ve `serhan.kose` "cross-company problem" / "eksik erişim" olarak bekleyen iş DEĞİLDİR; canlı doğrulama business truth'u zaten karşılıyor. 120/158 için canlı `calisan_kapsami` yanlıştı (`IC_PERSONEL`) ve canonical personel update owner (`PUT /personeller/{id}`) üzerinden `DIS_KAYNAK` olarak düzeltildi. Production mutation (personel/yetki) = **2** (120 + 158).

## Terminology lock (2026-10-03)

Personel sınıfı için **tek canonical Türkçe terim**: **Harici Personel** (karşılığı: **Dahili Personel**).
"Dış Kaynak", "Dış Kaynak Çalışan", "External Worker", "External Personnel" kullanıcıya / canonical business truth'a **YANSITILMAZ**.
Internal enum/storage identifier `DIS_KAYNAK` (ve `IC_PERSONEL`) geriye dönük uyumluluk için **DEĞİŞMEDEN** kalır; görünen ad daima "Harici Personel".
Harici Personel olması Fabrika/Karabük'te görevli olmasına engel DEĞİLDİR; Fabrika'da çalışması da onu Dahili Personel yapmaz (iki ayrı eksen).

## Historical note (2026-10-02/03 + POST_PR476 prior pins — SUPERSEDED)

Previous pin (POST_PR472) claimed: PRODUCTION tip **096**, PENDING **0**, DEPLOY `6b7dbba6…` (#472 / Deploy #1203), LAST_MERGED_PR **472**.
Previous pin (POST_PR475) claimed: DEPLOY `939c5f87…` (#475 / Deploy #1206), LAST_MERGED_PR **475**.
Obsolete after #476 merge (SHA `817a1e72…`), Deploy cPanel **#1207** run `37077642885` SUCCESS; migration **096** APPLIED (production tip **096**); migration **095** APPLIED; migration **094** APPLIED (NO_EVENT_DAY live).
Previous pin (POST_PR476) claimed: CODE/PROD tip **096 / 096**, DEPLOY `817a1e72…` (#476 / Deploy #1207 run `37077642885`), LAST_MERGED_PR **476**.
Obsolete after #496 merge (SHA `14dc7fa9…`), Deploy cPanel **#1228** run `37365978799` SUCCESS; migration **097** APPLIED (Apply #56 run `37391130248`; readback Ops #90 run `37391796990` PROD_TIP **097**).
Previous pin (POST_PR496) claimed: DEPLOY `14dc7fa9…` (#496 / Deploy #1228), LAST_MERGED_PR **496**.
Obsolete after #507 merge (SHA `83a51701…`), Deploy cPanel **#1240** SUCCESS; migration tip **097 / 097**, pending **0** (unchanged).
Previous pin (POST_PR507) claimed: DEPLOY `83a51701…` (#507 / Deploy #1240), LAST_MERGED_PR **507**.
Obsolete after #508–#512 merges (main `c2300054…`), Deploy cPanel **#1245** run `37677662532` SUCCESS; migration tip **097 / 097**, pending **0** (unchanged; #508–#512 migration eklemedi).
Previous pin (POST_PR563) claimed: CODE/PROD tip **100 / 100**, DEPLOY `188bcbdb…` (#563 / Deploy run `38617921090`), LAST_MERGED_PR **563**.
Previous pin (POST_PR562) claimed: CODE/PROD tip **100 / 100**, DEPLOY `b3e84c61…` (#562 / Deploy run `38559772162`), LAST_MERGED_PR **562**.
Previous pin (POST_PR560) claimed: CODE/PROD tip **100 / 100**, DEPLOY `fb1e3708…` (#560 / Deploy run `38519660078`), LAST_MERGED_PR **560**.
Previous pin (POST_PR512) claimed: CODE/PROD tip **099 / 097**, DEPLOY `c2300054…` (#512 / Deploy #1245 run `37677662532`), LAST_MERGED_PR **512**.
Obsolete after #513–#520 merges (main `f14ddbe8…`), Deploy cPanel **#1253** run `37968702102` SUCCESS; migration **098** APPLIED (Apply #57 run `37686359952`); migration **099** APPLIED (APPLY run `37911294869`, postcheck PASS); o pin'deki tip **099 / 099**, pending **0** (tarihsel; güncel tip **100 / 100**).

Archive identity strings (not current gates):
PREPARER_HISTORICAL: sedanurB
APPROVER_HISTORICAL: Sinem Hamaloğlu
TECHNICAL_STATUS: TECHNICAL_GAP_LOCAL_FIXABLE _(historical BM gap label; code owner shipped — not an open tip)_

NEXT_GATE: GÖRSEL EKRAN TURU (`BL-EKRAN-TURU`, 146 E) — teknik / migration blocker yok. Aktif backlog sahibi 146'dır. Visual WebKit Regression ilk manuel koşu run `37699163785` (2026-10-08 TR, SHA `bbaddbd1…`) SUCCESS ile kapandı. Açık gözlem: Harici Personel boş Statü canlı sayımı (146 E). Dinamik yetki ekran hizalaması bu çalışma ağacında yereldir; commit, PR, merge ve deploy yoktur. D/E residual'lar CLOSED / NON-GOAL (146). 120/158 `calisan_kapsami` `DIS_KAYNAK` olarak canlı düzeltildi; 219 + `serhan.kose` canlı readback ile doğrulandı (değişmedi).
