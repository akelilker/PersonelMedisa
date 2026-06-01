# 47 — E3c Serbest Zaman Düzeltme / İptal Event Kapanış Checkpoint

---

## 1. Faz Bilgisi

| Alan | Değer |
|------|-------|
| Faz adı | E3c — Serbest zaman düzeltme / iptal event modeli |
| Karar dokümanı commit | `9ae2922` — Add E3c serbest zaman duzeltme iptal karar dokumani |
| Kod commit | `bd3a9db` — Add E3c serbest zaman duzeltme iptal event modeli |
| CI | #338 / success |
| Deploy cPanel | #316 / success |
| Durum | Kapandı |

---

## 2. Amaç

Bu fazda E3a/E3b ile kurulan serbest zaman event store hattına audit izli düzeltme ve iptal event modeli eklenmiştir. Fiziksel silme yapılmadan hatalı oluşum veya kullanım kayıtlarının read model etkisi değiştirilebilir hale getirilmiştir.

---

## 3. Önceki Zincir

- **E3a:** `SERBEST_ZAMAN_OLUSUM` event modeli
- **E3b:** `SERBEST_ZAMAN_KULLANIM` event'i + bakiye düşümü
- **E3c karar:** Düzeltme / iptal event modeli kararı (`9ae2922`)
- **E3c kod:** Düzeltme / iptal event modeli implementasyonu (`bd3a9db`)

---

## 4. Kapsam

- `SERBEST_ZAMAN_IPTAL` event modeli
- `SERBEST_ZAMAN_DUZELTME` event modeli
- `SerbestZamanEvent` union'ının 4 varyanta genişletilmesi
- İptal event motoru (`olusturIptalEvent`)
- Düzeltme event motoru (`olusturDuzeltmeEvent`)
- Read modelde iptal baskın / düzeltme override sırası
- `POST /serbest-zaman/iptal`
- `POST /serbest-zaman/duzeltme`
- `GET /serbest-zaman/events` için 4 event tipi normalize desteği
- `GET /serbest-zaman/bakiye` için iptal/düzeltme uygulanmış bakiye
- Mock handler ve unit test kapsamı

---

## 5. Değişen Dosyalar

- `src/types/serbest-zaman.ts`
- `src/services/serbest-zaman-event-motoru.ts`
- `src/api/serbest-zaman.api.ts`
- `src/api/endpoints.ts`
- `src/api/mock-demo.ts`
- `tests/unit/serbest-zaman-event-motoru.test.ts`
- `tests/unit/serbest-zaman.api.test.ts`

---

## 6. Kapanış Kararları

- Fiziksel silme yapılmaz.
- İptal ve düzeltme ayrı event olarak tutulur.
- İptal event'i hedef OLUSUM/KULLANIM etkisini read modelde geçersiz kılar.
- Düzeltme event'i hedef OLUSUM/KULLANIM dakika etkisini override eder.
- İptal baskındır.
- İptal edilmiş hedef üzerinde düzeltme uygulanmaz.
- Aynı hedef ikinci kez iptal edilemez.
- Kullanım düzeltmesi bakiye aşımı üretemez.
- `kalan_dakika` negatif olmaz.
- `event_sayisi` E3a uyumu için ham OLUSUM sayısı olarak korunur.
- Haftalık kapanış snapshot mutate edilmez.
- 270 saat aggregate, puantaj motoru, UI/hook/page/routes kapsam dışı kalır.

---

## 7. Read Model Sırası

Bakiye hesabı şu sırayla yorumlanır:

1. Personelin serbest zaman event'leri alınır.
2. `IPTAL` event'leri `hedef_event_id` set'i oluşturur.
3. `DUZELTME` event'leri `hedef_event_id` bazında son düzeltme kazanacak şekilde override map oluşturur.
4. İptal edilmiş hedefler etkisizdir.
5. İptal edilmiş hedef üzerindeki düzeltmeler etkisizdir.
6. OLUSUM toplamı etkin dakika ile hesaplanır.
7. KULLANIM toplamı etkin dakika ile hesaplanır.
8. Süresi dolan hak hesabında E3a `son_kullanim_tarihi` mantığı korunur.
9. `kalan_dakika = max(toplam_hak_dakika - suresi_dolan_dakika - kullanilan_dakika, 0)`

---

## 8. Hata Davranışları

| Kod | Koşul | HTTP |
|-----|-------|------|
| `TARGET_NOT_FOUND` | hedef event yok | 404 |
| `TARGET_PERSONEL_MISMATCH` | hedef başka personele ait | 400 |
| `TARGET_ALREADY_CANCELLED` | iptal edilmiş hedef üzerinde düzeltme/işlem | 409 |
| `ALREADY_CANCELLED` | aynı hedefe ikinci iptal | 409 |
| `ZERO_DAKIKA` | `yeni_dakika <= 0` | 400 |
| `INSUFFICIENT_BALANCE` | kullanım düzeltmesi bakiyeyi aşar | 409 |
| `UNSUPPORTED_TARGET_EVENT` | hedef tipi desteklenmez veya uyumsuz | 400 |

---

## 9. Test ve Doğrulama

| Kontrol | Sonuç |
|---------|--------|
| `npm run test` | 500 passed / 28 files |
| `npm run typecheck` | success |
| CI #338 | success |
| Deploy cPanel #316 | success |

---

## 10. Kapsam Dışı Bırakılanlar

- UI / hook / page / route / CSS
- Haftalık kapanış snapshot builder
- 270 saat aggregate
- Puantaj hesap motoru
- E2 ödeme tercihi PUT side-effect
- Bordro / finans
- İptal edilmiş oluşum için aynı ödeme tercihiyle tekrar oluşum üretme
- Kapalı dönem düzeltme workflow'u

---

## 11. Bilinen Notlar

- `id` olmayan OLUSUM/KULLANIM event'leri bakiyede sayılır fakat iptal hedefi olamaz.
- `findOlusumByOdemeTercihiId` iptal edilmiş oluşumu hâlâ var sayar; bu E3c scope dışı bırakılmıştır.
- Snapshot immutable kalır; düzeltme/iptal yalnız serbest zaman read modelinde çözülür.

---

## 12. Sonraki Faz Notu

Bu fazdan sonra iki olası yön vardır:

- Serbest zaman zinciri genel kapanış/devir dokümanı
- Kapalı dönem / audit / tekrar oluşum davranışı için ayrı karar fazı

---

## 13. Kapanış Cümlesi

E3c fazı, serbest zaman event store hattında hatalı oluşum ve kullanım kayıtlarının fiziksel silme yapılmadan audit izli iptal ve düzeltme event'leriyle yönetilmesini tamamlamıştır. E3a/E3b davranışı korunmuş, read modelde iptal baskın ve düzeltme override sırası uygulanmış, kod fazı CI ve Deploy doğrulamasıyla kapatılmıştır.
