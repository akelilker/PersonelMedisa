# 62 — Revizyon Talebi Correction Layer Contract Kapanış Checkpoint

---

## 1. Faz Bilgisi

| Alan | Değer |
|------|-------|
| Faz adı | 61 — Revizyon Talebi Correction Layer Contract Kod Fazı |
| Karar dokümanı | **60** — Revizyon Talebi Correction Layer Kararı |
| Kod commit | `b4e1ed1` — Add revizyon talebi correction layer contract |
| CI | #353 / success |
| Deploy cPanel | #331 / success |
| Durum | Kapandı |

---

## 2. Amaç

Bu checkpoint, 61 kod fazında correction layer contract kapsamının tamamlandığını kayıt altına alır. Fazın amacı, ONAYLANDI revizyon taleplerinin kapanmış snapshot'ı overwrite etmeden ayrı correction event üretmesi, `correction_event_id` bağlantısı kurması ve read model overlay için pure helper altyapısını sağlamasıdır.

---

## 3. Önceki Zincir

- **50** — Kapalı dönem / audit workflow kararı
- **51** — Haftalık kapanış revizyon talebi kararı
- **52** — Revizyon talebi rol/yetki kararı
- **53** — Rol yetki matrisi revizyon permission güncellemesi
- **54** — Revizyon talebi contract kod iskeleti
- **55** — Revizyon talebi contract kapanış checkpoint
- **56** — Revizyon talebi scope/backend enforcement kararı
- **57** — Revizyon talebi mock scope enforcement kod fazı
- **58** — Mock scope enforcement checkpoint
- **59** — Gerçek backend scope enforcement kararı
- **60** — Correction layer kararı
- **61** — Correction layer contract kod fazı

---

## 4. Kapsam

Bu fazda yapılanlar:

- Correction event type contract oluşturuldu.
- Correction hata kodları tanımlandı.
- Revizyon tipi → correction tipi mapping helper oluşturuldu.
- Delta hesaplama contract helper'ı eklendi.
- Correction üretim / iptal state helper'ları oluşturuldu.
- Read model overlay helper'ı eklendi.
- Correction API client oluşturuldu.
- Correction endpoint grubu eklendi.
- Mock correction store ve handler'lar eklendi.
- Approve → correction üretim bağlantısı kuruldu.
- Idempotency kuralı eklendi.
- Correction finance mask genişletildi.
- Unit ve mock integration testleri eklendi.

---

## 5. Değişen Dosyalar

**Yeni:**

- `src/types/revizyon-correction.ts`
- `src/lib/revizyon-talebi/revizyon-correction-map.ts`
- `src/lib/revizyon-talebi/revizyon-correction-state.ts`
- `src/lib/revizyon-talebi/revizyon-correction-overlay.ts`
- `src/api/revizyon-correction.api.ts`
- `tests/unit/revizyon-correction-state.test.ts`
- `tests/unit/revizyon-correction-overlay.test.ts`
- `tests/unit/revizyon-correction.api.test.ts`

**Güncellenen:**

- `src/api/endpoints.ts`
- `src/api/mock-demo.ts`
- `src/lib/revizyon-talebi/revizyon-scope.ts`

---

## 6. Correction Type Contract

Correction event ayrı entity olarak tanımlandı. Revizyon talebinin kendisi değiştirilmeden correction etkisi ayrı event üzerinden izlenir.

**Correction tipleri:**

- `GIRIS_CIKIS_DUZELTME`
- `MOLA_DUZELTME`
- `DEVAMSIZLIK_DUZELTME`
- `SERBEST_ZAMAN_ETKI_DUZELTME`
- `KAPANIS_HESAP_REVIZYONU`
- `BORDRO_ETKI_NOTU`

**Correction event alanları içinde:**

- `revizyon_talebi_id`
- `personel_id`
- `hafta_baslangic`
- `hafta_bitis`
- `etkilenen_tarih`
- `kaynak_tipi`
- `kaynak_id`
- `correction_tipi`
- `onceki_deger`
- `yeni_deger`
- `delta_dakika`
- `delta_gun`
- `bordro_etki_var_mi`
- `bordro_etki_tipi`
- `audit_ref`
- `snapshot_ref`
- iptal bilgileri

