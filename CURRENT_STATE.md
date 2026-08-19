# PersonelMedisa — Güncel Ürün Durumu

Bu dosya ürünün **tek güncel durum kaynağıdır**. Eski S-serisi kapanış raporları, ops paketleri ve `.tmp-ops/` altındaki karar çıktıları tarihsel kanıttır; bu dosyayla çelişirlerse güncel ürün durumu olarak kullanılamazlar.

Açık/kapalı backlog için canonical kayıt: [`docs/guncel/110-master-closure-gap-registry.md`](docs/guncel/110-master-closure-gap-registry.md).

Her registry kaydı **tek** zorunlu statü taşır: `CLOSED` · `CODE_GAP` · `BUSINESS_DECISION_REQUIRED` · `OPS_ROLLOUT` · `INTENTIONAL_DEFER` · `NOT_APPLICABLE` · `DOC_STALE`.
`USER_GATED` / `CONDITIONAL_SCOPE` / `VERIFY_REQUIRED` metadata’dır; statü değildir.

## Karar

- **Ürün beyni:** `FROZEN` (domain owner / paralel motor yalnız ayrı teşhis + açık onay)
- **Görsel düzenleme aşaması:** `GO`
- **Code migration tip:** `068` (`068_sgk_actor_identity_lifecycle_audit.sql`)
- **Production migration tip:** **068**
- **Migration067:** `CLOSED_CONFIRMED` (canonical SQL); legacy `migration-067-production-precheck` ops yolu **RETIRED/REMOVED**
- **Migration068:** `CLOSED_CONFIRMED` (code + production schema); formal SGK actor create/verify/bind audit owner (`actor_identity_audits`)
- **Canonical migration execution:** `.github/workflows/apply-cpanel-migrations.yml` → FTP control-plane → protected cPanel cron worker (`api/bin/cpanel-migration-cron.php`); SSH migration dependency **yok**
- **Production personnel total:** `137`; personnel rollout `CLOSED` (Phase1 `122 CLOSED`, Phase2 `11 CLOSED`)
- **Canonical Güvenlik:** `Üretim → Üretim → Güvenlik`
- **S3F:** `CLOSED_PRODUCTION` (PR #148 merge `9e1b5c85049d5f2aada84ae59b2be926f0bc6441`; docs closure `72818720ae9dad9a77c31c933806a72acdc7bafd`)
- **QR pipeline:** S3C–S3F `CLOSED`
- **QR algorithms (locked):** `QR_INTERVAL_V1`, `QR_PUANTAJ_CANDIDATE_V1`, `QR_PUANTAJ_DECISION_V1`, `QR_CANDIDATE_HASH_V2`
- **Master closure audit:** 2026-08-12 (`chore/master-closure-audit`); classification hardening aynı PR’da
- **Retention Pack 2–4B:** physical destruction **INTENTIONAL_DEFER**; schema `059`/`060`/`062` production-ready; feature flag default **OFF**; real destruction **NO**
- **Serbest Zaman Pack 4B:** allocation-aware destroy + 6M deadline ops surface (`061`/`062` + `116` + `118`); **OPS_ROLLOUT_ACTIVE** (`MG-SZ-6M-001`); production schema rollout **COMPLETE**; ops follow-up `USER_GATED`
- **Pack5 Final Code Gap:** rolling OT policy + org location schema (`063`/`064` + `117` + `118`); `MG-OT-YEAR-POL/PATH` CLOSED; `MG-ORG-LOC-001` OPS_ROLLOUT USER_GATED (schema production-ready; personnel mapping still gated)
- **Org reference seed (`119`, 2026-08-13):** SGK employers `MEDISA`/`KARYAPI`/`SENAY_MOBILYA` + 7 verified work locations seeded
- **Pack6 org structure (`120`/`121`/`122`):** native Bölüm/Birim/Pozisyon + `subeler.sgk_isveren_id`; production schema/reference catalogs **VERIFIED**; locked 10-branch model + MRK=`Medisa` + ownership complete
- **Personnel count rollout:** production total `137` (`Phase1=122 CLOSED`, `Phase2=11 CLOSED`); personel org FK alanları (`sgk_isveren_id`, `calisma_lokasyonu_id`, `bolum_id`, `birim_id`, `pozisyon_id`) **VERIFY_REQUIRED** / `USER_GATED` — kanıtsız CLOSED yazılmaz
- **SGK actor lifecycle (code):** `ActorIdentityService` create/verify/bind + readback + `actor_identity_audits`; production schema via migration `068` (run [#32217771186](https://github.com/akelilker/PersonelMedisa/actions/runs/32217771186) @ `cd92d24…`, worker verify pass)

Görsel sistem çalışmaları mevcut component/owner içinde yapılabilir. Yeni domain özelliği freeze kapısından geçer.

## Canonical runtime / rollout flags (audit-doğrulanmış)

| Flag | Statü / değer | Metadata |
| --- | --- | --- |
| `PRODUCTION_MIGRATION_TIP` | **068** | Apply cPanel migrations run [#32217771186](https://github.com/akelilker/PersonelMedisa/actions/runs/32217771186) @ `cd92d24…`; bundle+verify pass |
| `CODE_MIGRATION_TIP` | **068** | `068_sgk_actor_identity_lifecycle_audit.sql` |
| `MIGRATION067_LEGACY_OPS` | **RETIRED/REMOVED** | Generic canonical control-plane only |
| `MIGRATION068` | **CLOSED_CONFIRMED** | Code + production `actor_identity_audits` schema |
| `SSH_MIGRATION_DEPENDENCY` | **NO** | cPanel SSH yok; FTP request + protected cron worker |
| `S3F` | **CLOSED_PRODUCTION** | — |
| `QR_PIPELINE` | **S3C–S3F CLOSED** | — |
| `REAL_REFERENCE_DATA` | **READY** — SGK/locations (`119`) + branches (`121`) + canonical catalogs (`122`); personnel org FK apply gated | `USER_GATED` |
| `PERSONNEL_ORG_FK_ROLLOUT` | **NOT_STARTED** | `sgk_isveren_id` / `calisma_lokasyonu_id` / `bolum_id` / `birim_id` / `pozisyon_id` — `VERIFY_REQUIRED` |
| `REAL_PERSONNEL_DATASET` | **CLOSED** | Production personnel rollout complete; `NO_PII_COMMITTED` |
| `REAL_PERSONNEL_IMPORTED` | **YES** | 137 production personnel |
| `SOURCE_DATA_REQUIRES_COMPLETION` | **NO** for closed rollout | exact person-level source details stay private |
| `PERSONEL_BINDING_REAL_ROLLOUT` | **NOT_STARTED** (schema `056` mevcut) | `USER_GATED` |
| `REAL_QR_EMPLOYEE_ROLLOUT` | **NOT_STARTED** | `USER_GATED` |
| `RETENTION_PHYSICAL_DESTRUCTION` | **INTENTIONAL_DEFER** (`MG-RET-PHYS-001`) | schema production-ready (`059`/`060`/`062` via `118`); flag default **OFF**; real destruction **NO** |
| `RETENTION_MANIFEST_COVERAGE` | **CLOSED** (`MG-RET-MAN-001`) | Pack 1 — creators 15/15 |
| `RETENTION_S3F_LEDGER_FINGERPRINT` | **CLOSED** (`MG-RET-S3F-001`) | Pack 1 — typed ONAY_AUDIT |
| `SERBEST_ZAMAN_6_MONTH_TRACKING` | **OPS_ROLLOUT_ACTIVE** (`MG-SZ-6M-001`) | Pack 4B ops surface (`116`); allocation schema production-ready (`061`/`062` via `118`); ops follow-up `USER_GATED` |
| `SGK_PERIOD_BUSINESS_DECISION` | **CLOSED_CONFIRMED** (`MG-SGK-1514-001`) | Medisa/Karyapı/Şenay Mobilya branches `1,4,5,6,7,8,9,10,11` → `AY_1_SON_GUN`; `15_TO_NEXT_MONTH_14` and `MIXED_BY_INSURED` not used |
| `SGK_PERIOD_PRODUCTION_ROLLOUT` | **OPS_ROLLOUT** | canonical read surface implemented locally; production read → compare → draft → submit → separate approval pending release |
| `YEAR_CROSSING_OT_POLICY` | **CLOSED** (`MG-OT-YEAR-POL-001`) | `ROLLING_12_MONTH_ACTUAL_DATE_V1` |
| `YEAR_CROSSING_OT_PATH` | **CLOSED** (`MG-OT-YEAR-PATH-001`) | Pack5 rolling owner (`117`); provenance schema production-ready (`063` via `118`) |
| `LEGACY_ROLE_ENUM_SHRINK` | **INTENTIONAL_DEFER** (`MG-DEF-ENUM-001`) | — |
| `UBGT_AUTHORITATIVE_CALENDAR` | **CLOSED_CONFIRMED** (`MG-OPS-UBGT-001`) | — |
| `SGK_OFFICIAL_CATALOG_PROD` | **CLOSED_CONFIRMED** (`MG-OPS-SGK-CAT-001`) | — |
| `ORG_BUSINESS_MODEL` | **CLOSED** (`MG-ORG-MODEL-001`) | karar kilitli 2026-08-12 |
| `ORG_LOCATION_SCHEMA` | **CLOSED_CONFIRMED** (`MG-ORG-LOC-001`) | production references resolved; personnel org FK apply `USER_GATED` |
| `SGK_EMPLOYER_REAL_REFERENCE` | **PRODUCTION_READY** | codes `MEDISA`/`KARYAPI`/`SENAY_MOBILYA` (`119`) |
| `WORK_LOCATION_REAL_REFERENCE` | **PRODUCTION_READY** | 7 verified location codes seeded (`119`) |
| `ORG_ATTRIBUTES_BOLUM_BIRIM_POZISYON` | **CLOSED** (`MG-ORG-ATTR-001`) | native fields via Pack6 `065` (`120`/`121`) |
| `ORG_STRUCTURE_SCHEMA` | **VERIFIED_PRODUCTION** | Schema + reference catalogs verified; personnel org FK values remain `VERIFY_REQUIRED` |
| `SGK_ACTOR_LIFECYCLE_CODE` | **CLOSED** | `ActorIdentityService` + migration `068` audit schema; ops business rollout ayrı kapı |
| `MG-OPS-POLICY-001` | **CLOSED_CONFIRMED** | Active revision `3`; 14/14; Pazar (`0`) |
| `CANONICAL_DOC_STALE` | **0** | historical snapshots preserved, not backlog |

## Doğrulanmış teknik temel

- Current `origin/main`: `259cc6ccca110248198f3f6ccd0602cadaafee30`.
- Migration tip: code **068**; production **068**. Migration067 canonical SQL `CLOSED_CONFIRMED`; legacy ops workflow **RETIRED/REMOVED**. Migration068 `CLOSED_CONFIRMED`.
- Canonical migration owner: `apply-cpanel-migrations.yml` + `cpanel-migration-cron.php`; public HTTP migration endpoint ve direct SQL canonical yol değil; SSH blocker **yok**.
- Org references: SGK=3, locations=7, locked 10 branches (`121`); canonical catalogs completed (`122`); personnel org FK alanları production'da bulk apply edilmedi — `VERIFY_REQUIRED` / `USER_GATED`.
- Pack6: `bolumler` / `birimler` / `pozisyonlar` + `subeler.sgk_isveren_id` (authorization still `personeller.sube_id`).
- SGK, şirket politikası kanıtı, bordro preflight, personel importu, revizyon, dual-control, retention request/approve/evaluate/execute (flag OFF), QR S3C–S3F owner’ları mevcut ve fail-closed çalışır.
- PERSONEL self-service: `/me` puantaj / yıllık izin / FM / QR yüzeyleri; maaş/bordro self-view **OUT_OF_SCOPE** (S3A).
- Smoke/test personeller korunur; gerçek personel dataset’i **kullanıcı onayı olmadan import edilmez**.
- Public repo’ya PII / exact personnel tallies yazılmaz.

## Birbirine karıştırılmaması gereken durumlar

| Katman | Durum | Görsel aşamayı engeller mi? |
| --- | --- | --- |
| Ürün/domain beyni | Frozen | Hayır |
| QR S3C–S3F | CLOSED_PRODUCTION | Hayır |
| Canonical docs / gap registry | Güncel (`110`); `CANONICAL_DOC_STALE=0` | Hayır |
| CODE_GAP | **0** — Pack5 closed (`117`) | Hayır |
| Retention / SZ-6M / Org-LOC | OPS_ROLLOUT (`USER_GATED`) — schema production-ready (`118`); feature/seed/mapping gated | Hayır |
| SGK/UBGT/hukuki kanıtlar | OPS_ROLLOUT + insan kararı | Hayır |
| Gerçek personel / org rollout | USER_GATED OPS_ROLLOUT | Hayır |
| Exact-SHA cPanel yayın | Ops / manuel upload | Tasarımı engellemez; canlıya çıkışı ops kapısına bağlar |

## Freeze kuralı

Görsel aşamada beyin kapsamı yalnız şu hallerde yeniden açılır:

1. Tekrarlanabilir P0 veri bütünlüğü hatası.
2. Yetki veya güvenlik açığı.
3. Mevcut owner sözleşmesini bozan doğrulanmış regresyon.
4. Yasal olarak zorunlu ve kanıtı tamamlanmış kural değişikliği.
5. `110` registry’de P0/P1 CODE_GAP için ayrı onaylı uygulama turu.

Performans, görsel tutarlılık, erişilebilirlik ve responsive düzenlemeler mevcut component/owner yapısı içinde yapılır. Yeni paralel domain sistemi kurulmaz.

## Yayın kabul kapıları

Bir commitin canlıya kabulü için ürün freeze kararından bağımsız olarak şunlar kanıtlanır:

1. `HEAD`, `origin/main` ve remote `main` eşit.
2. Aynı SHA için CI başarılı.
3. Aynı SHA için cPanel deploy başarılı; otomatik hat kullanılamıyorsa manuel upload sonrası build asset/SHA eşliği ayrıca kanıtlanmış.
4. Anonim `smoke:live` başarılı.
5. Dedicated `AUTH_SMOKE_READONLY` hesabıyla authenticated smoke başarılı.
6. Gerekli production write varsa ayrıca backup, insan onayı ve read-back kanıtı mevcut (`95` runbook).

Gerçek personel importu, SGK resmi katalog onayı, UBGT seed ve physical destruction **ayrı** üretim kapılarıdır; CI yeşili bunları otomatik açmaz.

## Tarihsel belgelerin kullanımı

- `docs/guncel/110-master-closure-gap-registry.md`: **tek canonical gap/backlog kaydı**.
- `docs/guncel/95-s96-release-ops-runbook.md`: güncel operasyon protokolü.
- `docs/guncel/99-payroll-compliance-critical-gaps-kapanis.md`: kapanmış kritik payroll owner kanıtı.
- `docs/guncel/105`–`109`: S3A–S3F faz kanıtı (`HISTORICAL_SNAPSHOT_PRESERVED`; DOC_STALE backlog değil).
- `docs/guncel/115`: Pack 4A historical snapshot.
- `docs/guncel/116`: Pack 4B code closure evidence.
- `docs/guncel/118`: Production migration rollout `059`→`064` evidence (tip **064**).
- `docs/guncel/119`: Org reference seed evidence (SGK employers + verified work locations; mapping preview-only).
- `.tmp-ops/**`: yerel tarihsel ops çıktısı; güncel backlog sayılmaz.
- Eski S-numaralı checkpoint’ler yalnız ait oldukları commit/dönem için kanıttır.

## Okuma — hesaplama cevap haritası

Kodun *neyi nasıl hesapladığını* tek bakışta görmek için:

- `docs/guncel/102-hesaplama-cevap-haritasi.md`

Bu dosya backlog değildir. Açık maddeler `110` registry’dedir. Canlı parametre ve yayın kapıları bu `CURRENT_STATE.md` dosyasına bağlıdır.

## Sonraki ürün aşaması

1. Görsel sistem (token / layout / tipografi / component / responsive / a11y).
2. `110` registry kapanış sırası: business input → CODE_GAP P1 → ops rollout (USER_GATED).
3. Faz adı uydurulmaz; repo’da `S3G`/`S4` yok.

**“PersonelMedisa tamamlandı”** yalnız `110` final completion tanımı sağlandığında kullanılır: `CODE_GAP=0`, `UNVERIFIED_CRITICAL=0`, `CANONICAL_DOC_STALE=0`, kalan yalnız `CLOSED` / `NOT_APPLICABLE` / `INTENTIONAL_DEFER` / USER_GATED `OPS_ROLLOUT`.

## 2026-08-17 Priority A closure

- **SGK catalog:** `CLOSED_CONFIRMED`; UBGT: `CLOSED_CONFIRMED`.
- **Payroll company policy:** `CLOSED_CONFIRMED`; active policy revision `3`, state `ONAYLANDI`, required/resolved keys `14/14`, missing `0`.
- **HAFTA_TATILI_GUNLERI:** `0` / Pazar. Other 13 policy values unchanged from revision 2.
- **Policy dual control:** submitter user ID `1`; approver `ilkerA` user ID `10`; self-approval `NO`.
- **Payroll policy read-only preflight:** `HAFTA_TATILI_GUNLERI_MISSING_BLOCKER=NO`; `PAYROLL_POLICY_RUNTIME_READY=YES`.
- **MG-OPS-POLICY-001:** `CLOSED_CONFIRMED`; `PRIORITY_A_REMAINING=NONE`.
- **Retention duration policy:** `CLOSED_CONFIRMED`; `POLICY_RETENTION_YEARS=10`; provenance `USER_CONFIRMED_BUSINESS_DECISION`. Physical destruction remains `INTENTIONAL_DEFER`; execution was not authorized.
- **SGK reporting period decision:** **CLOSED_CONFIRMED**; Medisa/Karyapı/Şenay Mobilya branches `1,4,5,6,7,8,9,10,11` target runtime enum `AY_1_SON_GUN`; local read-surface fix is ready, production rollout remains `OPS_ROLLOUT` pending release/apply.

## 2026-08-15 final personel preflight

- Canonical internal scope remains `IC_PERSONEL`; external directory-only scope remains first-class `DIS_KAYNAK`.
- `Personel Kartı` is read-only for ownership; `Kayıt ve Süreç` owns personnel writes.
- PR #170 UX closure is represented by merge SHA `ce9775bf3fcbca48bbd3ca80e4721e913a8e2f56`; CI/deploy/smoke closure is retained.
- Internal Phase 1 scope is 122 `IC_PERSONEL`; 20 missing phone values are `DEFERRED_USER_DATA` and non-blocking. Candidate-level Sicil and birth-date blockers are both 0; 13 `DIS_KAYNAK` rows remain Phase 2 / `DEFERRED_REFERENCE_DECISION`.
- The dedicated `AUTH_SMOKE_READONLY` contract remains smoke-only (`ops.auth_smoke.read`); it does not authorize production import or mutation.
- Authenticated production personnel rollout is complete: total `137` (`Phase1=122 CLOSED`, `Phase2=11 CLOSED`); no new personnel mutation is part of this closure.
- Live reference probe found the legacy `Üretim Genel → Güvenlik` branch; canonical tree check is **FAIL** and reference mutation is required, but was not performed.
- Source reconciliation remains private; raw blanks, candidate values, and importer errors stay separate. No production personnel import has been performed.
- Business decision: 20 IC phone values are deferred; missing-info UX continues to flag them, but phone absence is non-blocking for import and daily operations. Completion remains allowed later through Kayıt ve Süreç.
