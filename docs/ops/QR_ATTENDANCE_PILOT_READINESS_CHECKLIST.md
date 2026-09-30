# QR Attendance — Pilot / Saha Readiness Checklist

**Tür:** Ops + saha pilot kapısı (kod değişikliği gerektirmez).  
**Model:** `AUTHENTICATED_KIOSK` — şube kiosk dinamik QR üretir; authenticated personel uygulaması okutur.  
**Baseline notu:** QR core S3C–S3F + collar entitlement (#374) CLOSED; bu checklist geniş rewrite değildir.  
**Yasaklar:** Secret değeri yazma/okuma/loglama yok · production mutation yok · migration apply yok · device bind / offline yok.

---

## CURRENT ATTENDANCE/QR PHASE

**STATUS: CLOSED** — repo regression + saha fiziksel pilot kanıtı tamam; açık ürün/feature işi yok (residual ops yalnızca 146 non-QR backlog).

| Kanıt kalemi | Sonuç | Kaynak |
| --- | --- | --- |
| EXPIRED_QR | **PASS** | `QrTokenService` + `tests/php/S3CQrTokenServiceTestRunner.php` (`QR_TOKEN_EXPIRED` / 400, verify öncesi INSERT yok) |
| CROSS_BRANCH | **PASS** | `QrAttendanceEventService::scan` post-verify deny + `tests/php/CrossBranchDenyScanTestRunner.php` (`QR_CROSS_BRANCH_DENIED` / 403, 0 satır) |
| POST_094_CRON | **ACCEPTED_EVIDENCE_LIMITATION** | Migration 094 saha apply + cron davranışı production log kanıtı bu belgede zorunlu değil; anomaly cron core #439 + `093` dedupe repo testleri yeterli |
| Finding A (passive GİRİŞ/ÇIKIŞ symmetry) | **CLOSED / LIVE** | `own-qr-attendance-passive-*` tests + saha |
| Finding B (manager open-entry presentation) | **CLOSED / LIVE** | `QrManagerOpenEntryPresentationTestRunner` |
| Finding C (QR history mobile cards) | **CLOSED / LIVE** | saha + merged UI |
| Finding D (worked-day QR fallback on history summary) | **CLOSED / LIVE** | `personel-self-history-summary` tests + saha |

**Saha fiziksel (iPhone):** kiosk QR **GİRİŞ** · **ÇIKIŞ** · tamamlanmış çift · `/self/qr-hareketleri` geçmişi — **FIELD_PASS** (gerçek cihaz, 2026-09 saha).

---

## 1) Ürün modeli (eğitim kilidi)

- [x] Personel **kendi QR’ını göstermez**; **kiosk / şube ekranındaki** kısa ömürlü QR’ı okutur.
- [x] Giriş ve çıkış **açık seçimlidir** (`GIRIS` / `CIKIS`); token yalnız şube + süre taşır (`personel_id` QR içinde yok).
- [x] Raw olay `qr_attendance_events` tablosuna **append-only** yazılır.
- [x] QR okutma **otomatik `gunluk_puantaj` satırı oluşturmaz** (bilinçli model).
- [x] Puantaj saatleri **candidate → review → controlled apply** ile işlenir; apply mevcut satır gerektirir.
- [x] Entitlement: bağlı personel + kanonik **Mavi Yaka**; Beyaz Yaka / unbound fail-closed.
- [x] BIRIM_AMIRI / BOLUM_YONETICISI Mavi Yaka ise yönetim rolü korunur; kendi QR hakkı ayrıca verilir.

---

## 2) Production config (değer yazma yok)

- [x] `qr_signing_secret` production `medisa_config` içinde tanımlı mı? (değer bu belgeye / chate / log’a **asla** yazılmaz) — **REMOTE_PASS 2026-09-25:** `GET /qr-kiosk/token?sube_id=1|4` → 200 + token (secret değeri okunmadı/yazılmadı)
- [x] Secret placeholder (`CHANGE_ME…`) veya &lt;32 karakter değil mi? — **REMOTE_PASS 2026-09-25:** mint başarılı ⇒ placeholder/fail-closed path değil (`QR_CONFIG_NOT_READY` yok)
- [x] `qr_ttl_seconds` 30–120 aralığında mı? (geçersiz → sunucu default 60) — **REMOTE_PASS 2026-09-25:** `ttl_seconds=60`
- [ ] Eksik secret → yalnız QR uçları `QR_CONFIG_NOT_READY` (503); uygulama genelinin ayakta kaldığı doğrulandı mı? — negatif config testi bu turda yapılmadı (**ACCEPTED_EVIDENCE_LIMITATION**)

---

## 3) HTTPS / kamera / cihaz

- [x] Kiosk ve personel app **HTTPS** (secure context) üzerinden açılıyor — **REMOTE_PASS 2026-09-25:** `https://www.karmotors.com.tr/personelmedisa/`
- [x] Kamera izni akışı test edildi (izin reddi / kamera yok / başka app kullanıyor mesajları) — **FIELD_PASS** (iPhone Safari, saha)
- [x] Idle `QR Okut` / kamera CTA mobil viewport’ta görünür — **AUTOMATED_PASS 2026-09-25:** PR #405 merged/deployed `bf07ab07`; e2e 430×932 + 393×852 PASS; live bundle `qr-scan-cta-zone` + `QR Okut`
- [x] iPhone Safari / PWA smoke (gerçek kamera permission + QR okutma) — **FIELD_PASS**
- [ ] Android Chrome smoke — **OUT_OF_PILOT_SCOPE** (iPhone-first pilot kapandı)
- [x] BarcodeDetector yoksa jsqr fallback çalışıyor — **AUTOMATED_PASS** (`qr-scanner.ts` + unit tests)

---

## 4) Kiosk

- [x] `/qr-kiosk` yetkili hesapla açılıyor (`qr.kiosk.display`) — **REMOTE_PASS 2026-09-25:** route 200; token mint `ilkerA`/`GENEL_YONETICI` + `sube_id` Fabrika/Kayseri
- [x] Token TTL içinde yenileniyor; süre dolunca personelde “QR süresi doldu” mesajı — **EXPIRED_QR PASS** (repo) + **FIELD_PASS** (saha copy: `QR Kodunun Süresi Doldu. Yeni Kodu Okutun.`)
- [ ] Ekran kilidi / sleep politikası saha için uygun — **OPS_DEFER** (cihaz politikası, ürün gap değil)
- [x] Yanlış şube QR → `QR_CROSS_BRANCH_DENIED` (personelin güncel şubesi ↔ token `sube_id`) — **CROSS_BRANCH PASS** (`CrossBranchDenyScanTestRunner` + UI: `Bu QR Kodu Çalışma Yerinizle Eşleşmiyor.`)

---

## 5) Pilot roster

- [x] Pilot kullanıcıların `users.personel_id` bağlı ve personel **aktif** — **FIELD_PASS**
- [x] Collar read model **Mavi Yaka** (DB `personel_tipleri.ad`) — **FIELD_PASS**
- [x] Beyaz Yaka / unbound kontrol hesabı ile fail-closed smoke — **FIELD_PASS**
- [x] En az bir BIRIM_AMIRI (Mavi Yaka) kendi GİRİŞ/ÇIKIŞ kutularından okutabiliyor — **FIELD_PASS**
- [x] En az bir PERSONEL (Mavi Yaka) self home’dan okutabiliyor — **FIELD_PASS**

---

## 6) Puantaj / İK beklentisi

- [x] Saha/İK eğitildi: QR ≠ otomatik puantaj
- [x] Günlük puantaj satırı yoksa apply engellenir; manuel satır / normal puantaj akışı gerekir
- [x] Correction talebi (düzeltme) onay zinciri pilot şubede biliniyor

---

## 7) Smoke sırası (manuel)

1. [x] Kiosk token görünür + countdown — **FIELD_PASS**
2. [x] Personel kamera → şube QR okut → GİRİŞ — **FIELD_PASS**
3. [x] Self home’da giriş saati görünür — **FIELD_PASS**
4. [x] ÇIKIŞ okut — **FIELD_PASS**
5. [x] `/self/qr-hareketleri` raw liste — **FIELD_PASS**
6. [x] (İK) Puantaj QR aday yüzeyi — otomatik satır oluşmadığını doğrula — **FIELD_PASS**
7. [x] Bilerek başka şube QR → cross-branch red — **CROSS_BRANCH PASS**
8. [x] Süresi dolmuş QR → expired mesajı — **EXPIRED_QR PASS** + **FIELD_PASS**

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
| 2026-09-25 | HTTPS + `/api/health` 200 + `/qr-kiosk` 200 + `/qr-kiosk/token?sube_id=1|4` 200, `ttl_seconds=60`, secret değeri okunmadı | **REMOTE_PASS** — config/HTTPS/kiosk mint OK |
| 2026-09-25 | PR #405 MERGED/DEPLOYED `bf07ab07` (Deploy run `36144289989`); live smoke OK; bundle titles `QR ile Giriş/Çıkış` + mobile CTA; e2e 430×932/393×852 PASS | **AUTOMATED_PASS** — mobile CTA/layout |
| 2026-09-30 | ATTENDANCE/QR FINAL CLOSURE: saha iPhone GİRİŞ/ÇIKIŞ/history **FIELD_PASS**; `CrossBranchDenyScanTestRunner` + mevcut `S3CQrTokenServiceTestRunner`; Findings A–D **CLOSED/LIVE**; phase **CLOSED** | **PILOT_GATE_CLOSED** — `BL-QR-PILOT-OPS` checklist tamam (Android/negatif-secret **ACCEPTED_EVIDENCE_LIMITATION** veya **OPS_DEFER**) |
