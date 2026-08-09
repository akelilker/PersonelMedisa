# 100 — I9 Bordro Kapsamı ve Sahiplik Kararı (ADR)

## 1. Status

**PROPOSED / REVIEW** — henüz ACCEPTED değildir.

Bu belge docs-only’dir. Runtime kod, API, migration veya production yazma içermez.

## 2. Context

I1–I8 UI/IA paketleri Süreç/Personel Kartı giriş ve yaşam döngüsü yüzeylerini netleştirdi. Bordro hesaplama zinciri (snapshot → preflight → deterministik motor → aday → onay/çıktı) ise ayrı owner’lara dağılmış durumdadır. I9, bu zincirin **kapsam ve sahiplik** sınırlarını tek ADR’de sabitlemek için açılmıştır.

Otoriter motor/snapshot sözleşmeleri:

- `docs/guncel/85-s77-d-maas-hesaplama-motoru-kontrati.md` (`S77_D_OWNER_MAP_CONFIRMED`)
- `docs/guncel/83-s77-c-maas-hesaplama-snapshot-kontrati.md`
- `docs/guncel/81-s77-b-ucret-gecmisi-mevzuat-parametre-altyapisi.md`
- `docs/guncel/88-s77-d2-engine-v2-calisma-suresi-tatil-ucreti-kontrati.md`
- `docs/guncel/93-s85b-sgk-prim-gunu-owner-yerel-checkpoint.md`
- `docs/guncel/99-payroll-compliance-critical-gaps-kapanis.md`

Kod doğrulaması bu ADR’de bağlayıcıdır; eski doküman dilini geçersiz kılabilir (bkz. §15 Conflicts).

## 3. Problem

Payroll kapsamı şu an birden fazla yüzey/serviste yaşıyor:

- Raporlar → Maaş Hesaplama Merkezi
- Raporlar → Bordro Hazırlık Merkezi (preflight / ön izleme / gömülü hesaplama)
- Snapshot, aday, motor, SGK prim günü, ücret geçmişi, puantaj, finans girdileri

Riskler:

1. Süreç / Personel Kartı / Kayıt yüzeylerinin “payroll execution” sanılması
2. Canlı mutable veriden motor çalıştırma
3. İkinci bir payroll motoru (FE/puantaj motoru karıştırması)
4. Finans kaydının bordro sonucu sanılması
5. Onay/çıktı durumunun “uygulandı” sanılması (eksik yetenekleri mevcut gibi yazmak)

I9 bu sınırları açıkça ayırır; uygulama (I10+) için net sözleşmeyi bırakır.

## 4. Existing authoritative contracts

| Sözleşme | Rol |
| --- | --- |
| S77-B ücret/mevzuat altyapısı (`81`) | `personel_ucret_gecmisi` + `mevzuat_parametreleri` kanonik |
| S77-C snapshot (`83`) | Girdi freeze / hash / preflight |
| S77-D motor (`85`) | Saf motor + aday orkestrasyonu + canlı okuma yasağı |
| S77-D2 engine V2 (`88`) | Paralel motor yok; motor sürüm evrimi |
| S82 bordro hazırlık / ön izleme | Preflight aggregation + kontrol submission / finalize-return (permission-based; kod) |
| S85-B SGK prim günü (`93`) | Prim günü compute/resolve owner ayrımı |
| S87 compliance gaps (`99`) | Kritik uyum guard’ları |

**CURRENT_IMPLEMENTATION** (kod): motor sürümü `S91C2_PAYROLL_ENGINE_V2` (`api/src/Services/Payroll/MaasHesaplamaEngine.php`).  
**EXISTING_LOCKED_CONTRACT** (`85`): tarihsel olarak `S77D_PAYROLL_ENGINE_V1` yazar.  
**I9_DECISION:** ADR motor owner’ı `MaasHesaplamaEngine` olarak sabitler; sürüm string’inde **kod** esas alınır, `85` V1 ifadesi historical kabul edilir.

## 5. Owner matrix

