# 68 — Kayıt ve Süreç Merkezi Hattı S16 Kapanış Devir Notu

---

## 1. Amaç

Bu not, Kayıt ve Süreç Merkezi hattında S16A owner/gap analizi ve S16B E2E guard sonuçlarını tek yerde toplar.

Sonraki geliştiricinin Kayıt ve Süreç modal/workspace hattında nereden devam edeceğini, hangi owner dosyalarına dokunacağını, gateway route state contract'ını, mock/API drift risklerini ve bilinçli olarak açık bırakılan ürün kararlarını görmesi için yazılmıştır.

**Bu not implementasyon talimatı değildir.** Yeni özellik eklemeden önce §16 açık ürün kararlarından biri seçilmeli ve ilgili E2E/unit guard'ları (§13, §15) korunmalıdır.

Raporlar hattı `65`, Puantaj hattı `66`, Personel Kartı hattı `67` ile kapanmıştır; bu not Kayıt ve Süreç Merkezi hattını kapatır.

**Son kilit commit:** `88c94db` — `test: kayit surec gateway ve rol guardlarini kilitle`  
**Branch:** `main` (`origin/main` ile hizalı)

---

## 2. Kapsanan sprintler

| Sprint | Commit / Durum | Kilitlenen veya netleşen davranış                                                  |
| ------ | -------------- | ---------------------------------------------------------------------------------- |
| S16A   | analiz-only    | Kayıt ve Süreç owner, kayıt/süreç/gateway/rol/API/mock haritası                    |
| S16B   | `88c94db`      | Personel Kartı → Süreç Ekle → izin timeline, BIRIM_AMIRI kayıt/süreç negatif guard |

---

## 3. Kayıt ve Süreç modül haritası

| Alan                          | Owner                                                                                                                              |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Shell/modal                   | `src/app/AppShell.tsx`                                                                                                             |
| Workspace                     | `src/features/kayit/components/KayitSurecWorkspace.tsx`                                                                            |
| Üst tab header                | `src/features/kayit/components/KayitSurecTabHeader.tsx`                                                                            |
| Gateway redirect panel        | `src/features/kayit/components/KayitGatewayRedirectPanel.tsx`                                                                      |
| Modal controller              | `src/features/kayit/hooks/useKayitModalController.ts`                                                                              |
| Gateway intent                | `src/features/kayit/hooks/useKayitGatewayIntent.ts`                                                                                |
| Modal contract                | `src/features/kayit/kayit-modal-contract.ts`                                                                                       |
| Kayıt formu                   | `src/features/personeller/components/PersonelCreateFields.tsx`, `src/hooks/usePersoneller.ts`, `personel-create-utils.ts`         |
| Süreç formu                   | `KayitSurecWorkspace.tsx`, `SurecFormFields`, `src/api/surecler.api.ts`, `src/types/surec.ts`                                      |
| Personel Kartı gateway dönüşü | `src/features/personeller/hooks/usePersonelKartGatewayReturn.ts`                                                                   |
| Personel Kartı timeline       | `PersonelSurecGecmisiPanel.tsx`, `personel-timeline-utils.ts`, `src/lib/surec-history-sort.ts`                                     |
| API                           | `personeller.api.ts`, `surecler.api.ts`, `zimmetler.api.ts`, `belgeler.api.ts`, `personel-belge-kayitlari.api.ts`, `finans.api.ts` |
| Permission                    | `src/lib/authorization/role-permissions.ts`                                                                                        |
| Menü erişimi                  | `src/components/main-menu/MainMenu.tsx` (`menu-kayit-surec`, `canKayitSection`)                                                    |
| E2E test                      | `personel-dosya.spec.ts`, `role-smoke.spec.ts`, `kayit-*.spec.ts`                                                                  |
| E2E mock                      | `tests/e2e/helpers/mock-api.ts`                                                                                                    |
| Unit                          | `kayit-modal-contract.test.ts`, `useKayitGatewayIntent.test.ts`, `usePersonelKartGatewayReturn.test.ts`, `usePersonelDetail.test.ts` |

İlgili ama ayrı owner'lar:

- Personel Kartı sayfası: `PersonelDetayPage.tsx`
- Süreç takip sayfası (liste): `SurecTakipPage.tsx` — Kayıt modalından bağımsız route
- Belge kayıt bölümü: `KayitBelgeKayitlariSection.tsx`
- Süreç sabitleri/tab modeli: `kayit-surec-constants.ts`

