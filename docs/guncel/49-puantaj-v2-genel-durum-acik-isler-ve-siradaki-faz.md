# 49 — Puantaj V2 Genel Durum, Açık İşler ve Sıradaki Faz

---

## 1. Doküman Amacı

Bu doküman, Puantaj V2 hattında bugüne kadar kapanan karar/kod fazlarını, açık kalan işleri ve sıradaki en mantıklı faz seçeneklerini tek yerde toplar. Amaç, yeni kod fazına başlamadan önce proje yönünü netleştirmek ve scope dağılmasını engellemektir.

---

## 2. Güncel Repo Durumu

| Alan | Değer |
|------|-------|
| Son kapanan zincir | Serbest zaman zinciri genel kapanış/devir notu |
| Son commit | `a341bdd` — Add serbest zaman zinciri genel kapanis devir notu |
| Son CI | #340 — success |
| Son Deploy cPanel | #318 — success |
| Durum | `main` ve `origin/main` senkron olmalı |

---

## 3. Kapanan Ana Zincirler

| Zincir | Fazlar / dokümanlar | Durum |
|--------|---------------------|-------|
| **A. Haftalık kapanış snapshot hattı** | 39 — Haftalık kapanış snapshot sözleşmesi; A1 — Snapshot contract; A2 — Snapshot motor builder; A3 — Snapshot detail store | Kapalı |
| **B. 270 saat aggregate hattı** | E1 — 270 saat aggregate; 40 — E1 checkpoint | Kapalı |
| **C. Ödeme tercihi hattı** | 41 — E2 ödeme tipi / serbest zaman ön kararı; E2 — Fazla çalışma ödeme tercihi dar kod; 42 / 43 — E2 kapanış checkpoint'leri | Kapalı |
| **D. Serbest zaman event store hattı** | E3a — oluşum event modeli; E3b — kullanım event'i + bakiye düşümü; E3c — düzeltme / iptal event modeli; 48 — genel kapanış/devir notu | Kapalı |

---

## 4. Bugüne Kadar Tamamlanan Yetkinlikler

- Haftalık kapanış snapshot contract kuruldu.
- Snapshot motor builder kuruldu.
- Snapshot detail store kuruldu.
- Yıllık 270 saat fazla çalışma aggregate hattı kuruldu.
- Fazla çalışma ödeme tercihi seçilebilir hale geldi.
- Serbest zaman ödeme tercihi ön kararı kod hattına bağlandı.
- Serbest zaman oluşum event'i üretildi.
- Serbest zaman kullanım event'i ile bakiye düşümü sağlandı.
- Serbest zaman düzeltme / iptal event modeli kuruldu.
- Serbest zaman read model, bakiye ve API/mock/test hattı tamamlandı.
- CI ve Deploy doğrulamaları her kapanış fazında alındı.
- UI/hook/page/routes scope'u bilinçli olarak dışarıda tutuldu.

---

## 5. Mevcut Mimari Ayrım

Puantaj V2 hattında şu ayrım korunmalıdır:

- **Hesap motoru / servis:** iş kuralı ve hesap üretir.
- **API/mock:** contract ve demo persist davranışını taşır.
- **Hook/page/UI:** yalnız açıkça istenirse girilecek ayrı fazdır.
- **Snapshot:** mühür/read model alanıdır.
- **Serbest zaman event store:** haftalık snapshot'tan bağımsız ayrı event/read model hattıdır.
- **270 saat aggregate:** serbest zaman event store'dan bağımsız yıllık risk hattıdır.

---

## 6. Kapanan Owner Dosya Grupları

| Alan | Dosyalar |
|------|----------|
| **Snapshot** | `src/types/haftalik-kapanis.ts`, `src/services/haftalik-kapanis-snapshot.ts`, `src/api/haftalik-kapanis.api.ts`, ilgili testler |
| **270 saat** | `src/services/yillik-fazla-calisma-aggregate.ts`, ilgili testler |
| **Ödeme tercihi** | `src/types/fazla-calisma-odeme-tercihi.ts`, `src/api/fazla-calisma-odeme-tercihi.api.ts`, ilgili testler |
| **Serbest zaman** | `src/types/serbest-zaman.ts`, `src/services/serbest-zaman-donusum.ts`, `src/services/serbest-zaman-event-motoru.ts`, `src/api/serbest-zaman.api.ts`, `src/api/endpoints.ts`, `src/api/mock-demo.ts`, `tests/unit/serbest-zaman-event-motoru.test.ts`, `tests/unit/serbest-zaman.api.test.ts`, `tests/unit/serbest-zaman-donusum.test.ts` |

