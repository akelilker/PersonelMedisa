# 71 — MVP Genel Kapanış / Release Checklist S20

---

## 1. Amaç

Bu doküman PersonelMedisa MVP için genel kapanış ve release checklist dokümanıdır.

Bu doküman yeni geliştirme talimatı değildir. S8–S19 arasında kapatılan hatları tek baz çizgide toplar; kapalı sprint zincirini, guard özetini, modül durumunu, release checklist'ini ve backlog ayrımını tek referans noktasında birleştirir.

Bundan sonraki işler bu baz çizgi üstünden ayrı sprint olarak seçilmelidir. Kapalı hatlarda rastgele refactor veya paralel implementasyon yapılmamalıdır.

---

## 2. MVP baz çizgisi

| Alan                 | Değer                            |
| -------------------- | -------------------------------- |
| MVP kapanış commit'i | `d0ee6f7`                        |
| Son kapanan sprint   | S19B                             |
| Son kapanan guard    | Aylık Özet CSV/export şube scope |
| CI                   | `#426` success                   |
| Deploy cPanel        | `#404` success                   |
| Branch               | `main`                           |
| Status               | `origin/main` ile hizalı         |

---

## 3. Kapanan sprint zinciri

| Hat                           | Sprint     | Commit                                                                 | Durum  |
| ----------------------------- | ---------- | ---------------------------------------------------------------------- | ------ |
| Raporlar                      | S8–S12/S14 | `813f7b8`, `7ce88d9`, `654f0ec`, `fbf72db`, `6e4984d`, `57684a1`     | Kapalı |
| Puantaj                       | S14        | `1699297`, `6a54cd6`, `f6e5a77`                                      | Kapalı |
| Personel Kartı                | S15        | `a2b4d96`, `47cb3aa`                                                   | Kapalı |
| Kayıt ve Süreç                | S16        | `88c94db`, `860ec7a`                                                   | Kapalı |
| Şube / Active Şube            | S17        | `cb03b8b`, `42ed929`                                                   | Kapalı |
| Puantaj + Raporlar Şube Scope | S18        | `58edc47`, `07d5e9b`                                                   | Kapalı |
| CSV/export scope              | S19B       | `d0ee6f7`                                                              | Kapalı |

Referans kapanış notları: `65` (Raporlar), `66` (Puantaj), `67` (Personel Kartı), `68` (Kayıt ve Süreç), `69` (Şube / Active Şube), `70` (Puantaj + Raporlar Şube Scope).

---

## 4. Kapalı hatlar ve guard özeti

### Raporlar

- 8 rapor tipi smoke (`813f7b8`) — `personel-ozet`, `izin`, `devamsizlik`, `tesvik`, `ceza`, `ekstra-prim`, `is-kazasi`, `bildirim`
- Kolon contract — `getRaporColumns(type)` ile thead birebir eşleşme
- Pagination — `page` / `limit`, Onceki / Sonraki, `Sayfa X / Y` (`7ce88d9`)
- Departman filtresi — `[name="rapor-departman"]` (`6e4984d`)
- Aylık Özet rol/şube/onay — `aylik-ozet.view`, şube filtresi, bölüm onay / üst onay (`fbf72db`, `57684a1`)
- CSV smoke — `Excel'e Aktar` → `aylik-kapanis-ozeti-{ay}.csv` (`654f0ec`)
- CSV şube scope guard S19B ile tamamlandı — seçili şube verisi korunur, diğer şube verisi export'ta yok (`d0ee6f7`)

Kaynak: `tests/e2e/raporlar.spec.ts`, `docs/guncel/65-raporlar-hatti-s8-s11-kapanis-devir-notu.md`

### Puantaj

- Günlük puantaj — Personel ID + Tarih ile GET/PUT akışı
- Mühürleme modalı — ay seçimi, onay, mühür sonrası kilit (`1699297`)
- Rol guard — `puantaj.view`, mühürleme görünürlüğü rol bazlı
- BIRIM_AMIRI read-only — kayıt düzenleme yok, Amir Kontrol Etti (`6a54cd6`)
- Amir kontrol — `amir_kontrol` / `kontrol_durumu` alanları
- Active şube scope guard — GET/PUT scope mismatch 403 (`58edc47`)
- Mühürleme header-scope mock/E2E — payload explicit `sube_id` yok; `X-Active-Sube-Id` ile scope

