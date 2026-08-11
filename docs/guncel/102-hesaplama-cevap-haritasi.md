# 102 — Hesaplama Cevap Haritası

**Amaç:** Aylardır işlenen kodların *neyi nasıl hesapladığını* tek bakışta görmek.  
**Durum kaynağı:** Ürün freeze ve yayın kapıları için `CURRENT_STATE.md` esas alınır. Bu belge backlog değildir; **okuma / toplantı / denetim haritasıdır**.  
**Tarih:** 2026-08-11  
**Motor sürümü (kod):** `S91C2_PAYROLL_ENGINE_V2`  
**Compliance kontratı:** `S87_PAYROLL_COMPLIANCE_V1`

---

## 1. Bu belge ne işe yarar?

| Soru | Cevap burada mı? |
| --- | --- |
| Günlük/saatlik ücret nasıl bulunur? | Evet |
| Fazla mesai / serbest zaman / UBGT / HT nasıl etkiler? | Evet |
| SGK prim günü ve eksik gün kodları? | Evet |
| 18 yaş / 270 saat / gece yasağı? | Evet |
| Hangi dosya owner? | Evet |
| Canlıda parametre dolduruldu mu? | Hayır → `91` formu + `95` runbook |
| Görsel / deploy durumu? | Hayır → `CURRENT_STATE.md` |

Derin teknik kural metni: `04-hesap-motoru-kurallari.md`  
Bordro sahiplik sınırı: `100-i9-bordro-kapsami-ve-sahiplik-karari.md`

---

## 2. Hesap zinciri (tek cümle)

```text
Amir bildirimi / süreç / puantaj
  → haftalık kapanış + (gerekirse) revizyon/correction
  → maaş snapshot (girdi dondurma)
  → bordro hazırlık preflight
  → MaasHesaplamaEngine (saf; canlı DB okumaz)
  → maaş adayı → kontrol / kesinleştirme
```

**Altın kural:** Nihai maaş hesabı backend motorundadır. Frontend `puantaj-hesap-motoru` önizleme/parity içindir; ikinci bordro motoru değildir.

---

## 3. Owner haritası

| Alan | Owner (kod) | Not |
| --- | --- | --- |
| Günlük süre / mola / gece / HT hak / önizleme | `src/services/puantaj-hesap-motoru.ts` | FE parity + UI önizleme |
| Yıllık izin gün hakkı | `src/services/izin-hesap-motoru.ts` | ≤18 / ≥50 → min 20 gün |
| Serbest zaman dönüşüm | `src/services/serbest-zaman-donusum.ts` + event motoru | 1 saat FM → 1.5 saat SZ |
| Otoriter bordro motoru | `api/src/Services/Payroll/MaasHesaplamaEngine.php` | Saf hesap |
| Compliance / bloklar | `api/src/Services/Payroll/PayrollComplianceGuard.php` | Fail-closed |
| Snapshot | `api/src/Services/MaasHesaplamaSnapshotService.php` | Girdi freeze |
| Aday / hesap preflight | `api/src/Services/MaasHesaplamaAdayService.php` | |
| Bordro hazırlık readiness | `api/src/Services/BordroHazirlikPreflightService.php` | |
| SGK prim günü | `api/src/Services/Payroll/SgkPrimGunuEngine.php` | |
| Şirket çalışma politikası | `SirketCalismaPolitikasiService` + katalog | Onaysız parametre yok |
| Mevzuat parametreleri | `MevzuatParametreService` + legal katalog | Eksikse blocker |
| Saklama (10 yıl) | `api/src/Services/Retention/*` | Medisa saklama politikası |

---

## 4. Temel ücret formülleri