---

## 4. Modal / route state contract

Kayıt ve Süreç Merkezi **dedicated route değildir**. Ana sayfa (`/`) üzerinde `AppShell` içinde global modal olarak açılır.

| Konu | Davranış |
| ---- | -------- |
| Ana menü girişi | `menu-kayit-surec` → `openKayitModal(tab)` |
| Personel Kartı girişi | `navigate("/", { state: { kayitModal: … } })` |
| State çözümleme | `resolveKayitModalRouteConfig` (`kayit-modal-contract.ts`) |
| State tüketimi | `useKayitModalController` — route state okununca modal açılır |
| State temizliği | `navigate(pathname, { replace: true, state: null })` — consume sonrası URL state sıfırlanır |
| Modal kapanış | `closeKayitModal` — entry context (`initialSurecPersonelId`, intent, returnTo) resetlenir |
| Reload/F5 | Consume edilmiş state tekrar yok; modal otomatik açılmaz |
| Gateway dönüş | `usePersonelKartGatewayReturn` — `openPersonelEdit` / `openPersonelZimmet` state tüketir ve temizler |

`kayitModal` route state şekli:

```ts
{
  kayitModal: {
    tab: "yeni-kayit" | "surec",
    personelId?: string | number,
    intent?: "personel-edit-gateway" | "personel-zimmet-gateway",
    returnTo?: string
  }
}
```

Personel Kartı gateway dönüş state:

```ts
{ openPersonelEdit: true }   // inline düzenleme modu
{ openPersonelZimmet: true } // zimmet sekmesi + create modal
```

**Kritik:** Modal kapanınca veya state consume edilince aynı state tekrar tetiklenmemelidir. Gateway edit/zimmet reload senaryoları `personel-dosya.spec.ts` ve unit testlerle kilitlidir.

---

## 5. Tab contract

### Üst sekmeler

| Tab ID | UI etiketi | Primary action |
| ------ | ---------- | -------------- |
| `yeni-kayit` | Kayıt / Yeni Kayıt | `Kaydet` → `KAYIT_SUREC_PERSONEL_FORM_ID` |
| `surec` | Süreç | `Süreci Kaydet` → `KAYIT_SUREC_SUREC_FORM_ID` |

Kaynak: `KayitSurecTabHeader.tsx`, `useKayitModalController` (`kayitPrimaryLabel`, `kayitPrimaryFormId`).

### Süreç alt alanları (`PERSONEL_SUREC_TABS`)

| Alt sekme ID | Etiket |
| ------------ | ------ |
| `genel` | Genel |
| `izin-devamsizlik` | İzin / Devamsızlık |
| `pozisyon` | Pozisyon |
| `belgeler` | Belgeler |
| `mali` | Mali İşlemler |
| `zimmet` | Zimmet |
| `ceza` | Ceza |
| `ayrilma` | Ayrılma |

### Gateway prefill → klasik süreç layout

Personel Kartı'ndan `Süreç Ekle` ile gelen `initialSurecPersonelId` (route state `personelId`) şu kuralı tetikler:

```ts
const classicSurecFormLayout = editingSurec !== null || hasInitialSurecPersonel;
```

`classicSurecFormLayout === true` iken shell alt sekmeleri yerine klasik `SurecFormFields` layout'u açılır. S16B E2E testi bu davranışı gateway + izin timeline yansıması ile kilitlemiştir.

Pasif personelde ilgili alt sekmeler form yerine placeholder gösterir (`kayit-pasif-surec-shell.spec.ts`).

---

## 6. Yeni personel kayıt contract

| Konu | Owner / davranış |
| ---- | ---------------- |
| Form UI | `PersonelCreateFields` |
| Submit | `KayitSurecWorkspace.handlePersonelSubmit` |
| Payload builder | `buildCreatePersonelPayload` (`personel-create-utils.ts`) |
| Hook/API | `usePersoneller` → `createPersonel` → `POST /api/personeller` |

### Zorunlu alanlar

- TC Kimlik No (`create-tc`)
- Ad (`create-ad`)
- Soyad (`create-soyad`)
- Doğum tarihi (`create-dogum`)
- Telefon (`create-telefon`)
- Acil kişi / acil tel (`create-acil-kisi`, `create-acil-tel`)
- Sicil (`create-sicil`)
- İşe giriş (`create-ise-giris`)
- **Şube** (`create-sube`) — boş bırakılırsa `"Şube seçilmelidir."`
- Departman / Bölüm
- Görev / Unvan
- Personel tipi

