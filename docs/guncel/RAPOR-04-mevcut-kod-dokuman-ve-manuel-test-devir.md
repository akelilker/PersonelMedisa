# RAPOR-04
# MEDİSA PERSONEL
# Mevcut Kod Durumu, Bildirim Onay Zinciri ve Manuel Test Devir Raporu

**Belge tarihi:** 15.07.2026

**Durum:** S70C-S74-C3 kod kapanışı ve canlı doğrulama güncel devir

## Bağlı kaynaklar

- RAPOR-01 — Ürün Reset ve Operasyon Omurgası
- RAPOR-02 — Mevzuat Uyum Matrisi
- RAPOR-03 — Şirket Parametreleri ve Onay Politikası
- Güncel repo HEAD: `3355369` (S74-C3 docs kapanışı öncesi uygulama kodu referansı)

RAPOR-01, RAPOR-02 ve RAPOR-03 repo içinde bağımsız dosya halinde bulunmamaktadır. Bu nedenle içerikleri bu belgede yeniden üretilmemiştir; repo dışı ürün referansları olarak sınıflandırılmıştır.

## 1. Raporun amacı

- Güncel çalışan kodu kayıt altına almak.
- S70C-S74-C3 kapsamında tamamlanan hattı belgelemek.
- Çalışan kod, hedef ürün davranışı ve aktif doküman ayrımını netleştirmek.
- Manuel test başlangıç noktasını sabitlemek.
- Sonraki geliştirmelerin yanlış domain'e bağlanmasını önlemek.

## 2. Güncel repo durumu

| Alan | Değer |
|---|---|
| Branch | `main` |
| HEAD | `3355369` |
| origin/main | `3355369` |
| Working tree | Belge yazımı öncesi temiz |
| Son commit | `feat(ui): puantaj etki adayi uygula akisini ekle` |

Bu doküman seti commit edildiğinde HEAD değişecektir. `3355369`, S74-C3 docs kapanışı öncesi uygulama kodu referansıdır.

## 3. Tamamlanan fazlar

| Faz | Kapsam | Backend | UI | Canlı doğrulama | Durum |
|---|---|---|---|---|---|
| S70C | Günlük bildirim | Tamam | Tamam | PASS | KAPANDI |
| S71 | Haftalık mutabakat | Tamam | Tamam | PASS | KAPANDI |
| S72 | Aylık bildirim onayı | Tamam | Tamam | PASS | KAPANDI |
| S73 | Genel Yönetici bildirim üst onayı | Tamam | Tamam | PASS | KAPANDI |
| S74-B | Onaylı bildirim → puantaj etki adayı generate | Tamam | — | PASS (kontrollü) | KAPANDI |
| S74-C1/C2 | Karar altyapısı + Yok Say | Tamam | Tamam (C2B) | PASS | KAPANDI |
| S74-C3 | HAZIR aday Uygula (B1–B4) | Tamam | Tamam | PASS (B4 canlı apply) | KAPANDI |

Bu kapanış yalnızca fazların kendi kapsamı içindir; ürünün, puantaj motorunun veya bordro zincirinin tamamlandığı anlamına gelmez.

## 4. Çalışan operasyon zinciri

```text
Birim Amiri günlük bildirimi oluşturur
  -> TASLAK
  -> gönderir
  -> GONDERILDI
  -> haftalık mutabakat oluşturur
  -> günlük kayıtlar HAFTALIK_MUTABAKATA_ALINDI
  -> ay içindeki haftalar tamamlanır
  -> aylık bildirim onayı oluşturulur
  -> aylık onay TAMAMLANDI
  -> Genel Yönetici bildirim üst onayı
  -> üst onay TAMAMLANDI
```

