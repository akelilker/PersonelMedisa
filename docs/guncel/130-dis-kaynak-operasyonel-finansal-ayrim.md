# 130 — DIS_KAYNAK operasyonel / finansal ayrım ve org görevlendirme

**Tarih:** 2026-08-27  
**Branch:** `feat/dis-kaynak-operational-nonfinancial`  
**Code migration tip:** `076` (`076_dis_kaynak_gecici_gorevlendirme.sql`)  
**Production migration apply:** HAYIR (bu turda)

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

## Operasyonel vs finansal owner

Merkezi owner: `PersonelCalisanKapsamService`

- `assertTimeOperationalEligible` — DIS org veya aktif geçici görevlendirme ile **dahil**
- `assertFinancialEligible` / `sqlIcPersonelPredicate` — DIS **kesin dışarıda**
- `assertSgkIsverenAllowed` — `DIS_KAYNAK_SGK_ISVEREN_YASAK` korunur

## Geçici görevlendirme

Tablo: `personel_gecici_gorevlendirmeler` (migration 076).  
Permanent `personeller.*_id` overwrite edilmez. Aynı anda tek AKTIF görevlendirme (fail-closed).

- `BOLUM_YONETICISI` yalnız kendi bölümüne atar
- `BIRIM_AMIRI` başka bölüme çekemez
- Bağlantısız havuz: GY/Sistem/İK merkezi; bölüm yöneticisi minimal assignable pool

## Completeness

DIS için `departman_id` / `bolum_id` / `birim_id` / `gorev_id` / `personel_tipi_id` CRITICAL değildir.  
IC davranışı değişmez. `evaluate` ↔ `sqlHasMissingPredicate` parity korunur.

## Mobil

DIS: `shell` + `qr_scan` + `attendance_correct` = true.  
`izin_write` fail-closed (işveren/hukuki sonuç ayrılamadığı için).  
UI: "DIŞ KAYNAK — BİLGİ AMAÇLIDIR / ÜCRET VE SGK TAHAKKUKU OLUŞTURMAZ"

## Bilgi amaçlı çalışma özeti

QR/puantaj operasyonel kayıtları gerçek zaman kaydıdır.  
Gerçek bordro/SGK/banka pipeline'ına DIS eklenmez; ücret tahakkuku üretilmez.
