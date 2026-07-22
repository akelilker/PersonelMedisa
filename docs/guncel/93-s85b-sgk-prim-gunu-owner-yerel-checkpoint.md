# S85-B SGK Prim Günü Owner — Kapanış Checkpoint

Tarih: 22.07.2026
Branch (implementation): `feat/s85b-sgk-prim-gunu-owner`
Başlangıç SHA: `13ec6a235ef4988f8ac7296039e5baa4d7a3126f`
PR #65 merge SHA: `6b9a89d2135eb4d64c35616d1b19a5420394d5be`
Durum: Canlı migration 036/037 uygulandı; PR #65 merge + main CI + cPanel deploy tamamlandı; geçici ops tooling emekli edildi. Authenticated UI kabulü PENDING. Katalog/politika/candidate fail-closed blokları devam ediyor.

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

## Canlı apply ve deploy (tamamlandı)

1. Canlı DB `karmotor_medisa` tam yedeği alındı (repo dışı; schema/data/triggers; boyut + SHA-256 kanıtlandı).
2. Disposable restore + 036/037 ×2 + SGK test paketi yeşil geçti.
3. Canlıya sırayla 036 ve 037 uygulandı; postcheck: 67 tablo, 8 manifest, katalog/kod/eşleme/politika/SGK snap/audit = 0, 4 immutable trigger.
4. PR #65 merge SHA: `6b9a89d2135eb4d64c35616d1b19a5420394d5be`.
5. Main CI ve otomatik cPanel deploy başarılı.
6. Read-only otomatik smoke (health/auth/frontend/assets/SGK guards) yeşil.
7. Authenticated UI kabulü: **PENDING** (yetkili oturum yok).
8. Backup dosyaları repo dışında tutuldu; repoya alınmadı.

## Geçici ops tooling emekliliği

Canlı migration için kullanılan one-shot tooling görevini tamamladı ve repodan kaldırıldı:

- `.github/workflows/s85b-live-schema-migrate.yml` — silindi
- `scripts/s85b-live-migrate.php` — silindi

Canlı `api/public/_s85b_migrate.php` ve ilişkili geçici SQL/backup path dosyaları web üzerinden erişilemez. Canonical migration dosyaları `api/migrations/036_sgk_prim_gunu_owner.sql` ve `api/migrations/037_sgk_resmi_kaynak_manifesti_v1.sql` korunur; şema geri alınmaz.

## API ve export

- `GET /maas-hesaplama/sgk-sonuclari`
- `GET /maas-hesaplama/sgk-sonuclari/export.csv`

İki endpoint de `SgkPrimGunuService::listCanonicalResults` kullanır. CSV; SGK hesap hash'i, katalog sürümü, mevzuat manifest hash'i, snapshot kimliği ve revision numarasını taşır. Maaş Hesaplama Merkezi'ndeki SGK CSV aksiyonu salt-okunurdur.

## Fail-closed blocker sözleşmesi

Candidate üretimi; prim günü çözülemediğinde, katalog/kod/belge/süreç çelişkisinde, rapor türü veya ücret modeli belirsizliğinde, hastalık ilk iki gün politikası null olduğunda, ödenek-mahsup politikası eksikken veya canonical takvim eksikken durur. Blocker ayrıntısı personel, dönem/tarih, domain, kaynak süreç/belge ve çözüm önerisini taşır.

## Doğrulama sonucu (implementation PR #65)

- Full Vitest: 139 dosya / 1157 test — PASS
- PHP unit/integration ve MariaDB acceptance/concurrency — PASS
- Disposable MariaDB apply x2, invariant, immutability ve restore — PASS
- Typecheck — PASS
- API parity — PASS, fatal 0
- Production build — PASS
- İlgili Playwright E2E — 29/29 PASS
- `git diff --check` — PASS

## Açık hukuk ve mali müşavir kararları

- Resmî ve tarih etkili eksik gün kod kataloğunun eksiksiz içeriği yetkili kaynaktan onaylanmalı; doğrulanmadan seed yapılmamalı.
- 15–14 bildirim dönemi yalnız ihtiyaç bulunan şirket/sigortalı kapsamı için yetkili kararla aktive edilmeli.
- Maktu aylık, günlük, saatlik ve diğer ücret modellerinde raporlu gün ücret/mahsup etkisi şirket politikasıyla değil mevzuat ve yetkili görüşle kesinleştirilmeli.
- SGK fiilî ödemesi gelmeden tahmin, kesin bordro kalemine dönüştürülmemeli; mahsup/iade ve tamamlayıcı ödeme politikası ayrıca onaylanmalı.
- Ücret dışı ödeme, prim/ikramiye ve sonraki aya devreden PEK etkileri mevcut ayrı owner üzerinden hukuki/mali müşavir matrisiyle sürdürülmeli.

## Korunan yasaklar / paralel işler

- PR #64 açık + draft kalır; bu fazda dokunulmadı (`fix/fazla-calisma-gec-erken-owner`).
- Gerçek SGK kod seed'i, katalog `DOGRULANMIS_TAM`, şirket politikası onayı ve bordro candidate write yapılmadı.
- Mevcut personel/süreç/puantaj ve maaş snapshot verisi mutate edilmedi.
