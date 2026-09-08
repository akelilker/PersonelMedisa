# PersonelMedisa — Agent Çalışma İlkeleri

Bu proje React + Vite + TypeScript tabanlı PersonelMedisa uygulamasıdır. Kod değişikliklerinde stabilite, doğru owner, doğrulanabilirlik, üretim güvenliği ve minimum kullanıcı turu önceliklidir.

## Proje bağlamı

- Ana alanlar: Login/Auth, Kayıt ve Süreç, Personel Kartı, Puantaj, Raporlar/Aylık Kapanış, Finans.
- Deploy hedefi: cPanel altında `/personelmedisa/`.
- Ana build çıktısı: `dist/`.
- Production env için beklenen temel değerler:
  - `VITE_API_MODE=real`
  - `VITE_DEMO_API_FALLBACK=false`
  - `VITE_APP_BASE_PATH=/personelmedisa/`
- Güncel ürün/teknik durum için repo içindeki authoritative güncel durum kaynaklarını esas al. Eski rapor, branch, log, fixture veya tarihsel çıktı yeni ve doğrudan çelişen authoritative kanıt yoksa güncel gerçek olarak yorumlanmaz.

---

## Ana çalışma kuralı

Kapsam dışına çıkma; fakat kapsamı da kendiliğinden daraltma.

Ana hedefi en az kullanıcı turuyla, geniş fakat kontrollü biçimde tamamla.

Bir broad talimat verildiyse aynı hedefe ait araştırma, root-cause, çözüm, zorunlu bağımlılıklar, testler, dokümantasyon, PR hazırlığı ve raporu mümkün olduğunca aynı sweep içinde sonuna kadar ilerlet.

Kullanıcıya yalnız şu durumlarda geri dön:

- gerçek business decision,
- açık production / merge / deploy / migration apply onayı,
- geri döndürülemez veya yüksek riskli işlem,
- dışarıdan sağlanması gereken zorunlu bilgi,
- gerçekten ilerlemeyi durduran hard blocker.

Rutin teknik kararları kullanıcıya geri devretme.

---

## 1. Kapsam ve ürün sınırları

- Kullanıcının istemediği ürün özelliği, ekran, iş akışı, rol, veri veya mimari uydurma.
- Kullanıcının söylediği hedefi kendiliğinden genişletme veya daraltma.
- Ekran görüntüsüyle görsel düzenleme tarif edildiyse yalnız tarif edilen ekranı ve zorunlu bağımlı alanları düzenle.
- Kendi kendine yeni menü, modül, route, rol, workflow, business model veya yan özellik oluşturma.
- Toplu format, import sıralama, Prettier/lint cleanup veya genel refactor yapma.
- Gerçek production davranışını demo/mock/local fallback ile maskeleme.

---

## 2. Owner ve root-cause kuralı

- Önce gerçek owner dosyayı, owner component'i, owner hook'u, owner util'i, owner service'i veya canonical workflow'u bul.
- Sorunu doğru owner'da ve gerçek kök nedeninde çöz.
- Geçici patch, paralel çözüm, duplicate helper, ikinci state/route sistemi, dosya sonuna override veya `|| true` tipi hata yutan workaround üretme.
- Mevcut canonical owner üzerinden çözülebilecek iş için aynı işi yapan ikinci bir yapı oluşturma.
- Teknik mimari business truth'u taşıyamıyorsa workaround üretme; gerçek teknik gap'i doğru owner'da çöz.

---

## 3. Broad sweep / mikro görev yasağı

Aynı hedefi mikro görevlere bölme.

- Araştırma, root-cause, çözüm, zorunlu bağımlılıklar, ilgili testler, doküman uyumu, PR hazırlığı ve final raporu mümkün olduğunca tek sweep içinde tamamla.
- Ara bulgu temizse sırf yeni bir `NEXT_GATE` görüldü diye yeni kullanıcı turu yaratma.
- Aynı faz içinde çözülebilecek doğrulama, cross-PR kontrolü, test veya doküman uyumunu yeni faza bölme.
- Yalnız gerçek hard blocker, kullanıcıdan zorunlu business kararı veya açık onay gereken işlem çıkarsa dur.

