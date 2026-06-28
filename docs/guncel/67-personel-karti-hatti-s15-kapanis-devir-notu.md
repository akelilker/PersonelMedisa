# 67 — Personel Kartı Hattı S15 Kapanış Devir Notu

---

## 1. Amaç

Bu not, Personel Kartı hattında S15A owner analizi/recheck ve S15B E2E guard sonuçlarını tek yerde toplar.

Sonraki geliştiricinin `/personeller/:personelId` hattında nereden devam edeceğini, hangi owner dosyalarına dokunacağını, mock/API drift risklerini ve bilinçli olarak açık bırakılan ürün kararlarını görmesi için yazılmıştır.

**Bu not implementasyon talimatı değildir.** Yeni özellik eklemeden önce §14 açık ürün kararlarından biri seçilmeli ve ilgili E2E guard'ları (§13) korunmalıdır.

Raporlar hattı `65`, Puantaj hattı `66` ile kapanmıştır; bu not Personel Kartı hattını kapatır.

**Son kilit commit:** `a2b4d96` — `test: personel karti maas rol ve surec guardlarini kilitle`  
**Branch:** `main` (`origin/main` ile hizalı)

---

## 2. Kapsanan sprintler

| Sprint | Commit / Durum        | Kilitlenen veya netleşen davranış                                                       |
| ------ | --------------------- | --------------------------------------------------------------------------------------- |
| S15A   | analiz-only + recheck | Personel Kartı owner, sekme, süreç, gateway haritası                                    |
| S15B   | `a2b4d96`             | Maaş eksik uyarısı, BIRIM_AMIRI aksiyon görünürlüğü, süreç geçmişi event/ordering guard |

---

## 3. Personel Kartı modül haritası

| Alan                | Owner                                                                                                                                           |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Route               | `src/app/routes.tsx`                                                                                                                            |
| Route guard         | `src/router/ProtectedRoute.tsx`                                                                                                                 |
| Page                | `src/features/personeller/pages/PersonelDetayPage.tsx`                                                                                          |
| Tab shell           | `PersonelDosyaTabs.tsx`, `PersonelDosyaTabPanels.tsx`                                                                                           |
| Hero                | `PersonelDosyaHero.tsx`                                                                                                                         |
| Genel panel         | `PersonelKartPanelGenelBilgiler.tsx`                                                                                                            |
| Eğitim/Belgeler     | `PersonelBelgelerPanel.tsx`                                                                                                                     |
| Disiplin            | `PersonelDisiplinPanel.tsx`                                                                                                                     |
| Zimmet              | `PersonelZimmetEnvanterPanel.tsx`, `PersonelZimmetCreateModal.tsx`                                                                              |
| Süreç geçmişi       | `PersonelSurecGecmisiPanel.tsx`                                                                                                                 |
| Timeline builder    | `personel-timeline-utils.ts`, `src/lib/surec-history-sort.ts`                                                                                   |
| Detail hook         | `src/hooks/usePersonelDetail.ts`                                                                                                                |
| Gateway return hook | `src/features/personeller/hooks/usePersonelKartGatewayReturn.ts`                                                                                  |
| Kayıt modal/gateway | `kayit-modal-contract.ts`, `useKayitModalController.ts`, `useKayitGatewayIntent.ts`, `KayitSurecWorkspace.tsx`, `KayitGatewayRedirectPanel.tsx` |
| Permission          | `src/lib/authorization/role-permissions.ts`                                                                                                     |
| API                 | `personeller.api.ts`, `surecler.api.ts`, `zimmetler.api.ts`, `belgeler.api.ts`, `personel-belge-kayitlari.api.ts`, `finans.api.ts`              |
| E2E test            | `tests/e2e/personel-dosya.spec.ts`                                                                                                              |
| E2E mock            | `tests/e2e/helpers/mock-api.ts`                                                                                                                 |
| Unit                | `usePersonelKartGatewayReturn.test.ts`, `usePersonelDetail.test.ts`, `personel-disiplin-utils.test.ts`, `kayit-modal-contract.test.ts`          |

