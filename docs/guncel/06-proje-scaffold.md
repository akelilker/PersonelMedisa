# Medisa Personel ve Puantaj Yönetim Sistemi

## Proje Scaffold

Sürüm: `V1`

## Belgenin Amacı

Bu doküman, projenin kod bazında hangi klasör ve dosya iskeleti ile başlaması gerektiğini tanımlar.

Amaç:

- kodun ilk günden düzenli başlaması
- component ve modül sahipliğinin net olması
- CSS katmanlarının birbirine karışmaması
- API, state ve feature katmanlarının dağılmaması

Bu belge "hangi teknolojiyi seçelim?" sorusundan çok "hangi dosya neyin sahibi olacak?" sorusunu cevaplar.

## Temel İlke

Scaffold kurarken ana hedef, projeyi büyütmek için alan açmaktır.

Yani:

- bugünkü ihtiyaca göre minimum kurulum yapılır
- ama yarınki modül genişlemesinde mimari çökmez

Kural:

- klasörler rastgele ekran adına göre değil, sorumluluğa göre açılır
- ortak bileşenler modül klasörlerine gömülmez
- modül dosyaları global davranış yazmaz

## 1. Önerilen Kök Yapı

```text
medisa-personel/
  docs/
  public/
  src/
  tests/
  .editorconfig
  .gitignore
  package.json
  README.md
```

## 2. `docs/` Klasörü

Bu klasör ürün ve teknik anayasa belgeleri içindir.

Örnek içerik:

```text
docs/
  guncel/
    00-sistem-genel-bakis.md
    01-urun-anayasasi.md
    02-mvp-veri-kapsami.md
    03-ui-bilesen-sozlesmesi.md
    04-hesap-motoru-kurallari.md
    05-state-flow-api-kontrati.md
    06-proje-scaffold.md
    07-is-akislari-ve-senaryolar.md
    08-frontend-teknik-mimari.md
```

## 3. `public/` Klasörü

Derlenen uygulamanın statik varlıkları burada yaşar.

Örnek:

```text
public/
  favicon.ico
  manifest.json
  icons/
  images/
```

Kural:

- burada iş mantığı yaşamaz
- burada component CSS'i yaşamaz

## 4. `src/` Ana Kod Klasörü

Ana uygulama kodu burada yaşar.

Önerilen yapı:

```text
src/
  app/
  api/
  assets/
  components/
  features/
  hooks/
  lib/
  state/
  styles/
  types/
  utils/
```

## 5. `src/app/` Klasörü

Uygulama kabuğu burada yaşar.

Örnek:

```text
src/app/
  App.tsx
  AppShell.tsx
  routes.ts
  providers.tsx
  auth-guard.tsx
```

Sorumluluk:

- uygulama açılışı
- route tanımı
- layout kabuğu
- provider zinciri
- auth ve rol bazlı yönlendirme

Kural:

- feature iş mantığı burada yazılmaz

## 6. `src/api/` Klasörü

Backend ile konuşan katman burada yaşar.

Önerilen yapı:

```text
src/api/
  client.ts
  endpoints.ts
  auth.api.ts
  personeller.api.ts
  surecler.api.ts
  bildirimler.api.ts
  puantaj.api.ts
  raporlar.api.ts
  referans.api.ts
```

Sorumluluk:

- HTTP client
- endpoint fonksiyonları
- request/response mapping

Kural:

- burada UI render edilmez
- burada local component state tutulmaz

## 7. `src/assets/` Klasörü

Projeye ait ham varlıklar burada tutulur.

Örnek:

```text
src/assets/
  logos/
  icons/
  illustrations/
```

## 8. `src/components/` Klasörü

Uygulamanın ortak, tekrar kullanılabilir UI parçaları burada yaşar.

Önerilen yapı:

```text
src/components/
  app-shell/
  hero/
  icons-row/
  notifications/
  settings-menu/
  footer/
  back-bar/
  modal/
  form/
  buttons/
  dropdown/
  badge/
  states/
```

### 8.1 Component Bazlı Sahiplik

Örnek eşleşme:

- `hero/` = hero component'inin tek sahibi
- `footer/` = footer contract'ının tek sahibi
- `buttons/` = save/cancel/delete ailesinin tek sahibi
- `modal/` = ortak modal shell'in tek sahibi
- `notifications/` = bildirim iconu + panel mantığının tek sahibi

Kural:

- ortak component hem burada hem feature içinde ikinci kez tanımlanmaz

## 9. `src/features/` Klasörü

Asıl iş modülleri burada yaşar.

Önerilen yapı:

```text
src/features/
  auth/
  dashboard/
  personeller/
  surecler/
  bildirimler/
  puantaj/
  haftalik-kapanis/
  raporlar/
  finans/
  referans-veriler/
```

Her feature kendi içinde şu yapıya sahip olabilir:

```text
src/features/personeller/
  api/
  components/
  hooks/
  pages/
  schemas/
  services/
  store/
  types/
```

### 9.1 Feature İçeriği

- `pages/`: route veya ekran seviyesindeki bileşenler
- `components/`: sadece o feature'a özel alt bileşenler
- `services/`: feature'a özel iş akışı yardımcıları
- `schemas/`: validasyon şemaları
- `store/`: feature lokal state yapısı

### 9.2 Kural

- feature component'i global footer stilini yazamaz
- feature CSS'i `form-input` temel stilini ezemez
- feature sadece kendi modül kökü altında çalışır