| Alan | Owner | Kaynak |
| --- | --- | --- |
| PERSONEL_MASTER_OWNER | `personeller` + `PersonellerController` / `PersonelCreateService` / `PersonelCanonicalValidator` | API personel master |
| SALARY_HISTORY_OWNER | `personel_ucret_gecmisi` + `PersonelUcretService` | `81`, `PersonelUcretService.php` |
| UCRET_TIPI_OWNER | `personeller.ucret_tipi_id` (master alan; ücret geçmişinden ayrı) | Personel validators / snapshot PERSONEL |
| PUANTAJ_OWNER | FE: `src/services/puantaj-hesap-motoru.ts`; persist: `PuantajController` + `gunluk_puantaj` / aylık mühür | Puantaj hattı |
| WEEKLY_CLOSE_OWNER | `HaftalikKapanisController` + `haftalik_kapanislar` | Haftalık kapanış |
| SNAPSHOT_OWNER | `MaasHesaplamaSnapshotService` + `maas_hesaplama_*_snapshotlari` | `85`, `83` |
| PAYROLL_PREFLIGHT_OWNER | Üç katman: snapshot preflight (`MaasHesaplamaSnapshotService`), hesap preflight (`MaasHesaplamaAdayService`), readiness aggregation (`BordroHazirlikPreflightService`) | `83`, `85`, S82 |
| PAYROLL_ENGINE_OWNER | `MaasHesaplamaEngine` (saf; DB yok) | `85`, `MaasHesaplamaEngine.php` |
| PAYROLL_CANDIDATE_OWNER | `MaasHesaplamaAdayService` | `85` |
| FINANCE_INPUT_OWNER | Live: `ek_odeme_kesinti` / `EkOdemeKesintiController`; freeze: snapshot `FINANS` + `FinanceKalemCatalog` | `85` §3.6/§5 |
| LEGAL_PARAMETER_OWNER | Live: `mevzuat_parametreleri` / `MevzuatParametreService`; katalog: `MaasHesaplamaLegalParameterCatalog`; freeze: snapshot `MEVZUAT` | `81`, `85` |
| SGK_PRIM_GUNU_OWNER | Compute: `Payroll/SgkPrimGunuEngine`; resolve/persist: `SgkPrimGunuService` | `93` |
| PAYROLL_UI_OWNER | `MaasHesaplamaMerkeziPage` + `BordroHazirlikMerkeziPage` (Raporlar) | `RaporlarPage.tsx` |
| PAYROLL_APPROVAL_OWNER | **CURRENT:** `BordroOnIzlemeService` + `BordroHazirlikController`. Kontrol submission: `maas_hesaplama_adaylari.manage`. Geri gönderme / kesinleştirme: `bordro_kesinlestirme.approve` (role hard-code yok). `85` §9’daki “faz dışı” ifadesi superseded. | Migration `034`, S82 |
| PAYROLL_OUTPUT_OWNER | **FUTURE** (PDF/Excel bordro, banka dosyası, SGK bildirgesi). **Partial CURRENT:** readiness CSV / SGK sonuç CSV (ops export). | `85` §9, controllers |
| AUDIT_OWNER | Split: snapshot auditleri, aday auditleri, ücret auditleri, SGK immutable audits | migrations `018`/`020`/`023`/`034` |

## 6. Canonical data flow

```text
Personel / Ücret / Süreç / Puantaj / Finans / Mevzuat
        ↓
finalized / sealed source states
        ↓
payroll snapshot (MaasHesaplamaSnapshotService)
        ↓
Bordro Hazırlık readiness / preflight (BordroHazirlikPreflightService)
        + calculation preflight (MaasHesaplamaAdayService)
        ↓
deterministic payroll engine (MaasHesaplamaEngine)  ← no live DB reads
        ↓
calculation candidate (MaasHesaplamaAdayService persist)
        ↓
approval (BordroOnIzlemeService + BordroHazirlikController) / later output phases
```

Wording note: snapshot oluşturma sırasında kaynak tablolar okunur ve dondurulur. Motor çalışırken canlı master/puantaj/ücret/finans yeniden okunmaz.

## 7. UI / IA ownership

