# PersonelMedisa — Güncel Ürün Durumu

Bu dosya ürünün **tek güncel durum kaynağıdır**. Eski S-serisi kapanış raporları, ops paketleri ve `.tmp-ops/` altındaki karar çıktıları tarihsel kanıttır; bu dosyayla çelişirlerse güncel ürün durumu olarak kullanılamazlar.

Açık/kapalı backlog için canonical kayıt: [`docs/guncel/110-master-closure-gap-registry.md`](docs/guncel/110-master-closure-gap-registry.md).

Her registry kaydı **tek** zorunlu statü taşır: `CLOSED` · `CODE_GAP` · `BUSINESS_DECISION_REQUIRED` · `OPS_ROLLOUT` · `INTENTIONAL_DEFER` · `NOT_APPLICABLE` · `DOC_STALE`.
`USER_GATED` / `CONDITIONAL_SCOPE` / `VERIFY_REQUIRED` metadata’dır; statü değildir.

## Karar

- **Ürün beyni:** `FROZEN` (domain owner / paralel motor yalnız ayrı teşhis + açık onay)
- **Görsel düzenleme aşaması:** `GO`
- **Production migration tip:** `058` (kodda `059`–`062` dosyaları mevcut; production apply **YOK**)
- **S3F:** `CLOSED_PRODUCTION` (PR #148 merge `9e1b5c85049d5f2aada84ae59b2be926f0bc6441`; docs closure `72818720ae9dad9a77c31c933806a72acdc7bafd`)
- **QR pipeline:** S3C–S3F `CLOSED`
- **QR algorithms (locked):** `QR_INTERVAL_V1`, `QR_PUANTAJ_CANDIDATE_V1`, `QR_PUANTAJ_DECISION_V1`, `QR_CANDIDATE_HASH_V2`
- **Master closure audit:** 2026-08-12 (`chore/master-closure-audit`); classification hardening aynı PR’da
- **Retention Pack 2–4B:** physical destruction **OPS_ROLLOUT** (`MG-RET-PHYS-001` / `112`+`113`+`114`+`115`+`116`); allocation-aware SERBEST destroy code closed; feature flag default OFF; production apply/enable YOK
- **Serbest Zaman Pack 4B:** allocation-aware destroy + 6M deadline ops surface (`062` + `116`); **OPS_ROLLOUT** (`MG-SZ-6M-001`); production schema rollout pending

Görsel sistem çalışmaları mevcut component/owner içinde yapılabilir. Yeni domain özelliği freeze kapısından geçer.

## Canonical runtime / rollout flags (audit-doğrulanmış)

| Flag | Statü / değer | Metadata |
| --- | --- | --- |
| `PRODUCTION_MIGRATION_TIP` | **058** | kod ucu `062` mevcut (Pack 4B); production apply YOK |
| `S3F` | **CLOSED_PRODUCTION** | — |
| `QR_PIPELINE` | **S3C–S3F CLOSED** | — |
| `REAL_REFERENCE_DATA` | NOT_YET_ROLLED_OUT | `USER_GATED` |
| `REAL_PERSONNEL_DATASET` | USER_GATED | `NO_PII_COMMITTED` |
| `REAL_PERSONNEL_IMPORTED` | **NO** | `USER_GATED` |
| `SOURCE_DATA_REQUIRES_COMPLETION` | yes | ops details outside public repo |
| `PERSONEL_BINDING_REAL_ROLLOUT` | **NOT_STARTED** (schema `056` mevcut) | `USER_GATED` |
| `REAL_QR_EMPLOYEE_ROLLOUT` | **NOT_STARTED** | `USER_GATED` |
| `RETENTION_PHYSICAL_DESTRUCTION` | **OPS_ROLLOUT** (`MG-RET-PHYS-001`) | Pack 4B code closed (`116`); flag default OFF; migrations unapplied; prod enable YOK |
| `RETENTION_MANIFEST_COVERAGE` | **CLOSED** (`MG-RET-MAN-001`) | Pack 1 — creators 15/15 |
| `RETENTION_S3F_LEDGER_FINGERPRINT` | **CLOSED** (`MG-RET-S3F-001`) | Pack 1 — typed ONAY_AUDIT |
| `SERBEST_ZAMAN_6_MONTH_TRACKING` | **OPS_ROLLOUT** (`MG-SZ-6M-001`) | Pack 4B deadline/ops surface (`116`); production schema rollout pending |
| `SGK_15_14` | **BUSINESS_DECISION_REQUIRED** (`MG-SGK-1514-001`) | `CONDITIONAL_SCOPE`; preview BLOCKER_ONLY |
| `YEAR_CROSSING_OT_POLICY` | **BUSINESS_DECISION_REQUIRED** (`MG-OT-YEAR-POL-001`) | — |
| `YEAR_CROSSING_OT_PATH` | **CODE_GAP** (`MG-OT-YEAR-PATH-001`) | create calendar vs snapshot ISO vs compliance calendar filter |
| `LEGACY_ROLE_ENUM_SHRINK` | **INTENTIONAL_DEFER** (`MG-DEF-ENUM-001`) | — |
| `UBGT_AUTHORITATIVE_CALENDAR` | **OPS_ROLLOUT** (`MG-OPS-UBGT-001`) | `USER_GATED` |
| `SGK_OFFICIAL_CATALOG_PROD` | **OPS_ROLLOUT** (`MG-OPS-SGK-CAT-001`) | `VERIFY_REQUIRED` |
| `ORG_BUSINESS_MODEL` | **CLOSED** (`MG-ORG-MODEL-001`) | karar kilitli 2026-08-12 |
| `ORG_LOCATION_SCHEMA` | **CODE_GAP** (`MG-ORG-LOC-001`) | lokasyon ayrı takip |
| `ORG_ATTRIBUTES_BOLUM_BIRIM_POZISYON` | **BUSINESS_DECISION_REQUIRED** (`MG-ORG-ATTR-001`) | native mi / mapping yeterli mi? |
| `CANONICAL_DOC_STALE` | **0** | historical snapshots preserved, not backlog |

## Doğrulanmış teknik temel

- `main` / `origin/main` audit baseline: `72818720ae9dad9a77c31c933806a72acdc7bafd`.
- Migration dosya ucu kodda: `062_serbest_zaman_retention_destroy_gate.sql` (Pack 4B; production tip hâlâ **058**; apply yok).
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
| CODE_GAP (2 P1: `MG-OT-YEAR-PATH-001`, `MG-ORG-LOC-001`) | Açık — `110` | Hayır (ürün “tamam” iddiasını engeller) |
| Retention / SZ-6M | OPS_ROLLOUT (`USER_GATED`) — code closed Pack 4B | Hayır |
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
