# 69 — Şube / Active Şube Hattı S17 Kapanış Devir Notu

---

## 1. Amaç

Bu not, Şube ve Active Şube hattında S17A owner/gap analizi, S17B mock + E2E guard sonuçlarını tek yerde toplar.

Sonraki geliştiricinin aktif şube seçimi, API scope header'ı, personel list/detail şube kapsamı, Şube Yönetimi ve mock drift risklerinde nereden devam edeceğini görmesi için yazılmıştır.

**Bu not implementasyon talimatı değildir.** Yeni özellik eklemeden önce §15 açık ürün kararlarından biri seçilmeli ve ilgili E2E guard'ları (§13) korunmalıdır.

Kayıt ve Süreç hattı `68`, Personel Kartı hattı `67`, Puantaj hattı `66`, Raporlar hattı `65` ile kapanmıştır; bu not Şube / Active Şube hattını kapatır.

**Son kilit commit:** `cb03b8b` — `test: sube scope mock ve direct url guardlarini kilitle`  
**Branch:** `main` (`origin/main` ile hizalı; CI + Deploy cPanel success)

---

## 2. Kapsanan sprintler

| Sprint | Commit / Durum | Kilitlenen veya netleşen davranış |
| ------ | -------------- | --------------------------------- |
| S17A   | analiz-only + recheck (`860ec7a` üzerinde) | Active şube owner, API header, ShellHeaderActions, Şube Yönetimi, mock gap haritası; **KARAR B** (test + mock, üretim kodu yok) |
| S17B   | `cb03b8b`      | Mock personel list/detail şube scope; `sube-scope.spec.ts` (BIRIM_AMIRI direct URL, MUHASEBE list/header, GENEL_YONETICI no-scope) |
| S17C   | bu not         | Şube hattı kapanış/devir dokümantasyonu |

---

## 3. Şube / Active Şube modül haritası

| Alan | Owner |
| ---- | ----- |
| Oturum `active_sube_id` | `src/auth/auth-manager.ts`, `src/auth/auth-session-sube.ts` (`finalizeAuthSessionSube`) |
| React session + switch | `src/state/auth.store.tsx` (`setActiveSubeId` → storage + `loadDataFromServer`) |
| API header `X-Active-Sube-Id` | `src/api/api-client.ts` → `getActiveSubeIdForApiHeader()` |
| List/query `sube_id` | `src/data/data-manager.ts` → `getSubeIdForApiRequest()` |
| Cache key segment | `data-manager.ts` → `dataCacheKeys.*` (`subeSeg`) |
| Header UI (şube seçici) | `src/components/shell/ShellHeaderActions.tsx` |
| Shell görünürlük | `src/app/AppShell.tsx` (`showShellHeaderActions`, `minimal={isHomeRoute}`) |
| Personel list scope | `src/hooks/usePersoneller.ts` |
| Personel detail scope + redirect | `src/hooks/usePersonelDetail.ts`, `src/lib/detail-sube-context.ts` |
| Liste flash mesajı | `src/hooks/useSubeDetailListFlash.ts` |
| 403 global yönlendirme | `src/app/providers.tsx` (`onAuthForbidden` → `/yetkisiz`) |
| Şube Yönetimi route | `YonetimPaneliPage.tsx?tab=subeler` |
| Şube silme mesajı | `src/lib/yonetim/sube-delete.ts` |
| Ayarlar menü girişi | `ShellHeaderActions` → `data-testid="settings-sube-yonetimi"` |
| Permission | `src/lib/authorization/role-permissions.ts` (`personeller.view.sube`, `getAllowedSubeIds`) |
| E2E mock | `tests/e2e/helpers/mock-api.ts` |
| E2E şube scope | `tests/e2e/sube-scope.spec.ts` |
| E2E şube yönetimi | `tests/e2e/yonetim.spec.ts` |
| E2E rapor şube filtresi | `tests/e2e/raporlar.spec.ts` (aylık özet UI filtresi — ayrı contract) |

İlgili ama ayrı owner'lar:

