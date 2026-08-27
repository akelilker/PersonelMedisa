# 130 â€” DIS_KAYNAK operasyonel / finansal ayrÄ±m ve org gÃ¶revlendirme

**Tarih:** 2026-08-27
**Branch:** `feat/dis-kaynak-operational-nonfinancial`
**Code migration tip:** `076` (`076_dis_kaynak_gecici_gorevlendirme.sql`)
**Production migration tip:** `075` (076 apply HAYIR â€” bu turda)

## SUPERSEDES

`docs/guncel/127-external-worker-directory-only.md` iÃ§indeki ÅŸu karar **bayattÄ±r / SUPERSEDED**:

> "DIS_KAYNAK SGK, bordro ve zaman operasyonlarÄ±na girmez."

Yeni otoriter model: **zaman/operasyon â‰  finansal/iÅŸveren**.

## ÃœÃ§ baÄŸÄ±msÄ±z boyut

| Boyut | Anlam | DIS davranÄ±ÅŸÄ± |
| --- | --- | --- |
| A) `calisan_kapsami` | SGK / Ã¼cret / gerÃ§ek bordro / banka yÃ¼kÃ¼mlÃ¼lÃ¼ÄŸÃ¼ | `DIS_KAYNAK` = PersonelMedisa iÅŸveren mali kapsamÄ± dÄ±ÅŸÄ±nda |
| B) Organizasyon baÄŸlantÄ±sÄ± | Åube/Dep/BÃ¶lÃ¼m/Birim | TamamÄ± veya bir kÄ±smÄ± NULL olabilir (baÄŸlantÄ±sÄ±z havuz meÅŸru) |
| C) Rol / yetki | `RolePermissions` + `OrgScope` | DIS olduÄŸu iÃ§in rol engellenmez |

## Operasyonel vs finansal owner

Merkezi owner: `PersonelCalisanKapsamService`

- `assertTimeOperationalEligible` â€” DIS org veya aktif geÃ§ici gÃ¶revlendirme ile **dahil** (effective `sube_id` ÅŸart)
- `assertFinancialEligible` / `sqlIcPersonelPredicate` â€” DIS **kesin dÄ±ÅŸarÄ±da**
- `assertSgkIsverenAllowed` â€” `DIS_KAYNAK_SGK_ISVEREN_YASAK` korunur
- Ambiguous `assertOperationalEligible*` **kaldÄ±rÄ±ldÄ±**; caller'lar explicit time/finans seÃ§er

## Effective org (canonical)

Owner: `PersonelOperationalContextService`

- `resolveNow(personelId)` â€” current business-time (Europe/Istanbul)
- `resolveAt(personelId, timestamp)` â€” tarihsel (QR `occurred_at_utc` â†’ Istanbul); soft-end sonrasÄ± correction iÃ§in kapsayan pencere

Ã–ncelik: **aktif/kapsayan geÃ§ici gÃ¶revlendirme â†’ yoksa permanent org**.
Effective: `sube_id`, `departman_id`, `bolum_id`, `birim_id`.
`personeller.*` overwrite edilmez.

Partial org (Ã¶r. yalnÄ±z `bolum_id`, effective ÅŸube yok): completeness kÄ±rmÄ±zÄ± olmayabilir; **QR/puantaj operational ready sayÄ±lmaz** (fail-closed).

## GeÃ§ici gÃ¶revlendirme

Tablo: `personel_gecici_gorevlendirmeler` (migration 076).

- **`hedef_sube_id` explicit zorunlu** â€” silent `ORDER BY sube_id LIMIT 1` yok
- Zincir fail-closed: `hedef_sube_id` â†’ `sube_departmanlar` â†’ bÃ¶lÃ¼mÃ¼n departmanÄ± â†’ (opsiyonel) birim aynÄ± bÃ¶lÃ¼m
- Atomik create: transaction + `personeller` `SELECT â€¦ FOR UPDATE` + overlap yeniden kontrol + INSERT
- Overlap â†’ `GECICI_GOREVLENDIRME_CAKISMA`; bitiÅŸmeyen aralÄ±klar (01â€“05 / 06â€“10) serbest
- Soft-end; hard delete yok; ikinci end reject; `bitis >= baslangic`

## OrgScope / SubeScope

076 hazÄ±rsa `appendPersonelOrgFilter` / `assertPersonelAccess` aktif gÃ¶revlendirme `hedef_*` alanlarÄ±nÄ± OR eder.
076 yoksa 075 permanent-only davranÄ±ÅŸ (fatal SQL yok).

## Puantaj / zaman bulk

`p.sube_id = :sube_id` adaylarÄ± `sqlPersonelMatchesEffectiveSube` ile assignment-aware.
Finansal/SGK/bordro/banka SQL'leri yalnÄ±z `sqlFinancialEligiblePredicate` (IC) â€” DIS assignment olsa bile **0 aday**.

## Correction approver

`AttendanceCorrectionApproverResolver` event zamanÄ±ndaki effective org ile Ã§Ã¶zÃ¼lÃ¼r (`resolveAt`).
Zincir: BIRIM_AMIRI â†’ BOLUM_YONETICISI â†’ GENEL_YONETICI.
`SUBE_YONETICISI` zincire eklenmez. Self-approval yok.

## Completeness

DIS iÃ§in `departman_id` / `bolum_id` / `birim_id` / `gorev_id` / `personel_tipi_id` CRITICAL deÄŸildir.
IC davranÄ±ÅŸÄ± deÄŸiÅŸmez.

## Mobil

DIS: `shell` + `qr_scan` + `attendance_correct` = true.
`izin_write` fail-closed.
UI: "DIÅ KAYNAK â€” BÄ°LGÄ° AMAÃ‡LIDIR / ÃœCRET VE SGK TAHAKKUKU OLUÅTURMAZ"

## Finansal kesin kapalÄ± (assignment olsa bile)

REAL_PAYROLL / SGK / BANK_EXPORT / ÃœCRET_TAHAKKUKU = HAYIR.

## Bilgi amaÃ§lÄ± Ã§alÄ±ÅŸma Ã¶zeti

QR/puantaj operasyonel kayÄ±tlarÄ± gerÃ§ek zaman kaydÄ±dÄ±r.
GerÃ§ek bordro/SGK/banka pipeline'Ä±na DIS eklenmez; Ã¼cret tahakkuku Ã¼retilmez.

## Gap registry

- `MG-OPS-DIS-ORG-COMPLETE-001` = **CLOSED**
- `MG-OPS-DIS-OPS-MODEL-001` = **OPS_ROLLOUT** (production 076 apply + deploy tamamlanmadan CLOSED_CONFIRMED yazÄ±lmaz)

CURRENT_STATE: `CODE_MIGRATION_TIP=076`, `PRODUCTION_MIGRATION_TIP=075`.