### Maaş opsiyonel kuralı

- Maaş alanı boş bırakılabilir.
- Payload'da `maas_tutari` **yalnızca** geçerli sayı girildiyse eklenir; boşsa key gönderilmez.
- Kaynak: `buildCreatePersonelPayload` — `...(maasTutari !== undefined ? { maas_tutari: maasTutari } : {})`

### Personel Kartı uyarısı

Maaş eksik personelde Hero alanında:

| Alan | Değer |
| ---- | ----- |
| Metin | `Maaş bilgisi eksik.` |
| testid | `personel-maas-eksik-uyari` |
| Owner | `PersonelDosyaHero.tsx`, `isPersonelMaasMissing` |

Bu kural `kayit-yeni-personel.spec.ts` ve S15B Personel Kartı guard'ı (`personel-dosya.spec.ts`) ile kilitlidir.

### UX notu

Yeni personel ekleme yolu yalnızca Kayıt ve Süreç modalındadır; Personeller listesinde ayrı "Yeni personel ekle" butonu yoktur (`kayit-yeni-personel.spec.ts`).

---

## 7. Süreç Ekle contract

| Konu | Davranış |
| ---- | -------- |
| Oluşturma | `POST /api/surecler` |
| Liste/timeline | `GET /api/surecler` → Personel Kartı Süreç Geçmişi |
| Personel Kartı giriş | `handleOpenSurecModal` → `kayitModal: { tab: "surec", personelId }` |
| Form alanları | `surec-create-personel`, `surec-create-turu`, `surec-create-alt`, `surec-create-bas`, `surec-create-bitis`, `surec-create-aciklama` |

### S16B ile kilitlenen gateway akışı

1. Personel Kartı `/personeller/1`
2. `Süreç Ekle`
3. Kayıt ve Süreç modalı — Süreç tabı seçili
4. Personel prefill (`surec-create-personel` = `1`)
5. `IZIN` + `YILLIK_IZIN`
6. Tarih `2026-04-15`
7. Personel Kartı Süreç Geçmişi timeline'da yeni izin event'i görünür

Kaynak test: `personel-dosya.spec.ts` — `personel kartindan surec ekle izin kaydini timelinea yansitir`

### Süreç tipi tablosu

| Tip                    | Kayıt merkezi              | Timeline           | Model                        |
| ---------------------- | -------------------------- | ------------------ | ---------------------------- |
| İzin                   | Var                        | Var                | Event                        |
| Devamsızlık            | Var                        | Var                | Event                        |
| İş kazası / rapor      | Var                        | Var                | Event                        |
| Görev/unvan/bölüm/amir | Var                        | Var                | Kart güncelleme + event      |
| İşten ayrılma          | Var                        | Var                | Event only                   |
| Belge/sertifika        | Var                        | Timeline yok       | Belge sekmesi/kayıt listesi  |
| Zimmet                 | Var + Personel Kartı modal | Var                | Envanter + event             |
| Disiplin/ceza          | Var                        | Sınırlı            | Finans/ceza kaydı            |
| Teşvik                 | Kayıt süreci değil         | Timeline yok       | Rapor aggregate              |
| Maaş değişikliği       | Ayrı süreç tipi yok        | ORG event olabilir | Kart alanı + lifecycle event |

---

## 8. İşten Ayrılma contract

İşten Ayrılma **Personel Kartı alanı değildir**; süreç/event modeli olarak kalmalıdır.

| Konu | Davranış |
| ---- | -------- |
| Süreç tipi | `surec_turu: "ISTEN_AYRILMA"` |
| Kayıt yeri | Kayıt modalı → Süreç → Ayrılma sekmesi |
| Mock side-effect | `POST /api/surecler` sonrası personel `aktif_durum = "PASIF"` |
| Hero | Pasif / İşten Ayrıldı durumu |
| Timeline | İşten ayrılma event'i görünür |
| Pasif guard | Pasif personelde Ayrılma formu yerine uyarı placeholder |

E2E kaynakları:

- `kayit-ayrilma.spec.ts` — Kayıt modalı Ayrılma sekmesi
- `personel-dosya.spec.ts` — `yonetici surec ekler ve isten ayrilma personel durumunu pasife ceker`