| Adım | Tablo sahibi | Endpoint | Permission | UI sahibi | Duplicate / guard |
|---|---|---|---|---|---|
| Günlük liste | `gunluk_bildirimler` | `GET /bildirimler` | `bildirimler.view` / scope guard | Bildirimler sayfası | Şube ve rol scope uygulanır |
| Günlük detay | `gunluk_bildirimler` | `GET /bildirimler/{id}` | `bildirimler.detail.view` | Bildirim detay sayfası | Sahiplik/scope guard uygulanır |
| Günlük oluşturma | `gunluk_bildirimler` | `POST /bildirimler` | `gunluk_bildirim.create` | BIRIM_AMIRI günlük kayıt formu | Başlangıç state'i `TASLAK` |
| Günlük güncelleme | `gunluk_bildirimler` | `PUT /bildirimler/{id}` | `gunluk_bildirim.update_own_open` | Düzenleme formu | Yalnız kendi açık kaydı |
| Günlük gönderme | `gunluk_bildirimler` | `POST /bildirimler/{id}/submit` | `gunluk_bildirim.submit` | Gönder aksiyonu | Geçersiz state `409` |
| Düzeltme isteme | `gunluk_bildirimler` | `POST /bildirimler/{id}/request-correction` | `gunluk_bildirim.request_correction` | Düzeltme iste aksiyonu | Yalnız uygun gönderilmiş kayıt |
| Günlük iptal | `gunluk_bildirimler` | `POST /bildirimler/{id}/iptal` | `gunluk_bildirim.update_own_open` | İptal aksiyonu | Geçersiz state `409` |
| Haftalık özet | `haftalik_bildirim_mutabakatlari` | `GET /haftalik-bildirim-mutabakatlari/ozet` | `haftalik_mutabakat.view` | Haftalık Mutabakat paneli | Read-only roller yalnız görüntüler |
| Haftalık approve | `haftalik_bildirim_mutabakatlari` | `POST /haftalik-bildirim-mutabakatlari` | `haftalik_mutabakat.approve` | BIRIM_AMIRI paneli | Tekrar approve `409` |
| Haftalık detay | `haftalik_bildirim_mutabakatlari` | `GET /haftalik-bildirim-mutabakatlari/{id}` | `haftalik_mutabakat.view` | Panel/detail verisi | Rol, sahiplik ve scope guard |
| Aylık özet | `aylik_bildirim_onaylari` | `GET /aylik-bildirim-onaylari/ozet` | `aylik_bildirim_onayi.view` | Aylık Bildirim Onayı paneli | Eksik hafta onayı bloklar |
| Aylık approve | `aylik_bildirim_onaylari` | `POST /aylik-bildirim-onaylari` | `aylik_bildirim_onayi.approve` | BIRIM_AMIRI paneli | Tekrar approve `409` |
| Aylık detay | `aylik_bildirim_onaylari` | `GET /aylik-bildirim-onaylari/{id}` | `aylik_bildirim_onayi.view` | Panel/detail verisi | Rol, sahiplik ve scope guard |
| GY üst onay özet | `genel_yonetici_bildirim_onaylari` | `GET /genel-yonetici-bildirim-onaylari/ozet` | `genel_yonetici_bildirim_onayi.view` | Genel Yönetici Bildirim Onayı paneli | S72 tamamlanmamış veya eksik hafta bloklar |
| GY üst onay approve | `genel_yonetici_bildirim_onaylari` | `POST /genel-yonetici-bildirim-onaylari` | `genel_yonetici_bildirim_onayi.approve` | GENEL_YONETICI paneli | Tekrar approve `409` |
| GY üst onay detay | `genel_yonetici_bildirim_onaylari` | `GET /genel-yonetici-bildirim-onaylari/{id}` | `genel_yonetici_bildirim_onayi.view` | Panel/detail verisi | Rol ve scope guard |

## 5. Rol sahipliği

Permission modeli yetkinin sahibidir. UI görünürlüğü tek başına yetki kanıtı değildir; backend permission ve scope guard'ları nihai kontroldür.

### BIRIM_AMIRI

- `gunluk_bildirim.create`
- `gunluk_bildirim.update_own_open`
- `gunluk_bildirim.submit`
- `haftalik_mutabakat.view`
- `haftalik_mutabakat.approve`
- `aylik_bildirim_onayi.view`
- `aylik_bildirim_onayi.approve`

### BOLUM_YONETICISI

- Günlük bildirimleri görür ve uygun gönderilmiş kayıtta düzeltme isteyebilir.
- Haftalık ve aylık bildirim panellerini read-only görür.
- Haftalık veya yeni aylık bildirim approve sahibi değildir.

### GENEL_YONETICI

