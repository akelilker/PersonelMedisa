# 75 — S74-C3-B1 Dakika Parity ve Projection Hardening Kapanış Checkpoint

---

## 1. Faz Bilgisi

| Alan | Değer |
|------|-------|
| Faz adı | S74-C3-B1 — Dakika altyapısı, puantaj parity ve projection hardening |
| Review kapanış | S74-C3-B1-R1 |
| Ön koşul commit | `f4e6920` — fix(ui): puantaj etki aday paneli sube ve yetki akisini duzelt |
| Kod commit | yerel commit (push yok) |
| Durum | `S74_C3B1_INFRASTRUCTURE_READY` |

Bu faz S74-C3 apply (`/uygula`) endpointinin **önkoşul altyapısıdır**. Apply UI/endpoint bu fazda yoktur.

---

## 2. Amaç

1. Geç kalma ve erken çıkış dakikalarını puantajda kayıpsız saklamak.
2. Aylık mühür snapshot'ında aynı dakika değerlerini korumak.
3. Hesap motorunu açık dakika alanlarını authoritative kabul edecek hale getirmek.
4. GÖREVDE için canonical dayanak tanımlamak.
5. Ücretsiz izin adaylarının `HAZIR` üretilmesini engellemek.
6. PHP ve TypeScript puantaj enum parity'sini güvenli biçimde düzeltmek.

---

## 3. Kilitli Owner Kararları

### 3.1 Geç / erken dakika

| Kolon | Tip | Tablolar |
|-------|-----|----------|
| `gec_kalma_dakika` | `INT UNSIGNED NULL` | `gunluk_puantaj`, `puantaj_aylik_muhur_satirlari` |
| `erken_cikis_dakika` | `INT UNSIGNED NULL` | `gunluk_puantaj`, `puantaj_aylik_muhur_satirlari` |

- Aday etki miktarı (`GEC_KALMA_DAKIKA` / `ERKEN_CIKIS_DAKIKA`) doğrudan bu kolonlara yazılacaktır (apply fazında).
- Migration dosyası: `api/migrations/012_gunluk_puantaj_gec_erken_dakika.sql` — **canlı çalıştırılmadı**.

### 3.2 GÖREVDE

```text
hareket_durumu = Geldi
dayanak = Gorevde_Calisma
hesap_etkisi = Tam_Yevmiye_Ver
```

- PHP canonical dayanak listesinde `Gorevde_Calisma`.
- `gunluk-kayit-presets` `GOREVDE` preset'i aynı üçlüyü üretir.

### 3.3 Ücretsiz izin projection

- `ucretli_mi = 0` resmi izin süreci → `INCELEME_GEREKLI` / `UCRETSIZ_IZIN_DESTEKLENMIYOR`
- `HAZIR` aday **üretilmez**.

### 3.4 Hesap motoru authoritative dakika

- `gec_kalma_dakika` / `erken_cikis_dakika` doluysa saat farkı hesabından **önce** kullanılır.
- `0` değeri explicit sıfır dakika olarak kabul edilir.

### 3.5 Enum parity (TS ↔ PHP)

| Alan | Canonical |
|------|-----------|
| Kesinti | `Yevmiye_Kes` (legacy `KESINTI_YAP` map) |
| Ücretli izin etkisi | `Ucretli_Izin` |
| Rapor etkisi | `Raporlu` |
| Telafi | `Telafi` |
| Görevde dayanak | `Gorevde_Calisma` |

---

## 4. Kapsam Dışı (Bilinçli)

- `/uygula` endpoint
- Router apply route
- Frontend **Uygula** butonu
- `UYGULANDI` aday state geçişi
- Canlı migration çalıştırma
- Canlı POST / DB mutation
- Push / deploy

---

## 5. Değişen Dosyalar (13 + R1 review)

**Yeni:**

- `api/migrations/012_gunluk_puantaj_gec_erken_dakika.sql`
- `docs/guncel/75-s74-c3-b1-dakika-parity-kapanis-checkpoint.md`
- `tests/unit/012-gunluk-puantaj-gec-erken-dakika-migration.source.test.ts`
- `tests/unit/puantaj-controller-gec-erken-dakika.source.test.ts`
- `tests/unit/gunluk-kayit-presets.contract.test.ts`

**Güncellenen:**

- `api/src/Controllers/PuantajController.php`
- `api/src/Services/BildirimPuantajEtkiProjectionService.php`
- `src/types/puantaj.ts`
- `src/api/puantaj.api.ts`
- `src/services/puantaj-hesap-motoru.ts`
- `src/hooks/usePuantaj.ts`
- `src/features/bildirimler/gunluk-kayit-presets.ts`
- `src/api/mock-demo.ts`
- `tests/php/BildirimPuantajEtkiProjectionTestRunner.php`
- `tests/unit/puantaj-hesap-motoru.test.ts`
- `tests/unit/puantaj.api.test.ts`
- `tests/unit/bildirim-puantaj-etki-projection.php-runtime.test.ts`
- `tests/e2e/helpers/mock-api.ts`
- `tests/e2e/personel-dosya.spec.ts`

---

## 6. Yerel Doğrulamalar

| Kontrol | Sonuç |
|---------|-------|
| `npm run typecheck` | geçmeli |
| `npm run test` | geçmeli (940+ test) |
| `npm run build` | geçmeli |
| `php tests/php/BildirimPuantajEtkiProjectionTestRunner.php` | 17 senaryo + R1 regression |

---

## 7. Git Başlangıç (B1 öncesi)

| Kontrol | Değer |
|---------|-------|
| Branch | `main` |
| HEAD / origin/main | `f4e6920f3890eb646a023e3782b896bd93743b02` |
| ahead/behind | `0 / 0` |
| Tracked tree | temiz |

B1 değişiklikleri commit edilmemiş working tree olarak uygulandı; R1 review kapıları tamamlandıktan sonra tek commit ile kapanır.

---

## 8. Sıradaki Faz

- **S74-C3-B2:** `HAZIR` aday → `gunluk_puantaj` apply endpoint ve UI
- Deploy öncesi: migration `012` canlı ortamda çalıştırılmalı (ayrı operasyon)

---

## 9. Final Karar

`S74_C3B1_INFRASTRUCTURE_READY` — C3-B2 apply önkoşulları karşılandı.