| Yüzey | Payroll execution owner? | Rol |
| --- | --- | --- |
| Kayıt | **NO** | İlk personel master oluşturma |
| Süreç | **NO** | Post-create input/lifecycle (ücret, belge, pozisyon, …) |
| Personel Kartı | **NO** | Current state + history/dossier |
| Raporlar → Maaş Hesaplama Merkezi | **YES (calculation UI)** | Route: `/raporlar?panel=maas-hesaplama` |
| Raporlar → Bordro Hazırlık Merkezi | **YES (readiness/preflight/onay UI)** | Route: `/raporlar?panel=bordro-hazirlik` |

I9 UI taşımaz. Maaş hesaplama UI konumu kilitlidir: **Raporlar → Maaş Hesaplama Merkezi**.

## 8. Write ownership

| Write class | Owner | Not |
| --- | --- | --- |
| Personel master create/update | Personeller / Süreç personel formları | Payroll result yazmaz |
| Ücret dönem yazımı | `PersonelUcretService` → `personel_ucret_gecmisi` | Snapshot `UCRET` tüketir |
| Puantaj upsert / mühür | `PuantajController` | Motor canlı puantaj okumaz |
| Snapshot create/cancel | `maas_hesaplama.manage` | Freeze |
| Hesapla / aday | `maas_hesaplama_adaylari.manage` | Engine invoke |
| Muhasebe kontrol gönder | `maas_hesaplama_adaylari.manage` | Onay kuyruğu (`submitKontrol`) |
| Kesinleştir / geri gönder | `bordro_kesinlestirme.approve` | S82 (`kesinlestir` / `geriGonder`) |
| Finans ek ödeme/kesinti | Finans / `ek_odeme_kesinti` | Input; payroll result değil |
| `MAAS` finans kalemi | **BLOCKER** | Duplicate salary yasak |

## 9. Snapshot boundary

- **SNAPSHOT_REQUIRED = YES**
- Hesap yalnız geçerli (`OLUSTURULDU` + hash doğruluğu) snapshot üzerinden
- **LIVE_DATA_READ_DURING_ENGINE = NO**
- Mevzuat zorunlu parametre eksikse silent default yok → blocker
- Ücret kanonik kaynak: `personel_ucret_gecmisi` → freeze `UCRET` segmentleri

## 10. Permission boundary

**PAYROLL_VIEW_PERMISSION (dual):**

- `maas_hesaplama.view` — snapshot UI/API
- `maas_hesaplama_adaylari.view` — aday / çalıştırma / SGK list
- Panel gate (bordro hazırlık): `bordro_on_izleme.view`

**PAYROLL_MANAGE_PERMISSION (dual):**

- `maas_hesaplama.manage` — snapshot create/cancel
- `maas_hesaplama_adaylari.manage` — hesapla / kontrol submission (`submitKontrol`)
- Kesinleştir / geri gönder: `bordro_kesinlestirme.approve` (`kesinlestir` / `geriGonder`)

**PAYROLL_ROLE_MATRIX (code truth — `api/src/Auth/RolePermissions.php` + FE mirror):**

| Rol | maas_hesaplama view/manage | maas_hesaplama_adaylari view/manage | bordro_on_izleme.view | bordro_kesinlestirme.approve |
| --- | --- | --- | --- | --- |
| GENEL_YONETICI | yes / yes | yes / yes | yes | yes |
| MUHASEBE | yes / yes | yes / yes | yes | no |
| BOLUM_YONETICISI | no | no | no | no |
| BIRIM_AMIRI | no | no | no | no |
| PATRON | no | no | no | no |
| IK_BORDRO | no | no | yes | no |
| SGK_KARAR_ONAY_YETKILISI | no | no | yes | yes |

`85` yalnız `maas_hesaplama_adaylari.*` yazar; I9 kod matrisini bağlayıcı kabul eder.

## 11. Explicit non-goals

I9 şunları **yapmaz**:

- Runtime/src/api/migration değişikliği
- Payroll feature implementation
- UI taşıma (Süreç/Kart’a hesaplama)
- Yeni permission / endpoint
- Production deploy
- Bordro PDF/Excel/banka/SGK bildirgesi ürünleştirme
- İkinci motor

## 12. Rejected alternatives

