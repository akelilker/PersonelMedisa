# S88 — UBGT Gün Kapsamı ve Resmî Tatil Takvimi Owner

Tarih: 22.07.2026  
Branch: `feat/s88-ubgt-tatil-takvimi-owner`  
Base: `origin/main` @ `19145c1b03d77c10b037b7225f5ce01c90628f62`

## Owner kararı

Mevcut canonical tatil takvimi tablosu yoktu (`gun_tipi` manuel UBGT).  
**Karar B:** tek canonical `resmi_tatil_takvimi` (paralel ikinci sistem yok).  
Hafta tatili bu tabloya taşınmaz.

Payroll immutable satır: `puantaj_aylik_muhur_satirlari` (+ günlük projection `gunluk_puantaj`).

## Migration 039

- Additive, seedless, idempotent
- Gerçek tatil tarihi / production seed yok
- 038 dokunulmaz

## Fail-closed

- `KAYNAK_EKSIK` / `CAKISMA` / `BILINMIYOR` → payable yok
- `YARIM_GUN` → `HALF_DAY_UBGT_POLICY_*` (ödeme politikası kapalı)
- Interval owner yok → `TATIL_DONEMI_CALISMA_INTERVALI_EKSIK` (dakika üretilmez)
- Historical backfill / magic `13:00` / isimden tahmin yok

## Bu fazda yapılmayanlar

merge · deploy · production 039 apply · seed · policy write · bordro üretimi
