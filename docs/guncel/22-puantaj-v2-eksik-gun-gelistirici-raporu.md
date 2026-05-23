# 22. Puantaj V2 Eksik Gun Gelistirici Raporu

Surum: `Puantaj V2 eksik gun / ay sonu SGK / ucret etkisi faz baslangici`

Tarih: `2026-05-24`

## 1. Amac

Bu belge, Devam Primi V2 kapanisi sonrasi acilan **Puantaj V2 Eksik Gun / Ay Sonu SGK / Ucret Etkisi** fazi icin gelistirici devir raporudur.

Amac:

- Son yapilan isleri ve bugunku deploy kazasini kayit altina almak
- Guncel teknik durumu netlestirmek
- Siradaki fazda once teshis, sonra kural matrisi, sonra owner tasarimi ile ilerlemek
- Kod yazma baslamadan once dar ve kontrollu yol haritasi olusturmak

Bu belge kod fazi degildir. Yeni hesap kurali, finans kalemi, SGK resmi kodu veya bordro ciktisi acmaz.

## 2. Son Kapanan Faz

Son kapanan ana faz:

`Devam Primi V2 eligibility + readonly surface + cache/gecis stabilizasyonu`

Kapanan teknik hat:

- Eligibility karari dedicated owner'a alindi: `src/services/devam-primi-hesap-motoru.ts`
- Readonly surface hatti kuruldu:
  - `PersonelDetayPage`
  - `useDevamPrimiEligibilityOzeti`
  - `hesaplaDevamPrimiEligibility`
- Personel izolasyonu testli hale geldi
- Aktif sube cache izolasyonu testli hale geldi
- Personel detail request-sequence guard testli hale geldi
- Cached effect id mismatch / eski state tasima riski testlendi
- Personel 1 -> Personel 2 gecisinde eski `Kesildi` sonucunun tasinmadigi E2E ile kilitlendi

Kapanista bilerek acilmayan alanlar:

- Finans kalemi uretimi
- Bordro / net maas / prim tutari
- SGK resmi kod uretimi
- Dashboard metrigi
- Yeni devam primi is kurali
- UI icinde hesap

Referans belgeler:

- `docs/guncel/12-puantaj-gelistirici-devir-notu.md`
- `docs/guncel/20-devam-primi-owner-ve-dar-tasarim-karari.md`
- `docs/guncel/21-devam-primi-v2-kapanis-checkpoint.md`

## 3. Bu Oturumda Yapilanlar

### 3.1 Puantaj Formu - Durum Bildirimi Alani

Puantaj kaydinda `Gelmedi` hareket durumu icin yeni bir bilgi alani eklendi:

- Soru: `Durumu Bildirdi mi?`
- Cevaplar: `Evet` / `Hayir`
- `Evet` secilirse aciklama input'u gosterilir

Bu alanin amaci, eksik gun fazina giderken personelin devamsizlik bilgisini isverene bildirip bildirmedigini form tarafinda kayit altina almaktir.

Not: Bu ekleme tek basina SGK kodu, ucret kesintisi veya finans ciktisi uretmez.

### 3.2 Smoke Test Flaky Hatasinin Kapatilmasi

GitHub Actions tarafinda bir E2E smoke testinde su hata goruldu:

```text
locator.fill: waiting for [name='puantaj-beklenen-giris']
element was detached from the DOM, retrying
```

Kok neden:

- `Kaydi Getir` sonrasi puantaj formu async yukleme tamamlanmadan edit edilebilir durumdaydi.
- Test/kullanici `Hareket Durumu` alanini degistirebiliyor, hemen ardindan async `loadPuantaj` cevabi form state'ini yeniden kuruyordu.
- Bu sirada saat input'u DOM'dan detach oluyor ve smoke test patliyordu.

Dar duzeltme:

- `src/features/puantaj/pages/GunlukPuantajPage.tsx` icinde edit form kontrolleri `isLoading` sirasinda pasif hale getirildi.
- Kaydet akisi da yukleme bitmeden tetiklenmeyecek sekilde korunmus oldu.

Dogrulama:

```text
npm run typecheck -> OK
npx playwright test tests/e2e/smoke.spec.ts -> 4 passed
npx playwright test -> 28 passed
```

### 3.3 cPanel Paket / Sunucu Klasoru Kontrolu

Kontrol edilen yerel sunucu klasoru:

`C:\Users\Akel\Downloads\Yeni klasor`

Teshis:

- Deploy ayarlari ana problem degildi.
- `.env.production`, `vite.config.ts`, `.htaccess`, `scripts/zip-dist.mjs` hatti tutarliydi.
- Sorun, klasorde eski build hash'leri ile yeni build hash'lerinin karismasi riskine donmustu.

Yapilan is:

- `npm run build` calistirildi.
- `npm run pack:cpanel` ile guncel cPanel upload paketi uretildi:
  - `personelmedisa-cpanel-upload.zip`
- `C:\Users\Akel\Downloads\Yeni klasor` guncel `dist` icerigiyle hizalandi.

Guncel beklenen dist icerigi:

```text
.htaccess
favicon.svg
index.html
assets/index-BZD8ZXx-.css
assets/index-Dw6NfiZc.js
assets/logo-footer-MtCTgmaR.svg
assets/logo-header2-1XPy6LSF.svg
assets/logo-header2-mobile-header-5xL0sus1.svg
```

## 4. GitHub Upload Kazasi ve Kapanisi

### 4.1 Olay

GitHub uzerinden `Add files via upload` ile build ciktisi repo kokune yuklendi.

Hatalı commit:

```text
58431d3 Add files via upload
```

Bu commit su dosyalari kaynak repoya ekledi veya degistirdi:

- `assets/index-BZD8ZXx-.css`
- `assets/index-Dw6NfiZc.js`
- `assets/logo-footer-MtCTgmaR.svg`
- `assets/logo-header2-1XPy6LSF.svg`
- `assets/logo-header2-mobile-header-5xL0sus1.svg`
- `favicon.svg`
- `index.html`

### 4.2 Kok Neden

Repo kokundeki kaynak `index.html`, production build sonucu uretilen `index.html` ile degisti.

Bu nedenle CI su satiri kaynak import gibi gordu:

```text
/personelmedisa/assets/index-Dw6NfiZc.js
```

Vite build sirasinda bu dosyayi kaynak agacinda resolve etmeye calisti ve build patladi.

Bu nedenle sorun:

- Deploy secret sorunu degil
- cPanel path sorunu degil
- `.htaccess` sorunu degil
- Vite base path sorunu degil

Ana neden: build ciktisinin kaynak repo kokune upload edilmesi.

### 4.3 Duzeltme

Hatalı upload commit'i tarihce korunarak revert edildi.

Remote duzeltme commit'i:

```text
2599b05 Revert "Add files via upload"
```

Duzeltme etkisi:

- Koke yuklenen build `assets/*` dosyalari kaldirildi
- Koke yuklenen `favicon.svg` kaldirildi
- `index.html` tekrar kaynak haline dondu:

```text
<script type="module" src="/src/main.tsx"></script>
```

### 4.4 Son Kanitli Durum

GitHub Actions son durumu:

```text
CI -> success
Deploy cPanel -> success
```

Basarili commit:

```text
2599b05 Revert "Add files via upload"
```

Yerel repo durumu:

```text
main...origin/main
worktree temiz
```

Not: GitHub Actions ekraninda eski `Add files via upload` satirlarinin kirmizi/gri kalmasi normaldir. Onlar gecmis run kaydidir. Guncel referans en ustteki `Revert "Add files via upload"` yeşil CI ve Deploy satirlaridir.

## 5. Guncel Faz Giris Durumu

Yeni faz:

`Puantaj V2 Eksik Gun / Ay Sonu SGK / Ucret Etkisi`

Bu fazin acilis kosulu:

- Devam primi eligibility fazi kapanmis durumda
- Puantaj formunda `Durumu Bildirdi mi?` bilgisi alinabilir durumda
- Smoke detach problemi kapatilmis durumda
- GitHub Actions ve Deploy cPanel tekrar yesil durumda
- Deploy ayar problemi gorunmuyor
- Manuel GitHub upload ile build ciktisi yukleme riski tespit edilmis ve temizlenmis durumda

## 6. Siradaki Yol Haritasi

### Adim 0 - Yeniden Kontrol

Kota geldikten sonra once durum tekrar dogrulanacak:

```text
git status --short --branch
git log -3 --oneline
npm run typecheck
npx playwright test tests/e2e/smoke.spec.ts
```

Gerekirse GitHub Actions son commit durumu tekrar kontrol edilecek.

### Adim 1 - Teshis ve Kural Matrisi

Kod yazmadan once eksik gun karar evreni netlestirilecek.

Sorulacak ana sorular:

- Hangi puantaj hareketleri eksik gun sayilir?
- Hangi hareketler sadece gec kalma / erken cikma gibi ucret etkisine gider?
- `Durumu Bildirdi mi?` bilgisi hangi kararlari etkiler?
- Eksik gun SGK prim gununu ne zaman dusurur?
- Eksik gun ucret etkisi ile SGK eksik gun bildirimi ayni sey mi, ayrilacak mi?
- Raporlu / izinli / izinsiz / ucretsiz izin / devamsizlik olaylari nasil ayrilacak?
- Ay sonu hesaplamasi gunluk kayitlardan mi, ozet tablodan mi beslenecek?

Cikti:

- Eksik gun kural matrisi
- SGK prim gunu etkisi matrisi
- Ucret etkisi ayrim notu
- Kapsam disi kararlar listesi