İlgili ama ayrı owner'lar:

- Liste sayfası: `src/features/personeller/pages/PersonellerPage.tsx`
- Kayıt modal shell: `src/app/AppShell.tsx`
- Maaş eksik (Kayıt sonrası): `tests/e2e/kayit-yeni-personel.spec.ts`
- Belge kaydı → kart: `tests/e2e/kayit-belge-kayitlari.spec.ts`
- İşten ayrılma (Kayıt): `tests/e2e/kayit-ayrilma.spec.ts`
- Menü erişimi smoke: `tests/e2e/smoke.spec.ts`, `tests/e2e/role-smoke.spec.ts`

---

## 4. Route ve permission contract

| Alan | Değer |
| ---- | ----- |
| Detay route | `/personeller/:personelId` |
| Parametre | `personelId` (sayısal ID) |
| Component | `PersonelDetayPage` |
| Route permission | `personeller.detail.view` (`ROUTE_PERMISSION.personelDetail`) |
| Guard | `ProtectedRoute` — yetkisiz rol `/yetkisiz` |

Liste route ayrıdır:

| Alan | Değer |
| ---- | ----- |
| Liste route | `/personeller` |
| Permission | `personeller.view` veya `personeller.view.sube` (`PERSONELLER_LIST_ANY`) |

Dört rol de detay kartını görebilir:

- GENEL_YONETICI
- BOLUM_YONETICISI
- MUHASEBE
- BIRIM_AMIRI

### Permission matrisi (Personel Kartı ile ilgili)

| Rol | detail.view | view / view.sube | update | finans.view | surecler.create | surecler.view / view.sube |
| --- | ----------- | ---------------- | ------ | ----------- | --------------- | ------------------------- |
| GENEL_YONETICI | var | var | var | var | var | var |
| BOLUM_YONETICISI | var | var | var | var | var | var |
| MUHASEBE | var | var | var | var | var | var |
| BIRIM_AMIRI | var | view.sube | yok | yok | yok | view.sube |

**Not:** BIRIM_AMIRI şube-kapsamlı görünürlükle sınırlandırılabilir (`usePersonelDetail` sube mismatch redirect). Sekme E2E'de branch/şube kapsamı sınırlıdır (§11).

---

## 5. Beş sekme contract

| Sekme           | Label               | Tab ID                    | Panel ID                              | Not                                         |
| --------------- | ------------------- | ------------------------- | ------------------------------------- | ------------------------------------------- |
| Genel           | `Genel`             | `personel-kart-tab-genel-bilgiler` | `personel-kart-panel-genel-bilgiler`  | Kimlik, organizasyon, puantaj/izin özetleri |
| Eğitim/Belgeler | `Eğitim / Belgeler` | `personel-kart-tab-egitim-belgeler` | `personel-kart-panel-egitim-belgeler` | Belge durumu + eğitim/sertifika kayıtları   |
| Disiplin        | `Disiplin`          | `personel-kart-tab-disiplin` | `personel-kart-panel-disiplin`        | Finans ceza + süreç sinyalleri              |
| Zimmet          | `Zimmet`            | `personel-kart-tab-zimmet-envanter` | `personel-kart-panel-zimmet-envanter` | Zimmet/envanter listesi + gateway           |
| Süreç Geçmişi   | `Süreç Geçmişi`     | `personel-kart-tab-surec-gecmisi` | `personel-kart-panel-surec-gecmisi`   | Timeline/event görünümü                     |

Sekme state davranışı:

- Aktif tab state `PersonelDetayPage` içinde `useState<PersonelDosyaTabId>` ile tutulur.
- **URL tab deep-link yok.**
- `personelId` değişince (gateway return state yoksa) tab `genel-bilgiler`'e resetlenir.
- Gateway dönüş state (`openPersonelEdit`, `openPersonelZimmet`) tab ve/veya inline edit / zimmet modal açar.

