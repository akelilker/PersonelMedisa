# S77-B — Ücret Geçmişi ve Mevzuat Parametre Altyapısı

## 1. Ürün amacı

Personel üzerindeki tekil/canlı `maas_tutari` alanını; geçerlilik tarihli, geçmiş dönemleri bozmayan, denetlenebilir, rol/scope kontrollü ve ileride bordro snapshot’ına kaynak olabilecek kanonik ücret geçmişine taşımak.

İkinci hedef: bordro/maaş hesabında kullanılacak dönemsel mevzuat parametrelerini koddan ayırıp sürümlenebilir veri modeline almak.

Bu fazda tam bordro hesabı, SGK/vergi motoru veya PDF üretimi yoktur.

## 2. Owner değişikliği

| Alan | Eski owner | Yeni owner | Karar |
| --- | --- | --- | --- |
| Maaş tutarı | `personeller.maas_tutari` | `personel_ucret_gecmisi` | Legacy alan uyumluluk için kalır; kanonik okuma/yazma ücret geçmişi |
| Brüt/net | Doküman: net girişi (`net_maas_tutari`) | `ucret_turu` ENUM `BRUT`/`NET` | Create path varsayılanı `NET` |
| Geçerlilik | Yok | `gecerlilik_baslangic` zorunlu, `gecerlilik_bitis` opsiyonel | Inclusive aralık |
| Maaş geçmişi | Yok | `personel_ucret_gecmisi` | Overwrite yerine close+create |
| Audit | Yok (maaş için) | `personel_ucret_auditleri` | CREATE/UPDATE/CLOSE/CANCEL/MIGRATE |
| Rol/scope | Personel response’ta herkese maaş | `personeller.ucret.view` / `.manage` + şube scope | BIRIM_AMIRI / BOLUM_YONETICISI göremez |
| Mevzuat | Kod sabitleri / yok | `mevzuat_parametreleri` | Gerçek oran seed edilmez |

## 3. Legacy maaş alanı stratejisi

- Kolon: `personeller.maas_tutari DECIMAL(12,2) NULL`
- Ürün kontratı: kullanıcı girdisi net (`net_maas_tutari` alias)
- Bu fazda kolon silinmez / drop edilmez
- Körlemesine migration backfill yok
- Compatibility read: personelde hiç ücret geçmişi satırı yoksa ve `maas_tutari > 0` ise `resolveSalaryForDate` sanal `NET` kayıt döner (`kaynak=PERSONEL_KAYDI_MIGRASYON`, başlangıç=`ise_giris_tarihi`)
- İlk gerçek ücret kaydı oluşturulurken legacy tutar önceki döneme taşınabilir (MIGRATE audit)
- Yazma sonrası `syncLegacySalary` güncel aktif tutarı legacy kolona yansıtır

## 4. Şema

### Migration 018 — `personel_ucret_gecmisi` + `personel_ucret_auditleri`

- Parasallar: `DECIMAL(12,2)`
- State: `AKTIF` / `IPTAL`
- Generated `open_ended_aktif` + unique `(personel_id, open_ended_aktif)` → tek açık uçlu aktif kayıt
- Index: `(personel_id, gecerlilik_baslangic|bitis|state)`

### Migration 019 — `mevzuat_parametreleri` + `mevzuat_parametre_auditleri`

- `deger_tipi`: `SAYISAL` / `METIN` (aynı anda tek değer)
- Tarih aralığı + open-ended unique `(parametre_kodu, open_ended_aktif)`
- Seed yok

## 5. Date semantics

```text
başlangıç dahil, bitiş dahil
D geçerli ⇔ gecerlilik_baslangic <= D AND (bitis IS NULL OR D <= gecerlilik_bitis)
```

Overlap aynı inclusive kural ile backend transaction + `FOR UPDATE` personel kilidi ile engellenir.

## 6. API

```text
GET    /personeller/{id}/ucretler
GET    /personeller/{id}/ucretler/aktif
POST   /personeller/{id}/ucretler
PUT    /personeller/{id}/ucretler/{ucretId}
POST   /personeller/{id}/ucretler/{ucretId}/iptal
GET    /mevzuat-parametreleri
POST   /mevzuat-parametreleri
PUT    /mevzuat-parametreleri/{id}
POST   /mevzuat-parametreleri/{id}/iptal
```

Domain hata kodları: `SALARY_*`, `LEGAL_PARAMETER_*` — HTTP 400/403/404/409 mevcut JsonResponse standardıyla.

## 7. Rol / scope

| Rol | Maaş görme | Geçmiş | Yazma | Mevzuat |
| --- | ---: | ---: | ---: | ---: |
| BIRIM_AMIRI | Hayır | Hayır | Hayır | Hayır |
| BOLUM_YONETICISI | Hayır | Hayır | Hayır | Hayır |
| MUHASEBE | Evet | Evet | Evet | Görüntüleme |
| GENEL_YONETICI | Evet | Evet | Evet | Yönetim |
| PATRON | Hayır | Hayır | Hayır | Hayır |

İzinler:

- `personeller.ucret.view` / `personeller.ucret.manage`
- `mevzuat_parametreleri.view` / `mevzuat_parametreleri.manage`

Yetkisiz personel list/detail yanıtından `maas_tutari` / `net_maas_tutari` strip edilir. Şube dışı erişim 403.

## 8. Frontend

- Personel Kartı → Genel sekme → **Ücret Geçmişi** (`PersonelUcretGecmisiSection`)
- Yetkisiz: bölüm render edilmez, fetch yok, maaş eksik uyarısı da gizlenir
- Yeni ücret dönemi modalı; overlap mesajı sabit Türkçe metin
- Mevzuat paneli: Yönetim Paneli → `mevzuat` sekmesi

## 9. FINANCE_SALARY_MISSING

S76 warning korunur. Owner: dönem bitiş tarihi için `PersonelUcretService::resolveSalaryForDate` (+ legacy fallback). Bu fazda maaş hesabı blocker endpoint’i yok.

## 10. Concurrency

MariaDB/InnoDB: personel satırı `FOR UPDATE`, overlap validation, unique open-ended index. CI `mariadb:11.4` service + `PersonelUcretMysqlConcurrencyTestRunner`.

## 11. Açık riskler

- Canlıda legacy maaşlı personeller için otomatik backfill yok; ilk ücret kaydı anında MIGRATE ile tarihçe oluşur.
- Gerçek mevzuat oranları doğrulanmadan seed edilmez; boş parametre listesi kabul edilir.
- Bordro snapshot henüz yok; ileride canlı tablodan okuma yapılmamalıdır.

## 12. Başarı kodları (yerel kontrat)

```text
S77_B_OWNER_CONFIRMED
S77_B_SALARY_AUDIT_CONTRACT_DEFINED
S77_B_LEGAL_PARAMETER_CONTRACT_DEFINED
S77_B_SCHEMA_DESIGN_COMPLETE
S77_B_LEGACY_SALARY_COMPATIBILITY_DEFINED
S77_B_LOCAL_DOCS_COMPLETE
```
