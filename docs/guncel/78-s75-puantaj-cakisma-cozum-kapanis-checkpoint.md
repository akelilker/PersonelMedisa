# 78 — S75-BC Puantaj Çakışma Çözüm Kapanış Checkpoint

---

## 1. Doküman Amacı

Bu checkpoint, S75 «mevcut puantaj çakışması» (`PUANTAJ_OLUSTU`) kullanıcı yolunun kod, kontrat ve lokal test kapanışını tek yerde toplar.

**Final karar:** `S75_BC_CONFLICT_RESOLUTION_LOCAL_COMPLETE`

**Kapsam sınırı:** Lokal kod paketi — push, deploy ve canlı mutation **yoktur**.

---

## 2. Zincir Özeti

| Alt faz | Açıklama | Kod | Canlı |
|---------|----------|-----|-------|
| **S75-B** | Audit tablosu + classification + resolution servisleri | Kapalı | — |
| **S75-C** | `/cakisma-coz` controller/route + detay zenginleştirme | Kapalı | — |
| **S75-BC** | Frontend çakışma modalı + hook + E2E/unit testleri | Kapalı | — |

**S75 faz durumu:** lokal tam kapalı; canlı faz açılmamıştır.

---

## 3. Tamamlanan Yetkinlikler (Kod, Lokal)

- Apply/manuel apply `409 PUANTAJ_OLUSTU` sonrası MUHASEBE çakışma modalı.
- İki karar: `MEVCUT_PUANTAJI_KORU` → aday `YOK_SAYILDI`; `ADAY_ETKISIYLE_REVIZE_ET` → mevcut satır UPDATE + aday `UYGULANDI`.
- Yeni aday state'i tanımlanmaz; terminal state'ler S74 ile aynıdır.
- Çakışma sınıflandırması A–G (`BildirimPuantajEtkiConflictClassificationService`).
- Audit: `bildirim_puantaj_etki_cakisma_cozumleri` (migration `015`, aday başına tek kayıt).
- Puantaj concurrency: `expected_puantaj_id` + `expected_puantaj_hash`; stale → `PUANTAJ_STALE`.
- Aynı `request_hash` tekrarı → HTTP `200`, `idempotent: true`.
- Dönem kilidi: apply ile aynı `(sube, yıl, ay)` transaction protokolü.
- Revize: türetilmiş süre alanları NULL; giriş/çıkış/beklenen saat korunur.
- Revize yasak: resmî süreç dayanak + mühürlü puantaj.

---

## 4. Owner Dosya Grupları

| Alan | Dosya |
|------|-------|
| Classification | `api/src/Services/BildirimPuantajEtkiConflictClassificationService.php` |
| Resolution | `api/src/Services/BildirimPuantajEtkiConflictResolutionService.php` |
| Revize mapping | `api/src/Services/BildirimPuantajEtkiPuantajMapper.php` |
| Policy | `api/src/Services/BildirimPuantajEtkiDecisionPolicy.php` |
| Controller / route | `api/src/Controllers/BildirimPuantajEtkiAdaylariController.php`, `api/src/Router.php` |
| Permission | `api/src/Auth/RolePermissions.php`, `src/lib/authorization/role-permissions.ts` |
| Frontend panel + modal | `src/features/puantaj/components/BildirimPuantajEtkiAdaylariSection.tsx` |
| Hook | `src/hooks/useBildirimPuantajEtkiAdaylari.ts` |
| API client | `src/api/bildirim-puantaj-etki-adaylari.api.ts`, `src/api/endpoints.ts` |
| Display | `src/lib/bildirim-puantaj-etki-aday/display.ts` |
| CSS | `src/styles/modules/puantaj.css` |
| Migration | `api/migrations/015_bildirim_puantaj_etki_cakisma_cozumleri.sql` |
| Kontrat dokümanları | `docs/guncel/01`, `03`, `04`, `05`, `07`, `09` |

---

## 5. Ürün ve Hesap Sınırları

