# 64 — Haftalık Kapanış → Raporlar Bekleyen Kararlar Devir Notu

---

## 1. Amaç

Bu notun amacı, haftalık kapanış snapshot verisi ile raporlar modülü arasındaki **dokümanda kilitlenmiş ancak koda henüz yansıtılmamış** kararları ve bilinçli olarak ertelenen implementasyon maddelerini tek yerde kayıt altına almaktır.

Bu not **implementasyon talimatı değildir**. Kod/mock/type/test fazına geçiş, `docs/guncel/05-state-flow-api-kontrati.md` §13.5 matrisine ve bu devir notundaki kırmızı çizgilere uygun ayrı bir faz onayı ile açılmalıdır.

---

## 2. Son Kesin Durum

| Alan | Değer |
|------|-------|
| **Branch** | `main` (`origin/main` ile hizalı) |
| **§13 kontrat commit'i** | `102f4c6` — `docs: haftalik kapanis snapshot -> raporlar API kontratini §13'e ekle` |
| **§13.5 owner karar matrisi** | 2026-06-25 tarihinde `05-state-flow-api-kontrati.md` içinde 8/8 madde kilitlendi (**working tree'de; henüz commit edilmedi**) |
| **HEAD commit** | `f9f850a` |
| **Working tree** | `docs/guncel/05-state-flow-api-kontrati.md` modified; `docs/guncel/64-haftalik-kapanis-raporlar-bekleyen-kararlar-devir-notu.md` untracked; `merge_isci_evraklari.py` untracked |

**Dokümantasyon:**

- §13, `kapanis_id` / `snapshot_id` ilişkisini ve rapor endpoint whitelist'ini tanımlar.
- §13.5 owner karar matrisi dokümanda onaylıdır; alt maddeler `[KARAR ALINDI]` statüsündedir.

**Kod tarafı:**

- Frontend, mock, type ve test dosyalarında `kapanis_id` / rapor-snapshot bağlantısı **yapılmadı**.
- README'ye dokunulmadı.

---

## 3. Bilinçli Olarak Bırakılan Maddeler

Aşağıdaki maddeler **bilinçli olarak** kod fazına ertelenmiştir; eksiklik veya unutulmuş iş değildir.

| Madde | Durum |
|-------|--------|
| `kapanis_id` rapor query param'ı | `src/types/rapor.ts` ve `src/api/raporlar.api.ts` içinde yok |
| `snapshot_id` genel liste filtresi | Rapor API'sine eklenmedi (§13.5 madde 2: Hayır) |
| Endpoint whitelist enforcement | Mock/API seviyesinde uygulanmadı |
| `meta.kaynak` (`SNAPSHOT` / `LIVE`) izi | Rapor yanıtlarında yok |
| Correction overlay → rapor bağlantısı | Yapılmadı; sonraki faz (§13.5 madde 6) |
| `GET /api/raporlar/haftalik-kapanis-duzeltilmis` | Implementasyon yok |
| `/haftalik-kapanis` route'u | Gerçek sayfaya açılmadı; ana sayfaya yönlendirilir |
| Haftalık kapanış UI | Ayrı frontend fazına bırakıldı (§13.6) |
| §13.5 doküman değişikliği | Working tree'de; commit bekliyor |

---

## 4. Bırakılma Nedeni

- Dokümantasyon kontratı önce netleştirildi; kod tarafına erken varsayım taşınmaması için implementasyon bilinçli olarak bekletildi.
- Mock/type/test implementasyonu, kilitli §13.5 kararlarına göre **tek seferde** yapılmalı; parçalı veya öneri varsayımlarıyla açılmamalı.
- Correction overlay revizyon zincirinde (50–62) contract/mock seviyesinde vardır; raporlara bağlamak ayrı fazdır.
- Önce çalışan uygulama gerçek durumu (açılış, build, test, route, ekran) doğrulanmalı; görsel/polish işleri bu kontrolden sonra önceliklendirilmelidir.

---

## 5. Dokümanda Kilitlenmiş — Koda Taşınmayı Bekleyen Kararlar

Aşağıdaki sorular **§13.5 (2026-06-25) ile cevaplanmıştır**. Kod fazında bu cevaplar yeniden tartışılmadan uygulanmalıdır; sapma owner onayı gerektirir.

| # | Soru | Kilitli karar |
|---|------|---------------|
| 1 | `kapanis_id` batch/liste raporu filtresi olacak mı? | **Evet** — birincil batch filtresi |
| 2 | `snapshot_id` yalnızca satır/detay referansı mı kalacak? | **Evet** — rapor listesinde genel filtre değil |
| 3 | Hangi rapor endpoint'leri `kapanis_id` destekleyecek? | **§13.4 whitelist:** `personel-ozet`, `devamsizlik`, `izin`, `bildirim` → Evet; `tesvik`, `ceza`, `ekstra-prim`, `is-kazasi` → Hayır |
| 4 | `is-kazasi` endpoint'i desteklenecek mi? | **Hayır** — haftalık kapanış snapshot'ında iş kazası türevi alan yok |
| 5 | `kapanis_id` + tarih çakışmasında `400 INVALID_QUERY` mi dönecek? | **Evet** — kapanış haftası authoritative |
| 6 | `kapanis_id` varsa veri kaynağı `SNAPSHOT` mı olacak? | **Evet** — `meta.kaynak = "SNAPSHOT"` |
| 7 | Whitelist dışı `kapanis_id` için `400 UNSUPPORTED_FILTER` mı dönecek? | **Evet** — strict mod; param yok sayılmaz |
| 8 | Query param mı, dedicated endpoint mi? | **Faz 1: query param**; düzeltilmiş görünüm dedicated endpoint sonraki faz |

**Kaynak:** `docs/guncel/05-state-flow-api-kontrati.md` §13.2–§13.5

---

## 6. Kod Tarafı Kırmızı Çizgi

Kod fazı açılmadan önce aşağıdaki dosyalara **dokunulmamalıdır**:

| Dosya / alan | Yasak (bu faz öncesi) |
|--------------|------------------------|
| `src/types/rapor.ts` | `kapanis_id` / `snapshot_id` alanı eklenmez |
| `src/api/raporlar.api.ts` | `kapanis_id` query param eklenmez |
| `src/api/mock-demo.ts` | `/raporlar/*` snapshot branch eklenmez |
| `tests/unit/raporlar.api.test.ts` ve ilgili testler | Kod fazı onayı öncesi snapshot rapor testi yazılmaz |
| Raporlar UI | Haftalık kapanış filtresi / `kapanis_id` navigasyonu eklenmez |

**İstisna yok:** §13.5 matrisi dokümanda kilitli olsa bile, ayrı kod fazı onayı olmadan yukarıdaki dosyalar değiştirilmez.

---

## 7. Sıradaki Çalışma Yönü

1. **Dokümantasyon fazı** bu devir notu ile burada durur.
2. **Sıradaki ana iş:** çalışan uygulama gerçek durum kontrolü.
   - Uygulama açılış
   - Build
   - Test (`npm run test`, `npm run typecheck`)
   - Route ve ekran kontrolleri
3. Görsel/polish işleri bu kontrolden sonra önceliklendirilir.
4. Haftalık kapanış → raporlar **kod fazı**, uygulama kontrolü tamamlandıktan ve §13.5 commit'i alındıktan sonra ayrı talimatla açılır.

---

## 8. İlişkili Dokümanlar

| Doküman | İlişki |
|---------|--------|
| `05-state-flow-api-kontrati.md` §13 | Rapor–snapshot API kontratı ve §13.5 matrisi |
| `39-haftalik-kapanis-snapshot-sozlesmesi-karar.md` | `kapanis_id` / `snapshot_id` ID modeli |
| `50-kapali-donem-audit-workflow-karar.md` | Düzeltilmiş görünüm adayı endpoint |
| `60-revizyon-talebi-correction-layer-karar.md` | Correction overlay (sonraki faz) |
| `63-revizyon-talebi-zinciri-genel-devir-notu.md` | Revizyon/correction zinciri kapanışı |

---

## 9. Yeni Oturum Devir Cümlesi

> PersonelMedisa'da haftalık kapanış → raporlar ilişkisi §13 ile dokümante edildi (`102f4c6`); §13.5 owner matrisi 2026-06-25'te dokümanda 8/8 kilitlendi (commit bekliyor). Kod/mock/type/test tarafında `kapanis_id` rapor bağlantısı yok. Sıradaki iş çalışan uygulama kontrolü; rapor snapshot kod fazı ayrı talimatla açılır.
