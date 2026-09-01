# MG-PERSONNEL-LIFECYCLE-EXPORT-CLOSEOUT-001

Production tip **082**; repository migration tip **083** (`083_personel_organizasyon_degisiklik_auditleri.sql`).

Kullanıcının göndereceği gerçek personel Excel’i bu PR’a **dahil değildir**.

## Personel export / import sözleşmesi

| Konu | Owner |
|------|--------|
| XLSX export | `PersonelExportService` + `GET /personeller/export.xlsx` |
| Export reconcile | DB `COUNT(*)` vs fetched unique `personel_id`; mismatch → `409 PERSONEL_EXPORT_RECONCILE_FAILED` |
| Hassas alanlar | TC, IBAN, ücret, telefon, adres, acil durum **dışlanır** |
| Karabük modeli | `sube` ve `calisma_lokasyonu` **ayrı kolonlar**; birbirinden türetilmez |
| Toplu create import | `PersonelImportDryRunService` / `PersonelImportApplyService` (CREATE_ONLY) |
| Lifecycle bulk dry-run | `PersonelLifecycleBulkDryRunService` / `POST /personeller/lifecycle-bulk/dry-run` |
| Lifecycle bulk apply | `PersonelLifecycleBulkApplyService` / `POST /personeller/lifecycle-bulk/apply` (production’da bu tur çağrılmaz) |

## Personel yaşam döngüsü — canonical owner matrisi (özet)

| İşlem | Route | Service owner | Audit |
|-------|-------|---------------|-------|
| Yeni personel | `POST /personeller` | `PersonelCreateService` | — |
| İşten ayrılma | `POST /surecler` (`ISTEN_AYRILMA`) | `SureclerController` | süreç kaydı + PASIF |
| Kalıcı şube | `POST /personeller/{id}/kalici-sube-degisikligi` | `PersonelKaliciSubeDegisikligiService` | `personel_sube_degisiklik_auditleri` |
| Org alanları (görev, dep, bölüm, birim, pozisyon, SGK, lokasyon) | `POST /personeller/{id}/organizasyon-degisikligi` | `PersonelOrganizasyonDegisikligiService` | `personel_organizasyon_degisiklik_auditleri` (083) |
| Kullanıcı rol/erişim | `PUT /yonetim/kullanicilar/{id}` | `YonetimController` | `user_erisim_degisiklik_auditleri` (082) |
| Generic personel PUT | `PUT /personeller/{id}` | `PersonellerController::update` | Org alanı değişimi **yasak** → canonical owner |

Terfi/unvan (personel `gorev_id`) ≠ kullanıcı uygulama rolü (`users.rol`); ayrı preimage/audit yolları.

## Global şube seçici

- `setActiveSubeId`: global roller boş `sube_ids` iken `session.sube_list` doğrular.
- `finalizeAuthSessionSube`: global çoklu şube varsayılanı `active_sube_id = null` (Tüm şubeler).
- `ShellHeaderActions`: global için “Tüm şubeler” seçeneği.

## Excel dry-run / apply güvenlik

- Dry-run SELECT-only; `personel_id` + `sicil_no` eşleştirme; çelişki → blocker.
- Ad-soyad ile otomatik eşleştirme yok.
- Apply yalnız `can_apply=true` + checksum pin.
- Bağımsız satır transaction modeli (`INDEPENDENT_ROW_TRANSACTION`).
