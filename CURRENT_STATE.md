# PersonelMedisa — Güncel Ürün Durumu

Bu dosya ürünün **tek güncel durum kaynağıdır**. Eski S-serisi kapanış raporları, ops paketleri ve `.tmp-ops/` altındaki karar çıktıları tarihsel kanıttır; bu dosyayla çelişirlerse güncel ürün durumu olarak kullanılamazlar.

Açık/kapalı backlog için canonical kayıt: [`docs/guncel/110-master-closure-gap-registry.md`](docs/guncel/110-master-closure-gap-registry.md).

## Karar

- **Ürün beyni:** `FROZEN` (domain owner / paralel motor yalnız ayrı teşhis + açık onay)
- **Görsel düzenleme aşaması:** `GO`
- **Production migration tip:** `058`
- **S3F:** `CLOSED_PRODUCTION` (PR #148 merge `9e1b5c85049d5f2aada84ae59b2be926f0bc6441`; docs closure `72818720ae9dad9a77c31c933806a72acdc7bafd`)
- **QR pipeline:** S3C–S3F `CLOSED`
- **QR algorithms (locked):** `QR_INTERVAL_V1`, `QR_PUANTAJ_CANDIDATE_V1`, `QR_PUANTAJ_DECISION_V1`, `QR_CANDIDATE_HASH_V2`
- **Master closure audit:** 2026-08-12 (`chore/master-closure-audit`)

Görsel sistem çalışmaları mevcut component/owner içinde yapılabilir. Yeni domain özelliği freeze kapısından geçer.

## Canonical runtime / rollout flags (audit-doğrulanmış)

| Flag | Değer |
| --- | --- |
| `PRODUCTION_MIGRATION_TIP` | **058** |
| `S3F` | **CLOSED_PRODUCTION** |
| `QR_PIPELINE` | **S3C–S3F CLOSED** |
| `REAL_REFERENCE_DATA` | **USER_GATED / NOT_YET_ROLLED_OUT** |
| `REAL_PERSONNEL` | **USER_GATED / NOT_YET_IMPORTED** |
| `PERSONEL_BINDING_REAL_ROLLOUT` | **NOT_STARTED** (schema `056` mevcut) |
| `REAL_QR_EMPLOYEE_ROLLOUT` | **NOT_STARTED** |
| `RETENTION_PHYSICAL_DESTRUCTION` | **CODE_GAP** — `EXECUTION_HANDLER_NOT_IMPLEMENTED` (request/approve/eligibility var) |
| `RETENTION_MANIFEST_COVERAGE` | **CODE_GAP** — auto manifest yalnız `PERSONEL_OZLUK` + `ISE_GIRIS_CIKIS`; 13 kategori creator wiring eksik; S3F ledger ONAY_AUDIT manifest’e bağlı değil |
| `SERBEST_ZAMAN_6_MONTH_TRACKING` | **CODE_GAP** — `son_kullanim_tarihi`/bakiye var; ürünleşmiş vade takibi/compliance yok |
| `SGK_15_14` | **CONDITIONAL / BUSINESS_DECISION_REQUIRED** — preview `BLOCKER_ONLY` / `aktif_edildi_mi=false` / null dönem |
| `YEAR_CROSSING_OT_ALLOCATION` | **BUSINESS_DECISION_REQUIRED** (politika) + **CODE_GAP** (ISO aggregate vs calendar compliance filter uyumsuzluğu) |
| `LEGACY_ROLE_ENUM_SHRINK` | **INTENTIONAL_DEFER** — ENUM hâlâ `PATRON`, `IK_BORDRO`, `SGK_KARAR_ONAY_YETKILISI`, `IDARI_ISLER` taşır; runtime canonical model ayrı |
| `UBGT_AUTHORITATIVE_CALENDAR` | **OPS_ROLLOUT** — engine/schema var; production seed yok (`039` seedless) |
| `SGK_OFFICIAL_CATALOG_PROD` | **OPS_ROLLOUT / VERIFY_REQUIRED** — kod fail-closed; canlı DOGRULANMIS_TAM repo’dan doğrulanmaz |
| `ORG_SGK_BRANCH_LOCATION_MODEL` | Canonical **iş kararı kilitli** (Medisa/Karyapı/Şenay + sistem şubeleri + ayrı lokasyon); schema üçlüyü henüz ayıramıyor → CODE_GAP |

## Doğrulanmış teknik temel

- `main` / `origin/main` audit baseline: `72818720ae9dad9a77c31c933806a72acdc7bafd`.
- Migration dosya ucu kodda: `058_qr_puantaj_candidate_decision_ledger.sql`.
- SGK, şirket politikası kanıtı, bordro preflight, personel importu, revizyon, dual-control, retention request/approve, QR S3C–S3F owner’ları mevcut ve fail-closed çalışır.
- PERSONEL self-service: `/me` puantaj / yıllık izin / FM / QR yüzeyleri; maaş/bordro self-view **OUT_OF_SCOPE** (S3A).
- Smoke/test personeller 1–4 korunur; gerçek 122 personel importu **kullanıcı onayı olmadan yapılmaz**.

## Birbirine karıştırılmaması gereken durumlar

| Katman | Durum | Görsel aşamayı engeller mi? |
| --- | --- | --- |
| Ürün/domain beyni | Frozen | Hayır |
| QR S3C–S3F | CLOSED_PRODUCTION | Hayır |
| Canonical docs / gap registry | Güncel (`110`) | Hayır |
| CODE_GAP (retention destroy/manifest, SZ 6ay, OT year path, org location) | Açık — `110` | Hayır (ürün “tamam” iddiasını engeller) |
| SGK/UBGT/hukuki kanıtlar | Operasyon ve insan kararı | Hayır |
| Gerçek personel / org rollout | USER_GATED | Hayır |
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
- `docs/guncel/105`–`109`: S3A–S3F faz kanıtı (tarihsel tip satırları faz anını yansıtır).
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

**“PersonelMedisa tamamlandı”** yalnız `110` final completion tanımı sağlandığında kullanılır (`CODE_GAP=0`, critical `UNVERIFIED=0`, canonical `DOC_STALE=0`, kalan yalnız defer/NA/bilinçli ops).