**Bu model bozulursa ürün contract ihlali sayılır.**

---

## 9. Gateway contract

### Personel Kartı → Kartı Düzenle

| Alan | Değer |
| ---- | ----- |
| Intent | `personel-edit-gateway` |
| Tab | `yeni-kayit` |
| returnTo | `/personeller/:id` |
| Dönüş state | `{ openPersonelEdit: true }` |
| Sonuç | Genel Bilgiler sekmesi + inline edit modu |

Kayıt modalında `KayitGatewayRedirectPanel` geçiş mesajı gösterir; kullanıcı "Personel Kartına dön ve düzenle" ile döner.

### Personel Kartı → Süreç Ekle

| Alan | Değer |
| ---- | ----- |
| Tab | `surec` |
| personelId | prefill |
| Layout | Klasik `SurecFormFields` (`classicSurecFormLayout`) |
| S16B guard | İzin timeline yansıması E2E ile kilitli |

### Personel Kartı → Yeni Zimmet (gateway geçişi)

| Alan | Değer |
| ---- | ----- |
| Intent | `personel-zimmet-gateway` |
| Tab | `yeni-kayit` (geçiş bilgi paneli) |
| Dönüş state | `{ openPersonelZimmet: true }` |
| Sonuç | Zimmet sekmesi + create modal |

Not: Doğrudan zimmet ekleme Personel Kartı'nda `Yeni Zimmet Ekle` butonu ile de yapılabilir; gateway yolu geçiş bilgilendirmesi içindir.

### State temizliği

- Route state consume sonrası `replace: true, state: null`
- F5/reload sonrası modal ve gateway state tekrar açılmaz
- Unit: `useKayitGatewayIntent.test.ts`, `usePersonelKartGatewayReturn.test.ts`
- E2E: `personel-dosya.spec.ts` gateway reload testleri

---

## 10. Belge / Zimmet / Disiplin contract

### Belge

İki model vardır:

1. **Belge durumu** — VAR/YOK kaydı (`kayit-belgeler.spec.ts`)
2. **Belge/eğitim/sertifika kayıtları** — ayrı kayıt listesi (`kayit-belge-kayitlari.spec.ts`)

Personel Kartı Eğitim/Belgeler sekmesine yansır; **timeline'a düşmez**.

Pasif personelde belge formları yazma kapalıdır.

### Zimmet

- Kayıt merkezi Zimmet sekmesi
- Personel Kartı `PersonelZimmetCreateModal` + gateway yolu
- Timeline'da zimmet teslim/iade event'leri görünür
- BIRIM_AMIRI: `Yeni Zimmet Ekle` yok (`personel-dosya.spec.ts`, `role-smoke.spec.ts`)

E2E: `personel-dosya.spec.ts` — zimmet ekleme, gateway dönüşü, kayıt modal zimmet sekmesi

### Disiplin / Ceza

- Kayıt/Süreç Ceza sekmesi → finans/ceza kaydı (`kayit-ceza-finans.spec.ts`)
- Personel Kartı Disiplin sekmesi read-only (`personel-dosya.spec.ts`)
- BIRIM_AMIRI: `finans.view` yok → ceza/finans kayıtları görünmez

---

## 11. Role / permission contract

Menü kuralı (`MainMenu.tsx`):

```ts
const canKayitSection = hasPermission("personeller.create") || hasPermission("surecler.create");
```

`canKayitSection === false` → `menu-kayit-surec` disabled.

| Aksiyon          | GENEL_YONETICI | BOLUM_YONETICISI | MUHASEBE     | BIRIM_AMIRI  |
| ---------------- | -------------- | ---------------- | ------------ | ------------ |
| Kayıt merkezi aç | Evet           | Evet             | Evet         | Hayır        |
| Yeni personel    | Evet           | Evet             | Evet         | Hayır        |
| Süreç ekle       | Evet           | Evet             | Evet         | Hayır        |
| Kartı düzenle    | Evet           | Evet             | Evet         | Hayır        |
| Zimmet ekle      | Evet           | Evet             | Evet         | Hayır        |
| Belge/sertifika  | Evet           | Evet             | Evet         | Hayır        |
| İşten ayrılma    | Evet           | Evet             | Evet         | Hayır        |
| Şube kapsamı     | Tümü/mock      | Rol şubeleri     | Rol şubeleri | Kendi şubesi |

