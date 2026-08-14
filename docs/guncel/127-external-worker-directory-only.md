# 127 — Dış Kaynak Çalışan / Directory-Only (Pack7F)

**Tarih:** 2026-08-14
**Branch:** `feat/pack7f-external-worker-scope`
**Production migration tip:** `065` (değişmedi)
**Pack7F migration:** `066_personel_calisan_kapsami.sql` (yalnız kod; production'a uygulanmadı)

## Karar

`personeller.calisan_kapsami`, çalışanın SGK/bordro sorumluluğunun PersonelMedisa'ya ait olup olmadığını belirleyen ayrı ve birinci sınıf boyuttur:

- `IC_PERSONEL`: mevcut iç personel davranışı.
- `DIS_KAYNAK`: personel dizininde görünür; SGK, bordro ve zaman operasyonlarına girmez.

Bu alan şube yetkilendirmesinin veya mevcut `personel_bordro_kapsamlari.DAHIL/HARIC` modelinin yerine geçmez. Yetki sahibi yine `personeller.sube_id` + `SubeScope`; `HARIC` modeli değişmeden korunur.

## Şema ve 065 uyumluluğu

Migration `066`:

- `calisan_kapsami ENUM('IC_PERSONEL','DIS_KAYNAK') NOT NULL DEFAULT 'IC_PERSONEL'` ekler.
- `tc_kimlik_no`, `soyad`, `dogum_tarihi`, `telefon` alanlarını nullable yapar.
- mevcut satırları varsayılanla `IC_PERSONEL` tutar.
- non-null TC unique indexini korur; InnoDB'nin birden çok `NULL` kabul eden unique semantiğini kullanır.

Merkezi readiness owner'ı `PersonelCalisanKapsamSchema`dır. Kod migration `066`dan önce dağıtılırsa:

- list/detail/search yeni kolona koşulsuz erişmez;
- mevcut kişiler `IC_PERSONEL` kabul edilir;
- `DIS_KAYNAK` yazma isteği `SCHEMA_NOT_READY` ile fail-closed döner;
- mevcut iç personel akışları schema `065` üzerinde çalışmaya devam eder.

## Kimlik ve geçiş kontratı

| Alan | IC_PERSONEL | DIS_KAYNAK |
| --- | --- | --- |
| T.C. Kimlik No | zorunlu, geçerli ve non-null benzersiz | opsiyonel; verilirse normal doğrulama + benzersizlik |
| Ad | zorunlu | zorunlu |
| Soyad | zorunlu | opsiyonel / `NULL` |
| Doğum tarihi | zorunlu | opsiyonel / `NULL` |
| Telefon | zorunlu | opsiyonel / `NULL` |
| Sicil | zorunlu ve benzersiz | zorunlu ve benzersiz |
| İşe giriş | zorunlu | zorunlu |
| Normal organizasyon referansları | zorunlu | zorunlu |

Tek-token gerçek ad `ad=<kaynak tokenı>`, `soyad=NULL` olarak saklanır; placeholder üretilmez. Liste, detay, arama, API serialization, archive ve ad-soyad SQL ifadeleri nullable kimliğe göre güvenli hale getirilmiştir.

`IC_PERSONEL → DIS_KAYNAK` geçişi gelecekteki operasyonel adaylığı durdurur ve `sgk_isveren_id` alanını güvenle `NULL` yapar; tarihsel kayıt silmez. `DIS_KAYNAK → IC_PERSONEL` geçişi tam iç-personel kimliğini yeniden zorunlu kılar. Hiçbir geçiş geçmiş bordro/SGK/puantaj satırı üretmez veya silmez.

## Merkezi operasyon sınırı

`PersonelCalisanKapsamService` şu kontratların tek sahibidir:

- kapsam normalizasyonu;
- `IC_PERSONEL` aday SQL predicate'i;
- doğrudan işlem guard'ı (`PERSONEL_OPERASYON_KAPSAM_DISI`);
- nullable ad-soyad formatı;
- `DIS_KAYNAK` + SGK işvereni reddi (`DIS_KAYNAK_SGK_ISVEREN_YASAK`).

Backend aday filtreleri ve doğrudan işlem guard'ları QR, puantaj, izin/devamsızlık süreçleri, haftalık kapanış, fazla mesai, serbest zaman, maaş, bordro hazırlık/devir ve SGK prim/eksik gün snapshot zincirlerinde uygulanır. Dış kaynak kişi MEDISA/KARYAPI/SENAY SGK işvereni alamaz ve SGK/bordro export adayına giremez.

Frontend bir güvenlik sınırı değildir. Buna rağmen kullanıcıyı geçersiz aksiyona yönlendirmemek için dış kaynak kartında operasyon menüsü ve operasyon sekmeleri gösterilmez; genel kimlik/organizasyon ile temel belge alanları korunur. Personel listesinde kapsam filtresi ve `DIŞ KAYNAK` rozeti bulunur.

## Import kontratı

Import şablonunun opsiyonel `calisan_kapsami` kolonu `IC_PERSONEL` veya `DIS_KAYNAK` kabul eder; kolon yoksa varsayılan `IC_PERSONEL`dir. Böylece mevcut 122 iç-personel adayı geriye uyumlu kalır.

External satırda TC/soyad/doğum/telefon boş olabilir; verilirse normal doğrulanır. Ad, sicil, işe giriş ve normal organizasyon referansları zorunlu kalır. External satırda SGK işvereni reddedilir. Import `CREATE_ONLY_ALL_OR_NOTHING` kalır ve otomatik HARIC/bordro/SGK/puantaj/QR satırı üretmez.

## External-13 veri hazırlığı (yalnız aggregate)

Kaynak `Personel Listesi.xls` hash'i `50142B64A2CFD982196E6AA25DBF13612B3453CFC783348E0D44659B126027B0` olarak yeniden doğrulandı. TC'si boş 13 satırın tamamı yalnız toplu sayımla değerlendirildi; repoya kişi verisi yazılmadı.

| Alan | Kaynakta hazır |
| --- | --- |
| Toplam | 13 |
| Ad | 13/13 |
| Sicil | 13/13 (13/13 benzersiz) |
| İşe giriş | 13/13 |
| Şube | 0/13 |
| Çalışma lokasyonu | 0/13 |
| Departman | 7/13 ham değer mevcut |
| Bölüm | 0/13 |
| Birim | 0/13 |
| Ünvan | 0/13 |
| Pozisyon | 0/13 |
| Personel tipi | 0/13 |

İki satır tek-token ad içerir ve yeni nullable-soyad kontratına uygundur. Zorunlu organizasyon alanları eksik olduğundan `EXTERNAL_13_IMPORT_DATA_READY = NO`. Eksik değerler tahmin edilmemiş ve import yapılmamıştır.

## Test sınırı ve production güvenliği

Odaklı testler migration `066` idempotency/default davranışını, schema `065` fail-closed uyumluluğunu, nullable kolonları, non-null TC ve sicil unique kurallarını, internal/external validator ayrımını, tek-token adı, SGK reddini ve merkezi operasyon guard'ını MariaDB üzerinde doğrular. Source testleri aday filtrelerinin/doğrudan guard'ların ilgili owner'larda kaldığını ve UI directory-only davranışını kilitler.

- Production DB değişmedi.
- Production personel verisi değişmedi.
- Migration `066` production'a uygulanmadı.
- Canonical 122 veya external 13 import edilmedi.
- Retention aktivasyonu veya fiziksel imha yapılmadı.
- Pack7D docs PR #165 bu implementasyon PR'ından ayrı tutuldu ve merge edilmedi.
