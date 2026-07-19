# S79 — REVİZYON TALEBİ VE CORRECTION OWNER KONSOLİDE FINAL KAPANIŞ RAPORU

## 1. Belgenin Amacı

Bu rapor, PersonelMedisa projesinde kapanmış haftalık puantaj kayıtları için geliştirilen:

- revizyon talebi owner altyapısını,
- revizyon onay akışını,
- correction event katmanını,
- production migrationlarını,
- canlı kabul testlerini,
- geçici operasyon tooling temizliğini,
- API parity kapanışlarını

tek ve canonical bir belgede toplar.

Bu belge tamamlandıktan sonra S79-E ve S79-F fazları için yeni acceptance, hardening, R1/R2/R3/R4 veya tekrar kapanış turu açılmayacaktır.

---

# 2. Final Canonical Durum

```text
S79_E_FINAL_CLOSED_PRODUCTION_VERIFIED
S79_F_FINAL_CLOSED_PRODUCTION_VERIFIED

Deferred: 1
Fatal: 0
Known active gaps: 0
```

Final Git durumu:

```text
branch: main
HEAD: 1392ba7c7518c5ea2e97636dda0c96fb8ef13139
origin/main: 1392ba7c7518c5ea2e97636dda0c96fb8ef13139
ahead/behind: 0/0
working tree: clean
```

Final ürün runtime correction owner commit’i:

```text
9b3f7dc36ca43f61e22cb1672760943a00672bae
```

Final ops tooling cleanup commit’i:

```text
1392ba7c7518c5ea2e97636dda0c96fb8ef13139
```

---

# 3. Ürün Problemi ve Çözüm

PersonelMedisa’da haftalık puantaj kapanışı tamamlandıktan sonra geçmiş kayıtlarda doğrudan değişiklik yapılması;

- kapanmış dönemin sessizce değiştirilmesi,
- denetim izinin kaybolması,
- eski ve yeni değerin ayrıştırılamaması,
- puantaj ve bordro süreçlerinde kaynağı belirsiz değişiklikler oluşması

riskini taşıyordu.

S79 kapsamında bu problem iki katmanlı olarak çözüldü.

## 3.1. Revizyon Talebi Katmanı

Kullanıcı, kapanmış haftaya ait kayıt için gerekçeli revizyon talebi oluşturur.

Talep:

```text
TASLAK
→ ONAY_BEKLIYOR
→ ONAYLANDI / REDDEDILDI / IPTAL
```

state zincirinden geçer.

Revizyon talebi tek başına kapanmış snapshot verisini değiştirmez.

## 3.2. Correction Katmanı

Yalnız `ONAYLANDI` durumundaki revizyon talebi için ayrı bir correction event üretilebilir.

Correction:

- kapanmış snapshot’ı overwrite etmez,
- ham puantajı değiştirmez,
- ayrı ve denetlenebilir bir etki kaydı oluşturur,
- gerektiğinde soft-cancel edilebilir,
- read model ve ilerideki rapor/bordro adapterları için overlay verisi sağlar.

---

# 4. S79-E — Revizyon Talebi Owner

## 4.1. Endpointler

Gerçek PHP ve MariaDB owner hattına bağlanan endpointler:

```text
GET  /haftalik-kapanis/revizyon-talepleri
POST /haftalik-kapanis/revizyon-talepleri
GET  /haftalik-kapanis/revizyon-talepleri/:id
POST /haftalik-kapanis/revizyon-talepleri/:id/gonder
POST /haftalik-kapanis/revizyon-talepleri/:id/onay
POST /haftalik-kapanis/revizyon-talepleri/:id/red
POST /haftalik-kapanis/revizyon-talepleri/:id/iptal
```

## 4.2. State Modeli

```text
TASLAK
├── GONDER → ONAY_BEKLIYOR
└── IPTAL → IPTAL

ONAY_BEKLIYOR
├── ONAY → ONAYLANDI
├── RED → REDDEDILDI
└── IPTAL → IPTAL
```

Terminal durumlar:

```text
ONAYLANDI
REDDEDILDI
IPTAL
```

Terminal state üzerinde tekrar geçiş:

```text
409 STATE_CONFLICT
```

## 4.3. Revizyon Talebi Yetkileri