- Haftalık ve aylık bildirim panellerini read-only görür.
- Yeni aylık bildirim onayını approve etmez.
- `genel_yonetici_bildirim_onayi.view` ve `genel_yonetici_bildirim_onayi.approve` sahibidir.
- Bildirimler sayfasındaki Genel Yönetici bildirim üst onayı panelini görür ve onaylar.
- Legacy `genel_yonetici_onayi.*` permission'ları ayrı domain'dir; S73 ile alias değildir.

### MUHASEBE

- Haftalık ve aylık bildirim panellerini read-only görür.
- Bu panellerde approve aksiyonu yoktur.

## 6. Canlı smoke kanıtı

### S70C-S72 ilk zincir (2026-07)

| Kayıt | ID | Kaynak |
|---|---:|---|
| Günlük Bildirim | 1 | Canlı API/UI smoke |
| Haftalık Mutabakat | 1 | Canlı API/UI smoke |
| Aylık Bildirim Onayı | 1 | Canlı API/UI smoke |

Canlı test ayı `2026-07`, tarih aralığı `2026-07-01 / 2026-07-31` olarak doğrulanmıştır. Aylık özette toplam bildirim `1`, mutabakata alınan `1`, eksik hafta `0` görülmüş; canlı UI veya console hatası gözlenmemiştir.

### S73 kontrollü canlı zincir (2026-06)

| Kayıt | ID | Kaynak |
|---|---:|---|
| Günlük Bildirim | 2 | Kontrollü canlı audit/test |
| Haftalık Mutabakat | 2 | Kontrollü canlı audit/test |
| Aylık Bildirim Onayı | 2 | Kontrollü canlı audit/test |
| Genel Yönetici Üst Onay | 1 | Kontrollü canlı audit/test |

Bağlam: ay `2026-06`, şube Merkez (`sube_id` 1), Birim Amiri `user_id` 3, `aylik_bildirim_onayi_id` 2, onaylayan `user_id` 1, state `TAMAMLANDI`, `onaylandi_at` `2026-07-12 07:29:17`.

Bu kayıtlar ürün seed'i veya gerçek bordro kaydı değil; kontrollü canlı doğrulama/audit zincirleridir. ID 1/1/1 ve ID 2/2/2/1 zincirleri korunur. Silme veya arşivleme kararı henüz alınmamıştır. Yetkisiz doğrudan DB silme yapılmamalıdır.

## RAPOR-04C — S73 Genel Yönetici Bildirim Üst Onayı Canlı Kapanışı

### Backend

| Alan | Değer |
|---|---|
| Commit | `3396f7ca7ed3a308d87b847f2c79901804ab291a` |
| Mesaj | `feat(api): genel yonetici bildirim ust onayi temelini ekle` |
| CI | #581 success |
| Deploy | cPanel #539 success |
| Migration | `api/migrations/008_genel_yonetici_bildirim_onaylari.sql` canlı `karmotor_medisa` DB'ye manuel uygulandı |

Migration notu: cPanel deploy SQL migration çalıştırmaz; `api/migrations` deploy kapsamında değildir. 008 tekrar çalıştırılmamalıdır.

### Frontend

| Alan | Değer |
|---|---|
| Commit | `3984755ca3691350a184ed8c8c9d922f00fd2f58` |
| Mesaj | `feat(ui): genel yonetici bildirim onay panelini ekle` |
| CI | #582 success |
| Deploy | cPanel #540 success |

### Test sayıları (S73-C kapanış kanıtı)

| Paket | Sonuç |
|---|---|
| Unit/integration | 841/841 |
| Full E2E | 235/235 |
| S73 E2E | 6/6 |
| Context E2E | 4/4 |
| Hedef role/legacy paketi | 55/55 |

### Canlı işlem özeti

- Kullanıcı açık izniyle yapıldı.
- Toplam POST: 1
- Duplicate POST: 0
- POST HTTP: 201
- Summary refetch: 200
- Detail GET: 200
- Refresh ve yeniden giriş kalıcılığı: doğrulandı

## RAPOR-04D — S74-C3 Puantaj Etki Adayı Uygula Kod Kapanışı

### Kod commit zinciri

| Paket | Commit | Mesaj |
|-------|--------|-------|
| C3-B1 dakika | `695186a`, `6d2b365` | dakika altyapısı + kontrat tamamlama |
| C3-B2 apply API | `e2dd1bb` | onayli bildirim puantaj etki adayi uygula endpointi |
| C3-B3 Uygula UI | `3355369` | puantaj etki adayi uygula akisini ekle |