| Konu | Formül / kural | Kaynak parametre | Etiket |
| --- | --- | --- | --- |
| Günlük brüt | `sözleşme_baz / NORMAL_AY_GUN_SAYISI` | Tipik `30` | MEVZUAT + SIRKET |
| Saatlik brüt | `sözleşme_baz × 60 / (AYLIK_NORMAL_CALISMA_SAATI × 60)` | Tipik `225` saat | MEVZUAT + SIRKET |
| Hesap tabanı | Mesai / tatil / kesinti **brüt** üzerinden | NET sözleşme önce baz brüte çözülür | MEVZUAT |
| Süre birimi | Dakika | `7.5` saat → `450` dk | TEKNIK |

Canlıya `30` / `225` yazmak için onay formu: `91-bordro-hesaplama-calisma-politikasi-karar-onay-formu.md`.

---

## 5. Günlük çalışma süresi

| Adım | Kural |
| --- | --- |
| Brüt süre | Giriş–çıkış aralığı (dakika) |
| Yasal mola | Brüt süreye göre (ör. 4–7.5 saat → 30 dk; 7.5+ → 60 dk) |
| Net süre | `max(0, brüt − uygulanan mola)` |
| Gece bandı | **20:00 – 06:00** |
| Gece üst sınır uyarısı | Günlük gece brüt > 7.5 saat (450 dk) → `GECE_CALISMASI_7_5_SAAT_ASIMI` |

---

## 6. Haftalık fazla çalışma

| Bant | Aralık | Ücret | Güncel şirket kararı (S87) |
| --- | --- | --- | --- |
| Normal | ≤ 2700 dk (45 saat) | Baz maaşta | Sabit yasal eşik |
| Fazla sürelerle çalışma (FSC, %25) | sözleşme…2700 | ×1.25 | **Kapalı** — eşik üstü doğrudan FM |
| Fazla mesai (FM, %50) | > 2700 dk | ×1.5 | Açık |

- Yıllık limit: **270 saat = 16200 dk** (aşımda hard block; yaklaşmada uyarı ~15600 dk).
- 18 yaş altı: FM ve gece **hard block** (uyarı yetmez).
- Ödeme tercihi: `UCRET` veya `SERBEST_ZAMAN` (karar yoksa blocker).

> Kısmi süreli (<45 saat) sözleşmede FSC gerekirse mevcut `SIRKET_KARARI` ile çelişir; ayrı insan kararı + beyin açma gerekir.

---

## 7. Serbest zaman

| Konu | Sistem cevabı |
| --- | --- |
| Dönüşüm | 1 dk FM → 1.5 dk SZ (`SERBEST_ZAMAN_DONUSUM_KATSAYISI = 1.5`) |
| FM ücreti | `SERBEST_ZAMAN` seçildiyse FM `ARTI` üretilmez (çift etki yasak) |
| Karar mercii | Çalışanın **imzalı yazılı talebi** zorunlu; kanıt yoksa blocker |
| Event modeli | Oluşum / kullanım / düzeltme / iptal |
| **6 ay içinde kullandırma takibi** | **Henüz ürünleşmedi** (bilinçli açık) |

---

## 8. UBGT / resmi tatil

| Durum | Etki |
| --- | --- |
| Çalışılmayan UBGT | Kesinti yok (tam ücret korunur; politika/mod ile) |
| Çalışılan UBGT | Tipik `GUNLUK_ILAVE` → günlük × `UBGT_CARPANI` (çoğu senaryoda +1 yevmiye) |
| Modlar | `GUNLUK_ILAVE` \| `SAAT_CARPAN` \| `GUNLUK_ILAVE_VE_SAAT_CARPAN` |
| Yarım gün | Kapsam/politika yoksa fail-closed blocker |
| Serbest zamana çevirme | UBGT primi serbest zamana çevrilmez |

Takvim owner: resmi tatil takvimi UI + projection servisleri (`94-s88-ubgt-tatil-takvimi-owner.md`).

---

## 9. Hafta tatili / Pazar