Kullanılan permission anahtarları:

```text
revizyon.view
revizyon.create
revizyon.submit
revizyon.cancel
revizyon.approve
revizyon.reject
revizyon.view_finance_effect
revizyon.view_audit_history
```

Rol matrisi:

| Rol | Görüntüleme | Oluşturma | Gönderme | İptal | Onay | Red |
|---|---:|---:|---:|---:|---:|---:|
| GENEL_YONETICI | Evet | Evet | Evet | Evet | Evet | Evet |
| BOLUM_YONETICISI | Scope içi | Evet | Evet | Evet | Hayır | Hayır |
| MUHASEBE | Scope içi | Evet | Evet | Evet | Hayır | Hayır |
| BIRIM_AMIRI | Scope içi | Evet | Evet | Evet | Hayır | Hayır |
| PATRON | Hayır | Hayır | Hayır | Hayır | Hayır | Hayır |

Yetkiler controller içinde rol ismi kontrolüyle değil, `RolePermissions` owner’ı üzerinden uygulanır.

## 4.4. Scope

Canonical scope zinciri:

```text
revizyon talebi
→ personel_id
→ personeller.sube_id
→ SubeScope
```

Davranışlar:

- Scope dışı kayıt listede gösterilmez.
- Scope dışı detail `403` döner.
- Scope dışı create `403` döner.
- `allowedSubeIds=[]` non-GY kullanıcıya global erişim vermez.
- `GENEL_YONETICI` global erişime sahiptir.
- Client tarafından gönderilen `sube_id` kabul edilmez.

## 4.5. Ownership

Non-GY kullanıcı:

- yalnız kendi oluşturduğu talebi gönderebilir,
- yalnız kendi oluşturduğu açık talebi iptal edebilir.

Başka kullanıcının talebine gönderme veya iptal denemesi:

```text
403 REVISION_OWNER_DENIED
```

`GENEL_YONETICI` ownership bypass yetkisine sahiptir.

Onay ve red işlemleri talep ownership’ine değil, `revizyon.approve` ve `revizyon.reject` yetkilerine bağlıdır.

## 4.6. Dönem Guard

Revizyon talebi yalnız kapanmış haftalık dönem için açılabilir.

```text
Haftalık kapanış yok → 409 PERIOD_NOT_CLOSED
Haftalık kapanış açık → 409 PERIOD_NOT_CLOSED
Haftalık kapanış kapalı → create devam eder
```

Aylık mühür veya aylık kapanış, revizyon talebi oluşturmayı ya da state transition işlemlerini engellemez.

Bu owner şu hataları üretmez:

```text
PERIOD_LOCKED
PERIOD_STATE_UNKNOWN
```

## 4.7. Source Validation

Desteklenen kaynak tipleri:

```text
PUANTAJ
HAFTALIK_KAPANIS_SATIR
SUREC
SERBEST_ZAMAN
```

Talep oluşturulurken:

- personel,
- haftalık kapanış,
- snapshot,
- kaynak tipi,
- kaynak ID,
- etkilenen tarih

uyumu yeniden doğrulanır.

Hedef kaynak bulunamazsa veya talep bağlamıyla uyuşmazsa:

```text
404 TARGET_NOT_FOUND
```

Talep kaydı bulunamazsa:

```text
404 NOT_FOUND
```

## 4.8. Duplicate Açık Talep

Açık talep identity:

```text
personel_id
kaynak_tipi
kaynak_id
etkilenen_tarih
acik_talep_slot
```

Açık durumlar:

```text
TASLAK
ONAY_BEKLIYOR
```

Aynı identity için ikinci açık talep:

```text
409 ALREADY_EXISTS
```

Terminal durum sonrası aynı kaynak için yeni talep oluşturulabilir.

## 4.9. Audit

Revizyon talebi state geçmişi append-only tutulur.

Aksiyonlar:

```text
OLUSTUR
GONDER
ONAY
RED
IPTAL
```

Audit kaydı ana state değişikliğiyle aynı transaction içinde yazılır.

Audit insert başarısız olursa:

- ana kayıt oluşmaz,
- state değişmez,
- partial audit oluşmaz.

---

# 5. Migration 030

Migration:

```text
030_haftalik_kapanis_revizyon_talepleri.sql
```