### Migration 012

`api/migrations/012_gunluk_puantaj_gec_erken_dakika.sql` — `gunluk_puantaj` ve `puantaj_aylik_muhur_satirlari` için `gec_kalma_dakika` / `erken_cikis_dakika`. Owner onaylı tek seferlik canlı uygulama tamamlandı; tekrar çalıştırılmamalıdır.

### Deploy / smoke (B3)

| Alan | Değer |
|------|-------|
| CI | `29292315309` success |
| Deploy cPanel | `29292358038` attempt 2 success |
| Canlı HEAD | `3355369` |
| Canlı bundle örneği | `index-Bd29xmV9.js` |
| Read-only UI | Uygula/Yok Say işaretleri görünür |
| Canlı HAZIR aday (B3 öncesi) | Yok (`LIVE_HAZIR_ADAY_YOK`) |

### C3-B4 canlı kabul

**Final karar:** `LIVE_APPLY_IDEMPOTENCY_PASSED` / `S74_C3B4_LIVE_APPLY_IDEMPOTENCY_OK` / `S74_C3_FULLY_COMPLETE`

| Alan | Değer |
|------|-------|
| Bildirim | `#3` (GOREVDE, kontrollü kabul fixture'ı) |
| Personel / tarih | `#1` / `2026-07-15` |
| Onay zinciri | mutabakat `#3`, aylık `#1`, GY üst onay `#2` |
| Aday | `#3` HAZIR → UYGULANDI |
| Puantaj | `#3` INSERT |
| İkinci POST | HTTP `200`, `idempotent: true` |
| Mapping | `Geldi` + `Gorevde_Calisma` + `Tam_Yevmiye_Ver` |
| UI özet | HAZIR `0`, INCELEME `1`, UYGULANDI `1` |

Fixture korunur; silinmez, Yok Say yapılmaz, DB temizliği yapılmaz. Gerçek operasyon kaydı değildir.

**Backup (C3-B4 öncesi):** `karmotor_medisa.sql.gz` — `2026-07-15 08:38:46`, SHA256 `E3398457…FF9302`, migration `012` sonrası snapshot. Migration `012` öncesi backup kanıtı yoktur.

### Test kanıtı (B3 yerel kapanış)

| Paket | Sonuç |
|-------|-------|
| typecheck / unit / build | geçti |
| E2E `puantaj-etki-adaylari` | 8/8 |

Detay checkpoint: `docs/guncel/76-s74-c3-puantaj-etki-adayi-uygula-kapanis-checkpoint.md`

## RAPOR-04E — S74-D1/D3 Kontrollü Canlı Manuel Apply ve Idempotency Kabul Kanıtı

**Kapanış etiketi:** `S74_D1D3_CLOSED_MANUAL_APPLY_IDEMPOTENCY_VERIFIED`

**Başarılı canlı doğrulama etiketi:** `S74_D1D3_MANUAL_APPLY_IDEMPOTENCY_OK`

### Tam veritabanı yedeği

| Alan | Kanıt |
|------|-------|
| Dosya | `C:\Users\Akel\Downloads\karmotor_medisa (10).sql` |
| Boyut | 49.837 bayt |
| Zaman | 15.07.2026 19:41:40 |
| SHA256 | `3801A93B389433E3E17DAF142D5F28C56870C075F50CD3B4009FF85330F548F2` |
| İçerik | Doğru veritabanı başlığı; 20 `CREATE TABLE`; 17 `INSERT`; transaction ve `COMMIT` mevcut |

Yedek yalnız repo dışı canlı kabul kanıtıdır; repo içine alınmamış ve commit edilmemiştir.

### Kontrollü test zinciri

| Alan | Sonuç |
|------|-------|
| Temiz dönem | `2026-05` |
| Hafta | 11–17 Mayıs 2026 |
| Operasyon tarihi | `2026-05-15` |
| Bildirim | `#6`, `DIGER` |
| Haftalık mutabakat | `#5` |
| Aylık onay | `#3` |
| Genel Yönetici onayı | `#3` |
| Tek generation | Aday `#4`, başlangıç durumu `INCELEME_GEREKLI` |
| Tek UI manuel apply | `GOREVDE_CALISILMIS_GUN` |
| Oluşan puantaj | `#4` |
| Aynı body ile tek idempotency POST | HTTP `200`, `idempotent: true` |

Üçüncü apply, conflict denemesi veya ek generation yapılmadı.

### Canlı UI son durumu

| Alan | Sonuç |
|------|-------|
| Aday `#4` | Uygulandı |
| Uygulama modu | Manuel |
| Manuel karar | `GOREVDE_CALISILMIS_GUN` |
| Uygulanan puantaj | `#4` |
| Mayıs özeti | Toplam `1`; inceleme gereken `0`; uygulanan `1` |

### Veri artışları ve koruma kanıtı

| Veri | Artış |
|------|-------|
| Bildirim | +1 |
| Haftalık mutabakat | +1 |
| Aylık onay | +1 |
| Genel Yönetici onayı | +1 |
| Aday | +1 |
| Puantaj | +1 |

Tüm artışlar beklenen değerlerle birebir uyumludur.

- Bildirim `#4` değişmedi: durum `TASLAK`, tarih `2026-07-15`.
- Bildirim `#5` değişmedi.
- Haftalık mutabakat `#4` değişmedi.
- Aday `#1` değişmedi.
- Aday `#3` ve hash değerleri değişmedi.
- Mevcut Temmuz aylık onayı değişmedi.
- Mevcut Temmuz Genel Yönetici onayı değişmedi.

### Repo kapanış kapısı

| Alan | Sonuç |
|------|-------|
| Branch | `main` |
| HEAD | `400b2e33bc0955281e6ffab62d3729565b388b2c` |
| origin/main | `400b2e33bc0955281e6ffab62d3729565b388b2c` |
| Ahead / behind | `0 / 0` |
| Working tree | Temiz |
| `git diff --check` | Temiz |

### O tarihte kaydedilen kapanış kararı

S74-D1/D3 kontrollü canlı manuel apply ve idempotency doğrulaması başarıyla tamamlandı. Manuel karar yalnızca bir kez puantaja uygulandı; aynı request body ile yapılan tekrar çağrısı idempotent sonuç verdi ve mükerrer puantaj üretmedi. Beklenen veri artışları birebir doğrulandı, korunan kayıtlar değişmedi ve ek mutation yapılmadı. S74-D1/D3 zinciri kapatıldı.

Bu canlı kabul kanıtı geçerliliğini korur; ancak sonraki nihai kod denetiminde apply ile aylık mühürleme/snapshot arasında veri bütünlüğü yarışı bulunduğundan “zincir kapatıldı” kararı teknik nihai kapanış olarak supersede edilmiştir. Güncel karar: `S74_REOPEN_REQUIRED`.

## RAPOR-04F — S74-D1/D3R Dönem Kilidi Hardening (Lokal)

**Durum etiketi:** `S74_D1D3_PERIOD_LOCK_HARDENING_LOCAL_COMPLETE`

- Additive migration `014_puantaj_donem_kilitleri.sql`, `(sube_id, yil, ay)` primary key'li guard tabloyu tanımlar.
- `PuantajDonemKilidiService`, caller transaction içinde guard satırını hazırlar ve production MySQL/MariaDB'de `SELECT ... FOR UPDATE` uygular.
- Kilit sırası generate, otomatik apply, manuel apply, doğrudan günlük puantaj upsert ve aylık mühürleme yollarında dönem kilidi → owner row lock/write şeklinde tekilleştirilmiştir.
- Aylık mühürün existence kontrolü dönem kilidi altına taşınmış; snapshot ve seal INSERT aynı transaction içinde kalmıştır.
- PDO/SQLite entegrasyonu manuel apply transaction, rollback, tek commit, aynı-body idempotency ve farklı-karar conflict davranışlarını doğrular. Row-lock kanıtı olarak kullanılmaz.
- İzole lokal MariaDB 11.4.12/InnoDB iki bağlantılı concurrency runner'ı otomatik apply/manual apply/direct upsert ↔ seal bekleme sırasını, seal sonrası write engelini, rollback lock release'i, iki apply yarışında tek puantajı, farklı dönem bağımsızlığını ve ilk guard-row oluşturma yarışını doğrular.
- Manuel endpoint rol regresyonu unauthenticated `401`, yalnız MUHASEBE write, diğer roller `403` ve cross-branch `403` kontratını kapsar.
- Lokal doğrulama: typecheck başarılı; unit/integration `999/999`; build başarılı; hedef rol E2E `46/46`; full E2E `262/262`.

Bu bölüm lokal hardening anının tarihsel kaydıdır. Sonraki owner onaylı canlı migration, deploy ve kontrollü kabul `RAPOR-04G` ile tamamlanmıştır.

## RAPOR-04G — S74 Dönem Kilidi Deploy ve Final Canlı Kabul

**Dönem kilidi etiketi:** `S74_PERIOD_LOCK_DEPLOYED`

**Canlı manuel apply etiketi:** `S74_MANUAL_APPLY_LIVE_ACCEPTANCE_OK`

**Nihai kapanış etiketi:** `S74_FULLY_COMPLETE`

### Schema-first yayın kanıtı

| Alan | Kanıt |
|------|-------|
| İşlem öncesi yedek | `C:\Users\Akel\Downloads\karmotor_medisa (11).sql` |
| Yedek zamanı / boyutu | `15.07.2026 22:27:01 +03:00` / `54.611` bayt |
| Yedek SHA256 | `0AABA2461C1D070AFA82AC1E5FF0364530480645568AEB9C692FBBB6334B34BA` |
| Yedek içeriği | Doğru DB başlığı; 20 `CREATE TABLE`; 17 `INSERT`; transaction ve `COMMIT` mevcut |
| Migration | `014_puantaj_donem_kilitleri.sql` |
| Migration SHA256 | `D30FC045402D79634869412CD401EBC78481B24DE49938612B8FC6FDBC986820` |
| Kod SHA | `966cef64203b6f2fbc98733653918c37cb5744df` |
| CI | Run `29444965013`, attempt `1`, success |
| Otomatik cPanel deploy | Run `29445026076`, attempt `1`, success |
| Smoke | API health, auth guard, frontend root, JS ve CSS asset kapıları geçti |

Migration 014 yalnız additive guard tabloyu oluşturdu. Migration öncesi/sonrası bildirim, haftalık, aylık, GY, aday ve puantaj sayıları ile korunan kayıt fingerprint'leri aynı kaldı. Hardening kodu migration sonrasında deploy edildi; tablo yokken fail-open davranış yoktur.

### Kontrollü final fixture ve canonical onay zinciri

| Alan | Sonuç |
|------|-------|
| Temiz dönem | `2026-04` |
| Hafta | `2026-04-13` – `2026-04-19` |
| Operasyon tarihi | `2026-04-15` |
| Bildirim | `#7`, `DIGER` |
| Haftalık mutabakat | `#6`, `TAMAMLANDI` |
| Aylık bildirim onayı | `#4`, `TAMAMLANDI` |
| Genel Yönetici üst onayı | `#4`, `TAMAMLANDI` |
| Tek generation | Aday `#5`, `INCELEME_GEREKLI`, `MANUEL_INCELEME`, `DIGER_MANUEL_INCELEME` |
| Dönem guard satırı | `(sube_id=1, yil=2026, ay=4)`, tek canonical satır |
| Tek UI manuel apply | `GOREVDE_CALISILMIS_GUN`, miktar `NULL` |
| Oluşan puantaj | `#5` |
| Aynı body ile tek idempotency POST | HTTP `200`, `idempotent: true`, aynı puantaj `#5` |

İkinci fixture, üçüncü apply, farklı-body conflict, ek generation veya cleanup yapılmadı.

### Audit, snapshot, hash ve puantaj kanıtı

- Aday `#5`: `UYGULANDI`, `uygulama_modu=MANUEL`, karar veren user `#2`, karar zamanı `2026-07-15 20:01:15` (DB zamanı).
- Source hash: `5ba5ba509f1db627fd1032ff62fc3859af39db5490546ba38c56a9d87f6e3e7f`.
- Apply hash: `5caca0cc8515f2d2e68242ce6850a3330a2739b6ba035424717aae5c9da936de`.
- Önceki puantaj snapshot `NULL`; sonraki snapshot şeması `S74_MANUAL_APPLY_V1`.
- Puantaj `#5`: `ACIK`, `Geldi`, `Gorevde_Calisma`, `Tam_Yevmiye_Ver`, kaynak `BILDIRIM_ETKI_ADAYI`, kontrol `BEKLIYOR`, mühür `NULL`.
- Idempotency çağrısı karar zamanı, gerekçe, source hash, apply hash, snapshot ve puantaj ID değerlerini değiştirmedi; mükerrer puantaj üretmedi.

### Satır sayıları

| Alan | Başlangıç | Yeni bildirim/onay zinciri | Generation | İlk apply | Idempotency |
|---|---:|---:|---:|---:|---:|
| Günlük bildirim | 6 | 7 | 7 | 7 | 7 |
| Haftalık | 5 | 6 | 6 | 6 | 6 |
| Aylık | 3 | 4 | 4 | 4 | 4 |
| GY üst onay | 3 | 4 | 4 | 4 | 4 |
| Aday | 4 | 4 | 5 | 5 | 5 |
| Puantaj | 4 | 4 | 4 | 5 | 5 |
| `INCELEME_GEREKLI` | 2 | 2 | 3 | 2 | 2 |
| `UYGULANDI` | 2 | 2 | 2 | 3 | 3 |
| `MANUEL` | 1 | 1 | 1 | 2 | 2 |

### Korunan kayıtlar ve nihai karar

- Bildirim `#4`: `TASLAK`, `2026-07-15`, değişmedi.
- Bildirim `#5`, haftalık mutabakat `#4` ve aday `#1` değişmedi.
- Aday `#3` source/apply hash ve snapshot değerleri değişmedi.
- Mevcut Temmuz aylık ve Genel Yönetici onayları değiştirilmedi.

Apply–seal yarışı için bulunan açık ortak dönem kilidi protokolüyle giderilmiş; lokal MariaDB/InnoDB yarış testleri, canlı schema-first yayın, kontrollü generation, manuel apply ve aynı-body idempotency kabulü birlikte tamamlanmıştır. `S74_REOPEN_REQUIRED` artık güncel karar değildir; S74 nihai durumu `S74_FULLY_COMPLETE` olarak kapatılmıştır.

## 7. Tamamlanmayan ürün halkaları

Aşağıdakiler bug değil, henüz geliştirilmemiş sonraki ürün halkalarıdır:

1. Patron gördü/not/onay katmanı (tamamlanmış domain/API/UI akışı yok).
2. Bildirim ile tam otomatik puantaj/bordro köprüsü (S74-C3 yalnız sınırlı apply INSERT; finans/bordro dışı).
3. Merkezi şirket parametreleri.
4. Nihai bordro ön izleme.
5. Bordro kesinleştirme.
6. Gerçek üretim kullanıcı ve rol hazırlığı.
7. Canlı test veri politikası.

S73 Genel Yönetici bildirim üst onayı operasyonel audit/onay kaydıdır; puantaj, bordro veya legacy aylık kapanışı otomatik etkilemez.

## 8. Legacy yapıların durumu

| Yapı | Domain | Güncel sınır |
|---|---|---|
| `aylik_ozet_satirlari` | Personel x ay puantaj/rapor özeti | Yeni S72 aylık bildirim onayı değildir |
| `aylik_kapanis_state` | Legacy aylık aggregate state | Yeni bildirim onayıyla otomatik bağlı değildir |
| `puantaj_aylik_muhurleri` | Puantaj teknik mühür/snapshot | Bildirim onay zincirinden ayrı state flow'dur |
| `aylik_bildirim_onaylari` | Yeni S72 bildirim onay domain'i | BIRIM_AMIRI aylık bildirim onayıdır |
| `genel_yonetici_bildirim_onaylari` | Yeni S73 Genel Yönetici bildirim üst onayı | Legacy `genel_yonetici_onayi.*` ve `aylik-ozet` ile aynı domain değildir |

Bu yapılar arasında otomatik bağ yoktur. Legacy aylık özet, legacy Genel Yönetici onayı, puantaj mührü, yeni aylık bildirim onayı ve S73 üst onayı aynı süreç gibi yorumlanmamalıdır.

## 9. Manuel teste geçiş kararı

**MANUEL TESTE HAZIR — S73 DOKÜMANTASYON SENKRONU SONRASI**

- S70C-S73 role-smoke ve mevcut modüller manuel teste hazırdır.
- Patron acknowledgment ve bordro uçtan uca zinciri hazır değildir.

## 10. Manuel test rol matrisi

| Test başlığı | BIRIM_AMIRI | BOLUM_YONETICISI | GENEL_YONETICI | MUHASEBE |
|---|---|---|---|---|
| Login/yönlendirme | Aktif test | Aktif test | Aktif test | Aktif test |
| Menü görünürlüğü | Aktif test | Aktif test | Aktif test | Aktif test |
| Personel listesi | Read-only test | Aktif test | Aktif test | Aktif test |
| Personel detay | Read-only test | Aktif test | Aktif test | Aktif test |
| Süreçler | Read-only test | Aktif test | Aktif test | Aktif test |
| Günlük bildirim create | Aktif test | Yetkisiz/gizli test | Yetkisiz/gizli test | Yetkisiz/gizli test |
| Günlük bildirim edit | Aktif test | Yetkisiz/gizli test | Yetkisiz/gizli test | Yetkisiz/gizli test |
| Günlük bildirim submit | Aktif test | Yetkisiz/gizli test | Yetkisiz/gizli test | Yetkisiz/gizli test |
| Düzeltme isteği | Yetkisiz/gizli test | Aktif test | Aktif test | Yetkisiz/gizli test |
| Haftalık mutabakat görünümü | Aktif test | Read-only test | Read-only test | Read-only test |
| Haftalık approve | Aktif test | Yetkisiz/gizli test | Yetkisiz/gizli test | Yetkisiz/gizli test |
| Aylık onay görünümü | Aktif test | Read-only test | Read-only test | Read-only test |
| Aylık approve | Aktif test | Yetkisiz/gizli test | Yetkisiz/gizli test | Yetkisiz/gizli test |
| GY üst onay görünümü | Yetkisiz/gizli test | Yetkisiz/gizli test | Aktif test | Yetkisiz/gizli test |
| GY üst onay approve | Yetkisiz/gizli test | Yetkisiz/gizli test | Aktif test | Yetkisiz/gizli test |
| Puantaj | Read-only test | Aktif test | Aktif test | Aktif test |
| Raporlar | Aktif test | Aktif test | Aktif test | Aktif test |
| Finans | Yetkisiz/gizli test | Aktif test | Aktif test | Aktif test |
| Şube scope | Aktif test | Aktif test | Aktif test | Aktif test |
| Console/network hata kontrolü | Aktif test | Aktif test | Aktif test | Aktif test |
| Mobil görünüm | Aktif test | Aktif test | Aktif test | Aktif test |
| Masaüstü görünüm | Aktif test | Aktif test | Aktif test | Aktif test |

## 11. Manuel test sırası

1. BIRIM_AMIRI
2. BOLUM_YONETICISI
3. GENEL_YONETICI
4. MUHASEBE
5. Şube-cross scope kontrolleri
6. Mobil/PWA
7. Masaüstü
8. Console/network
9. Test veri değerlendirmesi

## 12. Sonraki geliştirme kapısı

S74-D1/D3R hardening, migration 014, canlı deploy ve kontrollü final kabul tamamlanmıştır. S74 için açık teknik kapanış kapısı kalmamıştır.

**S75 (canlı tam kapanış):** Migration `015` canlıdır; ilk S75 deploy `86dbdfe`, payload fix `36f28b1`, E2E tarih bağımsızlığı fix `ed010b2` ile yayınlanmıştır. Mevcut fixture reuse ile aynı personelin iki tarihinde aday `#6` Koru ve aday `#7` Revize kararları; aynı-body idempotency, tek farklı-karar conflict, audit/hash/snapshot ve alan sahipliği canlıda doğrulanmıştır. Final etiket: `S75_FULLY_COMPLETE`. Detay: `docs/guncel/78-s75-puantaj-cakisma-cozum-kapanis-checkpoint.md`.

Sonraki bağımsız ürün başlıkları yalnız etki adayı raporlama ve dönem kapanışı bağlantısıdır. Bunlar `S74_FULLY_COMPLETE` veya `S75_FULLY_COMPLETE` kararını geri açmaz.

Finans/bordro genişlemesi bu kararlar netleşmeden önerilmez.