- Bildirim personel ref fetch: `useBildirimler.ts` (`fetchPersonellerList` + `sube_id`)
- Finans / süreç listeleri: `useFinans.ts`, `useSurecler.ts`
- Kayıt bootstrap: `KayitSurecWorkspace.tsx`, `kayit-surec-cache.ts`
- Demo fallback (dev): `src/api/mock-demo.ts` — 404 sonrası client fallback; S17B detail scope **403** ile bypass edildi

---

## 4. Active şube session contract

Kaynak: `finalizeAuthSessionSube` (`auth-session-sube.ts`)

| `user.sube_ids` | `active_sube_id` | UI modu |
| --------------- | ---------------- | ------- |
| `[]` (boş) | `null` | Tüm şubeler — header badge "Tüm şubeler" |
| `[1]` (tek) | `1` | Tek şube badge (seçici yok) |
| `[1, 2, …]` (çoklu) | İlk yetkili veya kayıtlı geçerli id | Header şube seçici (`kind: "multi"`) |

Login sonrası `auth-manager.login` → `finalizeAuthSessionSube` ile tutarlılık kurulur.

`setActiveSubeId(nextId)`:

- Yetkisiz id veya çoklu şube dışı id → no-op
- `sube_ids` boşken `nextId !== null` → no-op
- Başarılı yazım → `auth.store` state güncelleme + `bumpAppDataRevision` + `loadDataFromServer`

Storage key: `medisa_auth_session` (`auth-constants.ts`)

---

## 5. API scope contract

### Header

`api-client.buildRequestHeaders`:

- Auth gerektiren path'lerde `X-Active-Sube-Id: "<active_sube_id>"` eklenir
- `active_sube_id === null` → header gönderilmez (tüm şube modu)

### Query

Hook'lar `getSubeIdForApiRequest()` ile `sube_id` query param ekler:

- `null` → param yok
- sayı → `sube_id=N`

Kullanan başlıca hook'lar: `usePersoneller`, `usePersonelDetail`, `useBildirimler`, `useSurecler`, `useFinans`, `data-manager` prefetch.

### Cache

`dataCacheKeys` personel/süreç/bildirim anahtarlarında `subeSeg(subeId)` segmenti vardır; active şube değişince refetch tetiklenir.

---

## 6. ShellHeaderActions UI contract

| Konu | Davranış |
| ---- | -------- |
| Görünürlük | Yalnızca `showShellHeaderActions === true` (login ve modül overlay dışı) |
| `minimal` | Ana sayfa (`/`) → `minimal=true` → şube seçici **gizlenir** (yalnızca sağ ikonlar) |
| Modül overlay | `/personeller`, `/puantaj`, vb. → `ShellHeaderActions` **render edilmez** |
| Tek şube | Badge (`sube-header-badge`) |
| Çoklu şube | `aria-label="Şube seç"` toggle + `#sube-selector-menu` dropdown |
| Seçenek | Şube adı + `(seçili)` işareti |

**S17A gap (bilinçli):** Çoklu şubeli kullanıcı modül ekranındayken header şube seçiciye UI'dan erişemez. S17B MUHASEBE testi bu yüzden `switchActiveSubeViaSession` helper kullanır.

---

## 7. Personel list / detail şube scope contract

### Liste (`GET /api/personeller`)

Üretim: `sube_id` query + `X-Active-Sube-Id` header (client).

Mock (S17B):

- `getRequestSubeScope(request, url)` — query öncelikli, sonra header
- Scope yok → tüm fixture
- Scope var + **`limit <= 10`** → `personel.sube_id === scope` (personel list sayfası `PAGE_SIZE=10`)
- Scope var + **`limit > 10`** → filtre uygulanmaz (bildirim ref `limit=250` legacy smoke uyumu)

### Detay (`GET /api/personeller/:id`)

Üretim: `usePersonelDetail` → API hata 403/404 → `shouldRedirectDetailAfterSubeMismatch` → `/personeller` + flash mesaj.

Mock (S17B):

