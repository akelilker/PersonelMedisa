# 45 — E3b Serbest Zaman Kullanım Event Kapanış Checkpoint

---

## 1. Faz Bilgisi

| Alan | Değer |
|------|-------|
| Faz adı | E3b — Serbest zaman kullanım event'i + bakiye düşümü |
| Commit | `68dfa9b` — Add E3b serbest zaman kullanim event and bakiye dusumu |
| CI | #335 / Run ID 26721981188 / success |
| Deploy cPanel | #313 / Run ID 26721981187 / success |
| Durum | Kapandı |

---

## 2. Amaç

Bu fazda E3a ile oluşan serbest zaman hakkının kullanım event'i ile tüketilmesi ve bakiyeden düşülmesi sağlandı.

---

## 3. Kapsam

- `SERBEST_ZAMAN_KULLANIM` event modeli
- Kullanım event motoru
- Bakiye read modelinde `kullanilan_dakika` düşümü
- `POST /serbest-zaman/kullanim` mock/API contract
- `GET /serbest-zaman/events` karma event liste desteği
- `GET /serbest-zaman/bakiye` kullanım sonrası bakiye desteği
- Unit test kapsamı

---

## 4. Değişen Dosyalar

- `src/types/serbest-zaman.ts`
- `src/services/serbest-zaman-event-motoru.ts`
- `src/api/serbest-zaman.api.ts`
- `src/api/endpoints.ts`
- `src/api/mock-demo.ts`
- `tests/unit/serbest-zaman-event-motoru.test.ts`
- `tests/unit/serbest-zaman.api.test.ts`

---

## 5. Kapanış Kararları

- E3a oluşum hattı korunmuştur.
- Kullanım event'i ayrı event tipi olarak tutulur.
- Kullanım, oluşmuş kullanılabilir bakiyeden düşer.
- Kullanım miktarı 0 veya negatif olamaz.
- Kullanım kullanılabilir bakiyeyi aşamaz.
- Başarısız kullanım persist edilmez.
- `kalan_dakika` negatif olmaz.
- `event_sayisi` E3a uyumu için oluşum lot sayısı olarak korunur.
- Haftalık kapanış snapshot'ı mutate edilmez.
- Serbest zaman event store ve bakiye endpoint'i read model kaynağıdır.

---

## 6. Bakiye Formülü

```
toplam_hak_dakika = Σ SERBEST_ZAMAN_OLUSUM
suresi_dolan_dakika = E3a son_kullanim_tarihi kuralı
kullanilan_dakika = Σ SERBEST_ZAMAN_KULLANIM
kalan_dakika = max(toplam_hak_dakika - suresi_dolan_dakika - kullanilan_dakika, 0)
```

---

## 7. Hata Davranışları

| Kod | Koşul | HTTP | Persist |
|-----|-------|------|---------|
| `ZERO_DAKIKA` | dakika <= 0 | 400 | yok |
| `NO_ELIGIBLE_BALANCE` | kullanılabilir bakiye yok | 409 | yok |
| `INSUFFICIENT_BALANCE` | kullanım bakiyeyi aşıyor | 409 | yok |

---

## 8. Test ve Doğrulama

| Kontrol | Sonuç |
|---------|--------|
| `npm run test` | 486 passed / 28 files |
| `npm run typecheck` | success |
| CI #335 | success |
| Deploy cPanel #313 | success |

---

## 9. Kapsam Dışı Bırakılanlar

- UI / hook / page / route / CSS
- Haftalık kapanış snapshot builder
- 270 saat aggregate
- Puantaj hesap motoru
- E2 ödeme tercihi PUT side-effect
- Bordro / finans
- DÜZELTME / İPTAL event implementasyonu

---

## 10. Sonraki Faz Notu

E3c için açık kalan karar:

- `SERBEST_ZAMAN_DUZELTME`
- `SERBEST_ZAMAN_IPTAL`
- Kullanım iptali ve bakiye iadesi
- Audit / correction event modeli
- Geriye dönük düzeltmenin snapshot ve read model etkisi

---

## 11. Kapanış Cümlesi

E3b fazı, serbest zaman hakkının kullanım event'i ile tüketilmesini ve bakiyeden güvenli şekilde düşülmesini tamamlamıştır. E3a oluşum hattı korunmuş, negatif bakiye ve fazla kullanım engellenmiş, kod fazı CI ve Deploy doğrulamasıyla kapatılmıştır.
