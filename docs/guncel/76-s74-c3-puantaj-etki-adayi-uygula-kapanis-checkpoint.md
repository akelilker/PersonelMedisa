# 76 — S74-C3 Puantaj Etki Adayı Uygula Kapanış Checkpoint

---

## 1. Doküman Amacı

Bu checkpoint, S74-C3 «Uygula» hattının (C3-A analiz → C3-B1 dakika altyapısı → C3-B2 apply backend → C3-B3 frontend Uygula → C3-B4 kontrollü canlı apply) kod, kontrat ve canlı kabul kapanışını tek yerde toplar.

**Son kilit kod commit:** `3355369` — `feat(ui): puantaj etki adayi uygula akisini ekle`
**Canlı kabul:** S74-C3-B4 — `LIVE_APPLY_IDEMPOTENCY_PASSED`
**Final karar:** `S74_C3_FULLY_COMPLETE`

---

## 2. Zincir Özeti

| Alt faz | Açıklama | Commit / referans | Kod | Canlı |
|---------|----------|-------------------|-----|-------|
| **C3-A** | Apply kontratı ön analizi | analiz-only | Kapalı | — |
| **C3-A-R1** | Owner karar matrisi | analiz-only | Kapalı | — |
| **C3-B1** | Dakika kolonları + projection kilidi | `695186a`, `6d2b365` | Kapalı | Migration `012` canlıda |
| **C3-B2** | `/uygula` backend | `e2dd1bb` | Kapalı | Canlıda |
| **C3-B3** | Frontend Uygula + confirmation modal | `3355369` | Kapalı | Canlıda |
| **C3-B4** | Kontrollü canlı apply + idempotency | — | Kapalı | `LIVE_APPLY_IDEMPOTENCY_PASSED` |

**S74-C3 faz durumu:** tam kapalı (B1–B4).

---

## 3. Tamamlanan Yetkinlikler (Kod + Canlı)

- `HAZIR` aday için atomik `gunluk_puantaj` INSERT (`BildirimPuantajEtkiApplyService`).
- Mevcut puantaj satırı UPDATE edilmez; duplicate `(personel_id, tarih)` → `409 PUANTAJ_OLUSTU`.
- Mühürlü ay blokajı (`409 PERIOD_LOCKED`).
- Karar audit: snapshot'lar, `uygulama_hash`, `uygulanan_puantaj_id`.
- Idempotent tekrar: bütünlük korunursa `200` + `idempotent: true` — **canlıda doğrulandı**.
- `gec_kalma_dakika` / `erken_cikis_dakika` authoritative alanları (migration `012` canlıda).
- Ücretsiz izin projection → `INCELEME_GEREKLI` / `UCRETSIZ_IZIN_MANUEL_INCELEME`.
- GÖREVDE canonical hedef: `Geldi` + `Gorevde_Calisma` + `Tam_Yevmiye_Ver` — **canlı mapping doğrulandı**.
- MUHASEBE frontend Uygula akışı — **canlı UI apply başarılı**.

---

## 4. Owner Dosya Grupları

| Alan | Dosya |
|------|-------|
| Apply servis | `api/src/Services/BildirimPuantajEtkiApplyService.php` |
| Controller / route | `api/src/Controllers/BildirimPuantajEtkiAdaylariController.php`, `api/public/index.php` |
| Projection | `api/src/Services/BildirimPuantajEtkiProjectionService.php` |
| Puantaj parity | `api/src/Controllers/PuantajController.php` |
| Policy | `api/src/Services/BildirimPuantajEtkiDecisionPolicy.php` |
| Frontend panel | `src/features/puantaj/components/BildirimPuantajEtkiAdaylariSection.tsx` |
| Hook | `src/hooks/useBildirimPuantajEtkiAdaylari.ts` |
| API client | `src/api/bildirim-puantaj-etki-adaylari.api.ts` |
| Display / permission | `src/lib/bildirim-puantaj-etki-aday/display.ts` |
| Migration | `api/migrations/012_gunluk_puantaj_gec_erken_dakika.sql` |
| Kontrat dokümanları | `docs/guncel/04`, `05`, `07`, `09`, `03` |

---

## 5. Ürün ve Hesap Sınırları