| Konu | Sistem cevabı |
| --- | --- |
| Hak ediş | Haftalık iş günlerinde tam çalışma → ücretli HT hakkı |
| Pazar/HT çalışması ek ödeme | Günlük ücret × **1.5** ilave (toplam etki ~2.5 yevmiye) |
| Mod / çarpan | `HAFTA_TATILI_HESAP_MODU` + `HAFTA_TATILI_CARPANI` (şirket politikası) |
| Varsayılan HT günü | Pazar (`0`) — `HAFTA_TATILI_GUNLERI` ile yapılandırılabilir |

---

## 10. Geç kalma / erken çıkma

| Konu | Sistem cevabı |
| --- | --- |
| Hesap | Dakika bazlı; çalışılmayan süre × saatlik brüt kesinti adayı |
| Yuvarlama | Kesintiye esas dakika (30 dk dilimleri vb. — motor helper) |
| Yasal tolerans | Yok |
| Varsayılan tolerans sabiti | `0` dk (firma artırabilir; migration `052` tolerans/disiplin hattı) |
| Habersiz | Ücret kesintisi aynı; ayrıca **disiplin adayı** üretilebilir |

---

## 11. Devamsızlık

| Konu | Sistem cevabı |
| --- | --- |
| Fiili gün | 1 gün mazeretsiz gelmeme → 1 gün kesinti adayı |
| HT kaybı | Aynı haftada HT hakkı düşer → **ayrı** ikinci kesinti kalemi |
| Rapor / ücretli izin | HT kaybı üretmez |
| SGK kodu | Tipik **15 — Devamsızlık** |

Kalem örnekleri: `DEVAMSIZLIK_FIILI_GUN_KESINTISI`, `HAFTA_TATILI_HAK_KAYBI_KESINTISI`.

---

## 12. Rapor / istirahat / iş kazası

| Konu | Sistem cevabı |
| --- | --- |
| SGK kodu | Tipik **01 — İstirahat** |
| Normal hastalık ilk 2 gün | Yasal taban: işveren ödemez; firma isterse parametre ile öder |
| Firma ödemezse | EKSI kalem: `NORMAL_HASTALIK_ILK_2_GUN_ODENMEDI` |
| İş kazası / meslek hastalığı / analık | Hastalık ilk-2-gün politikasından **ayrı** yönetilir |
| Politika belirsiz | Blocker (`NORMAL_HASTALIK_POLITIKASI_COZULEMEDI` vb.) |

---

## 13. SGK prim günü

| Konu | Sistem cevabı |
| --- | --- |
| Maktu tam ay | Ay 28/29/31 çekse de tam dönem → **30** prim günü adayı |
| Eksik gün | Rapor, ücretsiz izin, devamsızlık vb. düşer |
| Kod kataloğu | Örn. 01 istirahat, 07 puantaj, 15 devamsızlık (resmî katalog + şirket eşlemesi) |
| Fail-closed | Resmî kaynak / tamlık yoksa `DOGRULANMIS_TAM` seçilemez |

Owner ayrımı: compute = `SgkPrimGunuEngine`; persist/resolve = `SgkPrimGunuService`.

---

## 14. Yıllık izin (yaş kuralı)

| Koşul | Hak |
| --- | --- |
| Kıdem bandı | 1–5 yıl → 14; 5–15 → 20; 15+ → 26 (klasik tablo) |
| Yaş ≤ 18 veya ≥ 50 | Alt sınır **en az 20 gün** (daha az verilemez) |
| Firma daha fazla verir | Serbest (üst sınır bu kuralı bozmaz) |

Owner: `src/services/izin-hesap-motoru.ts`.

---

## 15. Operasyon akışı (belgedeki 12–15)

| Adım | Sistem yüzeyi |
| --- | --- |
| Ham yoklama | Bildirimler (Geç / Erken / Mesai / Gelmedi…) |
| Resmi süreç | Süreçler (izin, rapor, iş kazası…) |
| Etki adayı | Bildirim → puantaj etki projection / apply |
| Önizleme / onay | Bordro Hazırlık Merkezi + Maaş Hesaplama Merkezi |
| Mühür | Haftalık kapanış + dönem kapanış |
| Kapalı dönem düzeltme | Revizyon talebi + correction (doğrudan sessiz edit yok) |

