# 21. Devam Primi V2 Kapanis Checkpoint

Surum: `V2 eligibility faz kapanisi`

## 1. Amac

Bu belge, Devam Primi V2 fazinin **dokumantasyon kapanis checkpoint**'idir.

Amac, testli ve stabilize edilmis **devam primi eligibility** hattini kayit altina almak; finans, bordro, SGK ve dashboard kapsamina tasinmadan fazi kapatmaktir.

Bu belge kod fazidir degil. Yeni is kurali, API veya finans/bordro/SGK karari acmaz.

On kosul ve tasarim zemini: `20-devam-primi-owner-ve-dar-tasarim-karari.md`

## 2. V2 Mimari Ayrimi

Devam Primi V2, karar mantigini mevcut puantaj / finans / SGK owner'larindan ayri tutar. Katmanlar:

| Katman | Owner dosya | Sorumluluk |
|---|---|---|
| Karar motoru | `src/services/devam-primi-hesap-motoru.ts` | Donem + personel filtreli eligibility hesabi; tutar disi boolean/enum karar |
| Veri toplama ve gorunum | `src/hooks/useDevamPrimiEligibilityOzeti.ts` | Onbellekten donem puantaj kayitlarini toplar; motor ciktisini UI durumuna map eder |
| Salt okunur yuzey | `src/features/personeller/pages/PersonelDetayPage.tsx` | Personel detayinda eligibility ozeti gosterir; hesap yapmaz |

**Bilincli olarak bu hatta olmayan owner'lar:**

- Puantaj hesap motoru (`04-hesap-motoru-kurallari.md` kapsami) — devam primi hak/kes karari uretmez
- Finans CRUD / `PRIM` / `BONUS` / `EKSTRA_PRIM` kalemleri — manuel finans kalemi; otomatik devam primi owner'i degildir
- Dashboard SGK servisi — prim hak edisi yapmaz
- Bordro / net maas / oran hesabi — bu fazda yoktur

## 3. Eligibility Motoru — Uretilen Cikti

`hesaplaDevamPrimiEligibility` yalnizca **hak kazanma / kesilme / manuel inceleme** semantiginde karar uretir.

Motor ciktisi (`DevamPrimiEligibilitySonuc`):

- `hak_kazandi_mi`
- `kesildi_mi`
- `kesinti_nedeni` (ornegin `1_gun_hastalik_raporu`)
- `manuel_inceleme_gerekli_mi`
- `uygulanan_kural`, `aciklama`, `donem`, `prim_kurali_id`

Hook gorunum ciktisi (`DevamPrimiEligibilityOzetiView`):

- `durum`: `hak_kazandi` | `kesildi` | `manuel_inceleme`
- `durumLabel`: `Hak Kazandı` | `Kesildi` | `Manuel İnceleme Gerekli`
- `aciklama`, `kayitKapsamiNotu` (veri kapsami uyarisi)

V2 pilot kural seti (otomatik kesinti):

- Aylik donemde en az bir **tam gun** `Raporlu_Hastalik` + `Gelmedi` kaydi → `kesildi_mi: true`

Manuel inceleme isaretleri (otomatik kesinti yok):

- `prim_kurali_id` tanimli degil
- Donemde `Raporlu_Is_Kazasi` kaydi var

Otomatik kesinti **uretmeyen** olaylar (V2 kapsaminda):

- `Yok_Izinsiz`, `Gec_Geldi` ve benzeri gunluk olaylar tek basina kesinti tetiklemez

## 4. Uretilmeyen Ciktilar (Net Sinir)

Eligibility motoru ve hook **asagidakileri uretmez**:

- Finans kalemi veya otomatik `PRIM` / `BONUS` kaydi
- Bordro satiri, net maas, brut/tutar hesabi
- Prim orani, prim tutari, odeme tutari
- SGK resmi kodu veya SGK bildirim ciktisi
- Dashboard metrigi veya API endpoint cevabi

Para, oran ve mevzuat kodu ihtiyaclari sonraki fazlara (finans / bordro / SGK) birakilir; bu checkpoint onlari acmaz.

## 5. Eksik Onbellek / Eksik Donem Verisi Davranisi

`mapDevamPrimiEligibilityToView`, donem gun sayisina karsi yuklenen kayit sayisini karsilastirir (`kayitSayisi < donemGunSayisi`).

**Kural:** Eksik veri kapsaminda **Hak Kazandı** gosterilmez.

| Durum | Gorunum |
|---|---|
| Kapsam eksik + motor `kesildi_mi` | `Kesildi` korunur (kesin kesinti sinyali kaybolmaz) |
| Kapsam eksik + motor kesinti yok | `Manuel İnceleme Gerekli`; aciklama: donem puantaji tam yuklenmeden kesin degerlendirme yapilamaz |
| Tam kapsam + motor karar | Normal `hak_kazandi` / `kesildi` / `manuel_inceleme` map |

