# PersonelMedisa — Agent Çalışma İlkeleri

Bu proje React + Vite + TypeScript tabanlı PersonelMedisa uygulamasıdır. Kod değişikliklerinde stabilite, dar kapsam, owner yapısı ve doğrulanabilirlik önceliklidir.

## Proje bağlamı

- Ana alanlar: Login/Auth, Kayıt ve Süreç, Personel Kartı, Puantaj, Raporlar/Aylık Kapanış, Finans.
- Deploy hedefi: cPanel altında `/personelmedisa/`.
- Ana build çıktısı: `dist/`.
- Production env için beklenen temel değerler: `VITE_API_MODE=real`, `VITE_DEMO_API_FALLBACK=false`, `VITE_APP_BASE_PATH=/personelmedisa/`.

## Çalışma prensipleri

- Önce gerçek owner dosyayı, owner component'i, owner hook'u veya owner util'i bul.
- Mevcut owner üzerinden çözülebilecek sorun için paralel component, helper, state, route veya CSS sistemi kurma.
- Kullanıcı yalnızca analiz/plan istiyorsa dosya değiştirme.
- Açık uygulama talimatı varsa dar kapsamda uygula; gereksiz onay döngüsü oluşturma.
- Kapsam dışı dosya, selector, test veya config değiştirme.
- Toplu format, import sıralama, Prettier/lint cleanup veya genel refactor yapma.
- Gerçek production davranışını demo/mock/local fallback ile maskeleme.

## Git ve dosya güvenliği

- Başlamadan önce branch, `HEAD`, `origin/main` ve working tree durumunu kontrol et.
- Başkasına veya önceki göreve ait değişikliklere dokunma.
- `reset`, `clean`, `stash`, `checkout`, `rebase`, `amend` yalnızca açık talimatla yapılır.
- `.env.local`, gerçek secret, token veya canlı credential commit edilmez.
- `.env.production` yalnızca public Vite üretim ayarları içeriyorsa repoda tutulabilir; secret içeremez.

## UI/CSS kuralları

- Mevcut component kontratlarını bozma.
- Yeni CSS override bloğu, `!important`, negatif margin veya transform ile geçici hizalama yapma.
- Responsive davranış masaüstü/mobil/PWA etkileriyle birlikte düşünülür.
- Ortak component değiştiyse kullanan ana ekranlarda hızlı regresyon kontrolü yapılır.

## Test ve doğrulama

Kod değişikliği sonrası kapsamına göre en az şu kontrolleri çalıştır:

```bash
git status --short
git diff --stat
git diff --check
npm run typecheck
npm run test
npm run build
```

UI davranışı değiştiyse ilgili Playwright/E2E veya manuel smoke yapılır. Çalıştırılamayan kontrol varsa nedenini raporla; doğrulanmayan davranışı başarılı gibi yazma.

## Deploy notu

Deploy için `DEPLOY_CHECKLIST.md` esas alınır. Canlı build öncesi production env değerleri doğrulanır; canlıya `src`, `tests`, `.git`, `node_modules` veya lokal zip/log dosyaları gönderilmez.

## Cursor Cloud specific instructions

Bu bölüm gelecek cloud agent'lar içindir (bağımlılıklar update script ile kurulmuş varsayılır).

- Ana ürün frontend'tir (React + Vite). PHP `api/` yalnızca cPanel deploy hedefidir; lokal geliştirme/test için gerekli değildir.
- Dev'de API katmanı `auto` moddadır (`.env.development`: `VITE_API_MODE=auto`, `VITE_DEMO_API_FALLBACK=true`), bu yüzden uygulama gerçek backend/DB olmadan demo/mock veriyle tek başına çalışır.
- Demo login: herhangi bir kullanıcı adı/şifre kabul edilir (varsayılan rol `GENEL_YONETICI`; `birim`/`muhasebe`/`bolum` içeren kullanıcı adları farklı rol verir). Gerçek credential gerekmez.
- Demo modda beklenen davranış: personel liste endpoint'i boş döner ve "Kayıt ve Süreç" formunda bazı referans-veri hataları (ör. "Şube listesi yüklenemedi") görülebilir — bu bir ortam sorunu değil, backend olmadığı içindir.
- Standart komutlar `package.json` scripts ve `README.md`'de: `npm run dev` (Vite, varsayılan port 5173), `npm run typecheck`, `npm run test` (vitest), `npm run build`, `npm run e2e`.
- Ayrı bir `lint` scripti yoktur; statik kontrol için `npm run typecheck` kullanılır.
- Playwright E2E (`npm run e2e`) çalıştırmadan önce bir kereye mahsus `npx playwright install --with-deps` gerekir; e2e config dev sunucusunu 127.0.0.1:4173'te otomatik başlatır.
