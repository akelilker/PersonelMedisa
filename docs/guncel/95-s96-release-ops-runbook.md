# S96 — Release Ops Runbook (Nihai Ürün Kabul Kapıları)

**Durum:** Kod tarafı S95 ile hazır. Bu runbook, dış girdiler geldiğinde **yeni geliştirme olmadan** kontrollü canlı kabulü tamamlamak içindir.

**Kesin yasak (bu runbook çalıştırılırken bile, açık onay satırı yazılmadan):**

- Production SGK katalog import/approve write
- Production UBGT / resmi tatil seed write
- Production bordro kesinleştir / dönem mühür write (nihai kabul senaryosu hariç ve ayrı onay ile)
- Credential’ların repo/commit/log’a yazılması

Baz SHA (S95 kapanış): `f35da0d826e5267efb9b6a3f778393ab92f4d0bf`  
Güncel main SHA operasyon anında `git rev-parse origin/main` ile doğrulanır.

---

## 0) Hızlı komutlar

```bash
# Kod + ops kapısı durumu (write yok)
npm run release:gate

# Canlı read-only smoke (credential yok)
SMOKE_BASE_URL=https://<canlı-host> npm run smoke:live

# Ops kapılarını “hazır” işaretle (yalnız acknowledgement; write açmaz)
RELEASE_GATE_SGK_OFFICIAL_SOURCE=ready \
RELEASE_GATE_UBGT_SEED_APPROVED=ready \
RELEASE_GATE_PROD_WRITE_APPROVED=ready \
RELEASE_GATE_AUTH_SMOKE_CREDENTIAL=ready \
REQUIRE_OPS_READY=1 \
npm run release:gate

# Authenticated read-only smoke (login + GET; write yok)
SMOKE_BASE_URL=https://<canlı-host> \
SMOKE_AUTH_USERNAME=<test-user> \
SMOKE_AUTH_PASSWORD=<test-pass> \
npm run smoke:live
```

`REQUIRE_OPS_READY=1` iken dört ops env `ready` değilse çıkış kodu `2` (CODE_READY_OPS_PENDING).

---

## 1) Dış bağımlılık kapıları

| Kapı | Env acknowledgement | Ne bekleniyor | Write açar mı? |
| --- | --- | --- | --- |
| SGK resmi kaynak | `RELEASE_GATE_SGK_OFFICIAL_SOURCE=ready` | Birincil kamu kaynağı paket + hash; bkz. `94-s85c-sgk-katalog-manuel-kanit-talep-paketi.md` | Hayır (yalnız işaret) |
| UBGT / resmi tatil seed onayı | `RELEASE_GATE_UBGT_SEED_APPROVED=ready` | Hangi tarihler/kapsam seed edilecek yazılı onay | Hayır |
| Production write yetkisi | `RELEASE_GATE_PROD_WRITE_APPROVED=ready` | İmza / ticket / rol onayı | Hayır (işaret); gerçek write ayrı adım |
| Auth smoke hesabı | `RELEASE_GATE_AUTH_SMOKE_CREDENTIAL=ready` | Güvenli, mümkünse read-focused test kullanıcısı | Hayır |

Kod fail-closed referansları:

- `api/src/Controllers/SgkKatalogHazirlikController.php` — seed/write activation yok
- `api/src/Services/Payroll/SgkKatalogTamlikService.php` — `approve_aktif_mi => false`
- Router’da SGK import yalnız `/sgk-katalog-hazirlik/import/dry-run`

---

## 2) SGK katalog resmi kaynak geldiğinde (write öncesi)

1. Kaynak birincil mi doğrula (SGK / Resmî Gazete / e-Bildirge; blog kabul edilmez).
2. `94` talep paketindeki dosya adı + SHA256 standardını uygula.
3. Lokal dry-run ile `DOGRULANMIS_TAM` adaylığını değerlendir; production’a yazma.
4. `RELEASE_GATE_SGK_OFFICIAL_SOURCE=ready npm run release:gate`
5. Write için ayrıca `RELEASE_GATE_PROD_WRITE_APPROVED=ready` + açık insan onayı gerekir.
6. Write açıldığında (ayrı koşu): önce dry-run, sonra tek transaction/commit, sonra tamlık/blocker paneli read-back.

> Not: Mevcut PHP katmanı import write route’unu bilerek kapalı tutar. Write endpoint aktivasyonu **ayrı onaylı kod/değişiklik** gerektirir; bu runbook endpoint’i sessizce açmaz.

---

## 3) UBGT / resmi tatil seed onayı geldiğinde

1. Onay metninde dönem, `TAM_GUN` / `YARIM_GUN`, kaynak referansı, aktifleştirme yetkisi net olsun.
2. UI owner: `/resmi-tatil-takvimi` (taslak → aktifleştir; hard delete yok).
3. `RELEASE_GATE_UBGT_SEED_APPROVED=ready`
4. Production seed yalnızca `PROD_WRITE` onayı + kontrollü tek kayıt/liste ile yapılır.
5. Seed sonrası read-only kontrol: liste, readiness kartları, projection preview (puantaj yazmaz).

Owner notu: `docs/guncel/94-s88-ubgt-tatil-takvimi-owner.md`

---

## 4) Production write yetkisi geldiğinde (genel protokol)

Her write paketinde zorunlu sıra:

1. `git fetch` + `origin/main` SHA kaydı
2. `npm run release:gate` → `CODE_READY…` veya `FULL_READY`
3. `SMOKE_BASE_URL=… npm run smoke:live` → OK
4. Authenticated read smoke (varsa) → OK
5. Yazılacak endpoint listesini tek satırda yaz (ör. “resmi tatil create+activate; SGK yok”)
6. İnsan onayı kaydı (kim / ne / hangi dönem)
7. Write çalıştır
8. Hemen ardından read-back + `smoke:live`
9. Sonuç kaydı (tarih, SHA, CI/deploy run, OK/FAIL)

Bu koşuda (S96) write **yapılmayacaktır**.

---

## 5) Canlı authenticated smoke hesabı geldiğinde

1. Hesabı secret store’da tut; repoya koyma.
2. Dedicated teknik rol: `AUTH_SMOKE_READONLY` (exact 1 şube; yalnız `ops.auth_smoke.read`).
3. Dedicated smoke rolü hiçbir personel veya domain verisi okumaz.
   Endpoint yalnız authentication, permission ve tek-şube scope contractını doğrular.
4. `RELEASE_GATE_AUTH_SMOKE_CREDENTIAL=ready`
5. Çalıştır:

```bash
SMOKE_BASE_URL=https://<canlı-host> \
SMOKE_AUTH_USERNAME=<test-user> \
SMOKE_AUTH_PASSWORD=<test-pass> \
npm run smoke:live
```

Beklenen: anonymous health/auth-guard/frontend/assets OK + `login + GET /api/auth/smoke-read` OK.
Script login sonrası **POST/PUT/PATCH/DELETE çağırmaz**. Personel listesi okumaz.

---

## 6) Nihai ürün kabul checklist (ops)

- [ ] Main SHA doğrulandı
- [ ] Main CI SUCCESS
- [ ] Deploy cPanel SUCCESS (deploy head SHA = main)
- [ ] `npm run release:gate` → CODE_READY veya FULL_READY
- [ ] `smoke:live` OK
- [ ] (Opsiyonel) authenticated smoke OK
- [ ] Dört dış kapı için acknowledgement veya bilinçli OPEN notu
- [ ] Production write bu koşuda yapılmadı / ayrı onaylı koşuya bırakıldı

Kayıt şablonu `DEPLOY_CHECKLIST.md` smoke sonuç kaydı ile birleştirilir.