Oluşturulan yapılar:

```text
haftalik_kapanis_revizyon_talepleri
haftalik_kapanis_revizyon_talebi_gecmisi
```

Önemli şema kuralları:

- InnoDB
- utf8mb4
- domain FK’leri `RESTRICT` veya `NO ACTION`
- CASCADE yok
- generated açık talep slot’u
- açık talep duplicate unique index’i
- append-only geçmiş tablosu
- correction bağlantısı için nullable `correction_event_id`

Migration 030 production’da uygulanmış ve canlı kabul tamamlanmıştır.

Korunan migration SHA256 başlangıcı:

```text
477e27b5...
```

Migration 030 immutable kabul edilir.

---

# 6. S79-F — Correction Owner

## 6.1. Endpointler

```text
GET  /haftalik-kapanis/revizyon-corrections
GET  /haftalik-kapanis/revizyon-corrections/:id
POST /haftalik-kapanis/revizyon-talepleri/:id/correction-uret
POST /haftalik-kapanis/revizyon-corrections/:id/iptal
```

## 6.2. Correction Üretim Yetkisi

Read endpointleri:

```text
revizyon.view
```

Produce ve cancel endpointleri:

```text
revizyon.approve
```

kullanır.

Correction iptali için `revizyon.cancel` kullanılmaz.

V1’de correction produce ve cancel yalnız `GENEL_YONETICI` tarafından yapılabilir.

| Rol | List/Detail | Produce | Cancel |
|---|---:|---:|---:|
| GENEL_YONETICI | Evet | Evet | Evet |
| BOLUM_YONETICISI | Scope içi | Hayır | Hayır |
| MUHASEBE | Scope içi | Hayır | Hayır |
| BIRIM_AMIRI | Scope içi | Hayır | Hayır |
| PATRON | Hayır | Hayır | Hayır |

## 6.3. Produce State Guard

Correction yalnız:

```text
revizyon_talebi.durum = ONAYLANDI
```

durumunda üretilebilir.

Diğer state’lerde:

```text
409 CORRECTION_NOT_ALLOWED_FOR_STATE
```

Onay endpointi otomatik correction üretmez.

Canonical akış:

```text
Revizyon talebi onaylanır
→ durum ONAYLANDI
→ correction_event_id null

Ayrı correction-uret çağrısı yapılır
→ correction event oluşur
→ correction_event_id set edilir
```

## 6.4. One-to-One Bağ

Bir revizyon talebinin yalnız bir correction event’i olabilir.

Şema seviyesi:

```text
UNIQUE(revizyon_talebi_id)
UNIQUE(correction_event_id)
UNIQUE(audit_ref)
```

Aynı talep için ikinci produce:

```text
409 CORRECTION_ALREADY_EXISTS
```

Correction iptal edilmiş olsa bile aynı revizyon talebinden yeni correction üretilemez.

Yeni correction gerekiyorsa yeni revizyon talebi açılır.

## 6.5. Correction Mapping

| Revizyon tipi | Correction tipi |
|---|---|
| `PUANTAJ_GIRIS_CIKIS_DUZELTME` | `GIRIS_CIKIS_DUZELTME` |
| `MOLA_DUZELTME` | `MOLA_DUZELTME` |
| `DEVAMSIZLIK_DUZELTME` | `DEVAMSIZLIK_DUZELTME` |
| `SERBEST_ZAMAN_ETKI_DUZELTME` | `SERBEST_ZAMAN_ETKI_DUZELTME` |
| `KAPANIS_HESAP_REVIZYONU` | `KAPANIS_HESAP_REVIZYONU` |
| `BORDRO_ETKI_NOTU` | `BORDRO_ETKI_NOTU` |

Desteklenmeyen:

```text
SUREC_GEC_GIRIS
```

Sonuç:

```text
404 CORRECTION_TARGET_NOT_FOUND
```

## 6.6. Delta Contract

Numeric değerlerde:

```text
delta_dakika = yeni_deger - onceki_deger
```

Örnekler:

```text
60 → 90 = +30
90 → 60 = -30
60 → 60 = 0
```

String, boolean, null, object veya array değerlerde:

```text
delta_dakika = 0
```

Object ve array değerler correction contract’ında tek JSON string olarak tutulur.