Bu davranis, bos veya kismi onbellekte yanlis pozitif **Hak Kazandı** riskini kapatir.

## 6. Personel Izolasyonu — Regression Guvenligi

Eligibility hatti personel bazinda izole calisir:

**Motor:** `filterKayitlarByPersonelVeDonem` yalnizca `girdi.personel_id` ile eslesen kayitlari degerlendirir.

**Hook:** `toplaDonemPuantajKayitlari` onbellek anahtarlarinda `personelId` filtresi uygular; baska personelin puantaj kaydi girdiye karismaz.

**Cache / gecis stabilizasyonu:**

- Fallback onbellek taramasi aktif sube + hedef personel prefix'i ile sinirlandirildi; farkli subeye ait puantaj cache kaydi devam primi sonucuna karismaz.
- `usePersonelDetail` icinde request-sequence guard eklendi; personel gecisinde gec gelen eski detay cevabi guncel `personel` / `editForm` state'ini ezmez.
- Personel 1 -> Personel 2 gecisinde readonly devam primi kartinin eski `Kesildi` sonucunu tasimadigi E2E ile kilitlendi.

**Test regression kapsami** (`tests/unit/devam-primi-hesap-motoru.test.ts`):

- Baska personelin hastalik kaydi → hedef personel icin kesinti uretmez
- Hedef personelin ayni ay hastalik kaydi → kesinti uretir
- Farkli ay kayitlari → yalniz hedef `yil-ay` donemi degerlendirilir

**View regression** (`tests/unit/useDevamPrimiEligibilityOzeti.test.ts`):

- Eksik kapsamda `durumLabel` **Hak Kazandı** olmaz
- Eksik kapsam + kesinti yok → `manuel_inceleme`

## 7. Test ve Tip Durumu (Kapanis Ani)

Faz kapanisi aninda dogrulanan komutlar:

```text
npm run test     → 329 passed
npm run typecheck → OK
```

Ilgili test dosyalari (referans; bu belge test degistirmez):

- `tests/unit/devam-primi-hesap-motoru.test.ts`
- `tests/unit/useDevamPrimiEligibilityOzeti.test.ts`
- `tests/unit/usePersonelDetail.test.ts`
- `tests/e2e/personel-dosya.spec.ts`

Stabilizasyon sonrasi guncel dogrulama:

```text
npm run test -> 331 passed
npm run typecheck -> OK
npx playwright test tests/e2e/personel-dosya.spec.ts -> 7 passed
```

## 8. Bilincli Kapsam Disi (Sonraki Fazlar)

Bu checkpoint ile **acilmayan** alanlar:

- `src/` altinda yeni kod degisikligi (bu teslimat yalniz dokuman)
- `tests/` altinda degisiklik
- Yeni API endpoint
- Finans / bordro / SGK entegrasyonu veya yeni mevzuat karari
- Yeni is kurali (yarim gun rapor, ucretsiz izin, performans primi vb.)
- Otomatik finans kalemi olusturma
- Net maas / prim tutari / SGK kodu uretimi
- Dashboard veya coklu personel toplu prim raporu

Acik is sorulari ve gecis kriterleri icin bkz. `20-devam-primi-owner-ve-dar-tasarim-karari.md` bolum 8–9.

## 9. Faz Sonucu

Devam Primi V2 eligibility fazı su durumda kapatilir:

- Dedicated owner (`devam-primi-hesap-motoru.ts`) ile tutar disi karar omurgasi calisir durumda
- Personel detayinda salt okunur eligibility ozeti gosterilir
- Eksik veri, personel izolasyonu, aktif sube cache izolasyonu ve personel gecis guvenligi regression testleri ile korunur
- Finans, bordro ve SGK hatlari bu fazda **dokunulmadan** birakilir

Sonraki faz, bu belgedeki kapsam disi maddelerden **ayri bir urun/teknik karar** ile acilmalidir.

## Belge Gecmisi

| Tarih | Not |
|---|---|
| 2026-05-22 | Cache / gecis stabilizasyonu eklendi; aktif sube fallback izolasyonu, `usePersonelDetail` request guard'i ve personel gecisi E2E kilidi sabitlendi. Guncel dogrulama: `331 passed`, `personel-dosya.spec.ts` `7 passed`. |
| 2026-05-21 | Devam Primi V2 eligibility faz kapanis checkpoint; mimari ayrim, cikti sinirlari, eksik veri ve personel izolasyonu, test durumu sabitlendi. |
