# S24-B Kayıt Sonrası Cache Senkronu Kapanış Checkpoint

---

## 1. Faz Özeti

S24-B fazında yeni personel kaydı sonrası Personel Kartı liste/detail cache senkronu düzeltildi.

Kök problem:

Kayıt modalı yeni personeli başarıyla oluşturuyordu; ancak yalnızca `KayitSurecWorkspace` lokal state'i güncelleniyordu. Global `personeller:list:*` ve `personeller:detail:*` cache güncellenmediği için Personel Kartı stale kalabiliyordu.

Çözüm:

Yeni personel oluşturulduktan sonra global cache'e filter-aware optimistic prepend ve detail cache set işlemi eklendi (`commitPersonelCreateToCaches`).

---

## 2. Kapsam

Bu fazda yapılan işler:

- `data-manager.ts` içinde `commitPersonelCreateToCaches(created)` export edildi.
- Aktif şube prefix'i altındaki mevcut `personeller:list:*` cache key'leri taranıyor.
- Yalnızca `page=1` ve filtre uyumlu key'lere prepend yapılıyor.
- `personeller:detail:*` cache create sonrası set ediliyor.
- `KayitSurecWorkspace.handlePersonelSubmit` success path'ine helper çağrısı eklendi.
- Unit test: `tests/unit/commit-personel-create-cache.test.ts` (6 senaryo).
- E2E cache regression: `tests/e2e/kayit-yeni-personel.spec.ts` (geciktirilmiş GET ile cache kanıtı).

Kapsam dışı bırakılanlar:

- Backend/API değişikliği yok.
- Migration yok.
- CSS/UI tasarım değişikliği yok.
- Route redirect yok.
- `PersonellerPage.tsx` / `usePersoneller.ts` değişmedi.
- `PUT /personeller`, `POST /surecler`, silme endpointi yok.
- Pagination `total` meta güncellemesi zorlanmadı (mevcut offline prepend davranışı ile aynı).

---

## 3. Commitler

S24-B kapsamında iş commit'i:

| Commit | Açıklama |
|--------|----------|
| `92b14b3` | fix: kayit sonrasi personel karti list cache senkronu |

Checkpoint yazımı anında `main` ile `origin/main` senkron; HEAD: `92b14b3`.

---

## 4. Değişen Repo Dosyaları

| Dosya | Değişiklik |
|-------|------------|
| `src/data/data-manager.ts` | `commitPersonelCreateToCaches` + filtre parse/match helper'ları |
| `src/features/kayit/components/KayitSurecWorkspace.tsx` | Create success sonrası helper çağrısı |
| `tests/unit/commit-personel-create-cache.test.ts` | Yeni unit test dosyası |
| `tests/e2e/kayit-yeni-personel.spec.ts` | Cache regression E2E senaryosu |

---

## 5. Helper Davranışı (`commitPersonelCreateToCaches`)

Detail cache:

- `setCacheEntry(dataCacheKeys.personelDetail(activeSube, created.id), created)`

List cache tarama:

- Prefix: `personeller:list:s{activeSube}:`
- Yalnızca mevcut cache entry'ler; yeni key üretilmez.

Filtre uyumu (prepend yapılır):

| Filtre | Kural |
|--------|-------|
| page | Yalnızca `page === 1` |
| aktiflik | `tum` / `aktif` / `pasif` |
| search | Boş veya ad/soyad/tc içinde case-insensitive eşleşme |
| departmanId | Boş veya `created.departman_id` ile eşleşme |
| personelTipiId | Boş veya `created.personel_tipi_id` ile eşleşme |

Duplicate id:

- `optimisticPrependPersonel` mevcut id varsa ikinci kez eklemez.

Revision:

- `setCacheEntry` / `mergeCacheEntry` → `bumpRevision` + `notifyAppData`; `usePersoneller` subscription ile UI güncellenir.

Kayıt modalı lokal `setPersoneller` ve süreç sekmesine geçiş davranışı korundu.

---

## 6. Yerel Doğrulamalar

| Kontrol | Sonuç |
|---------|-------|
| `npm run test` | 589/589 geçti |
| `npm run build` | Geçti |
| E2E `kayit-yeni-personel.spec.ts` | 5/5 geçti |
| Kod review (S24-B) | Commit blocker yok |

---

## 7. Canlı Smoke Durumu

Planlanan S24-B canlı smoke (10 adım) otomasyon ortamında **tamamlanamadı**:

- Canlı site login ekranına yönlendirdi (`/personelmedisa/login`).
- Canlı kullanıcı şifresi repoda/ortamda bulunmadığı için adım 2–10 yapılamadı.

Deploy notu:

- `origin/main` üzerinde `92b14b3` mevcut.
- Frontend deploy GitHub Actions `dist/` üzerinden; PHP API değişikliği yok.
- Canlı bundle örneği: `index-C73xvyO4.js` (checkpoint anında).
- Tam canlı UI smoke, giriş bilgisi ile manuel tekrarlanmalıdır.

---

## 8. Bilinen Durumlar

- `page>1` cached list key'lerine dokunulmaz; yeni kayıt page 1 prepend mantığıyla görünür.
- Filtreli liste cache'inde kayıt filtreye uymuyorsa prepend yapılmaz (beklenen).
- `search` içinde `|` karakteri olursa key parse bozulabilir (düşük risk).
- Cross-şube kayıt (`created.sube_id` ≠ aktif header şube) ayrı UX konusu; S24-B kapsamı dışı.
- S23 canlı test personeli (id=3) hâlâ DB'de; silme endpointi yok.

---

## 9. GitHub Actions Notu

Push sonrası CI tetiklenir; başarılı CI sonrası Deploy cPanel frontend `dist/` yayınlar. `api/` deploy dışındadır.

---

## 10. Sıradaki Önerilen Faz

- S24-C: Kayıt sonrası hata yüzeyi sertleştirme (duplicate TC banner, şube 403 mesajı, isteğe bağlı field focus).
- Canlı S24-B UI smoke (login sonrası: kayıt → modal kapat → Personel Kartı listesinde anında görünürlük).
- İsteğe bağlı: S23B test kaydı operasyonel pasife alma kararı.
- Sonraki write fazı: `PUT /personeller/{id}` (S24-B sonrası ayrı faz).
