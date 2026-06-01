# 50 — Kapalı Dönem / Audit Workflow Kararı

---

## 1. Doküman Amacı

Bu doküman, Puantaj V2 hattında kapatılmış/mühürlenmiş dönemlere sonradan gelen düzeltme, iptal, ekleme ve audit ihtiyaçlarının nasıl ele alınacağını karara bağlar. Amaç, snapshot immutable kararını bozmadan, puantaj, serbest zaman, rapor ve ileride bordro/finans katmanına güvenli bir düzeltme modeli bırakmaktır.

---

## 2. Önceki Bağlam

- **39 / A1 / A2 / A3** ile haftalık kapanış snapshot hattı kuruldu.
- **E1** ile yıllık 270 saat aggregate hattı kuruldu.
- **E2** ile fazla çalışma ödeme tercihi hattı kuruldu.
- **E3a / E3b / E3c** ile serbest zaman event store hattı kuruldu.
- **48** ile serbest zaman zinciri genel kapanış/devir notu yazıldı.
- **49** ile Puantaj V2 genel durum ve açık işler dokümanı yazıldı.
- Kapalı dönem / audit workflow, **49** numaralı dokümanda önerilen sıradaki güvenli fazdır.

---

## 3. Problem Tanımı

Şu durumlar için açık karar gerekir:

- Haftalık kapanış alındıktan sonra yanlış puantaj kaydı fark edilirse ne olacak?
- Kapalı haftayı etkileyen süreç event'i sonradan girilirse ne olacak?
- Serbest zaman oluşum/kullanım/düzeltme/iptal kapalı dönemi etkilerse ne olacak?
- Snapshot fiziksel olarak değiştirilecek mi?
- Yeni snapshot mı üretilecek?
- Düzeltme event'i mi yazılacak?
- Raporlar hangi kaynağı esas alacak?
- İleride bordro/finans hangi veriyi güvenilir kabul edecek?

---

## 4. Ana Karar

- Haftalık kapanış snapshot **immutable** kalacaktır.
- Kapalı dönem verisi **doğrudan overwrite edilmeyecektir**.
- Kapalı döneme etki eden sonradan gelen işlem, **ayrı audit/düzeltme kaydı** olarak tutulacaktır.
- Kapanmış snapshot **geriye dönük sessizce değiştirilmez**.
- Düzeltme etkisi **rapor/read model katmanında ayrı gösterilir**.
- Bordro/finans fazına geçmeden önce kapalı dönem düzeltme etkisinin hangi çıktıya yansıyacağı **ayrıca netleştirilecektir**.

---

## 5. Tanımlar

| Terim | Tanım |
|-------|--------|
| **Açık dönem** | Henüz haftalık kapanışı alınmamış dönemdir. Normal hesap ve event güncellemesi yapılabilir. |
| **Kapalı dönem** | Haftalık kapanış snapshot'ı alınmış ve mühürlenmiş dönemdir. Normal mutation kabul etmez. |
| **Snapshot** | Kapanış anındaki hesap sonucunu temsil eden immutable kayıt/read modeldir. |
| **Audit event** | Kapalı dönem sonrası yapılan düzeltme, iptal veya açıklama kaydıdır. Eski kaydı silmez; yeni iz üretir. |
| **Düzeltme read model** | Snapshot + audit event etkisini birlikte gösteren raporlama katmanıdır. |

---

## 6. Kapalı Dönemde Mutation Kararı

| İşlem | Açık dönem | Kapalı dönem |
|-------|------------|--------------|
| Günlük puantaj güncelleme | Doğrudan güncellenebilir. | Reddedilir veya audit düzeltme akışına yönlenir. |
| Süreç event'i ekleme | Normal event olarak işlenir. | Kapalı dönem etkisi varsa audit workflow ister. |
| Serbest zaman event'i | Event store'a normal yazılır. | Kapalı snapshot etkisi doğuruyorsa audit etkisi ayrıca işaretlenir. |
| Haftalık kapanış yeniden hesaplama | Kapanış öncesi mümkündür. | Doğrudan overwrite yok; yeniden açma ayrı admin fazı gerektirir. |
| Rapor düzeltmesi | Güncel read model kullanılır. | Snapshot + audit farkı ayrı gösterilir. |

