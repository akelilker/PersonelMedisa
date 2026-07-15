# 76 — S74-C3 Puantaj Etki Adayı Uygula Kapanış Checkpoint

---

## 1. Doküman Amacı

Bu checkpoint, S74-C3 «Uygula» hattının (C3-A analiz → C3-B1 dakika altyapısı → C3-B2 apply backend → C3-B3 frontend Uygula) kod ve kontrat kapanışını, canlı doğrulama sınırlarını ve sıradaki faz önerisini tek yerde toplar.

**Son kilit kod commit:** `3355369` — `feat(ui): puantaj etki adayi uygula akisini ekle`
**Branch:** `main` (`origin/main` ile hizalı — `3355369c4abc0ea28082ff1485b2f5ed896859d4`)

---

## 2. Zincir Özeti

| Alt faz | Açıklama | Commit / referans | Kod durumu | Canlı doğrulama |
|---------|----------|-------------------|------------|-----------------|
| **C3-A** | Apply kontratı ön analizi | analiz-only | Kapalı | — |
| **C3-A-R1** | Owner karar matrisi | analiz-only | Kapalı | — |
| **C3-B1** | Dakika kolonları + projection kilidi | `695186a`, `6d2b365` | Kapalı | Migration `012` manuel uygulandı |
| **C3-B2** | `/uygula` backend + `BildirimPuantajEtkiApplyService` | `e2dd1bb` | Kapalı | Read-only guard smoke |
| **C3-B3** | Frontend Uygula + confirmation modal | `3355369` | Kapalı | Deploy retry + read-only UI smoke |
| **C3-B4** | Kontrollü canlı apply + idempotency | — | **Açık** | `NO_SAFE_LIVE_APPLY_FIXTURE` |

---

## 3. Tamamlanan Yetkinlikler (Kod)

- `HAZIR` aday için atomik `gunluk_puantaj` INSERT (`BildirimPuantajEtkiApplyService`).
- Mevcut puantaj satırı UPDATE edilmez; duplicate `(personel_id, tarih)` → `409 PUANTAJ_OLUSTU`.
- Mühürlü ay blokajı (`409 PERIOD_LOCKED`).
- Karar audit: snapshot'lar, `uygulama_hash`, `uygulanan_puantaj_id`.
- Idempotent tekrar: bütünlük korunursa `200` + `idempotent: true`.
- `gec_kalma_dakika` / `erken_cikis_dakika` authoritative alanları (migration `012`).
- Ücretsiz izin projection → `INCELEME_GEREKLI` / `UCRETSIZ_IZIN_MANUEL_INCELEME` (otomatik HAZIR yok).
- GÖREVDE canonical hedef: `Geldi` + `Gorevde_Calisma` + `Tam_Yevmiye_Ver`.
- MUHASEBE frontend: detay `HAZIR` + `puantaj.bildirim_etki.apply` → Uygula → AppModal onay → POST `/uygula`.
- Liste / detay / özet refetch; Yok Say akışı korundu.

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
| Toplu / sessiz apply | Yok; her karar tek aday + onay modalı |
| Canlı apply kanıtı | **Henüz yok** (B4 blokajı) |

---

## 6. Migration 012 — Canlı Durum

| Tablo | Kolonlar | Canlı |
|-------|----------|-------|
| `gunluk_puantaj` | `gec_kalma_dakika`, `erken_cikis_dakika` | Uygulandı (owner onaylı tek seferlik run) |
| `puantaj_aylik_muhur_satirlari` | aynı | Uygulandı |

`api/migrations` deploy otomasyonu dışındadır; tekrar çalıştırılmamalıdır.

---

## 7. Deploy ve Smoke Kanıtı (C3-B2 / C3-B3)

| Paket | Commit | CI | Deploy cPanel | Not |
|-------|--------|-----|---------------|-----|
| B2 apply API | `e2dd1bb` | `29291328279` success | success | Read-only `/uygula` guard |
| B3 Uygula UI | `3355369` | `29292315309` success | `29292358038` attempt 2 success | Canlı bundle örn. `index-Bd29xmV9.js` |

B3 read-only UI smoke: Uygula/Yok Say string'leri ve permission işaretleri canlı bundle'da; **canlıda HAZIR aday yok** (`LIVE_HAZIR_ADAY_YOK`).

---

## 8. C3-B4 Blokajı

**Final karar:** `NO_SAFE_LIVE_APPLY_FIXTURE`

- Canlıda güvenli HAZIR aday bulunamadı veya meşru kaynak üzerinden kontrollü üretim koşulları sağlanamadı.
- Tek `/uygula` mutation, idempotency POST ve puantaj satırı doğrulaması yapılmadı.
- Canlı aday/puantaj verisi bu oturumda değişmedi.

Bu nedenle S74-C3 **kod hattı kapalı**, **canlı apply E2E kanıtı açık** kabul edilir.

---

## 9. Bilinen Operasyonel Durumlar

- Şube 2 (Giresun) için aktif Birim Amiri eksikliği kod hatası değil; canlı veri/organizasyon eksikliği (C2B-R3 teşhisi geçerlidir).
- Canlı kontrollü smoke dönemi çoğunlukla `2026-06`; `2026-07` boş-state beklenen sonuç olabilir.
- Mock `user_id=4` canlı Birim Amiri olarak kullanılmaz.
- S74-B generate sonrası aday sayısı düşük; HAZIR yoğunluğu ortam verisine bağlıdır.

---

## 10. Sıradaki Faz Tespiti

| Seçenek | Artı | Eksi | Öneri |
|---------|------|------|-------|
| **A. S74-C3-B4 retry** | Apply/idempotency/audit canlı kanıtı | Gerçek DB mutation + güvenli fixture gerekir | **Öncelikli** — B4 blokajı kalkınca |
| **B. S74-D — Finans / bordro köprüsü ön analizi** | Ürün zincirini genişletir | Apply canlı kanıtı olmadan erken risk | B4 sonrası |
| **C. Toplu generate / operasyon otomasyonu** | Daha fazla HAZIR aday | Scope genişler; veri politikası gerekir | Acele edilmemeli |
| **D. Kapalı dönem / düzeltme workflow** | Mühür sonrası apply etkisi netleşir | Geniş karar fazı | Paralel ürün kararı |

**Önerilen sıradaki adım:** Owner onayı ve güvenli meşru HAZIR fixture ile **S74-C3-B4 kontrollü canlı apply ve idempotency doğrulamasının** tamamlanması. Kod/deploy tarafı hazır; eksik parça canlı mutation kanıtıdır.

---

## 11. Kapsam Dışı (Bu Checkpoint Sonrası Otomatik Açılmaz)

- Yeni apply kuralları veya overwrite davranışı
- Finans tabloları / bordro entegrasyonu
- Patron acknowledgment
- Migration `012` dışı şema değişikliği
- Sahte personel/bildirim üretimi

---

## 12. Kapanış Cümlesi

S74-C3 ile bildirim → puantaj etki adayı «Uygula» hattının backend, frontend, test ve kontrat omurgası tamamlanmış ve `3355369` canlıya dağıtılmıştır. Zincirin operasyonel kapanışı, güvenli canlı apply kanıtı (C3-B4) tamamlanana kadar **kısmi** sayılır; sıradaki en dar ve güvenli adım kontrollü canlı apply/idempotency fazıdır.