### `NEXT_GATE` sınıflandırması

`NEXT_GATE` her zaman `NEXT_INSTRUCTION` değildir.

A) `INTERNAL_CONTINUATION`  
Aynı broad görevin doğal devamıysa mevcut sweep içinde ilerle; kullanıcı turu yaratma.

B) `USER_APPROVAL_OR_BUSINESS_DECISION`  
Gerçek merge/deploy/mutation/migration onayı veya business kararı gerekiyorsa kullanıcıya dön.

C) `EXTERNAL_WAIT`  
Hosting/provider gibi dış koşul bekleniyorsa yeni teknik talimat üretme. Lokal yapılabilecek işi aynı broad sweep içinde tamamla; sonra gerçekten bekle.

---

## 4. Business truth kuralı

Kullanıcı ürün/iş gerçeği söylediğinde bunu authoritative business truth olarak kilitle.

- Aynı konuya ait business kararlarını mikro talimatlara bölme.
- Aynı business truth paketini tek authoritative package olarak ele al.
- Business truth ile access scope, fiziksel lokasyon, organizasyon, rol, sorumluluk veya başka teknik eksenleri birbirine karıştırma.
- Kullanıcının verdiği isim, lokasyon, yönetici, rol veya iş kuralını mevcut sistem verisiyle eşleştirirken production verisini tahmin etme.
- Daha önce verilen business kararını yeni ve açık çelişkili bilgi yoksa yeniden sorma.
- Eksik bilgi gerçekten gerekli ve başka şekilde çözülemiyorsa kullanıcıdan yalnız o business kararını iste.

---

## 5. PASS / CLOSED / APPLIED işleri yeniden açmama

Authoritative biçimde `PASS`, `CLOSED` veya `APPLIED` olan konuları şu nedenlerle yeniden `OPEN` sayma:

- stale doküman,
- eski branch,
- eski log,
- historical test fixture,
- daha önce düzeltilmiş hata,
- eski local çıktı.

Yalnız yeni ve doğrudan çelişen authoritative kanıt varsa yeniden aç.

Aynı SHA'da PASS olan doğrulamayı somut yeni risk yoksa tekrar çalıştırma.

---

## 6. Test ve doğrulama disiplini

Test kapsamını değişikliğin gerçek riskine göre seç.

### Görsel / metin / CSS
- focused test,
- gerekiyorsa typecheck/build,
- gerekiyorsa Fast CI.

### Normal frontend davranışı
- ilgili testler,
- Fast CI.

### Yetki / bordro / veritabanı / migration / production-control-plane
- ilgili focused/DB testleri,
- Fast CI,
- final SHA'da gerçekten gerekliyse yalnız bir Full CI.

### Genel test kuralları
- Her değişiklikte otomatik olarak `npm run test` + `npm run build` çalıştırmak zorunlu değildir.
- Exact-head GitHub CI aynı SHA için PASS ise somut yeni risk yoksa aynı wide suite'i lokalde tekrar çalıştırma.
- Daha önce aynı SHA'da PASS olan testi sebepsiz tekrarlama.
- Timeout artırmayı veya rerun yapmayı root-cause çözümü sayma.
- Flake şüphesinde yalnız failed spec/job en fazla bir kez rerun.
- Ortam bağımlı veya kapsam dışı bir test fail olduysa önce gerçek owner/risk ilişkisini değerlendir; unrelated failure'ı bu değişikliğin root-cause'u gibi ele alma.
- Çalıştırılamayan veya doğrulanamayan kontrol varsa bunu açıkça raporla; doğrulanmayan davranışı başarılı gibi yazma.

Gerekli olduğunda temel repo kontrolleri:

```bash
git status --short
git diff --stat
git diff --check
```

`npm run typecheck`, `npm run test`, `npm run build`, `npm run e2e` yalnız değişiklik kapsamına ve yukarıdaki risk modeline göre seçilir.

---

## 7. Production güvenliği ve preimage

Production verisini tahmin etme veya uydurma.

Mutation öncesi:

1. exact live preimage oku,
2. exact target'ı doğrula,
3. exact delta'yı çıkar,
4. korunacak alanları belirle,
5. rollback yolunu doğrula,
6. yalnız canonical owner üzerinden uygula,
7. post-readback yap,
8. unrelated drift olmadığını doğrula.

Kurallar:

- Direct SQL/phpMyAdmin mutation yapma.
- Ad-hoc prod script/SQL enjekte etme.
- Secret, credential, token veya production config değerini loglama/commit etme.
- Production mutation, migration apply, import/apply, merge ve açıkça yetki verilmemiş deploy için açık onay kapısını koru.
- Read-only production probe, araştırma, hazırlık, local implementation ve testler için gereksiz onay isteme.
- Kullanıcı bir production/merge/deploy/migration onayı verdiyse o onayın doğal kapanış zincirini sebepsiz bölme.

---

## 8. Onay sonrası yürütme

Kullanıcı merge + deploy veya belirli production mutation için açık onay verdiyse aynı hedefin doğal kapanışını mümkün olduğunca tek sweep içinde yürüt.

Örnek:

```text
precheck
→ merge
→ main CI
→ deploy
→ narrow production acceptance
→ postcheck
→ close
```

Kullanıcı onayının dışına çıkan yeni bir production mutation ortaya çıkarsa otomatik uygulama; yeni exact delta ve onay gerekir.

---

## 9. External blocker kuralı

Hosting, cPanel, FTP, DNS, sertifika, üçüncü taraf servis veya dış API arızası ana akışı bloke ederse:

- Önce gerçekten external blocker olduğunu kanıtla.
- Credential, secret, kod veya production ayarlarını kanıtsız değiştirme.
- Kör retry yapma.
- External blocker düzelene kadar production/live zorunlu olmayan lokal işleri durdurma.
- Aynı broad sweep içinde yapılabilecek:
  - araştırma,
  - local code,
  - focused test,
  - read-only helper,
  - no-apply plan,
  - dokümantasyon,
  - business decision hazırlığı
  tamamlanabildiği kadar tamamla.
- Live verification / production apply gerektiren maddeleri tek bekleme listesinde topla.
- Her açık madde için ayrı ayrı talimat veya gate üretme.
- Lokal yapılabilecek iş kalmadığında gerçekten bekle.

---

## 10. Tek sweep / çoklu PR kuralı

Tek broad sweep sonucunda birden fazla bağımsız PR oluşabilir; bu normaldir.

- Bağımsız owner'ları tek dev PR'a zorla birleştirme.
- Ancak her PR için ayrı review/merge/pin talimat zinciri üretme.
- Sweep sonunda tüm PR'ları birlikte değerlendir.
- Cross-PR overlap, migration tip, docs pin, merge order ve dependency kontrolünü tek consolidated integration gate içinde çöz.
- Bir PR diğerini stale yapıyorsa bunu agent kendi içinde reconcile etsin.
- Kullanıcıya yalnız gerçek merge/deploy onay noktasında dön.

---

## 11. Git ve dosya güvenliği

Başlamadan önce branch, `HEAD`, `origin/main` ve working tree durumunu kontrol et.

Yasaklar:

- force push,
- squash,
- rebase,
- hard reset,
- unrelated commit,
- kullanıcı değişikliğini silme,
- kapsam dışı dosyayı commit etme.

`reset`, `clean`, `stash`, `checkout`, `rebase`, `amend` yalnız açık talimatla yapılır.

Başkasına veya önceki göreve ait değişikliklere dokunma.

- `.env.local`, gerçek secret, token veya canlı credential commit edilmez.
- `.env.production` yalnız public Vite production ayarları içeriyorsa repoda tutulabilir; secret içeremez.

---

## 12. UI/CSS kuralları

- Mevcut component kontratlarını bozma.
- Yeni CSS override bloğu, `!important`, negatif margin veya transform ile geçici hizalama yapma.
- Responsive davranışı masaüstü/mobil/PWA etkileriyle birlikte düşün.
- Ortak component değiştiyse yalnız kullanan ana ekranlarda hızlı regresyon kontrolü yap.
- Kullanıcı yalnız belirli ekranı tarif ettiyse scope'u başka ekranlara taşırma.