- Scope mismatch → **403** + `"Bu kayıt aktif şube bağlamında görüntülenemiyor."`
- **403 seçildi:** dev `VITE_DEMO_API_FALLBACK=true` iken 404 demo fallback'e düşüp kartı yine gösteriyordu
- 403 ayrıca `emitAuthForbidden` → `/yetkisiz` (BIRIM_AMIRI direct URL testinde gözlemlenen üretim davranışı)

Flash mesaj (liste redirect senaryosu):

- Key: `SUBE_DETAIL_REDIRECT_STATE_KEY` (`subeDetayUyari`)
- Metin: `SUBE_DETAIL_REDIRECT_MESSAGE` — `useSubeDetailListFlash` okur ve state temizler

---

## 8. Şube Yönetimi contract

| Konu | Değer |
| ---- | ----- |
| Giriş | Ayarlar → `settings-sube-yonetimi` |
| Route | `/yonetim-paneli?tab=subeler` |
| Modal başlık | `ŞUBE YÖNETİMİ` |
| Boş şube silme | Onay dialog → başarı |
| Personelli şube silme | `SUBE_DELETE_BLOCKED_MESSAGE` alert'te kalır |

Mesaj (`sube-delete.ts`):

`Şubede Kayıtlı Personel Gözükmektedir. Kayıtlı Personel Varken Silme İşlemi Yapılamaz.`

E2E: `yonetim.spec.ts` — `genel yonetici ayarlar menusunden sube yonetimine gider, bos subeyi siler ve personelli subeyi silemez`

---

## 9. Role / şube kapsamı contract

| Rol | `sube_ids` (mock login) | Active şube | Personel list |
| --- | ----------------------- | ------------- | --------------- |
| GENEL_YONETICI | `[]` | `null` | Tüm şubeler |
| BOLUM_YONETICISI | `[2]` | `2` | Depolama |
| MUHASEBE | `[1, 2]` | default `1` | Active şubeye göre |
| BIRIM_AMIRI | `[1]` | `1` | Merkez only |

BIRIM_AMIRI permission: `personeller.view.sube` (tüm `personeller.view` değil).

Direct URL şube dışı personel: mock 403 → üretimde `/yetkisiz` veya (404 senaryosunda) `/personeller` redirect + flash.

---

## 10. Mock drift notları (S17B)

| Konu | Not |
| ---- | --- |
| List scope `limit <= 10` | Bilinçli mock pragmatizmi; bulk ref fetch scope'suz |
| Detail 403 vs 404 | Demo fallback bypass için 403 |
| Fixture personel | id=1 Ayşe/Merkez; id=2 Mehmet/Depolama |
| Login `sube_list` | Mock login yanıtında `sube_ids` role göre |
| Üretim değişmedi | S17B yalnızca `tests/e2e/**` |

---

## 11. E2E ile kilitlenen davranışlar

### S17B — `tests/e2e/sube-scope.spec.ts`

#### `birim amiri sube disi personel kartini direct url ile acamaz`

- Rol: `BIRIM_AMIRI`, `sube_ids: [1]`
- `/personeller/1` → Ayşe Yılmaz hero OK
- `/personeller/2` → `/yetkisiz` veya `/personeller`; Mehmet hero yok
- Koşullu flash: `/personeller` redirect'inde `SUBE_DETAIL_REDIRECT_MESSAGE`

#### `muhasebe active sube degisince personel listesini ve header scopeunu daraltir`

- Rol: `MUHASEBE`, `sube_ids: [1, 2]`
- İlk liste: Ayşe var, Mehmet yok; request'te `x-active-sube-id: "1"` veya `sube_id=1`
- `switchActiveSubeViaSession(page, 2)` + `/personeller`
- Mehmet var, Ayşe yok; request'te scope `2`

#### `genel yonetici sube scope olmadan tum personel listesini gorur`

- Ayşe + Mehmet birlikte görünür

### Mevcut ilgili E2E

