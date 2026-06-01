# 48 — Serbest Zaman Zinciri Genel Kapanış ve Devir Notu

---

## 1. Doküman Amacı

Bu doküman E2, E3a, E3b ve E3c ile tamamlanan serbest zaman zincirinin genel kapanış ve geliştirici devir notudur. Amaç, sonraki geliştiricinin hangi kararların kapandığını, hangi dosyaların owner olduğunu, hangi alanların kapsam dışı kaldığını ve sonraki fazda nereden devam edileceğini tek yerden görmesini sağlamaktır.

---

## 2. Zincir Özeti

| Faz | Açıklama | Commit / referans | Durum |
|-----|----------|-------------------|-------|
| **E2** | Fazla çalışma ödeme tercihi / serbest zaman ön kararı | `ce55567` / `77f37fa` kapanış hattı | Kapalı |
| **E3a** | Serbest zaman oluşum event modeli | `9f6fd15` / `67ab53f`; checkpoint: `499caea` / `46d0c2a` hattı | Kapalı |
| **E3b** | Serbest zaman kullanım event'i + bakiye düşümü | Kod: `68dfa9b`; checkpoint: `9a83753`; CI: #335 / #336 success; Deploy: #313 / #314 success | Kapalı |
| **E3c** | Serbest zaman düzeltme / iptal event modeli | Karar: `9ae2922`; kod: `bd3a9db`; checkpoint: `31dd553`; CI: #337 / #338 / #339 success; Deploy: #315 / #316 / #317 success | Kapalı |

---

## 3. Tamamlanan Yetkinlikler

- Fazla çalışma ödeme tercihi `SERBEST_ZAMAN` seçilebilir hale geldi.
- `SERBEST_ZAMAN_OLUSUM` event'i üretildi.
- Oluşan hak bakiye read modelinde görülebilir hale geldi.
- `SERBEST_ZAMAN_KULLANIM` event'i ile bakiye düşümü sağlandı.
- Kullanım mevcut bakiyeyi aşamaz.
- `SERBEST_ZAMAN_IPTAL` event'i ile hedef oluşum/kullanım etkisizleştirilebilir hale geldi.
- `SERBEST_ZAMAN_DUZELTME` event'i ile hedef oluşum/kullanım dakika etkisi override edilebilir hale geldi.
- İptal baskın, düzeltme override sırası read modelde uygulandı.
- Fiziksel silme yapılmadan audit izli event modeli kuruldu.
- API/mock/test hattı bu zincire göre genişletildi.

---

## 4. Event Tipleri

| Event tipi | Amaç | Bakiye etkisi |
|------------|------|---------------|
| `SERBEST_ZAMAN_OLUSUM` | Fazla çalışma ödeme tercihi serbest zaman olduğunda hak üretir. | `toplam_hak_dakika` artırır. |
| `SERBEST_ZAMAN_KULLANIM` | Personelin serbest zaman kullanımını kaydeder. | `kullanilan_dakika` artırır, `kalan_dakika` düşer. |
| `SERBEST_ZAMAN_IPTAL` | Hedef OLUSUM veya KULLANIM event'inin etkisini fiziksel silmeden kaldırır. | OLUSUM iptalinde hak azalır; KULLANIM iptalinde bakiye iade edilir. |
| `SERBEST_ZAMAN_DUZELTME` | Hedef OLUSUM veya KULLANIM event'inin dakika etkisini düzeltir. | Hedef event'in dakika etkisini `yeni_dakika` ile override eder. |

---

## 5. Read Model / Bakiye Formülü

```
toplam_hak_dakika = etkin SERBEST_ZAMAN_OLUSUM toplamı
suresi_dolan_dakika = E3a son_kullanim_tarihi kuralına göre süresi dolan hak
kullanilan_dakika = etkin SERBEST_ZAMAN_KULLANIM toplamı
kalan_dakika = max(toplam_hak_dakika - suresi_dolan_dakika - kullanilan_dakika, 0)
```

**Read model sırası:**

1. Personelin serbest zaman event'leri alınır.
2. `IPTAL` event'leri `hedef_event_id` set'i oluşturur.
3. `DUZELTME` event'leri `hedef_event_id` bazında son düzeltme kazanacak şekilde override map oluşturur.
4. İptal edilmiş hedefler etkisizdir.
5. İptal edilmiş hedef üzerindeki düzeltmeler etkisizdir.
6. OLUSUM toplamı etkin dakika ile hesaplanır.
7. KULLANIM toplamı etkin dakika ile hesaplanır.
8. Süresi dolan hak hesabında E3a `son_kullanim_tarihi` mantığı korunur.
9. `kalan_dakika` negatif olmaz.

---

## 6. Owner Dosyalar

| Alan | Dosya |
|------|-------|
| **Types** | `src/types/serbest-zaman.ts` |
| **Motor** | `src/services/serbest-zaman-event-motoru.ts`, `src/services/serbest-zaman-donusum.ts` |
| **API** | `src/api/serbest-zaman.api.ts`, `src/api/endpoints.ts`, `src/api/mock-demo.ts` |
| **Tests** | `tests/unit/serbest-zaman-event-motoru.test.ts`, `tests/unit/serbest-zaman.api.test.ts`, `tests/unit/serbest-zaman-donusum.test.ts` |
| **Karar / checkpoint dokümanları** | `docs/guncel/41-e2-odeme-tipi-serbest-zaman-karar.md`, `docs/guncel/44-e3a-serbest-zaman-olusum-event-kapanis-checkpoint.md`, `docs/guncel/45-e3b-serbest-zaman-kullanim-event-kapanis-checkpoint.md`, `docs/guncel/46-e3c-serbest-zaman-duzeltme-iptal-event-karar.md`, `docs/guncel/47-e3c-serbest-zaman-duzeltme-iptal-event-kapanis-checkpoint.md` |