Correction owner:

- saat stringlerini parse etmez,
- puantaj motorunu yeniden çalıştırmaz,
- yeni mevzuat hesaplaması yapmaz.

## 6.7. Finance Mask

`revizyon.view_finance_effect` yetkisi olmayan kullanıcıda:

```text
bordro_etki_var_mi → korunur
bordro_etki_tipi → null
bordro etkili aciklama → null
```

Bu maskeleme yalnız response projection’da yapılır.

DB kaydı değiştirilmez.

## 6.8. Correction Cancel

Correction fiziksel olarak silinmez.

İptal işlemi:

```text
iptal_edildi_mi = true
iptal_zamani = server time
iptal_eden_kullanici_id = auth user
```

İlk cancel:

```text
200
```

İkinci cancel:

```text
404 CORRECTION_NOT_FOUND
```

Korunan alanlar:

```text
revizyon_talebi.correction_event_id
audit_ref
snapshot_ref
original aciklama
olusturan_kullanici_id
olusturma_zamani
```

İptal açıklaması internal `iptal_aciklamasi` alanında tutulur.

---

# 7. Migration 031

Migration:

```text
031_haftalik_kapanis_revizyon_corrections.sql
```

SHA256:

```text
83EC409A8390BAE5C3CFCC04617A65E30DE99EF5A3736D77B71D211A537A8599
```

Oluşturulan tablo:

```text
haftalik_kapanis_revizyon_corrections
```

Migration ayrıca revizyon talebi ile correction arasındaki FK bağlantısını tamamlar.

Önemli şema kuralları:

```text
UNIQUE(revizyon_talebi_id)
UNIQUE(audit_ref)
UNIQUE(correction_event_id)
```

Toplam FK sayısı production kabulünde:

```text
14
```

Tüm FK davranışları:

```text
ON DELETE RESTRICT / NO ACTION
ON UPDATE RESTRICT / NO ACTION
```

CASCADE yoktur.

Migration 031 production’da uygulanmıştır.

Production DB:

```text
database: karmotor_medisa
host: zelda.veridyen.com
MariaDB: 10.6.21
```

---

# 8. Snapshot ve Motor İzolasyonu

S79-E ve S79-F’nin temel güvenlik kararı:

```text
Kapanmış snapshot overwrite edilmez.
```

Correction produce veya cancel işlemleri şu tablolara/domainlere write yapmaz:

```text
haftalik_kapanis_satirlari
puantaj
surecler
serbest_zaman_events
bordro
finans
```

İzin verilen write kapsamı:

```text
haftalik_kapanis_revizyon_corrections INSERT/UPDATE
haftalik_kapanis_revizyon_talepleri.correction_event_id UPDATE
```

Canlı kabul sırasında snapshot checksum produce ve cancel öncesi/sonrası karşılaştırılmış ve değişmediği doğrulanmıştır.

```text
Snapshot immutable: PASS
```

---

# 9. Transaction ve Concurrency

## 9.1. Revizyon Talebi

- Paralel create: yalnız bir açık talep oluşur.
- Paralel gönderme: yalnız bir transition başarılı olur.
- Paralel onay/red: yalnız bir terminal karar başarılı olur.
- Paralel iptal/gönder: ürün state semantiğine göre yalnız geçerli sıra kabul edilir.
- Audit ve state aynı transaction içinde tutulur.

## 9.2. Correction

Paralel produce:

```text
bir istek → 200
bir istek → 409 CORRECTION_ALREADY_EXISTS
```

Sonuç:

```text
tek correction
tek correction_event_id
orphan correction yok
```

Paralel cancel:

```text
bir istek → 200
bir istek → 404 CORRECTION_NOT_FOUND
```

Sonuç:

```text
tek iptal metadata seti
partial state yok
```

Gerçek MariaDB child-process acceptance testleri geçmiştir.

Production ortamında concurrency testi tekrar çalıştırılmamıştır.

```text
NOT_TESTED_IN_PRODUCTION
Covered by MariaDB acceptance
```

---

# 10. Mock, E2E ve PHP Parity

Aşağıdaki katmanlar aynı kontrata getirilmiştir:

```text
TS API client
demo mock
E2E mock
PHP Router
RevizyonController
MariaDB schema
unit tests
runtime tests
API parity checker
```