Sekme butonlarında dedicated `data-testid` yok; E2E `role="tab"` + label kullanır.

---

## 6. Genel sekme / hesaplanan alanlar / maaş contract

### Hero (`PersonelDosyaHero`)

- Ad, soyad, sicil, departman, görev, çalışma durumu, işe giriş tarihi.
- Pasif personelde `pasiflik_durumu_etiketi` veya `PASIF` etiketi.

### Genel panel (`PersonelKartPanelGenelBilgiler`)

- Kimlik ve İletişim (salt okunur `DossierRecord`)
- Organizasyon ve Acil Durum
- Aylık Puantaj Özeti (`PersonelPuantajOzetSection`) — SGK prim günü, devam primi readonly kartları
- İzin Özeti (`PersonelIzinOzetSection`) — kıdem, yıllık hak, kullanılan, **kalan izin** (`hesaplaIzinBakiye`)

### Hesaplanan alanlar

- Görüntüleme modunda tüm alanlar **salt okunur**; input değildir.
- Düzenleme yalnızca gateway sonrası `PersonelInlineEditForm` (ayrı mod).
- Hizmet/kıdem ve kalan izin hesaplanmış gösterimdir; ayrı “hizmet süresi” etiketi yok.

### Maaş contract

| Konu | Değer |
| ---- | ----- |
| Kartta maaş tutarı | Gösterilmez |
| Eksiklik uyarısı | Gösterilir |
| Kayıt sırasında maaş | Opsiyonel |
| Exact metin | `Maaş bilgisi eksik.` |
| testid | `personel-maas-eksik-uyari` |
| Mock fixture | personel id **4** — `Maas Eksik` (`maas_tutari` yok) |
| S15B guard | `personel karti maas eksik uyarisini gosterir` |

Kayıt akışı maaş uyarısı ayrıca `kayit-yeni-personel.spec.ts` ile kilitlidir; S15B Personel Kartı owner içinde id 4 fixture'ını kilitler.

---

## 7. Eğitim/Belgeler, Disiplin, Zimmet contract

### Eğitim / Belgeler

- Belge durumu + eğitim/sertifika kayıtları **aynı sekmede** (`PersonelBelgelerPanel`).
- Kart **read-only** gösterimdir; düzenleme Kayıt ve Süreç merkezi üzerinden yürür.
- testid: `personel-belgeler-panel`, `personel-belge-kayit-list`

Endpointler:

- `GET /api/personeller/:id/belge-durumu`
- `GET /api/personeller/:id/belge-kayitlari`

E2E: `yonetici egitim belgeler sekmesinde read-only belge durumunu gorur`, `kayit-belge-kayitlari.spec.ts`

### Disiplin

- Finans CEZA kayıtları + süreç disiplin sinyalleri (devamsızlık vb.).
- testid: `personel-disiplin-panel`, `personel-disiplin-ceza-section`, `personel-disiplin-surec-signals`
- `finans.view` yoksa finans ceza bölümünde yetkisiz mesaj:

  **`Finans ceza kayıtlarını görüntüleme yetkiniz yok.`**

- BIRIM_AMIRI `finans.view` taşımaz → S15B ile kilitlendi.
- Ceza ekleme karttan yapılmaz; Kayıt ve Süreç / Finans modülü üzerinden.

E2E: `yonetici disiplin sekmesinde read-only ceza ve surec sinyallerini gorur`, `birim amiri personel kartinda yetkisiz aksiyonlari gormez`

### Zimmet

- Zimmet listesi kartta tablo olarak görünür (`.personel-zimmet-table`).
- `Yeni Zimmet Ekle` yetkiye bağlıdır (`personeller.update`).
- BIRIM_AMIRI için hem action menüsünde hem Zimmet sekmesinde ekle butonu **yoktur**.
- Zimmet gateway dönüş state: `openPersonelZimmet: true` → zimmet sekmesi + modal açılır.

