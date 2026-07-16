# 78 — S75 Puantaj Çakışma Çözüm Final Kapanış Checkpoint

---

## 1. Final Karar

S75 mevcut puantaj çakışma çözüm hattı kod, migration, CI, deploy ve kontrollü canlı kabul katmanlarında tamamlanmıştır.

**Final karar:** `S75_FULLY_COMPLETE`

- **Canlı kapanış tarihi:** 16.07.2026
- **İlk S75 deploy SHA:** `86dbdfee98ce455284a3c8756447a224548d22da`
- **Payload fix SHA:** `36f28b137b74a5be459f1ff8674d510c75eabd23`
- **E2E tarih bağımsızlığı fix SHA:** `ed010b26069984a06fec8d8efa18b292271475c4`

Migration `015_bildirim_puantaj_etki_cakisma_cozumleri.sql` canlı `karmotor_medisa` şemasında mevcuttur. Düzeltme SHA'ları için CI, 269/269 E2E, otomatik cPanel deploy ve mutation-free smoke kapıları geçmiştir.

---

## 2. Canlı Kabul Modeli

Yeni fixture, ikinci generation, backfill, cleanup, DELETE veya canlı adaya doğrudan DB UPDATE yapılmadı. Daha önce oluşturulmuş tek personel / iki tarih modeli yeniden kullanıldı:

| Karar | Bildirim | Aday | Puantaj | Tarih | Sonuç |
|-------|----------|------|---------|-------|-------|
| Mevcut puantajı koru | `#8` | `#6` | `#6` | `2026-03-02` | Aday `YOK_SAYILDI`; puantajın tüm kolonları ve `updated_at` değeri değişmedi |
| Aday etkisiyle revize et | `#9` | `#7` | `#7` | `2026-03-03` | Aday `UYGULANDI`; aynı puantaj ID'si kontrollü UPDATE edildi |

Ortak zincir: Haftalık `#7` → Aylık `#5` → Genel Yönetici `#5/TAMAMLANDI`. Aday üretimi daha önce tamamlanmıştı; canlı kabul bu kayıtları yeniden üretmeden kullandı.

---

## 3. Projection ve Legacy Uyumluluğu

- Yeni projection'larda `S75_V2`, deterministic `etki_turu + etki_miktari + etki_birimi` canonical kolon semantiğidir.
- Mevcut `S74_V1` fixture'ları backfill edilmez. Dar fallback yalnız `INCELEME_GEREKLI + MEVCUT_PUANTAJ_VAR + S74_V1` kimliği, geçerli `source_snapshot/source_hash` ve eşleşen personel/tarih/bildirim kimliği için çalışır.
- Aday `#6` effective payload: `DEVAMSIZLIK_GUN / 1 / GUN`.
- Aday `#7` raw DB değerleri `etki_miktari=NULL`, `etki_birimi=NULL` olarak korundu; canlı detay DTO'su legacy snapshot'tan güvenle `GEC_KALMA_DAKIKA / 20 / DAKIKA` çözdü.
- Aday `#7` revize preview'u `gec_kalma_dakika=20`, `kaynak=BILDIRIM_ETKI_REVIZYON`, `kontrol_durumu=BEKLIYOR` üretti. Bu kanıt, source hash yeniden hesaplaması ve snapshot kimlik doğrulaması geçmeden oluşamaz.

---

## 4. Karar, Idempotency ve Conflict Kabulü

### Aday `#6` — Koru

- İlk UI kararı: HTTP `200`, `idempotent:false`.
- Aday: `YOK_SAYILDI`, `uygulama_modu=CAKISMA_COZUM`, `uygulanan_puantaj_id=NULL`.
- Puantaj `#6`: ID, tam-kolon SHA-256 fingerprint ve `updated_at` başlangıçla aynı.
- Aynı request body tek tekrar: HTTP `200`, `idempotent:true`; yeni audit veya puantaj UPDATE yok.
- Aynı expected puantaj ID/hash ile tek farklı karar: HTTP `409`, `REVISION_DECISION_CONFLICT`; kayıtlar değişmedi.

### Aday `#7` — Revize

- İlk UI kararı: HTTP `200`, `idempotent:false`.
- Aday: `UYGULANDI`, `uygulama_modu=CAKISMA_COZUM`, `uygulanan_puantaj_id=7`.
- Puantaj `#7`: aynı ID; `state=ACIK`, `muhur_id=NULL`, `hareket_durumu=Gec_Geldi`, `gec_kalma_dakika=20`, `erken_cikis_dakika=NULL`, `kaynak=BILDIRIM_ETKI_REVIZYON`, `kontrol_durumu=BEKLIYOR`.
- Aynı pre-revision expected hash içeren request body tek tekrar: HTTP `200`, `idempotent:true`; ikinci UPDATE/audit yok ve `updated_at` değişmedi.