Yetki matrisi: `09-rol-yetki-matrisi.md` + `RolePermissions`.

---

## 16. Saklama / arşiv

| Konu | Sistem cevabı |
| --- | --- |
| Süre | **10 yıl** (`POLICY_RETENTION_YEARS`) |
| Dil | “Medisa saklama politikası” (kanunen iddia etmez; hedef 10 yıl) |
| Kategoriler | Özlük, puantaj, bordro, izin, rapor, SGK, FM, serbest zaman, disiplin… |
| İmha | Retention + legal hold + imha talebi workflow (süre dolmadan imha yok) |

---

## 17. “Resmi durum” belgesi ile çapraz kontrol

Kaynak: masaüstü `puantaj resmi durum.docx` (toplantı mevzuat özeti).

| Madde | Kod cevabı | Açık / dikkat |
| --- | --- | --- |
| 1 Ücret /30 /225 / brüt | Var (parametreli) | Canlı onay formu |
| 2 SGK gün + 01/15/07 | Var | Resmî katalog ops kapısı |
| 3 İzin yaş 20 gün | Var | — |
| 4 18 yaş FM/gece blok | Var | — |
| 5 FM 1.5 + 270 saat | Var | FSC %25 **kapalı** |
| 6 Serbest zaman | Var (dönüşüm+kanıt) | **6 ay vade takibi yok** |
| 7 UBGT | Var | Politika/mod onayı |
| 8 HT / Pazar 1.5 | Var | — |
| 9 Geç/erken | Var | Tolerans firma kararı |
| 10 Devamsızlık + HT | Var | — |
| 11 Rapor / ilk 2 gün | Var | Firma checkbox |
| 12–15 Akış | Var | — |
| 16 10 yıl | Var | — |

---

## 18. Bilinçli kapsam dışı / FUTURE

| Konu | Durum |
| --- | --- |
| Serbest zaman 6 ay kullanım vadesi | Ürünleşmedi |
| FSC (%25) aktif bant | S87 ile kapalı |
| Zorunlu/olağanüstü çalışma istisna modeli | Bilinçli kapsam dışı |
| Bordro PDF / banka dosyası / SGK bildirgesi çıktısı | FUTURE (kısmi CSV var) |
| İkinci bordro motoru | Yasak |

---

## 19. Nereden doğrularım?

```bash
# FE parity / birim
npm run test -- tests/unit/puantaj-hesap-motoru.test.ts
npm run test -- tests/unit/izin-hesap-motoru.test.ts

# PHP motor / compliance (ortamda php varsa)
php tests/php/MaasHesaplamaEngineTestRunner.php
php tests/php/PayrollComplianceGuardTestRunner.php
php tests/php/SgkPrimGunuEngineTestRunner.php
```

Detaylı compliance kapanış: `99-payroll-compliance-critical-gaps-kapanis.md`.

---

## 20. Okuma sırası (yeni gelen için)

1. Bu belge (`102`) — neyin nasıl hesaplandığı  
2. `CURRENT_STATE.md` — freeze / yayın  
3. `100-i9-bordro-kapsami-ve-sahiplik-karari.md` — bordro sınırları  
4. `04-hesap-motoru-kurallari.md` — derin kural metni  
5. `91` + `95` — canlı parametre ve ops kapıları  

---

## Sonuç

Sistem; ücret bölenleri, FM, HT/UBGT, SGK prim günü, devamsızlık+HT kaybı, hastalık ilk 2 gün, 18 yaş blokları, 270 saat, serbest zaman dönüşümü ve 10 yıl saklama için **kodlanmış cevap** taşır.  
Elinde bu belgeyle “aylardır ne işledik?” sorusunun ürün cevabı okunabilir; canlı kesinleştirme ise ayrı operasyon/onay kapılarından geçer.