### Adim 2 - Owner Dosya Tasarimi

Teshis bittikten sonra dar owner belirlenecek.

Ilk aday owner:

```text
src/services/puantaj-hesap-motoru.ts
```

Ancak owner karari kod okunmadan kesinlestirilmeyecek. Mevcut servis sorumluluklari incelenip su ayrim korunacak:

- Hesap cekirdegi hesap yapar
- Hook veri toplar / state yonetir
- Page UI gosterir
- API sadece veri tasir

Bu fazda UI icinde hesap yapilmayacak.

### Adim 3 - Dar Cursor / Kod Talimati

Kural matrisi ve owner netlestikten sonra dar talimat hazirlanacak.

Talimatin sinirlari:

- Minimum diff
- Tek owner dosya veya en dar dosya seti
- Yeni dosya ancak gercekten gerekirse
- API contract degisikligi ancak onayla
- UI/hook/page katmanina dokunma, eger faz sadece hesap cekirdegi ise
- Test kapsami sadece degisen davranisa gore

### Adim 4 - Kod Fazina Gecis

Kod fazi ancak onaydan sonra acilacak.

Olası ilk teknik hedef:

- Gunluk puantaj kayitlarindan ay sonu eksik gun sayisi uretmek
- SGK prim gunu etkisini hesap cekirdeginde saf fonksiyonla belirlemek
- Ucret etkisini SGK kararindan ayri tutmak
- `Durumu Bildirdi mi?` alanini sadece ilgili kurallarda karar girdisi olarak kullanmak

Bu adimda finans kalemi, bordro tutari veya SGK resmi kodu uretilmeyecek.

## 7. Kapsam Disi Kalacaklar

Bu faz baslangicinda asagidakiler acilmamis sayilir:

- Otomatik finans kalemi uretimi
- Net maas / bordro satiri uretimi
- SGK resmi eksik gun kodu uretimi
- Dashboard metriği
- Toplu ay sonu onay ekrani
- Yeni rapor/export tasarimi
- UI icinde hesap
- Deploy pipeline degisikligi

## 8. Riskler ve Koruma Notlari

### 8.1 GitHub Upload Riski

Build ciktisi GitHub repo kokune upload edilmeyecek.

Dogru akis:

- Kaynak kod commit/push edilir
- GitHub Actions build alir
- Deploy cPanel workflow'u `dist` icerigini sunucuya yollar

Manuel cPanel gerekiyorsa:

- `personelmedisa-cpanel-upload.zip` kullanilir
- GitHub repo kokune `dist`, `assets`, production `index.html` yuklenmez

### 8.2 Kural Karisiklik Riski

Eksik gun, SGK prim gunu ve ucret kesintisi ayni kavram degildir.

Bu yuzden ilk fazda:

- Kavramlar ayrilacak
- Karar matrisi yazilacak
- Owner siniri cizilecek
- Sonra kod acilacak

### 8.3 Regression Riski

Puantaj formu daha once async state nedeniyle input detach sorunu yasadi.

Yeni fazda form davranisina dokunulursa:

- Loading guard korunacak
- Eski smoke test yeniden calistirilacak
- DOM detach riski tekrar izlenecek

## 9. Devam Ederken Ilk Kontrol Listesi

Kota geldikten sonra ilk yapilacaklar:

```text
1. GitHub Actions son iki run yesil mi?
2. main ve origin/main senkron mu?
3. Worktree temiz mi?
4. Kaynak index.html hala /src/main.tsx mi?
5. Repo kokunde build assets klasoru yok mu?
6. Smoke test hala geciyor mu?
7. Eksik gun fazi icin mevcut puantaj hesap motoru sorumluluklari ne durumda?
```

Bu kontrol temizse eksik gun teshis dokumani ve kural matrisiyle devam edilecek.

## 10. Kisa Sonuc

Su anki durum:

- Kod hatti tekrar toparlandi
- GitHub upload kaynakli CI/deploy kirilmasi kapatildi
- CI ve Deploy cPanel yesil
- Puantaj formundaki `Durumu Bildirdi mi?` alani sonraki eksik gun fazina veri zemini hazirliyor
- Siradaki dogru adim kod yazmak degil; eksik gun / SGK prim gunu / ucret etkisi ayrimini teshis edip kural matrisi cikarmak

Bir sonraki oturumda hedef:

`Puantaj V2 eksik gun fazi icin once kural matrisi, sonra owner dosya, sonra dar kod talimati.`

## Belge Gecmisi

| Tarih | Not |
|---|---|
| 2026-05-24 | Puantaj V2 eksik gun faz girisi, durum bildirimi alani, smoke stabilizasyonu, cPanel paket kontrolu ve GitHub upload revert kapanisi kayit altina alindi. |