Permission kaynağı: `role-permissions.ts`

- `GENEL_YONETICI`, `BOLUM_YONETICISI`, `MUHASEBE`: `personeller.create`, `surecler.create`, `personeller.update`, `finans.view`
- `BIRIM_AMIRI`: yalnızca `personeller.view.sube`, `surecler.view.sube`, `personeller.detail.view` — create/update/finans yok

S16B negatif guard (`role-smoke.spec.ts`):

- `menu-kayit-surec` disabled
- Force click sonrası modal açılmaz
- `/personeller/1` kartında Süreç Ekle, Kartı Düzenle, Yeni Zimmet Ekle görünmez

---

## 12. API / mock notu

S16B'de `mock-api.ts` **değişmedi**. Mevcut handler'lar yeterlidir.

### POST /api/surecler

- Yeni süreç in-memory `surecler` listesine `unshift` ile eklenir
- `ISTEN_AYRILMA` → ilgili personel `aktif_durum = "PASIF"`
- Personel Kartı timeline `GET /api/surecler?personel_id=…` ile yeni event'i görür

### POST /api/personeller

- Yeni personel `personeller` listesine eklenir
- `maas_tutari` opsiyonel — gönderilmezse undefined kalır

Mock state Personel Kartı, Kayıt/Süreç ve rapor fixture'larıyla paylaşılır.

### Drift riskleri

| Risk | Not |
| ---- | --- |
| Active şube switch E2E yok | Şube header/switch davranışı Kayıt hattında ayrı testlenmemiş |
| Belge/sertifika timeline dışı | Ürün kararı bekliyor |
| Teşvik rapor-only | Kayıt süreci değil |
| Maaş değişikliği ayrı süreç tipi değil | ORG/lifecycle event olabilir |
| Workspace büyüklüğü | `KayitSurecWorkspace.tsx` geniş; UI selector drift riski var |
| Shell vs klasik layout | Gateway prefill layout dallanması selector kırılganlığı yaratabilir |

---

## 13. E2E ile kilitlenen davranışlar

### S16B — yeni kilitler

#### `personel kartindan surec ekle izin kaydini timelinea yansitir`

Kaynak: `tests/e2e/personel-dosya.spec.ts`

- Rol: `GENEL_YONETICI`
- Personel id: `1`
- Personel Kartı → `Süreç Ekle`
- Modal Süreç tabı, URL `/`
- Personel prefill (`surec-create-personel` = `1`)
- `IZIN` + `YILLIK_IZIN`
- Tarih `2026-04-15`
- Personel Kartı Süreç Geçmişi timeline'da izin event'i

#### `birim amiri kayit ve surec merkezini acamaz`

Kaynak: `tests/e2e/role-smoke.spec.ts`

- Rol: `BIRIM_AMIRI`
- `menu-kayit-surec` disabled
- Force click sonrası modal yok
- `/personeller/1`:
  - Süreç Ekle yok
  - Kartı Düzenle yok
  - Yeni Zimmet Ekle yok

### Mevcut Kayıt/Süreç E2E özeti

| Dosya | Kilitlenen davranış |
| ----- | ------------------- |
| `kayit-yeni-personel.spec.ts` | Şube zorunlu, maaş opsiyonel, `personel-maas-eksik-uyari`, liste ekranında yeni personel yolu yok |
| `kayit-ayrilma.spec.ts` | `ISTEN_AYRILMA`, pasif durum, timeline event, pasif placeholder |
| `kayit-belge-kayitlari.spec.ts` | Belge kaydı oluşturma, kartta read-only görünüm |
| `kayit-belgeler.spec.ts` | Belge durumu VAR/YOK persist |
| `kayit-pozisyon.spec.ts` | Pozisyon değişikliği PUT + `POZISYON_DEGISTI` POST, effective_date |
| `kayit-mali-finans.spec.ts` | Mali kayıt → Finans modülü |
| `kayit-ceza-finans.spec.ts` | Ceza kaydı → Finans modülü |
| `kayit-pasif-surec-shell.spec.ts` | Pasif personelde form placeholder'ları |

### Personel Kartı E2E (Kayıt hattı ile kesişen)