E2E sonucu:

```text
291 PASS
```

Typecheck:

```text
PASS
```

Build:

```text
PASS
```

PHP lint:

```text
PASS
```

Correction MariaDB acceptance:

```text
PASS
```

Windows’ta görülen bazı farklı domain concurrency flake’leri `origin/main` baseline sınıfındadır.

Revizyon veya correction owner kaynaklı yeni aktif kırık bulunmamıştır.

---

# 11. Production Kabul Kanıtları

## 11.1. Migration 030

- Production’da uygulandı.
- Schema postcondition geçti.
- Revizyon create/state/audit canlı smoke tamamlandı.
- Fixture cleanup tamamlandı.
- Final integrity geçti.
- Geçici S79-E ops tooling kaldırıldı.

Final S79-E ops cleanup merge:

```text
1816b72
```

## 11.2. Migration 031

Pre-031 backup:

```text
karmotor_medisa_pre_031_20260719-143203.sql
```

Backup boyutu:

```text
138660 bytes
```

Backup SHA256:

```text
f1b52c20…3dba1a6
```

Migration apply sonucu:

```text
S79_F_MIGRATE_OK
```

Schema sonucu:

```text
correction_event_fk_count = 1
```

Canlı GY minimum smoke:

```text
produce → 200
duplicate produce → 409
cancel → 200
second cancel → 404
```

Final integrity:

```text
marker = 0
orphan correction = 0
dual link = 0
baseline counts restored
```

Cleanup sonucu:

```text
S79_F_SMOKE_CLEANUP_OK
```

Migration run:

```text
GitHub Actions run: 29691004626
```

---

# 12. Ops Tooling Temizliği

Geçici production migration tooling dosyaları kaldırılmıştır.

Silinen S79-E tooling:

```text
.github/workflows/s79er3-live-schema-migrate.yml
scripts/s79er3-live-migrate.php
```

Silinen S79-F tooling:

```text
.github/workflows/s79fr3-live-schema-migrate.yml
scripts/s79fr3-live-migrate.php
```

S79-F tooling cleanup commit’i:

```text
1392ba7
chore(ops): remove S79-F-R3 temporary Migration 031 live tooling
```

Repo’da:

```text
s79er3 kalıntısı yok
s79fr3 kalıntısı yok
```

Production public migrate stub bulunmamaktadır.

---

# 13. CI ve Deploy

S79-E ve S79-F PR, main CI ve deploy kapıları başarıyla tamamlanmıştır.

S79-F correction owner merge:

```text
PR #41
Merge SHA: 9b3f7dc36ca43f61e22cb1672760943a00672bae
```

Main CI:

```text
29688586854
success
```

Correction owner deploy:

```text
29688636494
success
```

Final ops cleanup deploy:

```text
29691610711
success
```

Final deployed repository SHA:

```text
1392ba7c7518c5ea2e97636dda0c96fb8ef13139
```

---

# 14. Canlı Read-Only Smoke

Canlı adres:

```text
https://www.karmotors.com.tr/personelmedisa
```

Kontroller:

```text
GET /api/health → 200
frontend root → 200
hashed JS → 200
hashed CSS → 200
```

Unauthenticated revizyon/correction endpointleri:

```text
401
```

Bu sonuç front-controller ve auth guard’ın canlıda doğru çalıştığını doğrular.

---

# 15. API Parity Final Durumu

Kapalı revizyon talebi maddeleri:

```text
D-REV-01 CLOSED
D-REV-02 CLOSED
D-REV-03 CLOSED
D-REV-04 CLOSED
D-REV-05 CLOSED
D-REV-06 CLOSED
```

Kapalı correction maddeleri:

```text
D-COR-01 CLOSED
D-COR-02 CLOSED
D-COR-03 CLOSED
```

Deferred:

```text
D-BEL-01
```

Final parity:

```text
Deferred: 1
Fatal: 0
Known active gaps: 0
```

---

# 16. D-BEL-01 Kararı

`D-BEL-01`, personel belge kaydı update owner/parity konusudur.

Bu madde:

- revizyon talebi owner’ını,
- correction owner’ını,
- Migration 030 veya 031’i,
- haftalık kapanış akışını,
- canlı S79 kabulünü