bulunur.

---

## 7. Correction Üretim Kararı

Correction yalnız ONAYLANDI revizyon talepleri için üretilebilir. ONAYLANDI olmayan talepler `CORRECTION_NOT_ALLOWED_FOR_STATE` alır.

**Idempotency:**

- Talep üzerinde `correction_event_id` varsa ikinci üretim `CORRECTION_ALREADY_EXISTS` döner.
- Store içinde aynı `revizyon_talebi_id` için correction varsa ikinci üretim engellenir.
- Approve path ve `correction-uret` endpoint'i aynı `persistDemoCorrectionForTalep` helper üzerinden çalışır.

**Approve davranışı:**

- `ONAY_BEKLIYOR` talep onaylandığında correction üretim kontrolü yapılır.
- Correction üretilemezse approve state'i yanlış mutate edilmez.
- Correction üretilirse talep `ONAYLANDI` olur ve `correction_event_id` set edilir.

---

## 8. Read Model Overlay Kararı

Read model overlay helper contract seviyesinde kuruldu.

**Davranış:**

- `iptal_edildi_mi` false olan correction'lar aktif kabul edilir.
- Aynı `kaynak_tipi` + `kaynak_id` + `etkilenen_tarih` + `correction_tipi` için son `olusturma_zamani` kazanır.
- Snapshot objesi mutate edilmez.
- `toplam_net_dakika` numeric ise `delta_dakika` eklenir.
- `BORDRO_ETKI_NOTU` delta uygulamaz; `correction_events` metadata'sında görünür.

---

## 9. Snapshot / Motor İzolasyonu

Bu fazda snapshot immutable kararı korunmuştur.

**Kesin davranış:**

- `src/services/haftalik-kapanis-snapshot.ts` değiştirilmedi.
- Puantaj motoru import edilmedi.
- Serbest zaman motoru import edilmedi.
- Yıllık fazla çalışma aggregate değiştirilmedi.
- Correction flow snapshot'ı yalnız okur.
- `buildHaftalikKapanisSnapshot` correction path'te çağrılmaz.
- Testlerde approve + correction sonrası kapanış snapshot satırlarının değişmediği doğrulandı.

---

## 10. Scope / Finance Visibility

Correction görünürlüğü mevcut revizyon talebi scope kurallarıyla hizalandı.

**Eklenen davranış:**

- `canViewRevizyonCorrection`, `canViewRevizyonTalep` davranışını kullanır.
- `maskCorrectionFinanceFields` finance visibility kararına göre correction response'unu maskeler.
- `BIRIM_AMIRI` için `bordro_etki_tipi` null yapılır.
- Bordro etkili correction açıklaması yetkisiz role maskelenir.
- `bordro_etki_var_mi` flag'i korunur.

---

## 11. API Contract

Correction API client oluşturuldu.

**Fonksiyonlar:**

- `fetchRevizyonCorrections`
- `fetchRevizyonCorrectionDetail`
- `produceRevizyonCorrection`
- `cancelRevizyonCorrection`

**Endpoint ailesi:**

- `GET /haftalik-kapanis/revizyon-corrections`
- `GET /haftalik-kapanis/revizyon-corrections/:id`
- `POST /haftalik-kapanis/revizyon-talepleri/:id/correction-uret`
- `POST /haftalik-kapanis/revizyon-corrections/:id/iptal`

---

## 12. Hata Kodları

| Kod | Açıklama |
|-----|----------|
| `CORRECTION_ALREADY_EXISTS` | Aynı revizyon talebi için correction zaten üretilmiş |
| `CORRECTION_NOT_ALLOWED_FOR_STATE` | Talep durumu correction üretimine uygun değil |
| `CORRECTION_TARGET_NOT_FOUND` | Hedef kaynak/snapshot bulunamadı |
| `CORRECTION_SCOPE_DENIED` | Kullanıcı kapsamı dışı correction erişimi |
| `CORRECTION_IMMUTABLE_SNAPSHOT` | Snapshot mutate girişimi reddedildi |
| `CORRECTION_RECOMPUTE_REQUIRED` | İşlem için yeniden hesaplama gerekli (ileri faz) |
| `CORRECTION_FINANCE_SCOPE_DENIED` | Finans kapsamı dışı bordro etki erişimi |
| `INVALID_CORRECTION_PAYLOAD` | Geçersiz correction istek gövdesi |
| `CORRECTION_NOT_FOUND` | Correction kaydı bulunamadı |

