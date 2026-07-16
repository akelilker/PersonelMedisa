# 79 — S76 Dönem Kapanış Merkezi Yerel Uygulama Checkpoint

---

## 1. Final Karar

S76 dönem kapanış preflight motoru, mühür enforcement, audit tablosu, etki adayı dönem raporu ve Raporlar altı UI yerelde uygulanmıştır.

**Final etiket:** `S76_LOCAL_IMPLEMENTATION_COMPLETE`

- **Kapsam:** Yerel kod + test + dokümantasyon
- **Canlı migration:** Yapılmadı
- **Deploy / push:** Yapılmadı
- **Production DB mutation:** Yapılmadı

---

## 2. Canonical Kapanış

- Teknik kapanış owner: `puantaj_aylik_muhurleri`
- Endpoint: `POST /puantaj/muhurle` (yeni paralel close endpoint yok)
- Blocker varsa: `409 PERIOD_CLOSE_BLOCKED`
- Mühürlü dönem: idempotent `200`, `PERIOD_ALREADY_SEALED` blocker üretilmez
- Maaş eksikliği: `FINANCE_SALARY_MISSING` → `WARNING` (mühürü engellemez)

---

## 3. Migration

| Dosya | İçerik |
|-------|--------|
| `016_donem_kapanis_auditleri.sql` | `donem_kapanis_auditleri` append-only audit |
| `017_donem_kapanis_ve_etki_rapor_indexleri.sql` | Eksik rapor/preflight indexleri |

---

## 4. Backend Owner'lar

| Owner | Rol |
|-------|-----|
| `BildirimDonemContextService` | Bildirim zinciri ortak sorgular |
| `DonemKapanisPreflightService` | BLOCKER/WARNING/INFO + hash `S76_PERIOD_CLOSE_PREFLIGHT_V1` |
| `DonemKapanisPreflightItemsService` | Detay/pagination/CSV satırları |
| `DonemKapanisAuditService` | Blocked/success audit idempotency |
| `DonemKapanisController` | Preflight summary/items/CSV/audit list |
| `BildirimPuantajEtkiRaporQueryService` | Etki adayı dönem raporu |

---

## 5. API

| Method | Path |
|--------|------|
| GET | `/puantaj/donem-kapanis-preflight` |
| GET | `/puantaj/donem-kapanis-preflight/items` |
| GET | `/puantaj/donem-kapanis-preflight/export.csv` |
| GET | `/puantaj/donem-kapanis-auditleri` |
| GET | `/puantaj/bildirim-etki-adaylari/rapor` |
| GET | `/puantaj/bildirim-etki-adaylari/rapor/export.csv` |

---

## 6. Permission Matrisi (özet)

| Rol | Dönem kapanış view | Export | Etki rapor view | Export | Mühür |
|-----|-------------------|--------|-----------------|--------|-------|
| GENEL_YONETICI | ✓ | ✓ | ✓ | ✓ | ✓ |
| MUHASEBE | ✓ | ✓ | ✓ | ✓ | ✗ |
| BOLUM_YONETICISI | ✓ | ✗ | ✓ | ✗ | ✓ |
| BIRIM_AMIRI | ✓ (kendi kapsam) | ✗ | ✓ (kendi kapsam) | ✗ | ✗ |

---

## 7. Frontend

Raporlar altında (yeni top-level menü yok):

- `/raporlar?panel=donem-kapanis` — Dönem Kapanış Merkezi
- `/raporlar?panel=etki-adayi` — Etki Adayı Raporu

---

## 8. Test Kapıları (yerel)

- PHP: `DonemKapanisPreflightTestRunner`, `DonemKapanisCloseIntegrationTestRunner`, `DonemKapanisMysqlConcurrencyTestRunner`
- Vitest: migration source, php-runtime, frontend unit
- E2E: `donem-kapanis-merkezi.spec.ts`, `etki-adayi-raporu.spec.ts`
- `npm run typecheck`, `npm run test`, `npm run build`

MariaDB concurrency runner: `MEDISA_TEST_MYSQL_DSN` yoksa skip (S74/S75 ile aynı model).

---

## 9. S76-C Devam Planı (canlı)

1. Migration 016/017 canlı `karmotor_medisa` üzerinde additive uygulama
2. cPanel deploy (yalnız `dist/` + `api/`)
3. Smoke: preflight GET, blocker 409, temiz ay mühür, audit satırı
4. Etki raporu filtre/CSV canlı doğrulama
5. Checkpoint güncelleme: `S76_FULLY_COMPLETE` (canlı kabul sonrası)

---

## 10. Başarı Kodları (yerel)

- `S76_PREFLIGHT_ENGINE_LOCAL_OK`
- `S76_PERIOD_CLOSE_ENFORCEMENT_LOCAL_OK`
- `S76_CLOSE_AUDIT_LOCAL_OK`
- `S76_EFFECT_CANDIDATE_REPORT_LOCAL_OK`
- `S76_PERIOD_CLOSE_UI_LOCAL_OK`
- `S76_CONCURRENCY_LOCAL_OK`
- `S76_FULL_REGRESSION_GATE_OK`
- `S76_LOCAL_IMPLEMENTATION_COMPLETE`
