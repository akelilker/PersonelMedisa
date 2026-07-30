# S106 — Production Apply (henüz uygulanmadı)

Bu belge canlıya yazma talimatıdır. **Bu PR içinde canlı DB apply / merge / deploy yapılmaz.**

## Önkoşullar

- Branch `feat/s106-sgk-resmi-kaynakli-kisitli-katalog` merge edilmiş olmalı
- Deploy sonrası API + migration dosyaları sunucuda olmalı
- Actor: `GENEL_YONETICI`
- Paket: `ops/sgk/S106-SGK-EKSIK-GUN-19-CANONICAL.json`

## Migration sırası

1. Mevcut zincir `036` … `041` uygulanmış olmalı
2. Apply: `api/migrations/042_sgk_resmi_kaynakli_kisitli_katalog.sql`
3. İkinci apply idempotent olmalı (hata vermemeli)
4. Mevcut katalog satırları silinmemeli

## Package hash

```bash
# ops/sgk içinde
sha256sum -c S106-SGK-EKSIK-GUN-19-SHA256SUMS.txt
```

Canonical + write-plan SHA değerleri `S106-SGK-EKSIK-GUN-19-SHA256SUMS.txt` içindedir.

## Dry-run

```http
POST /sgk-katalog-hazirlik/import/dry-run
Content-Type: application/json

{
  "format": "JSON",
  "rows": <CANONICAL.rows>,
  "manifests": <CANONICAL.manifests>,
  "tamlik": {
    "gunce_tam_kod_listesi_kanitlandi_mi": false,
    "kod_bazli_yururluk_tarihi_tam_mi": false,
    "ebildirge_guncel_gorunum_dogrulandi_mi": false,
    "ucuncu_taraf_kaynak_kullanildi_mi": false,
    "expert_draft_tek_basina_mi": false
  }
}
```

Beklenen:

- `gecerli_satirlar` = 19
- `hatali_satirlar` = 0
- `tamlik.tamlik_durumu` = `RESMI_KAYNAKLI_KISITLI`
- `import_yapilabilir_mi` = true
- `dogrulanmis_tam_secilebilir_mi` = false

## Transactional import → submit → approve

```http
POST /sgk-katalog-hazirlik/import
POST /sgk-katalog-hazirlik/submit
POST /sgk-katalog-hazirlik/approve
```

Approve attestation (zorunlu):

```json
{
  "katalog_surumu": "SGK-EKSIK-GUN-RESMI-2026-07",
  "resmi_kaynaklar_incelendi_mi": true,
  "belirsiz_tarihler_uydurulmadi_mi": true,
  "kisitli_kullanim_kabul_edildi_mi": true
}
```

Aynı hash ikinci çağrı → idempotent. Farklı hash aynı sürüm → 409.

## Read-back

Çalıştır: `ops/sgk/S106-SGK-EKSIK-GUN-19-VERIFY.sql` (salt okunur).

Kontrol listesi:

- [ ] Kod sayısı = 19, unique = 19
- [ ] Exact set: 01,03,04,05,06,07,08,09,10,11,12,13,15,16,17,18,19,20,21
- [ ] 26/27/28/29 yok
- [ ] `tamlik_durumu` = `RESMI_KAYNAKLI_KISITLI`
- [ ] `state` = `ONAYLANDI`
- [ ] `DOGRULANMIS_TAM` değil
- [ ] Kod 07 `sifir_gun_sifir_kazanc_durumu` = `YASAK`
- [ ] `gecerlilik_baslangic` NULL / `gecerlilik_tarih_durumu` = `BELIRLENEMEDI`

## Rollback

1. Yeni onaylı sürümü `IPTAL` state’ine çekme (immutable ONAYLANDI ise yeni sürüm + önceki bağlantı)
2. Migration 042 kolonları additive; geri almak için ayrı rollback script gerekir (DROP COLUMN / ENUM daraltma riskli — production’da tercih edilmez)
3. Uygulama kodu geri alınırsa eski fail-closed (yalnız DOGRULANMIS_TAM) davranışına döner; 042 şema kalabilir

## Beklenen sonuç

```text
RESMI_KAYNAKLI_KISITLI + ONAYLANDI
kod_sayisi = 19
DOGRULANMIS_TAM = false
```