| Dosya | Konu |
| ----- | ---- |
| `yonetim.spec.ts` | Şube Yönetimi CRUD, personelli silme engeli |
| `raporlar.spec.ts` | Aylık özet UI şube filtresi (rapor ekranı; API header scope değil) |
| `smoke.spec.ts` | BIRIM_AMIRI bildirim personel seçimi (bulk list scope'suz mock) |
| `role-smoke.spec.ts` | Rol menü smoke (şube seçici değil) |

---

## 12. Unit test kapsamı

| Dosya | Konu |
| ----- | ---- |
| `auth.api.test.ts` | Login `active_sube_id` finalize |
| `role-permissions.test.ts` | `sube_ids` / permission |
| `usePersonelDetail.test.ts` | Detay hook (sube context mock) |
| `api-client.test.ts` | Header / hata davranışı |

### Eksik / backlog

- `ShellHeaderActions` component testi yok
- Gerçek UI şube dropdown E2E yok (overlay/minimal gap)
- Puantaj mühürleme şube scope E2E yok
- Detaylı rapor API header scope E2E yok
- Mock list scope'un tüm `limit` değerlerinde üretim parity'si yok

---

## 13. Korunacak guardlar

1. `tests/e2e/sube-scope.spec.ts` üç testi merge öncesi yeşil kalmalıdır.
2. `tests/e2e/yonetim.spec.ts` şube silme engeli bozulmamalıdır.
3. Mock `getRequestSubeScope` query-before-header önceliği korunmalıdır.
4. Mock detail scope mismatch **403** kalmalıdır (demo fallback regresyonu).
5. Mock list scope `limit <= 10` kuralı değişirse `smoke.spec.ts` BIRIM_AMIRI bildirim testi kontrol edilmelidir.
6. `SUBE_DETAIL_REDIRECT_MESSAGE` metni `detail-sube-context.ts` ile uyumlu kalmalıdır.
7. Üretim şube scope değişikliği yapılacaksa önce S17B E2E genişletilmelidir (KARAR B sırası).
8. `finalizeAuthSessionSube` kuralları login/active switch contract'ının tek kaynağıdır; paralel helper açılmamalıdır.

---

## 14. Açık ürün kararları / backlog

| Konu | Durum |
| ---- | ----- |
| Header şube seçici modül overlay'de erişilebilir mi? | UI/UX kararı — S17A gap |
| Mock list scope tüm limit'lerde mi uygulanacak? | Mock/üretim hizalama backlog |
| Puantaj mühürleme şube scope | Backlog (S14 notu) |
| Detaylı rapor `X-Active-Sube-Id` E2E | Backlog |
| BIRIM_AMIRI 403 → `/yetkisiz` vs `/personeller` redirect | Üretim tutarlılık kararı |
| `switchActiveSubeViaSession` yerine gerçek dropdown testi | UI owner değişikliği sonrası |
| Demo fallback şube scope parity | Dev-only; production `.env.production` `VITE_DEMO_API_FALLBACK=false` |

---

## 15. Doğrulama komutları

Şube / mock değişikliği sonrası minimum gate:

```bash
npm run typecheck
npm run test
npm run build
npx playwright test tests/e2e/sube-scope.spec.ts
npx playwright test tests/e2e/yonetim.spec.ts
npm run e2e
```

Doküman-only sprint (S17C) minimum gate:

```bash
npm run typecheck
npm run test
npm run build
```

---

## 16. Sonuç

Şube / Active Şube hattında owner haritası (S17A), mock list/detail scope (S17B) ve üç yeni E2E guard ile personel şube kapsamı test ortamında üretim contract'a yaklaştırıldı. Üretim kodu değiştirilmedi (KARAR B).

Şube hattı mevcut MVP davranışı açısından güvenli devir noktasına geldi.

Bundan sonra şube scope üretim değişikliği veya UI şube seçici erişilebilirliği ele alınacaksa önce §14 backlog maddelerinden biri seçilmeli; mock `limit <= 10` pragmatizmi ve 403 detail davranışı bilinçli olarak dokümante edilmiştir.