---

## 5. Alan Sahipliği ve Concurrency

Revize sırasında korunan alanlar: `id`, `personel_id`, `tarih`, `giris_saati`, `cikis_saati`, `beklenen_giris_saati`, `beklenen_cikis_saati`, `gercek_mola_dakika`, kullanıcı açıklamaları ve `created_at`.

Aday etkisinin sahibi olduğu alanlar: `hareket_durumu`, `dayanak`, `hesap_etkisi`, `gec_kalma_dakika`, `erken_cikis_dakika`, `kaynak`, `kontrol_durumu`, `updated_at`.

Stale olabilecek türetilmiş alanlar güvenli biçimde `NULL`landı: `hesaplanan_mola_dakika`, `net_calisma_suresi_dakika`, `gunluk_brut_sure_dakika`, `hafta_tatili_hak_kazandi_mi`.

Concurrency kontratı canlıda `expected_puantaj_id + expected_puantaj_hash`, aynı-body retry ve farklı-karar conflict yollarıyla kabul edildi. Dönem mühürlü değildi; kararlar aynı `(şube, yıl, ay)` kilit protokolü altında çalıştı.

---

## 6. Audit, Hash ve Snapshot

Final audit tablosu tam iki satırdır:

| Audit | Aday | Puantaj | Tarih | Conflict class | Karar |
|-------|------|---------|-------|----------------|-------|
| `#1` | `#6` | `#6` | `2026-03-02` | `LEGACY_BELIRSIZ` | `MEVCUT_PUANTAJI_KORU` |
| `#2` | `#7` | `#7` | `2026-03-03` | `AMIR_KONTROL_EDILMIS` | `ADAY_ETKISIYLE_REVIZE_ET` |

Her iki satırda `snapshot_schema=S75_CONFLICT_RESOLUTION_V1`; `request_hash` ve `sonuc_hash` 64 karakter lowercase SHA-256; önceki/sonraki snapshot JSON'ları geçerlidir. Retry ve farklı-karar conflict yeni audit üretmedi; audit hash, snapshot ve zamanları değişmedi.

---

## 7. Final Sayımlar ve Korunan S74 Kayıtları

Final sayımlar: Bildirim `9`, Haftalık `7`, Aylık `5`, GY `5`, Aday `7`, Puantaj `7`, Audit `2`, dönem kilidi `2`, mühür `0`.

Karar öncesi ve sonrası tam DB dump tuple SHA-256 fingerprint karşılaştırmasıyla şu S74 kayıtları değişmeden kaldı:

- bildirim `#4/#5/#7`
- haftalık `#4`
- aday `#1/#3/#5`
- puantaj `#3/#5`
- Temmuz aylık `#1`
- Temmuz GY `#2`
- bu adaylardaki S74 source/apply snapshot ve hash alanları

Karşılaştırma sonucu: `11/11 MATCH`.

---

## 8. Owner ve Yetki

- Endpoint: `POST /puantaj/bildirim-etki-adaylari/{id}/cakisma-coz`
- Permission: yalnız `MUHASEBE` — `puantaj.bildirim_etki.resolve_conflict`
- Classification: `BildirimPuantajEtkiConflictClassificationService`
- Resolution: `BildirimPuantajEtkiConflictResolutionService`
- Projection/revize mapping: `BildirimPuantajEtkiPuantajMapper`
- Audit: `bildirim_puantaj_etki_cakisma_cozumleri` (`uq_bpecc_aday`)
- UI: `BildirimPuantajEtkiAdaylariSection.tsx`

---

## 9. Operasyonel Etiketlerin Statüsü

`NO_CLEAN_S75_PERIOD_AVAILABLE` ve `S75_LIVE_ACCEPTANCE_DEFERRED` backend hata kodu değildir. Bunlar önceki canlı kabul planlamasında kullanılan ve mevcut fixture reuse + kontrollü kabul ile aşılmış operasyonel değerlendirme etiketleridir; API kontratına veya kalıcı state modeline eklenmez.

---

## 10. Kapanış

`S75_PAYLOAD_FIX_DEPLOYED`, `S75_LEGACY_FIXTURE_LIVE_COMPATIBILITY_OK`, `S75_CONFLICT_KEEP_LIVE_OK`, `S75_CONFLICT_REVISE_LIVE_OK`, `S75_CONFLICT_IDEMPOTENCY_LIVE_OK`, `S75_SINGLE_PERSON_TWO_DATE_LIVE_OK` ve `S75_FULLY_COMPLETE` doğrulanmıştır.

S75 için açık deploy, migration, fixture kabulü veya dokümantasyon kapısı kalmamıştır. Etki adayı raporlama ve dönem kapanışı bağlantısı S75'i yeniden açmadan ayrı ürün fazı olarak ele alınır.