---

## 7. Dokunulmaması Gereken Alanlar

Bu zincirde bilinçli olarak kapsam dışı bırakılanlar:

- `src/services/haftalik-kapanis-snapshot.ts`
- `src/types/haftalik-kapanis.ts`
- `src/services/yillik-fazla-calisma-aggregate.ts`
- `src/api/haftalik-kapanis.api.ts`
- `src/services/puantaj-hesap-motoru.ts`
- `src/api/fazla-calisma-odeme-tercihi.api.ts`
- `src/hooks/**`
- `src/features/**/pages/**`
- routes
- CSS
- bordro / finans

---

## 8. Snapshot Kararı

Haftalık kapanış snapshot immutable kabul edilmiştir. Serbest zaman event store ve bakiye read model ayrı tutulmuştur. E3a/E3b/E3c boyunca haftalık kapanış snapshot builder mutate edilmemiştir. Kapalı dönem düzeltmesi gerekiyorsa ayrı faz açılmalıdır.

---

## 9. API Contract Özeti

- `GET /serbest-zaman/events`
- `GET /serbest-zaman/bakiye`
- `POST /serbest-zaman/olusum`
- `POST /serbest-zaman/kullanim`
- `POST /serbest-zaman/iptal`
- `POST /serbest-zaman/duzeltme`

**Not:**

- GET endpoints 4 event tipini okuyabilir.
- POST endpoints başarılı eventleri mock store'a append eder.
- Başarısız validation durumlarında event persist edilmez.

---

## 10. Hata Kodları Özeti

- `ALREADY_EXISTS`
- `NOT_ELIGIBLE`
- `ZERO_DAKIKA`
- `NOT_PERSISTED`
- `NO_ELIGIBLE_BALANCE`
- `INSUFFICIENT_BALANCE`
- `TARGET_NOT_FOUND`
- `TARGET_PERSONEL_MISMATCH`
- `TARGET_ALREADY_CANCELLED`
- `ALREADY_CANCELLED`
- `UNSUPPORTED_TARGET_EVENT`

---

## 11. Test Güvencesi

E3c sonrasında test sayısı **500 passed / 28 files** seviyesine çıkmıştır. Serbest zaman motor ve API testleri E3a/E3b/E3c davranışlarını birlikte korur.

**E3c kod fazı son doğrulama:**

- `npm run test` → 500 passed
- `npm run typecheck` → success
- CI #338 → success
- Deploy cPanel #316 → success

**E3c checkpoint doğrulama:**

- CI #339 → success
- Deploy cPanel #317 → success

---

## 12. Bilinen Sınırlar

- `id` olmayan OLUSUM/KULLANIM event'leri bakiyede sayılır fakat iptal hedefi olamaz.
- `findOlusumByOdemeTercihiId` iptal edilmiş oluşumu hâlâ var sayar.
- Kapalı dönem düzeltme workflow'u yoktur.
- UI ekranı yoktur.
- Bordro/finans entegrasyonu yoktur.
- Serbest zaman kullanım talebi/onay akışı yoktur.
- Gerçek backend persist katmanı yoktur; mock store üzerinden çalışır.

---

## 13. Sonraki Mantıklı Fazlar

| Faz / konu | Açıklama |
|------------|----------|
| Kapalı dönem / audit workflow kararı | Snapshot sonrası düzeltme politikası |
| İptal edilmiş oluşum sonrası tekrar oluşum davranışı | `ALREADY_EXISTS` ve tekrar oluşum kuralları |
| Serbest zaman UI / yönetim ekranı | Operasyonel yönetim arayüzü |
| Gerçek backend persist contract | Mock store yerine kalıcı katman |
| Bordro/finans entegrasyon kararı | Ödeme ve muhasebe hattı |
| Kullanım talebi / onay akışı | İş akışı ve yetkilendirme |
| Serbest zaman zinciri mevzuat/operasyon genel kontrolü | Uçtan uca iş kuralı doğrulaması |

---

## 14. Geliştiriciye Devam Notu

Yeni geliştirici bu hatta devam edecekse önce `docs/guncel/48-serbest-zaman-zinciri-genel-kapanis-ve-devir-notu.md` dosyasını, sonra sırasıyla 44, 45, 46, 47 numaralı dokümanları okumalıdır. Kodda ilk bakılacak dosya `src/services/serbest-zaman-event-motoru.ts` olmalıdır. UI veya puantaj motoruna atlamadan önce event store read model mantığı anlaşılmalıdır.

---

## 15. Kapanış Cümlesi

Serbest zaman zinciri; oluşum, kullanım, bakiye düşümü, iptal ve düzeltme event modeliyle V1 çekirdek event-store/read-model seviyesinde tamamlanmıştır. Bu zincir, haftalık snapshot ve puantaj motorunu mutate etmeden ayrı serbest zaman event store hattı olarak kapatılmıştır.