## 10. `src/hooks/` Klasörü

Tekrar kullanılabilir custom hook'lar burada yaşar.

Örnek:

```text
src/hooks/
  use-auth.ts
  use-modal.ts
  use-role-access.ts
  use-breakpoint.ts
  use-footer-dim.ts
```

Kural:

- feature'a çok özel hook ise feature klasöründe kalır
- proje genelinde kullanılacak hook burada yaşar

## 11. `src/lib/` Klasörü

Framework veya kütüphane adaptörleri burada tutulur.

Örnek:

```text
src/lib/
  http/
  storage/
  date/
```

Sorumluluk:

- düşük seviye teknik yardımcı katman

## 12. `src/state/` Klasörü

Uygulama genel state burada yaşar.

Örnek:

```text
src/state/
  auth.store.ts
  ui.store.ts
  notifications.store.ts
```

Kural:

- sadece gerçekten global state burada tutulur
- feature özel state mümkünse feature içinde kalır

## 13. `src/styles/` Klasörü

Bu klasör CSS mimarisinin omurgasıdır.

Önerilen yapı:

```text
src/styles/
  tokens/
    colors.css
    spacing.css
    radius.css
    shadows.css
    z-index.css
    breakpoints.css
  base/
    reset.css
    typography.css
    globals.css
  layout/
    app-shell.css
    content-wrap.css
    grid.css
  components/
    hero.css
    icons-row.css
    notifications.css
    footer.css
    back-bar.css
    modal.css
    form.css
    buttons.css
    dropdown.css
    badge.css
    states.css
  modules/
    dashboard.css
    personeller.css
    surecler.css
    bildirimler.css
    puantaj.css
    raporlar.css
    finans.css
  platform/
    mobile.css
    desktop.css
    ios-pwa.css
    android-pwa.css
  print/
    report-print.css
  index.css
```

### 13.1 Katman Kuralı

- `tokens`: sadece değişken
- `base`: temel global davranış
- `layout`: kabuk ve taşıyıcı yapı
- `components`: ortak UI bileşenleri
- `modules`: ekran özel stiller
- `platform`: platform farkları
- `print`: sadece baskı görünümü

### 13.2 Yasaklar

- modül CSS içinde global modal yeniden tanımlanmaz
- footer component style'ı feature içinde ezilmez
- `!important` ile katmanlar birbirine karşı savaştırılmaz

## 14. `src/types/` Klasörü

Ortak type tanımları burada tutulur.

Örnek:

```text
src/types/
  api.ts
  auth.ts
  personel.ts
  surec.ts
  bildirim.ts
  puantaj.ts
  rapor.ts
```

## 15. `src/utils/` Klasörü

Saf yardımcı fonksiyonlar burada tutulur.

Örnek:

```text
src/utils/
  format-date.ts
  format-currency.ts
  format-phone.ts
  normalize-tc-kimlik.ts
```

Kural:

- iş kuralı ağır logic burada birikmez
- domain-specific hesap fonksiyonları gerekirse feature service katmanında yaşar

## 16. `tests/` Klasörü

Testler kodla birlikte düşünülmelidir.

Önerilen yapı:

```text
tests/
  unit/
  integration/
  e2e/
```

Öncelikli test alanları:

- hesap motoru kuralları
- form validasyonları
- state geçişleri
- kritik API adaptörleri

## 17. Özellikle Açılması Gereken İlk Dosyalar

Projeye başlarken en erken oluşturulması gereken dosyalar:

```text
src/app/App.tsx
src/app/AppShell.tsx
src/api/client.ts
src/components/hero/Hero.tsx
src/components/footer/AppFooter.tsx
src/components/modal/AppModal.tsx
src/components/buttons/UniversalButtonGroup.tsx
src/components/form/FormField.tsx
src/features/personeller/pages/PersonellerPage.tsx
src/features/personeller/pages/PersonelDetayPage.tsx
src/features/surecler/pages/SurecTakipPage.tsx
src/features/bildirimler/pages/BildirimlerPage.tsx
src/styles/index.css
```

## 18. V1 İçin En Mantıklı Başlangıç Sırası

Kodlama sırası önerisi:

1. uygulama kabuğu
2. styles katmanlarının iskeleti
3. ortak componentler
4. auth ve rol yönlendirmesi
5. personel modülü
6. süreç modülü
7. bildirim modülü
8. puantaj ve haftalık kapanış
9. raporlar

Sebep:

- iskelet olmadan modül geliştirmek eski dağınık yapıyı tekrar üretir

## 19. Bu Scaffold'ın Çözdüğü Kök Problem

Bu iskelet özellikle şu hataları engellemek için tasarlanmıştır:

- aynı CSS'in üç farklı dosyada yazılması
- global bileşenin feature içinde override edilmesi
- API çağrılarının sayfa dosyalarına dağılması
- hesap motoru logic'inin component içine gömülmesi
- kod büyüdükçe dosya sahipliğinin kaybolması

## 20. Sonuç

Scaffold, bu projenin taşıyıcı kolon sistemidir.

Bu belgeye göre başlanırsa:

- component sahipliği net olur
- modül sınırları bozulmaz
- CSS mimarisi ilk günden düzenli başlar
- dokümanlarda tanımladığımız kurallar koda daha temiz dökülür

Bu belge sonrası iki doğal adım vardır:

- gerçek repo scaffold'ını dosya olarak oluşturmak
- veya ekran bazlı görev planı çıkarmak