---

## 7. Snapshot Kararı

Haftalık kapanış snapshot builder mevcut haliyle kapanış anının mühür kaydını üretir. Bu snapshot:

- silinmez
- sessizce güncellenmez
- sonradan gelen düzeltme ile overwrite edilmez

Kapalı dönem düzeltmeleri **ayrı audit kaydı** ile izlenir. Gerekirse ileride “revised snapshot” veya “correction layer” ayrı fazda tasarlanabilir. **Bu karar dokümanı mevcut snapshot builder'a kod değişikliği yaptırmaz.**

---

## 8. Audit Event Modeli Kararı

Audit kaydı minimum şu alanları taşımalıdır:

- `id`
- `kaynak_tipi`
- `kaynak_id`
- `personel_id`
- `donem_baslangic`
- `donem_bitis`
- `etkilenen_tarih`
- `audit_tipi`
- `onceki_deger`
- `yeni_deger`
- `aciklama`
- `olusturan_kullanici_id`
- `olusturma_zamani`
- `onay_durumu`

**Audit tipi örnekleri:**

- `PUANTAJ_DUZELTME`
- `SUREC_GEC_GIRIS`
- `SERBEST_ZAMAN_DUZELTME`
- `SERBEST_ZAMAN_IPTAL`
- `KAPANIS_REVIZYON_TALEBI`
- `BORDRO_ETKI_NOTU`

**Not:** Bu doküman audit event implementasyonu yapmaz; yalnız karar verir.

---

## 9. Read Model Kararı

Kapalı dönem raporlarında üç seviye gösterim önerilir:

| Seviye | Açıklama |
|--------|----------|
| **1. Orijinal snapshot** | Kapanış anındaki veri. Denetim ve izlenebilirlik için değişmez. |
| **2. Audit farkları** | Sonradan gelen düzeltme / iptal / not / revizyon talepleri. |
| **3. Düzeltilmiş görünüm** | Orijinal snapshot + audit farklarının raporlama amaçlı birleşik görünümü. |

**Karar:** Operasyon ekranları hangi seviyeyi gösterdiğini açıkça belirtmelidir. Sessizce “sanki eski veri değişmiş” gibi gösterim yapılmayacaktır.

---

## 10. Serbest Zaman ile İlişki

Serbest zaman E3a/E3b/E3c ile ayrı event store olarak kapatılmıştır. Bu event store haftalık snapshot'ı mutate etmez. Kapalı dönemi etkileyen serbest zaman düzeltmeleri şu şekilde ele alınacaktır:

- Event store kendi read modelini günceller.
- Haftalık kapanış snapshot'ı geriye dönük değişmez.
- Raporlama katmanı gerekirse “kapalı dönem sonrası serbest zaman düzeltmesi” olarak audit farkı gösterir.
- Bordro/finans etkisi ayrı fazda karara bağlanır.

---

## 11. Puantaj ile İlişki

Kapalı dönemde günlük puantaj doğrudan değiştirilemez. Yanlış giriş/çıkış, mola, devamsızlık veya süreç etkisi sonradan fark edilirse:

- ana puantaj satırı overwrite edilmez
- audit düzeltme talebi oluşturulur
- gerekirse rapor/read model düzeltme farkı gösterir
- yeniden açma gerekiyorsa ayrı admin workflow fazı gerekir

---

## 12. Bordro / Finans ile İlişki

Bu doküman bordro/finans entegrasyonu yapmaz. Ancak kapalı dönem düzeltmelerinin bordroya etkisi kritik olduğu için şu karar alınır:

- Bordro/finans, doğrudan mutable günlük veriye değil, **kapanmış snapshot + audit etkisi netleşmiş read model** katmanına bakmalıdır.
- Kapalı dönem sonrası düzeltme varsa bordro çıktısında **“düzeltme etkisi”** ayrı kalem veya ayrı uyarı olarak taşınmalıdır.
- Net bordro davranışı sonraki bordro/finans karar fazına bırakılmıştır.