**Not:** `CORRECTION_RECOMPUTE_REQUIRED` V1'de tanımlıdır fakat tetiklenmez. Gerçek recompute workflow sonraki faz konusudur.

---

## 13. Test ve Doğrulama

| Kontrol | Sonuç |
|---------|-------|
| `npm run test` | 551 passed |
| `npm run typecheck` | success |
| CI #353 | success |
| Deploy cPanel #331 | success |

**Test kapsamı:**

- correction state / idempotency / iptal kuralları
- active correction filtreleme
- son-kazanır overlay
- immutable snapshot overlay
- approve → correction üretimi
- ikinci correction engeli
- correction cancel soft delete
- finance mask
- scope dışı correction detail 403
- snapshot unchanged kontrolü

---

## 14. Bilinen Notlar

- `mock-demo.ts` bu fazda büyüdü; iş mantığının önemli bölümü lib helper'lara ayrıldı.
- `SUREC_GEC_GIRIS` revizyon tipi correction üretmez.
- String saat delta hesabı V1'de 0 kalır; detaylı süre parse/recompute ileride ele alınır.
- Correction layer bordro hesabı üretmez.
- Serbest zaman correction adapter bu fazda açılmadı.
- Gerçek backend audit log bu fazda yoktur; contract seviyesinde alanlar hazırlandı.

---

## 15. Kapsam Dışı Bırakılanlar

- UI
- route
- hook
- CSS
- snapshot builder değişikliği
- puantaj motoru
- serbest zaman event motoru
- yıllık fazla çalışma aggregate
- bordro hesabı
- finans entegrasyonu
- gerçek recompute workflow
- gerçek backend auth/persist enforcement
- audit ekranı

---

## 16. Sonraki Faz Seçenekleri

| Seçenek | Amaç | Risk | Öneri |
|---------|------|------|-------|
| **A. Correction Layer UI Karar Dokümanı** | correction badge, audit görünümü, ham snapshot / düzeltilmiş görünüm ayrımını tanımlamak. | UI erken şişebilir. | Güçlü aday. |
| **B. Correction Layer Contract Kapanış Sonrası Genel Devir Notu** | 50–62 zincirini toparlamak. | Kod üretmez. | Eğer bağlam kapanacaksa güçlü aday. |
| **C. Gerçek Backend Enforcement Kod Fazı** | Auth context, backend scope, persist ve audit log. | Gerçek backend hazır değilse erken olur. | Backend hazırlığına bağlı. |
| **D. Correction Recompute / Adapter Kararı** | süre parse, motor recompute, serbest zaman adapter. | Motor sınırına girer. | Ayrı karar dokümanı gerekir. |

---

## 17. Önerilen Sıradaki Adım

Önerilen sonraki adım, **"Correction Layer UI Karar Dokümanı"** veya **"Revizyon Zinciri Genel Devir Notu"** seçeneklerinden biridir. Eğer kod fazlarına ara verilecekse genel devir notu daha güvenlidir. Eğer revizyon workflow'u kullanıcı yüzüne taşınacaksa önce UI karar dokümanı hazırlanmalıdır.

**Önerilen dosyalar:**

- `docs/guncel/63-revizyon-talebi-correction-layer-ui-karar.md`
- veya `docs/guncel/63-revizyon-zinciri-genel-devir-notu.md`

---

## 18. Kapanış Cümlesi

61 Revizyon Talebi Correction Layer Contract kod fazı, ONAYLANDI revizyon taleplerinin snapshot'ı overwrite etmeden correction event üretmesini, `correction_event_id` bağlantısını, idempotency kontrolünü, read model overlay helper'ını ve correction API/mock contract'ını tamamlamıştır. Faz CI ve Deploy doğrulamasıyla kapatılmıştır.