| Alternatif | Ret gerekçesi |
| --- | --- |
| Payroll calculation inside Süreç | Süreç input/lifecycle owner; execution UI Raporlar’da kilitli |
| Live mutable data during engine run | `85` canlı okuma yasağı; determinism kırılır |
| Generic finance records as payroll result | Finans input; `MAAS` duplicate blocker |
| Personel Card as payroll executor | Kart dossier/history; execution değil |
| Second payroll engine | `88` “Paralel motor yok”; FE `puantaj-hesap-motoru` parity sabiti ayrıdır, ikinci bordro motoru değildir |

## 13. Consequences

- Downstream işler (I10+) Süreç/Kart write cleanup yaparken payroll execution’a dokunmaz.
- Bordro hazırlık ikinci motor olamaz; readiness/preflight/onay yüzeyidir.
- Doküman sürüm string’leri kod ile çelişirse kod + bu ADR güncel çözüm olur; `85` historical V1 etiketi korunabilir.
- Onay/finalization akışı “future” değil; S82 `BordroOnIzlemeService` + `BordroHazirlikController` current’tır (permission-based: kontrol = `maas_hesaplama_adaylari.manage`, finalize/return = `bordro_kesinlestirme.approve`). Çıktı ürünleri hâlâ future’dır.
- `BordroHazirlikPreflightService` içindeki “Genel yönetici final onayı” readiness/input blocker’ıdır; `bordro_kesinlestirme.approve` finalization permission’ı ile karıştırılmaz.

## 14. Follow-on phases

| Phase | Scope |
| --- | --- |
| I10 | Personel Kartı write cleanup (payroll dışı) |
| Later | Output ürünleri (PDF/Excel/banka/SGK bildirgesi) — ayrı karar |
| Later | Permission key consolidation (dual keys → tek isimlendirme) — opsiyonel |

## 15. Open questions

1. Permission key’lerin tek isim altında birleştirilmesi ürün kararı mı, yoksa dual keys kalıcı mı?
2. Readiness/SGK CSV “payroll output” sayılacak mı, yoksa ops export olarak mı ayrılacak?
3. Formal actor identity (`actor_identities`, S98 ADR) ile `personeller` master ayrımı bordro snapshot kimlik alanında ileride genişletilecek mi?

Bu sorular I9’u bloklamaz; PROPOSED ADR mevcut kod + kilitli sözleşmelerle merge-review’e gidebilir.

## Conflicts (explicit)

| CONFLICT | CURRENT_SOURCE | OLDER_SOURCE | ADR_RESOLUTION |
| --- | --- | --- | --- |
| Engine version string | Code/tests `S91C2_PAYROLL_ENGINE_V2` | `85` `S77D_PAYROLL_ENGINE_V1` | Code wins; `85` historical |
| Candidate contract name | Code `S85B_PAYROLL_CANDIDATE_V1` | `88` `S77D_PAYROLL_CANDIDATE_V2` naming | Code contract id |
| Permission keys | Dual `maas_hesaplama*` + `maas_hesaplama_adaylari*` + bordro onay | `85`/`83` tek anahtar seti | Code matrix |
| S82 approval/finalization | Implemented S82 `BordroOnIzlemeService` + `BordroHazirlikController` (kontrol = `maas_hesaplama_adaylari.manage`; finalize/return = `bordro_kesinlestirme.approve`) | `85` §9 “faz dışı” | Current = S82 permission-based; `85` superseded on approval |

## Key paths

```text
docs/guncel/85-s77-d-maas-hesaplama-motoru-kontrati.md
api/src/Services/Payroll/MaasHesaplamaEngine.php
api/src/Services/MaasHesaplamaSnapshotService.php
api/src/Services/MaasHesaplamaAdayService.php
api/src/Services/BordroHazirlikPreflightService.php
api/src/Services/BordroOnIzlemeService.php
api/src/Controllers/BordroHazirlikController.php
api/src/Services/SgkPrimGunuService.php
api/src/Services/PersonelUcretService.php
api/src/Controllers/MaasHesaplamaController.php
api/src/Auth/RolePermissions.php
src/features/raporlar/pages/MaasHesaplamaMerkeziPage.tsx
src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx
src/services/puantaj-hesap-motoru.ts
```