---

## 13. Validation Kararları

| Kod | Ne zaman |
|-----|----------|
| `PERIOD_LOCKED` | Kapalı döneme doğrudan mutation denenirse. |
| `AUDIT_REQUIRED` | Kapalı döneme etki eden işlem audit workflow'a yönlenmeliyse. |
| `REOPEN_REQUIRED` | İşlem normal audit ile çözülemeyecek kadar yapısal ise. |
| `SNAPSHOT_IMMUTABLE` | Snapshot overwrite denenirse. |
| `REVISION_ALREADY_EXISTS` | Aynı kaynak için açık revizyon talebi varsa. |
| `UNAUTHORIZED_PERIOD_REVISION` | Yetkisiz kullanıcı kapalı dönem düzeltmesi isterse. |

---

## 14. API Kararı

İleride açılabilecek endpoint önerileri:

- `POST /kapali-donem/audit-events`
- `GET /kapali-donem/audit-events`
- `POST /kapali-donem/revizyon-talebi`
- `POST /kapali-donem/revizyon-talebi/{id}/onay`
- `POST /kapali-donem/revizyon-talebi/{id}/red`
- `GET /raporlar/haftalik-kapanis-duzeltilmis`

**Not:** Bu doküman endpoint implementasyonu yapmaz.

---

## 15. Test Kararı

İleride kod fazında minimum testler:

- Kapalı dönemde günlük puantaj update → `PERIOD_LOCKED`
- Kapalı döneme süreç event'i → `AUDIT_REQUIRED`
- Snapshot overwrite denemesi → `SNAPSHOT_IMMUTABLE`
- Audit event read modelde fark üretir
- Orijinal snapshot değişmeden kalır
- Aynı kaynak için ikinci açık revizyon → `REVISION_ALREADY_EXISTS`
- Yetkisiz kapalı dönem revizyonu → `UNAUTHORIZED_PERIOD_REVISION`

---

## 16. Kapsam Dışı

Bu dokümanda yapılmayacaklar:

- Kod implementasyonu
- API/mock değişikliği
- UI ekranı
- Snapshot builder değişikliği
- Puantaj motoru değişikliği
- Serbest zaman event motoru değişikliği
- Bordro hesaplama
- Finans entegrasyonu
- Yetki matrisi kod değişikliği

---

## 17. Sonraki Faz Seçenekleri

| Seçenek | Amaç | Risk |
|---------|------|------|
| **A. Kapalı dönem audit event contract kod fazı** | Audit event type/API/mock/test iskeleti. | Yeni domain hattı açılır. |
| **B. Haftalık kapanış revizyon talebi karar dokümanı** | Reopen/revise/admin approval flow netleştirme. | Yetki ve UI ihtiyacı doğar. |
| **C. Bordro/finans etki karar dokümanı** | Kapalı dönem farkları bordroya nasıl yansır. | Mevzuat ve muhasebe kararı gerekir. |
| **D. Puantaj V2 test hardening** | Kod genişletmeden mevcut hatların test güvencesini artırmak. | Yeni ürün yetkinliği eklemez ama güven verir. |

---

## 18. Önerilen Sıradaki Adım

Bu karar dokümanından sonra en güvenli devam, doğrudan kod yazmak değil, **「Haftalık kapanış revizyon talebi karar dokümanı」** hazırlamaktır. Çünkü audit event contract kodlanmadan önce revizyonun kim tarafından, hangi statülerle, hangi döneme ve hangi etkiyle açılacağı netleşmelidir.

**Önerilen dosya:**

`docs/guncel/51-haftalik-kapanis-revizyon-talebi-karar.md`

---

## 19. Kapanış Cümlesi

Kapalı dönem / audit workflow karar dokümanı, haftalık snapshot'ın immutable kalacağını ve kapalı döneme sonradan gelen değişikliklerin doğrudan overwrite yerine audit/revizyon modeliyle ele alınacağını karara bağlar. Bu karar, bordro/finans ve UI fazlarına geçmeden önce dönem güvenliğini sağlayan üst çerçevedir.