Kaynak: `tests/e2e/puantaj.spec.ts`, `tests/e2e/puantaj-rapor-sube-scope.spec.ts`, `docs/guncel/66-puantaj-hatti-s14-kapanis-devir-notu.md`

### Personel Kartı

- Maaş opsiyonel — kayıt sırasında zorunlu değil
- `Maaş bilgisi eksik.` uyarısı — maaş yoksa hero/panel gösterimi
- `personel-maas-eksik-uyari` — data-testid guard
- BIRIM_AMIRI yetkisiz aksiyon guard — Süreç Ekle / zimmet / belge aksiyonları görünmez
- Süreç geçmişi timeline — event sıralama, İşten Ayrılma süreç/event olarak
- Zimmet / belge / disiplin sekmeleri — 5 sekme smoke

Kaynak: `tests/e2e/personel-dosya.spec.ts`, `docs/guncel/67-personel-karti-hatti-s15-kapanis-devir-notu.md`

### Kayıt ve Süreç

- Kayıt merkezi modal/gateway — `KayitSurecWorkspace`, merkezi modal kontratı
- Maaş opsiyonel — personel oluşturma formu
- Şube zorunlu — kayıt sırasında şube seçimi gerekli
- Personel Kartı dönüş gateway — `usePersonelKartGatewayReturn`, route state temizliği
- İşten Ayrılma süreç/event — kart alanı değil, süreç tipi (`ISTEN_AYRILMA`)
- İzin / devamsızlık / süreç kayıtları — Kayıt modal üzerinden
- BIRIM_AMIRI kayıt/süreç açamaz — menü ve modal negatif guard

Kaynak: `tests/e2e/kayit-*.spec.ts`, `tests/e2e/personel-dosya.spec.ts`, `docs/guncel/68-kayit-surec-hatti-s16-kapanis-devir-notu.md`

### Şube / Active Şube

- Active şube session — `active_sube_id`, `finalizeAuthSessionSube`
- `X-Active-Sube-Id` — `api-client.ts` → `getActiveSubeIdForApiHeader()`
- Personel list/detail scope — `getSubeIdForApiRequest()`, cache key segment
- BIRIM_AMIRI direct URL guard — kendi şubesi dışı personel kartı 403 / yönlendirme
- MUHASEBE active şube liste daraltma — header ile scope
- GENEL no-scope davranışı — tüm şubeler görünür
- Şube Yönetimi ve silme uyarısı — personel varsa silme engeli

Kaynak: `tests/e2e/sube-scope.spec.ts`, `tests/e2e/yonetim.spec.ts`, `docs/guncel/69-sube-active-sube-hatti-s17-kapanis-devir-notu.md`

### Puantaj / Raporlar Şube Scope