E2E (gateway): `zimmet gateway donusu…`, `kart duzenle gateway…`, reload sonrası state temizliği  
Unit: `usePersonelKartGatewayReturn.test.ts`, `kayit-modal-contract.test.ts`

---

## 8. Süreç geçmişi / timeline contract

| Konu | Owner / değer |
| ---- | ------------- |
| Sekme | `Süreç Geçmişi` |
| Panel | `PersonelSurecGecmisiPanel` |
| Timeline builder | `buildPersonelTimeline` (`personel-timeline-utils.ts`) |
| Sort owner | `src/lib/surec-history-sort.ts` |
| testid | `personel-surec-timeline` |

Kaynaklar:

- Personel kartından sentetik **İşe Giriş** (`personel.ise_giris_tarihi`)
- `fetchSureclerList` — `usePersonelDetail`, limit **20**
- `fetchZimmetlerList` — zimmet teslim/iade eventleri

State sahibi backend/mock kaynaklarıdır; frontend timeline birleştirir ve sıralar.

Sıralama önceliği (yeniden eskiye): `effective_date` → `baslangic_tarihi` → `created_at` → id.

UI'da `effective_date` varsa birincil tarih: `Geçerlilik: {date}`.

### Event destekleri

| Event                      | Durum                       |
| -------------------------- | --------------------------- |
| İşe giriş                  | Var (sentetik)              |
| İzin                       | Var                         |
| Devamsızlık / geç gelme    | Var                         |
| İşten ayrılma              | Var, süreç/event olarak     |
| Org / pozisyon değişikliği | Var                         |
| Bağlı amir değişikliği     | Var                         |
| Zimmet teslim/iade         | Var                         |
| Belge/sertifika            | Timeline'da yok, ayrı sekme |
| Teşvik                     | Timeline'da yok             |
| Maaş değişikliği           | Timeline'da yok             |

**Ürün kuralı:** İşten ayrılma kart alanı değildir; süreç/event olarak kalmalıdır (`02-mvp-veri-kapsami.md`, `05-state-flow-api-kontrati.md`). Hero'da pasiflik etiketi görünür; ayrı “işten ayrılma” kart field'ı yoktur.

### S15B kilitlenen seed (personel id 1)

Mock süreç seed:

- IZIN — `effective_date: 2026-04-10`
- DEVAMSIZLIK — `baslangic_tarihi: 2026-03-08`

Mock zimmet seed:

- KASK — teslim `2026-03-01`
- KULAKLIK — teslim `2026-01-15`, iade `2026-02-20`

S15B guard assert:

- İşe Giriş, İzin, Devamsızlık, Zimmet/Kask görünür
- **İlk satır İzin + `2026-04-10`**

Genişletilmiş çoklu-event ordering (org + işten ayrılma + zimmet karışımı) ayrı fixture gerektirebilir; §14 backlog.

---

## 9. Kayıt ve Süreç merkezi / gateway contract

Personel Kartı aksiyonları (`PersonelDosyaActionRow`):

| Aksiyon | Yetki | Davranış |
| ------- | ----- | -------- |
| Süreç Ekle | `surecler.create` | `navigate("/", { kayitModal: { tab: "surec", personelId } })` |
| Kartı Düzenle | `personeller.update` | Gateway: `intent: personel-edit-gateway`, `returnTo: /personeller/:id` |
| Yeni Zimmet Ekle | `personeller.update` | Gateway: `intent: personel-zimmet-gateway`, `returnTo: /personeller/:id` |
| Süreç Geçmişini Aç | `surecler.view` / `view.sube` (create yoksa) | Tab → süreç geçmişi |

Gateway akışı:

1. `kayitModal` route state → `useKayitModalController` consume + `replace state: null`
2. `KayitSurecWorkspace` + `KayitGatewayRedirectPanel` bilgi mesajı
3. Dönüş butonu → `useKayitGatewayIntent.handleGatewayReturn`
4. `navigate(returnTo, { openPersonelEdit \| openPersonelZimmet })`
5. `usePersonelKartGatewayReturn` consume + tab/edit/modal aç + state temizle