| Konu | S75-BC gerçeği |
|------|----------------|
| Tetikleyici | `/uygula` veya `/manuel-uygula` → `PUANTAJ_OLUSTU` |
| Yetki | Yalnız `MUHASEBE` — `puantaj.bildirim_etki.resolve_conflict` |
| Koru | Puantaj değişmez; aday `YOK_SAYILDI`, `uygulama_modu=CAKISMA_COZUM` |
| Revize | Aynı `gunluk_puantaj` id UPDATE; `kaynak=BILDIRIM_ETKI_REVIZYON` |
| Yeni INSERT | Yok |
| Finans / bordro | Otomatik girdi üretilmez |
| Canlı kanıt | **Yok** (bilinçli) |

---

## 6. Migration 015 — Durum

| Tablo | Amaç | Canlı |
|-------|------|-------|
| `bildirim_puantaj_etki_cakisma_cozumleri` | Çakışma karar audit (unique `aday_id`) | Uygulanmadı |

`api/migrations` deploy otomasyonu dışındadır; canlıya owner onayı olmadan çalıştırılmamalıdır.

---

## 7. Hash Şeması — `S75_CONFLICT_RESOLUTION_V1`

| Hash | Girdi |
|------|-------|
| `request_hash` | `aday_id`, `expected_state`, `karar_turu`, normalize `gerekce`, `expected_puantaj_id`, `expected_puantaj_hash` |
| `sonuc_hash` | `source_hash`, `conflict_class`, karar, puantaj id, önce/sonra concurrency payload, `karar_veren_user_id` |
| `current_puantaj_hash` | `BildirimPuantajEtkiPuantajMapper::canonicalPuantajConcurrencyPayload` (+ `updated_at`) |

Revize başarısında aday `uygulama_hash` = `sonuc_hash`.

---

## 8. Lokal Test Kanıtı

| Katman | Dosya / komut |
|--------|----------------|
| PHP runtime | `tests/php/BildirimPuantajEtkiConflictResolutionTestRunner.php` |
| PHP concurrency | `tests/php/BildirimPuantajEtkiConflictResolutionMysqlConcurrencyTestRunner.php` |
| Unit source | `tests/unit/bildirim-puantaj-etki-conflict-resolution*.test.ts` |
| Role guard | `tests/unit/role-permissions-puantaj-etki.test.ts`, `tests/e2e/api-role-guard.spec.ts` |
| E2E UI | `tests/e2e/puantaj-etki-adaylari.spec.ts` |
| Migration source | `tests/unit/015-bildirim-puantaj-etki-cakisma-cozumleri-migration.source.test.ts` |

Doğrulanan davranışlar: sınıf A–G, keep/revise/idempotent, `REVISION_DECISION_CONFLICT`, `PUANTAJ_SOURCE_PROTECTED`, revize türetilmiş alan invalidation, korunan saat alanları.

---

## 9. Bilinen Sınırlar

- Canlı deploy, CI pipeline ve kontrollü production fixture **yapılmamıştır**.
- Migration `015` yalnız repoda; canlı şema değişikliği beklenmez.
- S75 canlı kabul, ayrı owner onaylı faz olarak planlanmalıdır.

---

## 10. Sonraki Adım (Öneri)

1. Migration `015` schema-first canlı uygulama + deploy onayı
2. Kontrollü canlı çakışma fixture'ı ile keep/revise/idempotency kabulü
3. Etki adayı raporlama / dönem kapanışı bağlantısı (S75'ten bağımsız)

---

## 11. Kapsam Dışı (S75 Sonrası Otomatik Açılmaz)

- Yeni aday state tanımı
- Otomatik overwrite (kullanıcı kararı olmadan)
- Finans/bordro entegrasyonu
- Canlı mutation bu checkpoint kapsamında

---

## 12. Kapanış Cümlesi

S75-BC ile `PUANTAJ_OLUSTU` çakışma çözüm hattının backend, frontend, test ve kontrat paketi lokal olarak tamamlanmıştır. Faz **lokal kapalıdır** (`S75_BC_CONFLICT_RESOLUTION_LOCAL_COMPLETE`). Canlı faz açık değildir.