- Puantaj GET/PUT scope mismatch 403 mock — active şube dışı personel
- Puantaj mühürleme active şube scope — header-scope kabul
- Detaylı rapor active şube scope — `fetchRapor` header ile daralır
- GENEL no-scope detaylı rapor — tüm şube verisi
- Aylık Özet explicit `sube_id` farkı — query param ile şube filtresi (header-scope'tan ayrı contract)

Kaynak: `tests/e2e/puantaj-rapor-sube-scope.spec.ts`, `docs/guncel/70-puantaj-raporlar-sube-scope-hatti-s18-kapanis-devir-notu.md`

### CSV/export scope

- Aylık Özet şube filtresi sonrası CSV içeriği aynı scope'u korur
- Seçili şube verisi vardır
- Diğer şube verisi yoktur
- Full E2E `82/82` geçmiştir (S19B kapanış doğrulaması)

Kaynak: `tests/e2e/raporlar.spec.ts` (S19B CSV şube scope guard), commit `d0ee6f7`

---

## 5. Ana modül durumu

| Modül          | MVP durumu | Test durumu | Release notu       |
| -------------- | ---------- | ----------- | ------------------ |
| Login / rol    | Hazır      | Unit + E2E  | 4 rol              |
| Ana Menü       | Hazır      | Smoke/role  | 3 ana kapı         |
| Kayıt ve Süreç | Hazır      | E2E         | Modal/gateway      |
| Personel Kartı | Hazır      | E2E         | Maaş/süreç/rol     |
| Puantaj        | Hazır      | E2E + unit  | Günlük/mühür/amir  |
| Raporlar       | Hazır      | E2E         | 8 tip + Aylık Özet |
| Şube Yönetimi  | Hazır      | E2E         | CRUD/silme guard   |
| Active Şube    | Hazır      | E2E         | Header/session     |
| CSV/export     | Hazır      | E2E         | Scope guard        |
| CI/Deploy      | Hazır      | Actions     | cPanel deploy      |

---

## 6. Release checklist

### Lokal repo

- [ ] `git fetch origin --prune`
- [ ] `git status -sb` → `## main...origin/main`
- [ ] `git log --oneline -5` son commit `d0ee6f7`
- [ ] working tree temiz

### Lokal validation

- [ ] `npm run typecheck`
- [ ] `npm run test`
- [ ] `npm run build`
- [ ] `npm run e2e`

### Kritik E2E

- [ ] `npx playwright test tests/e2e/raporlar.spec.ts`
- [ ] `npx playwright test tests/e2e/puantaj.spec.ts`
- [ ] `npx playwright test tests/e2e/puantaj-rapor-sube-scope.spec.ts`
- [ ] `npx playwright test tests/e2e/sube-scope.spec.ts`
- [ ] `npx playwright test tests/e2e/personel-dosya.spec.ts`
- [ ] `npx playwright test tests/e2e/role-smoke.spec.ts`
- [ ] `npx playwright test tests/e2e/yonetim.spec.ts`

### GitHub Actions

- [ ] CI success
- [ ] Deploy cPanel success
- [ ] Deploy artifact doğru path'e gitti
- [ ] cPanel canlı URL açılıyor

### Canlı smoke

- [ ] Login
- [ ] Ana Menü
- [ ] Kayıt ve Süreç modalı
- [ ] Personel Kartı
- [ ] Puantaj
- [ ] Raporlar
- [ ] Aylık Özet CSV export
- [ ] Şube Yönetimi
- [ ] Active şube değişimi
- [ ] BIRIM_AMIRI kısıtları
- [ ] MUHASEBE active şube scope
- [ ] GENEL_YONETICI no-scope görünüm

---

## 7. Rollere göre release smoke

| Rol              | Kontrol                                                                    |
| ---------------- | -------------------------------------------------------------------------- |
| GENEL_YONETICI   | tüm şubeler, kayıt/süreç, personel kartı, puantaj, raporlar, şube yönetimi |
| BOLUM_YONETICISI | kendi/aktif şube, puantaj/mühürleme, raporlar, süreç                       |
| MUHASEBE         | active şube, personel listesi, raporlar, finansal/kart görünürlük          |
| BIRIM_AMIRI      | read-only, amir kontrol, kayıt/süreç yok, şube dışı erişim yok             |

---

## 8. Release blocker ayrımı

| Konu                                 | Durum              | Blocker mı? |
| ------------------------------------ | ------------------ | ----------- |
| Frontend E2E guardlar                | Kapalı             | Hayır       |
| CI/Deploy                            | Yeşil olmalı       | Evet        |
| Backend `X-Active-Sube-Id` contract  | Dış bağımlılık     | Koşullu     |
| Env config                           | Doğru olmalı       | Evet        |
| CSV/export scope                     | S19B ile kapalı    | Hayır       |
| Puantaj mühür explicit `sube_id` yok | Header-scope kabul | Hayır       |
| Detaylı rapor explicit `sube_id` yok | Header-scope kabul | Hayır       |

---

## 9. Environment / deploy notları

Production için `VITE_API_MODE=real` beklenir.

Demo fallback kapalı olmalıdır: `VITE_DEMO_API_FALLBACK=false`.

Base path production'da `/personelmedisa/` olmalıdır: `VITE_APP_BASE_PATH=/personelmedisa/`.

Frontend active şube header gönderir: `X-Active-Sube-Id` (`api-client.ts`).

Backend tarafında `X-Active-Sube-Id` scope'unun gerçekten uygulanması release öncesi ayrıca doğrulanmalıdır. Mock/E2E guard'lar frontend contract'ı kilitler; canlı backend enforcement ayrı smoke gerektirir.

cPanel deploy sonrası cache/version kontrolü gerekebilir. Deploy checklist için `DEPLOY_CHECKLIST.md` esas alınır.

---

## 10. Korunacak contractlar

1. Maaş kayıt sırasında opsiyoneldir.
2. Maaş yoksa Personel Kartı `Maaş bilgisi eksik.` gösterir.
3. Şube kayıt sırasında zorunludur.
4. İşten Ayrılma kart alanı değil süreç/event'tir.
5. BIRIM_AMIRI kayıt/süreç açamaz.
6. BIRIM_AMIRI Personel Kartı aksiyonlarını göremez.
7. BIRIM_AMIRI kendi şubesi dışı personel kartı/puantaj açamaz.
8. Active şube header: `X-Active-Sube-Id`.
9. GENEL_YONETICI no-scope/tüm veri davranışı korunur.
10. Aylık Özet explicit `sube_id` kullanır.
11. Detaylı raporlar header-scope ile daralır.
12. Puantaj mühürleme header-scope ile ilgili şubeyi etkiler.
13. Aylık Özet CSV export seçili şube scope'unu korur.
14. Şube silme personel varsa şu uyarıyla engellenir:
    `Şubede Kayıtlı Personel Gözükmektedir. Kayıtlı Personel Varken Silme İşlemi Yapılamaz.`

---

## 11. Backlog — release sonrası

| Backlog                                  | Tip              | Öncelik | Release blocker mı? |
| ---------------------------------------- | ---------------- | ------- | ------------------- |
| Puantaj mühür payload explicit `sube_id` | Backend contract | Sonra   | Hayır               |
| Detaylı rapor explicit `sube_id`         | Backend contract | Sonra   | Hayır               |
| Puantaj UI personel seçim guard          | Production/test  | Yakın   | Hayır               |
| BOLUM/MUHASEBE negatif rol matrix        | Test-only        | Yakın   | Hayır               |
| Gerçek UI şube seçici E2E                | Test-only/UI     | Sonra   | Hayır               |
| Active şube tüm modül refetch UI E2E     | Test-only        | Sonra   | Hayır               |
| Şube soft-delete/pasif davranışı         | Ürün kararı      | Sonra   | Hayır               |
| Belge/sertifika timeline kararı          | Ürün kararı      | Park    | Hayır               |
| Teşvik süreç/event kararı                | Ürün kararı      | Park    | Hayır               |
| Maaş değişikliği süreç tipi              | Ürün kararı      | Park    | Hayır               |
| Kayıt/Süreç workspace parçalama          | Refactor         | Park    | Hayır               |
| Offline report-engine parity             | Teknik           | Park    | Hayır               |

---

## 12. Bundan sonra çalışma kuralı

Bu baz çizgiden sonra her yeni iş ayrı sprint olmalıdır.

Önce analiz, sonra test/production, sonra docs kapanış sırası korunmalıdır.

Kapalı hatlarda rastgele refactor yapılmamalıdır.

Production kod gerekiyorsa önce ürün kararı alınmalıdır.

Push sadece CI/Deploy planıyla yapılmalıdır.

Referans kapanış notları (`65`–`70`) ve bu doküman (`71`) birlikte MVP dondurulmuş baz çizgisini tanımlar.

---

## 13. Sonuç

MVP frontend baz çizgisi release/demo checkpoint'e hazırdır.

Kritik frontend guardlar E2E ile kilitlenmiştir (son guard: Aylık Özet CSV/export şube scope — `d0ee6f7`).

Release öncesi koşullu dış kontrol backend `X-Active-Sube-Id` contract doğrulamasıdır.

Kalan maddeler backlog'dur; release blocker değildir.

S20 sonrası proje yeni faza geçebilir.