**Kritik:** Route state consume sonrası temizlenir; F5/reload sonrası modal tekrar açılmaz.

E2E: gateway edit/zimmet, reload temizliği  
Unit: `usePersonelKartGatewayReturn.test.ts`, `useKayitGatewayIntent.test.ts`, `kayit-modal-contract.test.ts`

S15B: BIRIM_AMIRI gateway başlatan aksiyonları görmez (`birim amiri personel kartinda gateway baslatan aksiyonlari goremez`, `birim amiri personel kartinda yetkisiz aksiyonlari gormez`)

---

## 10. E2E ile kilitlenen davranışlar

Kaynak test dosyası: `tests/e2e/personel-dosya.spec.ts` (17 test)

### S15B — yeni guard'lar

#### `personel karti maas eksik uyarisini gosterir`

- Rol: GENEL_YONETICI
- personel id **4**
- `personel-maas-eksik-uyari` + exact `Maaş bilgisi eksik.`
- Beş sekme görünür; pageerror yok

#### `birim amiri personel kartinda yetkisiz aksiyonlari gormez`

- Rol: BIRIM_AMIRI
- personel id **1**
- Kartı Düzenle / Yeni Zimmet Ekle / Süreç Ekle yok
- Disiplin: finans ceza yetkisiz mesajı
- Zimmet: ekle butonu yok; mevcut satırlar görünür

#### `personel karti surec gecmisi mevcut eventleri sirali gosterir`

- Rol: GENEL_YONETICI
- personel id **1**
- İşe Giriş, İzin, Devamsızlık, Zimmet/Kask
- İlk satır İzin + `2026-04-10`

### Önceki sprintlerden kilitlenen davranışlar (özet)

| Test | Kilitlenen davranış |
| ---- | ------------------- |
| `personel karti bes sekme modelini ve temel panelleri gosterir` | 5 sekme + panel testid/boş-state |
| `devam primi readonly karti personel gecisinde…` | Cache izolasyonu |
| `yonetici surec ekler ve isten ayrilma…` | ISTEN_AYRILMA → pasif + timeline |
| `yonetici zimmet ekler…` | Zimmet tablo + gateway |
| `zimmet gateway donusu…` | Tab korunması + F5 state temizliği |
| `kart duzenle gateway donusu…` | Edit modu açılır |
| `kart duzenle gateway reload…` | Reload sonrası modal/state yok |
| `birim amiri… gateway baslatan aksiyonlari goremez` | Gateway UI yok |
| `yonetici surec modalinda zimmet…` | Kayıt → kart timeline |
| `yonetici departman… org surecini…` | Org timeline tepesi |
| `yonetici bagli amiri degistirdiginde…` | Amir timeline event |
| `yonetici izlenen org alanlarina dokunmadan…` | Gereksiz süreç üretilmez |
| `yonetici egitim belgeler sekmesinde…` | Read-only belge |
| `yonetici disiplin sekmesinde…` | Read-only ceza + sinyaller |

İlgili dış E2E:

- `kayit-yeni-personel.spec.ts` — maaş opsiyonel + uyarı (Kayıt akışı)
- `kayit-belge-kayitlari.spec.ts` — belge → kart read-only
- `kayit-ayrilma.spec.ts` — ayrılma → pasif hero
- `smoke.spec.ts`, `role-smoke.spec.ts` — menü/liste erişimi

---

## 11. Mock/API notu

S15B'de `tests/e2e/helpers/mock-api.ts` **değişmedi**.

Mevcut fixture (Personel Kartı E2E için yeterli):

| Fixture | Değer |
| ------- | ----- |
| personel id 1 | Ayşe Yılmaz — maaş dolu, timeline seed |
| personel id 2 | Mehmet Kaya — gateway zimmet testleri |
| personel id 3 | Pasif Ornek |
| personel id 4 | Maas Eksik — maaş yok |
| surecler (id 1) | IZIN + DEVAMSIZLIK |
| zimmetler (id 1) | KASK + KULAKLIK (iade) |
| belge kayıtları | Forklift, Ehliyet |

