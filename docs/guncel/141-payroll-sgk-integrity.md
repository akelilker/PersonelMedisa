# 141 — Canonical payroll SGK integrity

**Status:** CLOSED_CONFIRMED (merged PR #242; production deploy includes tip `a17da8a`; aktif IC missing SGK = 0)
**Baseline:** `1da67928c849919fcb9a05c3ee4ce34e1f27ac43`

## Rules

1. **Active IC create/import:** `sgk_isveren_id` is required. No branch-default autofill.
2. **Same-company invariant:** `sgk_isverenler.sirket_id` must equal the personnel branch company (`subeler.sirket_id`). Equality to `subeler.sgk_isveren_id` is **not** required.
3. **Owner:** `PersonelSgkCompanyConsistency` — used by create, import dry-run/apply, organizasyon değişikliği, and kalıcı şube değişikliği (historical API error codes preserved on branch move).
4. **Completeness:** IC_PERSONEL missing SGK → incomplete; DIS_KAYNAK ignores SGK key.
5. **DIS_KAYNAK:** SGK remains forbidden / forced NULL.

## Archive residual (personel_id=1)

Payroll candidate set uses employment overlap (`ise_giris` / `cikis_tarihi`), not `aktif_durum`.

- personel_id=1 is PASIF + archive/read-only + `cikis_tarihi=NULL` → still intersects future periods → residual `SGK_ISVEREN_MISSING`.
- **Semantics decision:** keep resolver unchanged (do not exclude PASIF for counter cleanup).
- **Root cause:** data inconsistency (`PASIF` without exit date), not an SGK backfill case for an archived historical member.
- **Remediation (separate approval):** employment-exit owner to set real `cikis_tarihi` if business confirms end of employment. No SGK backfill / snapshot rewrite / archive-flag mutation in this phase.