| Konu | S74-C3 gerçeği |
|------|----------------|
| Köprü kapsamı | S73 üst onayı sonrası S74-B aday üretimi → MUHASEBE karar (Yok Say / Uygula) → yalnız seçili HAZIR adayda `gunluk_puantaj` INSERT |
| Finans / bordro | Otomatik finans adayı veya bordro girdisi üretilmez |
| Legacy aylık özet | `aylik_ozet` / `genel_yonetici_onayi.*` hattı değişmez |
| INCELEME_GEREKLI | Yalnız Yok Say; Uygula yasak |
| Ücretsiz izin | Otomatik apply desteklenmez |
| Canlı apply kanıtı | **Tamam** (C3-B4) |

---

## 6. Migration 012 — Canlı Durum

| Tablo | Kolonlar | Canlı |
|-------|----------|-------|
| `gunluk_puantaj` | `gec_kalma_dakika`, `erken_cikis_dakika` | Uygulandı |
| `puantaj_aylik_muhur_satirlari` | aynı | Uygulandı |

`api/migrations` deploy otomasyonu dışındadır; tekrar çalıştırılmamalıdır.

**Backup ayrımı:**

- Migration `012` öncesi backup kanıtı **yoktur**; bu eksiklik geriye dönük kapatılmış gibi gösterilmez.
- `karmotor_medisa.sql.gz` — 15 Temmuz 2026 tarihinde doğrulanan, migration sonrası ve C3-B4 mutation öncesi canlı snapshot'tır.
- Backup kanıtı: tarih `2026-07-15 08:38:46`, SHA256 `E3398457E917B7A815E458FB52CF2F086BCD9A3F388D9495A906DB22D9FF9302`, gzip açılmış, doğru veritabanı doğrulanmış, 20 `CREATE TABLE` ve 17 `INSERT` bulunmuş.
- Backup dosyası repoya eklenmez.

---

## 7. Deploy ve Smoke Kanıtı (C3-B2 / C3-B3)

| Paket | Commit | CI | Deploy cPanel | Not |
|-------|--------|-----|---------------|-----|
| B2 apply API | `e2dd1bb` | `29291328279` success | success | Read-only `/uygula` guard |
| B3 Uygula UI | `3355369` | `29292315309` success | `29292358038` attempt 2 success | Canlı bundle örn. `index-Bd29xmV9.js` |

B3 öncesi read-only smoke: canlıda HAZIR aday yoktu (`LIVE_HAZIR_ADAY_YOK`); B4 ile kontrollü fixture üzerinden apply kanıtlandı.

---

## 8. C3-B4 — Kontrollü Canlı Apply ve İdempotency Kabulü

**Final kararlar:**

- `LIVE_APPLY_IDEMPOTENCY_PASSED`
- `S74_C3B4_LIVE_APPLY_IDEMPOTENCY_OK`
- `S74_C3_FULLY_COMPLETE`

### Kontrollü kabul zinciri

| Alan | Değer |
|------|-------|
| Bildirim | `#3` |
| Bildirim türü | `GOREVDE` |
| Personel | `#1` |
| Tarih | `2026-07-15` |
| Haftalık mutabakat | `#3` |
| Aylık onay | `#1` |
| Genel Yönetici üst onayı | `#2` |
| Oluşan yeni aday | `#3` |
| Aday başlangıç state | `HAZIR` |
| UI apply | başarılı |
| Aday final state | `UYGULANDI` |
| Uygulanan günlük puantaj | `#3` |

### İdempotency

| Kontrol | Sonuç |
|---------|-------|
| İkinci apply POST | HTTP `200` |
| İkinci response | `idempotent: true` |
| Puantaj ID | değişmedi |
| Yeni puantaj satırı | oluşmadı |
| 64 karakterlik `uygulama_hash` | değişmedi |
| `sonraki_puantaj_snapshot` | değişmedi |
| Aday state | değişmedi |
| Karar audit alanları | değişmedi |

### Canonical canlı mapping (`gunluk_puantaj` #3)

| Alan | Değer |
|------|-------|
| `hareket_durumu` | `Geldi` |
| `dayanak` | `Gorevde_Calisma` |
| `hesap_etkisi` / yevmiye | `Tam_Yevmiye_Ver` |
| `state` | `ACIK` |
| `gec_kalma_dakika` | `NULL` |
| `erken_cikis_dakika` | `NULL` |

### UI özet sayaçları (kabul sonrası)

| State | Adet |
|-------|------|
| `HAZIR` | 0 |
| `INCELEME_GEREKLI` | 1 |
| `UYGULANDI` | 1 |

### API health

- HTTP `200`, `status: ok`

### Repo durumu (kabul sırasında)

- Kod değişikliği yok
- HEAD / `origin/main` senkrondu (`3355369`)
- Canlı kabul sırasında repo dosyası değişmedi

---