Mock/API drift riskleri:

- Süreç list limit **20** — timeline eksik kalabilir
- Belge/sertifika timeline dışı — ürün beklentisi farklıysa drift
- Teşvik/maaş timeline dışı
- Branch/şube kapsamı sekme E2E'de sınırlı
- Deterministik çoklu-event ordering geniş fixture gerektirebilir

---

## 12. Unit test kapsamı

| Dosya | Kapsam |
| ----- | ------ |
| `usePersonelKartGatewayReturn.test.ts` | Gateway dönüş state contract |
| `usePersonelDetail.test.ts` | Detay hook, stale response koruması |
| `personel-disiplin-utils.test.ts` | Disiplin sinyal filtre/sıralama |
| `kayit-modal-contract.test.ts` | `kayitModal` route state parse |
| `useKayitGatewayIntent.test.ts` | Gateway mesaj + return navigate |

Eksik / opsiyonel teknik backlog:

- `personel-timeline-utils` direkt unit test yok
- `surec-history-sort` direkt unit test yok

---

## 13. Korunacak guardlar

1. `tests/e2e/personel-dosya.spec.ts` bozulmadan Personel Kartı değişikliği merge edilmez.
2. Beş sekme label/panel contract değişirse E2E güncellenmeden geçilemez.
3. Maaş eksik contract bozulamaz:
   - `Maaş bilgisi eksik.`
   - `personel-maas-eksik-uyari`
4. BIRIM_AMIRI yetkisiz aksiyonları görünür hale gelirse E2E kırılmalıdır.
5. Disiplin finans ceza yetki davranışı korunmalıdır.
6. Zimmet gateway return state temizliği korunmalıdır.
7. İşten ayrılma kart field değil süreç/event olarak kalmalıdır.
8. Timeline ordering değişirse S15B guard'ı bilinçli güncellenmelidir.
9. Kayıt ve Süreç gateway state temizliği korunmalıdır.
10. Mock fixture değiştirilirse maaş eksik id 4 ve timeline id 1 guard'ları korunmalıdır.

---

## 14. Açık ürün kararları

| Konu                                                | Durum                |
| --------------------------------------------------- | -------------------- |
| URL ile tab deep-link                               | Ürün kararı bekliyor |
| Timeline'da belge/sertifika eventleri               | Ürün kararı bekliyor |
| Timeline'da teşvik eventleri                        | Ürün kararı bekliyor |
| Maaş değişikliği event olarak tutulacak mı?         | Ürün kararı bekliyor |
| Maaş tutarı kartta gösterilecek mi?                 | Ürün kararı bekliyor |
| BOLUM_YONETICISI / MUHASEBE sekme aksiyon matrisi   | Ek E2E/backlog       |
| Personel Kartı branch/şube kapsam E2E               | Ek E2E/backlog       |
| Süreç geçmişi için direkt unit test                 | Teknik backlog       |
| Personel Kartı sekmelerinin URL state ile korunması | Ürün/UX kararı       |
| Genişletilmiş timeline ordering fixture             | Test+mock backlog    |

---

## 15. Doğrulama komutları

Personel Kartı hattında değişiklik sonrası minimum gate:

```bash
npm run typecheck
npm run test
npm run build
npx playwright test tests/e2e/personel-dosya.spec.ts
npm run e2e
```

---

## 16. Sonuç

Personel Kartı hattında beş sekme, maaş eksik uyarısı, BIRIM_AMIRI read-only aksiyon görünürlüğü, Disiplin/Zimmet yetki davranışı ve Süreç Geçmişi event/ordering guard'ları E2E ile kilitlendi.

Personel Kartı hattı mevcut MVP davranışı açısından güvenli devir noktasına geldi. Bundan sonra yeni geliştirme yapılacaksa önce §14 açık ürün kararlarından biri seçilmeli; guard'lar (§13) korunmalıdır.