---

## 7. Açık Kalan Ana Başlıklar

| Başlık | Durum | Not |
|--------|-------|-----|
| Kapalı dönem düzeltme workflow'u | Açık | Snapshot immutable bırakıldı; düzeltme gerekiyorsa ayrı karar fazı gerekir. |
| İptal edilmiş oluşum sonrası tekrar oluşum davranışı | Açık | `findOlusumByOdemeTercihiId` iptal edilmiş oluşumu hâlâ var sayar; bilinçli scope dışı. |
| Gerçek backend persist contract | Açık | Şu an mock-demo üzerinden çalışıyor. |
| UI / yönetim ekranları | Açık | Serbest zaman ve puantaj read model için henüz UI açılmadı. |
| Kullanım talebi / onay akışı | Açık | Serbest zaman kullanım event'i doğrudan motor/API seviyesinde var; onay workflow'u yok. |
| Bordro / finans entegrasyonu | Açık | Bu fazlarda bordro/finans bilinçli dışarıda bırakıldı. |
| Rapor / istirahat kesin bordro politikası | Açık | Önceki rapor/istirahat belgelerinde karar bekleyen alanlar vardı. |
| Günlük puantaj kapalı dönem davranışı | Açık | Haftalık kapanış mühür mantığı var; yeniden açma/düzeltme workflow'u ayrı karar ister. |

---

## 8. Sıradaki Faz Seçenekleri

| Seçenek | Artı | Eksi | Öneri |
|---------|------|------|-------|
| **A. Kapalı dönem / audit workflow karar dokümanı** | Snapshot, serbest zaman ve puantaj düzeltme davranışı netleşir. | Yeni koddan önce geniş karar gerektirir. | Güçlü aday |
| **B. Gerçek backend persist contract dokümanı** | Mock'tan gerçek API'ye geçiş yolu netleşir. | Backend hazır değilse bekleyebilir. | Orta aday |
| **C. Serbest zaman UI / yönetim ekranı** | Yapılan iş görünür hale gelir. | UI scope'u açılır; dikkatli owner ayrımı gerekir. | Acele edilmemeli |
| **D. Bordro / finans entegrasyon kararı** | İşletme çıktısına yaklaşır. | Kural yoğun ve riskli; önce kapalı dönem/state netleşmeli. | Şimdilik beklesin |
| **E. Puantaj genel devir / test hardening fazı** | Geniş kod yazmadan güvenlik artar. | Yeni ürün yetkinliği eklemez. | İyi ara faz |

---

## 9. Önerilen Sıradaki Adım

Önerilen sonraki adım, doğrudan yeni kod yazmak yerine **「Kapalı dönem / audit workflow karar dokümanı」** hazırlamaktır.

**Sebep:**

- Haftalık snapshot immutable tutuldu.
- Serbest zaman düzeltme/iptal read modelde çözüldü.
- Kapalı döneme sonradan gelen düzeltmenin snapshot, rapor, puantaj ve event store etkisi henüz ürün kararı olarak kilitlenmedi.
- Bordro/finans ya da UI fazına geçmeden önce bu kararın netleşmesi gerekir.

**Önerilen yeni dosya:**

`docs/guncel/50-kapali-donem-audit-workflow-karar.md`

---

## 10. Kapsam Dışı Uyarısı

Bu doküman yeni kod fazı başlatmaz. Özellikle şu dosyalara dokunulmayacaktır:

- `src/**`
- `tests/**`
- UI/hook/page/routes/CSS
- workflow dosyaları
- package dosyaları

---

## 11. Geliştiriciye Devam Notu

Yeni faza başlayacak geliştirici önce 48 numaralı serbest zaman devir notunu, ardından bu 49 numaralı genel durum dokümanını okumalıdır. Kod owner dosyalarına girmeden önce hangi zincirin kapalı, hangi başlığın açık olduğu netleştirilmelidir.

---

## 12. Kapanış Cümlesi

Puantaj V2 hattında haftalık snapshot, 270 saat aggregate, ödeme tercihi ve serbest zaman event store zincirleri V1 çekirdek seviyede kapatılmıştır. Sıradaki en güvenli ilerleme, kapalı dönem ve audit workflow kararının netleştirilmesidir.
