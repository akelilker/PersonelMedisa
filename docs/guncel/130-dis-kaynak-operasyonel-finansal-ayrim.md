# 130 — DIS_KAYNAK operasyonel / finansal ayrım ve org görevlendirme

**Tarih:** 2026-08-28
**Branch:** `feat/dis-kaynak-operational-nonfinancial`
**Code migration tip:** `076` (`076_dis_kaynak_gecici_gorevlendirme.sql`)
**Production migration tip:** `076` (canonical production applied; schema ready; assignment count=0)

## SUPERSEDES

`docs/guncel/127-external-worker-directory-only.md` içindeki şu karar **bayattır / SUPERSEDED**:

> "DIS_KAYNAK SGK, bordro ve zaman operasyonlarına girmez."

Yeni otoriter model: **zaman/operasyon ≠ finansal/işveren**.

## Üç bağımsız boyut

| Boyut | Anlam | DIS davranışı |
| --- | --- | --- |
| A) `calisan_kapsami` | SGK / ücret / gerçek bordro / banka yükümlülüğü | `DIS_KAYNAK` = PersonelMedisa işveren mali kapsamı dışında |
| B) Organizasyon bağlantısı | Şube/Dep/Bölüm/Birim | Tamamı veya bir kısmı NULL olabilir (bağlantısız havuz meşru) |
| C) Rol / yetki | `RolePermissions` + `OrgScope` | DIS olduğu için rol engellenmez |
| D) SGK / bordro kaynağı | `sgk_isveren_id` (hangi işveren üzerinden SGK/bordro) | Farklı şirketin AKTİF SGK işvereni geçerli; NULL (Bağ-Kur) da geçerli |
| E) Fiili çalışma yeri | `calisma_lokasyonu_id` | Şubeden bağımsız; DIS'in gerçekten çalıştığı yer |

> **2026-09-13 güncellemesi (phase `EMPLOYMENT_SCOPE_WORKPLACE_INSURANCE_MODEL_CORRECTION`):**
> `assertSgkIsverenAllowed` / `DIS_KAYNAK_SGK_ISVEREN_YASAK` **kaldırıldı**. SGK işvereni
> bir finansal kapsam kanıtı değil, bordro kaynağı bilgisidir; aynı-şirket invariant'ı
> yalnız `IC_PERSONEL` için `PersonelSgkCompanyConsistency` üzerinden uygulanır.
> Finansal ayrım (`assertFinancialEligible`, `sqlFinancialEligiblePredicate`) korunur.

## Operasyonel vs finansal owner

Merkezi owner: `PersonelCalisanKapsamService`

- `assertTimeOperationalEligible` — DIS org veya aktif geçici görevlendirme ile **dahil** (effective `sube_id` şart)
- `assertFinancialEligible` / `sqlIcPersonelPredicate` — DIS **kesin dışarıda**
- Ambiguous `assertOperationalEligible*` **kaldırıldı**; caller'lar explicit time/finans seçer

## Effective org (canonical)

Owner: `PersonelOperationalContextService`

- `resolveNow(personelId)` — current business-time (Europe/Istanbul)
- `resolveAt(personelId, timestamp)` — tarihsel (QR `occurred_at_utc` → Istanbul); soft-end sonrası correction için kapsayan pencere

Öncelik: **aktif/kapsayan geçici görevlendirme → yoksa permanent org**.
Effective: `sube_id`, `departman_id`, `bolum_id`, `birim_id`.
`personeller.*` overwrite edilmez.

Partial org (ör. yalnız `bolum_id`, effective şube yok): completeness kırmızı olmayabilir; **QR/puantaj operational ready sayılmaz** (fail-closed).

## Geçici görevlendirme

Tablo: `personel_gecici_gorevlendirmeler` (migration 076).

- **`hedef_sube_id` explicit zorunlu** — silent `ORDER BY sube_id LIMIT 1` yok
- Zincir fail-closed: `hedef_sube_id` → `sube_departmanlar` → bölümün departmanı → (opsiyonel) birim aynı bölüm
- Atomik create: transaction + `personeller` `SELECT … FOR UPDATE` + overlap yeniden kontrol + INSERT
- Overlap → `GECICI_GOREVLENDIRME_CAKISMA`; bitişmeyen aralıklar (01–05 / 06–10) serbest
- Soft-end; hard delete yok; ikinci end reject; `bitis >= baslangic`

## OrgScope / SubeScope

076 hazırsa `appendPersonelOrgFilter` / `assertPersonelAccess` aktif görevlendirme `hedef_*` alanlarını OR eder.
076 yoksa 075 permanent-only davranış (fatal SQL yok).

## Puantaj / zaman bulk

`p.sube_id = :sube_id` adayları `sqlPersonelMatchesEffectiveSube` ile assignment-aware.
Finansal/SGK/bordro/banka SQL'leri yalnız `sqlFinancialEligiblePredicate` (IC) — DIS assignment olsa bile **0 aday**.

## Correction approver

`AttendanceCorrectionApproverResolver` event zamanındaki effective org ile çözülür (`resolveAt`).
Zincir: BIRIM_AMIRI → BOLUM_YONETICISI → GENEL_YONETICI.
`SUBE_YONETICISI` zincire eklenmez. Self-approval yok.

## Completeness

DIS için `departman_id` / `bolum_id` / `birim_id` / `gorev_id` / `personel_tipi_id` CRITICAL değildir.
IC davranışı değişmez.

## Mobil

DIS: `shell` + `qr_scan` + `attendance_correct` = true.
`izin_write` fail-closed.
UI: "HARİCİ PERSONEL — BİLGİ AMAÇLIDIR / ÜCRET VE SGK TAHAKKUKU OLUŞTURMAZ"

## Finansal kesin kapalı (assignment olsa bile)

REAL_PAYROLL / SGK / BANK_EXPORT / ÜCRET_TAHAKKUKU = HAYIR.

## Bilgi amaçlı çalışma özeti

QR/puantaj operasyonel kayıtları gerçek zaman kaydıdır.
Gerçek bordro/SGK/banka pipeline'ına DIS eklenmez; ücret tahakkuku üretilmez.

## Gap registry / production kapanis

- `MG-OPS-DIS-ORG-COMPLETE-001` = **CLOSED**
- `MG-OPS-DIS-OPS-MODEL-001` = **CLOSED_CONFIRMED**
- Migration `076` production applied (canonical worker SUCCEEDED)
- Schema ready: `personeller.sube_id` nullable; `personel_gecici_gorevlendirmeler` VAR
- Production assignment count = 0 (gercek gorevlendirme bu turda olusturulmadi)
- Gercek personel / rol / user / SGK / payroll mutation = 0
- DIS permanent org optional; effective org gecici gorevlendirme aware
- REAL_PAYROLL / SGK / BANK_EXPORT = HAYIR (076 bu siniri degistirmedi)
- Gercek gorevlendirme rolloutu ayri insan/operasyon kararidir; teknik production kapanisini bloke etmez

CURRENT_STATE: `CODE_MIGRATION_TIP=076`, `PRODUCTION_MIGRATION_TIP=076`.