engellemez.

Aktif belge düzenleme UI veya gerçek belge update ihtiyacı açılmadan yalnız parity sayısını sıfırlamak amacıyla geliştirilmemelidir.

Canonical durum:

```text
D-BEL-01 DEFERRED
Non-blocking
```

---

# 17. S79 Kapsamında Yapılmayanlar

S79 şu alanları bilinçli olarak kapsamaz:

```text
aktif revizyon talebi UI geliştirmesi
aktif correction UI geliştirmesi
snapshot builder değişikliği
puantaj motoru recompute
serbest zaman correction adapter
bordro hesaplama
SGK/vergi hesaplaması
finans kaydı üretimi
personel belge update owner
```

Bu alanlardan biri gerçek ürün ihtiyacı olarak açılırsa ayrı ve geniş kapsamlı bir faz olarak ele alınmalıdır.

S79 içine geri eklenmemelidir.

---

# 18. Operasyonel Sonuç

S79 sonunda sistem aşağıdaki kabiliyetlere sahiptir:

1. Kapanmış haftalık kayıt için gerekçeli revizyon talebi oluşturma.
2. Revizyonu onaya gönderme.
3. Genel yönetici tarafından onaylama veya reddetme.
4. Talebi scope ve ownership kurallarıyla koruma.
5. Aynı kaynak için duplicate açık talebi engelleme.
6. Tüm state geçişlerini append-only geçmişte tutma.
7. Onaylanmış talep için ayrı correction event üretme.
8. Correction event’i snapshot’a dokunmadan saklama.
9. Correction event’i soft-cancel etme.
10. Finance alanlarını role göre maskeleme.
11. Paralel işlemlerde duplicate ve partial state oluşmasını engelleme.
12. Production migration, canlı smoke ve integrity kapılarını tamamlama.

---

# 19. Canonical Kapanış Kararı

```text
S79_E_FINAL_CLOSED_PRODUCTION_VERIFIED
S79_F_FINAL_CLOSED_PRODUCTION_VERIFIED

Migration 030:
APPLIED AND VERIFIED

Migration 031:
APPLIED AND VERIFIED

Revizyon Talebi Owner:
PRODUCTION VERIFIED

Correction Owner:
PRODUCTION VERIFIED

Snapshot Integrity:
VERIFIED

Temporary Ops Tooling:
REMOVED

Deferred:
1 — D-BEL-01

Fatal:
0

Known active gaps:
0
```

---

# 20. Tekrar Açmama Kararı

Aşağıdaki gerekçelerle S79 yeniden açılmayacaktır:

```text
ek acceptance turu
tekrar hardening turu
aynı permission matrisini yeniden test etme
aynı scope matrisini yeniden test etme
aynı migrationı yeniden doğrulama
aynı canlı smoke’u yeniden çalıştırma
parity sayısını yalnız sıfırlamak isteme
```

S79 yalnız şu durumda yeniden ele alınabilir:

```text
production incident
schema drift
security açığı
veri bütünlüğü problemi
onaylanmış yeni ürün gereksinimi
```

Bunun dışında yeni işler yeni faz numarasıyla ve tek geniş kapsam altında yürütülmelidir.

---

# 21. Bundan Sonraki Çalışma Kuralı

Yeni fazlarda aşağıdaki çalışma modeli kullanılacaktır:

```text
1. Tek geniş ön analiz
2. Tek owner implementasyonu
3. Tek acceptance + PR + production kapanışı
4. Tek ops cleanup
5. Tek final rapor
```

Aynı özellik için gereksiz R1/R2/R3/R4 zincirleri açılmayacaktır.

Güvenlik veya veri bütünlüğü gerektirmedikçe aynı test matrisi tekrar tekrar koşturulmayacaktır.

---

# 22. Final Etiket

```text
S79_FULLY_CLOSED_PRODUCTION_VERIFIED
Deferred: 1
Fatal: 0
Known active gaps: 0

D-REV-01..06 CLOSED
D-COR-01..03 CLOSED
D-BEL-01 DEFERRED

Migration 030 applied
Migration 031 applied
Live revision smoke verified
Live correction smoke verified
Snapshot immutable verified
Temporary ops tooling removed
Main clean and synchronized
```