| Test | Konu |
| ---- | ---- |
| Gateway edit/zimmet reload | State temizliği, F5 sonrası modal tekrar açılmaz |
| Maaş eksik uyarısı | Hero contract |
| BIRIM_AMIRI negatif aksiyonlar | Gateway başlatan butonlar görünmez |
| Süreç geçmişi ordering | Mevcut event sıralaması |
| Zimmet ekleme / timeline | Envanter + süreç geçmişi |
| Disiplin read-only | Ceza sinyalleri |
| Eğitim/Belgeler read-only | Belge durumu |

---

## 14. Unit test kapsamı

| Dosya | Kapsam |
| ----- | ------ |
| `kayit-modal-contract.test.ts` | `resolveKayitModalRouteConfig` — surec prefill, gateway intent, invalid state |
| `useKayitGatewayIntent.test.ts` | Gateway mesaj görünürlüğü, dönüş navigate state (`openPersonelEdit` / `openPersonelZimmet`) |
| `usePersonelKartGatewayReturn.test.ts` | Route state consume + temizlik, permission guard, gateway navigate contract |
| `usePersonelDetail.test.ts` | Detay hook davranışı (Personel Kartı veri yükleme) |

### Eksik / opsiyonel

- `KayitSurecWorkspace` component-level unit yok (dosya büyük)
- Active şube switch için E2E yok
- Belge/sertifika timeline davranışı ürün kararı bekliyor
- BOLUM_YONETICISI / MUHASEBE aksiyon matrisi için ek negatif E2E yok

---

## 15. Korunacak guardlar

1. `tests/e2e/personel-dosya.spec.ts` bozulmadan Personel Kartı/Kayıt gateway değişikliği merge edilmez.
2. `tests/e2e/role-smoke.spec.ts` BIRIM_AMIRI menü erişim davranışını korur.
3. Maaş opsiyonel kalmalıdır — boş maaşta payload'a `maas_tutari` eklenmemeli.
4. Şube zorunlu kalmalıdır — `"Şube seçilmelidir."` validation.
5. İşten Ayrılma kart alanı değil süreç/event olarak kalmalıdır.
6. Gateway route state consume sonrası temizlenmelidir (`state: null`).
7. `Süreç Ekle` prefill bozulursa S16B testi kırılmalıdır.
8. `POST /api/surecler` mock state davranışı korunmalıdır (in-memory liste + ISTEN_AYRILMA pasif).
9. BIRIM_AMIRI Kayıt/Süreç modalını açamamalıdır.
10. Active şube switch ayrı backlog kararıdır; mevcut testler bunu kapsıyor gibi yorumlanmamalıdır.

---

## 16. Açık ürün kararları

| Konu                                               | Durum                |
| -------------------------------------------------- | -------------------- |
| Active şube switch E2E                             | S16C/backlog         |
| Belge/sertifika timeline'a düşecek mi?             | Ürün kararı bekliyor |
| Teşvik süreç/event olacak mı?                      | Ürün kararı bekliyor |
| Maaş değişikliği ayrı süreç tipi olacak mı?        | Ürün kararı bekliyor |
| Kayıt ve Süreç workspace parçalanacak mı?          | Teknik backlog       |
| URL/state ile modal deep-link kalıcı olacak mı?    | Ürün/UX kararı       |
| BOLUM_YONETICISI / MUHASEBE aksiyon matrisi ek E2E | Backlog              |
| Active şube/personel kapsam güvenlik testi         | Backlog              |

---

## 17. Doğrulama komutları

Kayıt ve Süreç hattında değişiklik sonrası minimum gate:

```bash
npm run typecheck
npm run test
npm run build
npx playwright test tests/e2e/personel-dosya.spec.ts
npx playwright test tests/e2e/role-smoke.spec.ts
npm run e2e
```

Doküman-only sprint (S16C) minimum gate:

```bash
npm run typecheck
npm run test
npm run build
```

---

## 18. Sonuç

Kayıt ve Süreç Merkezi hattında yeni personel kayıt, maaş opsiyonel, şube zorunlu, Süreç Ekle gateway, izin timeline yansıması, İşten Ayrılma event modeli ve BIRIM_AMIRI negatif erişim davranışı E2E/unit ile yeterli seviyede kilitlendi.

Kayıt ve Süreç hattı mevcut MVP davranışı açısından güvenli devir noktasına geldi.

Bundan sonra yeni geliştirme yapılacaksa önce §16 açık ürün kararlarından biri seçilmeli; seçilen karar için ilgili guard testleri genişletilmeli veya yeni E2E eklenmelidir.