## 9. Fixture Niteliği (Aday #3 / Bildirim #3)

- Bu bildirim normal günlük operasyon kaydı değil; **kontrollü canlı kabul fixture'ıdır**.
- Bildirim açıklamasında bu durum açıkça belirtilmiştir.
- Kayıt sistemin gerçek onay zincirinden geçirilmiştir (mutabakat → aylık onay → GY üst onay → generate → apply).
- Doğrudan DB INSERT/UPDATE kullanılmamıştır.
- Fixture **silinmeyecek**, Yok Say yapılmayacak, DB'den temizlenmeyecek.
- Canlı kabul ve audit kanıtı olarak korunacaktır.
- «Gerçek operasyon kaydı» şeklinde yanlış ifade kullanılmaz.

---

## 10. Bilinen Operasyonel Durumlar

- Şube 2 (Giresun) aktif Birim Amiri eksikliği kod hatası değil; canlı veri eksikliği (C2B-R3 geçerlidir).
- Mock `user_id=4` canlı Birim Amiri olarak kullanılmaz.
- Kabul fixture'ı dışındaki aday yoğunluğu ortam verisine bağlıdır; `INCELEME_GEREKLI: 1` açık kayıt vardır.

---

## 11. Sonraki Geniş Faz Tespiti

### Aday 1 — MANUEL_INCELEME karar ve çözüm akışı

| Boyut | Değerlendirme |
|-------|---------------|
| Ürün değeri | Yüksek — `DIGER` / `INCELEME_GEREKLI` kayıtlarının operasyonel kapanışı |
| Kapsam | Karar modeli, UI/endpoint, süreç/puantaj bağlantısı |
| Risk | Orta — yanlış otomatik kapanış üretim verisini bozar |
| Migration | Muhtemelen yok; mevcut aday state altyapısı üzerinde |
| Canlı mutation | Düşük (karar fazı) / orta (çözüm uygulama fazında) |
| Ürün kararı | İnceleme kaydının hangi kararla kapatılacağı, manuel düzeltme sınırı |

### Aday 2 — Mevcut puantaj çakışması (`PUANTAJ_OLUSTU`) çözüm akışı

| Boyut | Değerlendirme |
|-------|---------------|
| Ürün değeri | Yüksek — apply öncesi çakışma gerçek üretim senaryosu |
| Kapsam | Kullanıcı yolu, audit, overwrite yasağı netliği |
| Risk | Yüksek — mevcut puantajın ezilmesi |
| Migration | Muhtemelen yok |
| Canlı mutation | Orta (kontrollü senaryo gerekir) |
| Ürün kararı | Çakışma sonrası düzeltme/iptal/reopen politikası |

### Aday 3 — Etki adaylarının raporlama ve dönem kapanışına bağlanması

| Boyut | Değerlendirme |
|-------|---------------|
| Ürün değeri | Orta-yüksek — operasyon görünürlüğü |
| Kapsam | Rapor/read model, aylık kapanış öncesi kontrol kapısı |
| Risk | Orta — scope genişleyebilir |
| Migration | Olası (rapor aggregate) |
| Canlı mutation | Düşük (read-only rapor fazı) |
| Ürün kararı | UYGULANDI / YOK_SAYILDI / İNCELEME dağılımı ve kapanış öncesi blok kuralları |

**Önerilen sonraki ana sprint:** **S74-D1 — MANUEL_INCELEME karar ve çözüm akışı ön analizi / karar fazı**

Gerekçe: Canlı kabul sonrası panelde `INCELEME_GEREKLI: 1` açık kayıt vardır; apply hattı kapandıktan sonra en dar ve ürün değeri yüksek boşluk, inceleme kayıtlarının operasyonel kapanış modelidir. `PUANTAJ_OLUSTU` ve raporlama fazları bunu takip etmelidir.

---

## 12. Kapsam Dışı (S74-C3 Sonrası Otomatik Açılmaz)

- Yeni apply kuralları veya overwrite davranışı
- Finans tabloları / bordro entegrasyonu
- Patron acknowledgment
- Migration `012` dışı şema değişikliği
- Kabul fixture'ının silinmesi veya temizlenmesi

---

## 13. Kapanış Cümlesi

S74-C3 ile bildirim → puantaj etki adayı «Uygula» hattının backend, frontend, test, kontrat ve kontrollü canlı apply/idempotency kanıtı tamamlanmıştır. Faz **tam kapalıdır** (`S74_C3_FULLY_COMPLETE`). Sıradaki önerilen geniş adım MANUEL_INCELEME karar ve çözüm akışıdır.
