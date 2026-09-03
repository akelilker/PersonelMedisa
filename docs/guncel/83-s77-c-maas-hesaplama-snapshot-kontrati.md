# S77-C — Maaş Hesaplama Preflight ve Değişmez Girdi Snapshot Kontrati

## Amaç

Mühürlenmiş puantaj döneminden maaş hesaplama girdilerini güvenli biçimde dondurmak.
Bu fazda brüt/net, SGK, vergi veya bordro PDF üretilmez.

## Owner’lar

| Alan | Owner |
|---|---|
| Ücret | `personel_ucret_gecmisi` / `PersonelUcretService` |
| Mevzuat | `mevzuat_parametreleri` / `MevzuatParametreService` |
| Mühür | `puantaj_aylik_muhurleri` + satırlar |
| Dönem kilidi | `PuantajDonemKilidiService` |
| Finans | `ek_odeme_kesinti` |
| Etki adayları | `onayli_bildirim_puantaj_etki_adaylari` + S75 çözümleri |
| Snapshot | `MaasHesaplamaSnapshotService` |

## Canonical org identity (create-time freeze)

Execution / uniqueness key remains **`(sube_id, yil, ay)`** — same as mühür. This is branch-operational attendance close, not an SGK-employer close product.

At snapshot create, `resolvePersonnelSet` freezes into `personel_snapshot_json`:

- `sube_id` (operational branch)
- `sgk_isveren_id` (+ kod/ad) from **`personeller.sgk_isveren_id`** — never inferred from `subeler.sgk_isveren_id`
- `sirket_id` (+ kod/ad) from the employer’s company when present, else the branch company

Header `fetchSube` freezes branch display `tam_ad` plus branch company and branch-default SGK as **operational context only**. Detail reads frozen payloads; it does not re-resolve live org.

When org-location schema is ready, IC personel without `sgk_isveren_id` raise `SGK_ISVEREN_MISSING` (BLOCKER). `DIS_KAYNAK` stays outside the IC payroll set.

## Veri modeli

- `maas_hesaplama_donem_snapshotlari`
- `maas_hesaplama_personel_snapshotlari`
- `maas_hesaplama_girdi_snapshotlari`
- `maas_hesaplama_snapshot_auditleri`

Migration:

- `020_maas_hesaplama_snapshotlari.sql`
- `021_maas_hesaplama_snapshot_guvenlik_indexleri.sql`

Immutability: child tablolarda UPDATE/DELETE trigger ile yasak; root’ta yalnız `OLUSTURULDU → IPTAL`.

## API

- `GET /maas-hesaplama/preflight`
- `GET /maas-hesaplama/snapshotlar`
- `GET /maas-hesaplama/snapshotlar/{id}`
- `POST /maas-hesaplama/snapshotlar`
- `POST /maas-hesaplama/snapshotlar/{id}/iptal`
- `GET /maas-hesaplama/snapshotlar/{id}/audit`
- `GET /maas-hesaplama/auditler`

## Rol / scope

- `maas_hesaplama.view` / `maas_hesaplama.manage`
- MUHASEBE + GENEL_YONETICI: evet
- BIRIM_AMIRI / BOLUM_YONETICISI / PATRON: hayır
- Şube scope backend’de zorunlu

## Frontend

Raporlar paneli: `?panel=maas-hesaplama` — Maaş Hesaplama Merkezi.
Ana menü üç buton kuralı korunur.

## Test kapıları

- SQLite integration: `MaasHesaplamaSnapshotTestRunner`
- Migration: `MaasHesaplamaMigrationTestRunner`
- MariaDB concurrency: `MaasHesaplamaSnapshotMysqlConcurrencyTestRunner`
- E2E: `tests/e2e/maas-hesaplama-merkezi.spec.ts`

## Başarı kodları (yerel)

`S77_C_SCHEMA_DESIGN_COMPLETE`
`S77_C_IMMUTABILITY_CONTRACT_COMPLETE`
`S77_C_SNAPSHOT_DOMAIN_OWNER_COMPLETE`
`S77_C_API_CONTRACT_COMPLETE`
`S77_C_ROLE_SCOPE_COMPLETE`
`S77_C_FRONTEND_CENTER_COMPLETE`
`S77_C_MOCK_LIVE_PARITY_OK`
`S77_C_MARIADB_CONCURRENCY_OK`
