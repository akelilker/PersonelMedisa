# Personel GÃ¼venli Hesap AÃ§ma ve Aktivasyon

**Durum:** kaynakta uygulandÄ± / canlÄ±ya henÃ¼z uygulanmadÄ±
**Migration:** `075_personel_account_activation.sql`
**Sahip servis:** `PersonelAccountOnboardingService`

## KullanÄ±cÄ± adÄ± kuralÄ±

| Kural | DeÄŸer |
|------|--------|
| BiÃ§im | Ä°lk ad (kÃ¼Ã§Ã¼k Latin) + soyadÄ±n ilk harfi (bÃ¼yÃ¼k Latin) |
| Ã–rnek | Ä°lker AKEL â†’ `ilkerA` |
| Ã‡oklu ad | YalnÄ±z `ad` alanÄ±nÄ±n ilk kelimesi kullanÄ±lÄ±r (Mehmet Ali YILMAZ â†’ `mehmetY`) |
| TÃ¼rkÃ§e karakter | Ã§/ÄŸ/Ä±/Ä°/Ã¶/ÅŸ/Ã¼ â†’ Latin eÅŸleri; boÅŸluk/nokta/tire temizlenir |
| Sicil numarasÄ± | KullanÄ±cÄ± adÄ± **deÄŸildir** ve Ã¼retime katÄ±lmaz |
| Sicil deÄŸiÅŸince | KullanÄ±cÄ± adÄ± **deÄŸiÅŸmez** |
| Mevcut hesaplar | KullanÄ±cÄ± adlarÄ± **deÄŸiÅŸtirilmez** (backfill yok) |
| Ã‡akÄ±ÅŸma | Otomatik sayÄ± eklenmez; yetkiliye â€œBu kullanÄ±cÄ± adÄ± zaten kullanÄ±lÄ±yor. FarklÄ± bir kullanÄ±cÄ± adÄ± belirleyin.â€ uyarÄ±sÄ± |

## Åifre kuralÄ±

YÃ¶netici / Ä°K / Genel YÃ¶netici personelin ÅŸifresini seÃ§mez, gÃ¶rmez, Ã¶ÄŸrenmez.

Personel, tek kullanÄ±mlÄ±k aktivasyon baÄŸlantÄ±sÄ± Ã¼zerinden kendi ÅŸifresini belirler.

Sonraki giriÅŸ: kullanÄ±cÄ± adÄ± (Ã¶r. `ilkerA`) + personelin kendi ÅŸifresi.

## AkÄ±ÅŸ (yalnÄ±z yeni onboarding)

1. YÃ¶netici/Ä°K: **Personel HesabÄ± OluÅŸtur** (ÅŸifre alanÄ± yok; kullanÄ±cÄ± adÄ± Ã¶nerisi otomatik)
2. Sunucu `PERSONEL` kullanÄ±cÄ± oluÅŸturur; iÃ§ kullanÄ±m iÃ§in rastgele kullanÄ±lamaz kimlik bilgisi hashâ€™lenir (dÃ¶nÃ¼lmez)
3. `activation_required=1`, `must_change_password=1`
4. Tek kullanÄ±mlÄ±k aktivasyon daveti: yalnÄ±z SHA-256 hash saklanÄ±r; ham token bir kez URL olarak dÃ¶ner
5. Personel `/personel-aktivasyon#token=â€¦` aÃ§ar, ÅŸifresini seÃ§er
6. Token tÃ¼ketilir; `activation_required=0`, `must_change_password=0`

## Aktivasyon gÃ¼venliÄŸi

- Token entropisi â‰¥ 256 bit (`random_bytes(32)` â†’ hex)
- TTL sahibi: `medisa_config('personel_activation_ttl_minutes')` (varsayÄ±lan **1440**)
- Genel URL sahibi: `medisa_config('app_public_url')` (Host baÅŸlÄ±ÄŸÄ±na gÃ¼venilmez)
- Fragment taÅŸÄ±ma (`#token=`); sayfa fragmentâ€™i hemen temizler
- Issue/reissue yanÄ±tlarÄ±: `Cache-Control: no-store` + `Referrer-Policy: no-referrer`
- Bekleyen kullanÄ±cÄ± baÅŸÄ±na en fazla bir canlÄ± davet (transactional revoke + insert)
- EÅŸzamanlÄ± redeem: satÄ±r kilitleri â†’ tam olarak bir baÅŸarÄ±

Ã–rnek aktivasyon URL ÅŸekli:

`https://www.karmotors.com.tr/personelmedisa/personel-aktivasyon#token=<gizli>`

## Mevcut hesaplar

Grandfathered. Migration yalnÄ±z gÃ¼venli varsayÄ±lanlarla sÃ¼tun/tablo ekler:

- kullanÄ±cÄ± adlarÄ±nÄ± yeniden adlandÄ±rmaz
- ÅŸifreleri sÄ±fÄ±rlamaz
- aktivasyon beklemeye almaz
- rol/baÄŸlantÄ± deÄŸiÅŸtirmez
- davet Ã¼retmez

## DIS_KAYNAK

AynÄ± teknik onboarding/aktivasyon serbesttir.
`PersonelMobileCapabilityService` iÅŸ yeteneklerini ÅŸu mesajla kapalÄ± tutmaya devam eder:

> YapÄ±m AÅŸamasÄ±ndadÄ±r. Onay Bekleyen Kapsamlar TamamlandÄ±ÄŸÄ±nda KullanÄ±ma AÃ§Ä±lacaktÄ±r.

## Genel oluÅŸturma bypass

`POST /yonetim/kullanicilar` ile `rol=PERSONEL` + `personel_id` â†’ `PERSONEL_USE_SECURE_ONBOARDING`.
Personel dÄ±ÅŸÄ± yÃ¶netim/sistem kullanÄ±cÄ± oluÅŸturma deÄŸiÅŸmez.

## API

| Method | Path | Auth |
|--------|------|------|
| POST | `/yonetim/personeller/{id}/hesap-onboarding` | `yonetim-paneli.manage` |
| POST | `/yonetim/kullanicilar/{id}/aktivasyon-yenile` | `yonetim-paneli.manage` |
| GET | `/yonetim/kullanicilar/{id}/aktivasyon-meta` | `yonetim-paneli.manage` |
| POST | `/auth/personel-activation/status` | public (token) |
| POST | `/auth/personel-activation/complete` | public (token) |

Ä°steÄŸe baÄŸlÄ± gÃ¶vde alanÄ±: `username` â€” yalnÄ±z Ã§akÄ±ÅŸma sonrasÄ± yetkili alternatif kullanÄ±cÄ± adÄ±.

## CanlÄ±ya alma kapÄ±sÄ±

- `PRODUCTION_MIGRATION_APPLY = NO` aÃ§Ä±k ops onayÄ± olmadan
- Mevcut aktif kadroyu toplu yeniden provision etme
- Migration + `app_public_url` hazÄ±r olmadan canlÄ±ya deploy etme

## Denetim olaylarÄ±

`PERSONEL_ACCOUNT_CREATED`, `PERSONEL_ACCOUNT_BOUND`, `ACTIVATION_LINK_ISSUED`, `ACTIVATION_LINK_REISSUED`, `ACTIVATION_COMPLETED`, `ACTIVATION_REVOKED`

DÃ¼z metin ÅŸifre, ÅŸifre hashâ€™i, ham token veya tam aktivasyon URLâ€™si denetlenmez.
