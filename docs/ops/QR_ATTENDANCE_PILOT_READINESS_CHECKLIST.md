# QR Attendance — Pilot / Saha Readiness Checklist

**Tür:** Ops + saha pilot kapısı (kod değişikliği gerektirmez).  
**Model:** `AUTHENTICATED_KIOSK` — şube kiosk dinamik QR üretir; authenticated personel uygulaması okutur.  
**Baseline notu:** QR core S3C–S3F + collar entitlement (#374) CLOSED; bu checklist geniş rewrite değildir.  
**Yasaklar:** Secret değeri yazma/okuma/loglama yok · production mutation yok · migration apply yok · device bind / offline yok.

---

## 1) Ürün modeli (eğitim kilidi)

- [ ] Personel **kendi QR’ını göstermez**; **kiosk / şube ekranındaki** kısa ömürlü QR’ı okutur.
- [ ] Giriş ve çıkış **açık seçimlidir** (`GIRIS` / `CIKIS`); token yalnız şube + süre taşır (`personel_id` QR içinde yok).
- [ ] Raw olay `qr_attendance_events` tablosuna **append-only** yazılır.
- [ ] QR okutma **otomatik `gunluk_puantaj` satırı oluşturmaz** (bilinçli model).
- [ ] Puantaj saatleri **candidate → review → controlled apply** ile işlenir; apply mevcut satır gerektirir.
- [ ] Entitlement: bağlı personel + kanonik **Mavi Yaka**; Beyaz Yaka / unbound fail-closed.
- [ ] BIRIM_AMIRI / BOLUM_YONETICISI Mavi Yaka ise yönetim rolü korunur; kendi QR hakkı ayrıca verilir.

---

## 2) Production config (değer yazma yok)

- [x] `qr_signing_secret` production `medisa_config` içinde tanımlı mı? (değer bu belgeye / chate / log’a **asla** yazılmaz) — **REMOTE_PASS 2026-09-25:** `GET /qr-kiosk/token?sube_id=1|4` → 200 + token (secret değeri okunmadı/yazılmadı)
- [x] Secret placeholder (`CHANGE_ME…`) veya &lt;32 karakter değil mi? — **REMOTE_PASS 2026-09-25:** mint başarılı ⇒ placeholder/fail-closed path değil (`QR_CONFIG_NOT_READY` yok)
- [x] `qr_ttl_seconds` 30–120 aralığında mı? (geçersiz → sunucu default 60) — **REMOTE_PASS 2026-09-25:** `ttl_seconds=60`
- [ ] Eksik secret → yalnız QR uçları `QR_CONFIG_NOT_READY` (503); uygulama genelinin ayakta kaldığı doğrulandı mı? — negatif config testi bu turda yapılmadı

---

## 3) HTTPS / kamera / cihaz

- [x] Kiosk ve personel app **HTTPS** (secure context) üzerinden açılıyor — **REMOTE_PASS 2026-09-25:** `https://www.karmotors.com.tr/personelmedisa/`
- [ ] Kamera izni akışı test edildi (izin reddi / kamera yok / başka app kullanıyor mesajları)
- [ ] iPhone Safari smoke
- [ ] Android Chrome smoke
- [ ] BarcodeDetector yoksa jsqr fallback çalışıyor

---

## 4) Kiosk

- [x] `/qr-kiosk` yetkili hesapla açılıyor (`qr.kiosk.display`) — **REMOTE_PASS 2026-09-25:** route 200; token mint `ilkerA`/`GENEL_YONETICI` + `sube_id` Fabrika/Kayseri
- [ ] Token TTL içinde yenileniyor; süre dolunca personelde “QR süresi doldu” mesajı bekleniyor
- [ ] Ekran kilidi / sleep politikası saha için uygun
- [ ] Yanlış şube QR → `QR_CROSS_BRANCH_DENIED` (personelin güncel şubesi ↔ token `sube_id`)

---

## 5) Pilot roster

- [ ] Pilot kullanıcıların `users.personel_id` bağlı ve personel **aktif**
- [ ] Collar read model **Mavi Yaka** (DB `personel_tipleri.ad`)
- [ ] Beyaz Yaka / unbound kontrol hesabı ile fail-closed smoke
- [ ] En az bir BIRIM_AMIRI (Mavi Yaka) kendi GİRİŞ/ÇIKIŞ kutularından okutabiliyor
- [ ] En az bir PERSONEL (Mavi Yaka) self home’dan okutabiliyor

---

## 6) Puantaj / İK beklentisi

- [ ] Saha/İK eğitildi: QR ≠ otomatik puantaj
- [ ] Günlük puantaj satırı yoksa apply engellenir; manuel satır / normal puantaj akışı gerekir
- [ ] Correction talebi (düzeltme) onay zinciri pilot şubede biliniyor

---

## 7) Smoke sırası (manuel)

1. Kiosk token görünür + countdown
2. Personel `Kamerayı aç` → şube QR okut → GİRİŞ
3. Self home’da giriş saati görünür
4. ÇIKIŞ okut
5. `/self/qr-hareketleri` raw liste
6. (İK) Puantaj QR aday yüzeyi — otomatik satır oluşmadığını doğrula
7. Bilerek başka şube QR → cross-branch red
8. Süresi dolmuş QR → expired mesajı

---

## 8) Bilinçli dışı bırakılanlar (bu checklist kapsamı değil)

- Device binding / offline QR yazma
- Anomaly → revizyon kontrollü UX
- ~~Migration 090/091 apply~~ → **APPLIED** (POST_PR402; see CURRENT_STATE / 146)
- Görsel redesign / Personel Detay redesign
- Production personel mutasyonu

---

**Owner referansları:** `QrTokenService`, `QrConfig`, `QrAttendanceEventService`, `QrPuantajCandidateDecisionService`, `RolePermissions::hasQrSelfServiceEntitlement`, `docs/guncel/105–109`.

---

## Remote verify log (BL-QR-PILOT-OPS)

| Tarih | Kanıt | Sonuç |
| --- | --- | --- |
| 2026-09-25 | HTTPS + `/api/health` 200 + `/qr-kiosk` 200 + `/qr-kiosk/token?sube_id=1|4` 200, `ttl_seconds=60`, secret değeri okunmadı | **PARTIAL_REMOTE_PASS** — config/HTTPS/kiosk mint OK; kamera/cihaz/roster/smoke saha tick’leri açık |
