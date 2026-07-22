# S85-B SGK Prim Günü Owner — Yerel Checkpoint

Tarih: 22.07.2026  
Branch: `feat/s85b-sgk-prim-gunu-owner`  
Başlangıç SHA: `13ec6a235ef4988f8ac7296039e5baa4d7a3126f`  
Durum: Yerel implementation ve doğrulama tamamlandı; migration apply, production write, merge ve deploy yapılmadı.

## Owner haritası

- Tek hesap owner'ı: `api/src/Services/Payroll/SgkPrimGunuEngine.php`
- Kaynak çözümleme, snapshot persistence ve salt-okunur sorgu: `api/src/Services/SgkPrimGunuService.php`
- Bordro snapshot/revision zinciri: `MaasHesaplamaSnapshotService`
- Candidate fail-closed kapısı: `MaasHesaplamaAdayService`
- PEK günlük sınırlarının hesaplanan prim günüyle ölçeklenmesi: `Payroll/MaasHesaplamaEngine`
- Personel Kartı, Maaş Hesaplama Merkezi ve CSV yalnız immutable backend snapshot sonucunu tüketir; frontend SGK günü hesaplamaz.

## Migration ve veri modeli

`036_sgk_prim_gunu_owner.sql` additive ve tekrar çalıştırılabilir owner şemasıdır. Kaynak manifesti, sürümlü katalog, kod/çakışma masterı, süreç eşlemesi, şirket politikası, ilişkisel belge, personel sigortalılık sürümü, iş göremezlik finans ayrımı, SGK snapshot ve immutable audit tablolarını kurar.

`037_sgk_resmi_kaynak_manifesti_v1.sql` sekiz doğrulanmış resmî kaynak ve SHA-256 değerini ekler. Eksik gün kodu veya şirket politikası seed etmez. Resmî tam katalog ve yetkili şirket kararları girilmeden engine fail-closed kalır.

036/037 disposable MariaDB üzerinde iki kez uygulanmış; mevcut baseline veri invariant'ı, snapshot/audit immutability trigger'ları ve yedekten geri dönüş provası doğrulanmıştır.

## Production apply kapısı

Bu checkpoint production migration yetkisi vermez. Ayrı onaydan sonra sıra değiştirilemez:

1. Canlı DB'nin transaction/tables/routines/triggers içeren tam yedeğini al; dosya boyutu, tablo sayısı ve SHA-256 değerini kaydet.
2. Mevcut son migration ve 036/037 SHA-256 değerlerini yeniden doğrula.
3. 036'yı uygula; beklenen tablolar, foreign key'ler, index'ler ve dört immutable trigger için schema inventory al.
4. 037'yi uygula; manifest satır sayısını ve hash'leri doğrula. Kod/politika tablolarının boş kaldığını kanıtla.
5. Eski operasyonel tabloların satır sayısı ve fingerprint'lerini pre-migration değerleriyle karşılaştır.
6. Uygulama deploy'u ancak schema ve veri invariant kapıları geçerse ayrıca onaylanabilir.

Rollback normal akışta migration dosyasını ters SQL ile sessizce düşürmek değildir. Apply veya invariant kapısı başarısızsa production write durdurulur; önce uygulama kodu eski sürümde tutulur, ardından onaylı pre-migration tam yedek izole DB'de restore edilip doğrulanır ve yalnız yetkili bakım penceresinde canlı restore kararı alınır. `DROP TABLE`, veri silme veya snapshot mutasyonu ayrı destructive onay olmadan yapılmaz.

## API ve export

- `GET /maas-hesaplama/sgk-sonuclari`
- `GET /maas-hesaplama/sgk-sonuclari/export.csv`

İki endpoint de `SgkPrimGunuService::listCanonicalResults` kullanır. CSV; SGK hesap hash'i, katalog sürümü, mevzuat manifest hash'i, snapshot kimliği ve revision numarasını taşır. Maaş Hesaplama Merkezi'ndeki SGK CSV aksiyonu salt-okunurdur.

## Fail-closed blocker sözleşmesi

Candidate üretimi; prim günü çözülemediğinde, katalog/kod/belge/süreç çelişkisinde, rapor türü veya ücret modeli belirsizliğinde, hastalık ilk iki gün politikası null olduğunda, ödenek-mahsup politikası eksikken veya canonical takvim eksikken durur. Blocker ayrıntısı personel, dönem/tarih, domain, kaynak süreç/belge ve çözüm önerisini taşır.

## Doğrulama sonucu

- Full Vitest: 139 dosya / 1157 test — PASS
- PHP unit/integration ve MariaDB acceptance/concurrency — PASS
- Disposable MariaDB apply x2, invariant, immutability ve restore — PASS
- Typecheck — PASS
- API parity — PASS, fatal 0
- Production build — PASS
- İlgili Playwright E2E — 29/29 PASS
- `git diff --check` — PASS
- Vitest status 255 — detached MariaDB process + cross-worker execution owner lock sonrasında tekrarlanmadı

## Açık hukuk ve mali müşavir kararları

- Resmî ve tarih etkili eksik gün kod kataloğunun eksiksiz içeriği yetkili kaynaktan onaylanmalı; doğrulanmadan seed yapılmamalı.
- 15–14 bildirim dönemi yalnız ihtiyaç bulunan şirket/sigortalı kapsamı için yetkili kararla aktive edilmeli.
- Maktu aylık, günlük, saatlik ve diğer ücret modellerinde raporlu gün ücret/mahsup etkisi şirket politikasıyla değil mevzuat ve yetkili görüşle kesinleştirilmeli.
- SGK fiilî ödemesi gelmeden tahmin, kesin bordro kalemine dönüştürülmemeli; mahsup/iade ve tamamlayıcı ödeme politikası ayrıca onaylanmalı.
- Ücret dışı ödeme, prim/ikramiye ve sonraki aya devreden PEK etkileri mevcut ayrı owner üzerinden hukuki/mali müşavir matrisiyle sürdürülmeli.

## Korunan yasaklar

PR #64'e müdahale edilmedi. Production migration, canlı veri değişikliği, merge ve deploy yapılmadı. Mevcut mühürlü snapshot değiştirilmedi; doğrulanmamış kod/politika seed edilmedi; null politika false'a çevrilmedi.