---

## 13. Raporlama

Final raporu kısa ve karar verilebilir tut.

En fazla şu alanlar yeterlidir:

- root cause,
- changed files,
- test sonucu,
- commit / PR,
- merge / deploy / mutation durumu,
- gerçek blocker,
- next gate.

Aynı bilgiyi farklı başlıklarla tekrar etme.

Ara rapor üretme; broad sweep'in doğal sınırı bitmeden kullanıcıya gereksiz status döndürme.

---

## 14. “Bir şeyler yapıyor görünme” davranışından kaçın

Teknik olarak yapılacak iş kalmadıysa açıkça söyle.

Yapma:

- yeni gate icat etme,
- aynı doğrulamayı farklı isimle tekrar yaptırma,
- PASS SHA'yı tekrar audit ettirme,
- external blocker varken deploy'u körlemesine tekrar deneme,
- kullanıcıya gereksiz “şunu da agent'a yapıştır” döngüsü üretme,
- sırf aktivite olsun diye test veya refactor başlatma.

---

## 15. Deploy notu

Deploy için `DEPLOY_CHECKLIST.md` esas alınır.

Canlı build öncesi production env değerleri doğrulanır.

Canlıya şu içerikler gönderilmez:

- `src`
- `tests`
- `.git`
- `node_modules`
- lokal zip/log dosyaları
- secret/credential içeren dosyalar

Deploy sonrası yalnız marker'a güvenme; gerekiyorsa runtime/API/worker/parity doğrulamasını canonical read-only owner üzerinden yap.

---

## 16. Agent / Cloud çalışma notları

Bu bölüm Copilot/Cursor/Cloud agent oturumları içindir.

- Ana ürün frontend'tir (React + Vite).
- PHP `api/` cPanel production/runtime hedefidir; production-control-plane ve migration işlerinde gerçek owner olabilir.
- Dev'de API katmanı `auto` moddadır (`.env.development`: `VITE_API_MODE=auto`, `VITE_DEMO_API_FALLBACK=true`).
- Demo/mock davranışını production kanıtı olarak kullanma.
- Demo login gerçek credential gerektirmez; production identity/scope doğrulamasında demo kullanıcıyı authoritative sayma.
- Standart komutlar `package.json` scripts ve `README.md` içindedir.
- Ayrı bir `lint` scripti yoksa statik kontrol için gerektiğinde `npm run typecheck` kullan.
- Playwright E2E yalnız ilgili davranış riskine göre çalıştırılır; sırf mevcut olduğu için her değişiklikte koşulmaz.
- Production DB/socket erişimi agent oturumunda yoksa repo içindeki mevcut authenticated GitHub Actions/read-only ops owner'larını kullan. “Local session erişemiyor” tek başına production evidence blocker değildir.
- Mevcut read-only workflow/helper varken ikinci bir paralel read-only sistem kurma.
- GitHub Actions veya control-plane job'larında mevcut fail-closed guard'ları kaldırma; yalnız false-positive/stale guard kanıtlanırsa canonical owner'da düzelt.

---

## 17. Son kontrol listesi

Bir işi kapatmadan önce kendine şunları sor:

- Ana hedef gerçekten tamamlandı mı?
- Aynı broad scope içinde yapılabilecek işi sonraki tura bıraktım mı?
- PASS/CLOSED bir işi gereksiz yeniden açtım mı?
- Production verisini tahmin ettim mi?
- Doğru owner'da mı çözdüm?
- Gereksiz full test çalıştırdım mı?
- Aynı SHA'da PASS olan testi tekrar ettim mi?
- Kullanıcının vermediği bir özellik/rol/akış uydurdum mu?
- Kullanıcıdan gereksiz teknik onay istedim mi?
- `NEXT_GATE` gerçekten kullanıcı aksiyonu mu?
- Final rapor kısa ve karar verilebilir mi?

Bu sorulardan biri sorun gösteriyorsa kullanıcıya dönmeden önce aynı sweep içinde düzelt.
