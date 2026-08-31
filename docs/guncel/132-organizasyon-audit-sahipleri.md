CODE_MIGRATION_TIP: 080
PRODUCTION_MIGRATION_TIP: 079

# 132 — Organizasyon denetim sahipleri ve kalıcı personel şube değişikliği

**Paket:** `MG-ORG-AUDITED-BRANCH-CHANGE-001`
**Durum:** yalnızca kod ve şema. **Hiçbir production business mutation
uygulanmamıştır.** Bu doküman bir apply onayı değildir.

## 0. Ne uygulanmadı

Bu turda şunların **hiçbiri** yapılmamıştır: migration apply, şube oluşturma,
personel taşıma, kullanıcı yetki kapsamı değişikliği, envanter/mapping yeniden
çalıştırma, deploy, merge. Kod tarafındaki migration ucu 080'e taşındı;
production'da uygulanmış ucu hâlâ 079'dur.

## 1. Lokasyon modeli — değişmedi

- `calisma_lokasyonlari` kayıtları **global fiziksel çalışma noktalarıdır**.
  Bir lokasyon bir şubenin alt birimi değildir; ayrı bir eksendir.
- Eşlenmemiş **7 lokasyonun `sube_id` alanı NULL kalır**. Bu bir eksik değil,
  bilinçli bir karardır (bkz. 129); NULL bırakmak, yanlış bir şubeye bağlamaktan
  daha doğru bir ifadedir.
- **Karabük multi-company bir lokasyondur: Medisa + Şenay.** Tek bir şubeye
  bağlanamaz, çünkü bağlanması iki şirketten birini kaydın dışına atardı.
- **İzmir ve Sakarya MEDISA'ya aittir** ve birer ayrı şube adayıdır. Henüz
  oluşturulmamışlardır.

Personel kimliği, adı veya benzeri PII bu dokümana veya denetim tablolarına
yazılmaz.

## 2. Owner sınırı

| Yazma yolu | Owner | Denetim tablosu |
| --- | --- | --- |
| Kalıcı personel şube değişikliği | `PersonelKaliciSubeDegisikligiService` | `personel_sube_degisiklik_auditleri` |
| Şube oluşturma | `OrganizasyonService::createSube` | `sube_olusturma_auditleri` |
| Kullanıcı organizasyon kapsamı | `YonetimController::kullaniciGuncelle` | `user_org_scope_auditleri` |
| Denetim satırı yazımı | `OrganizasyonAuditWriter` | — |
| Actor + istek parmak izi | `OrganizasyonAuditContext` | — |

`OrganizasyonAuditWriter` genel amaçlı bir olay günlüğü **değildir**: her yazma
yolunun kendi tablosu ve kendi typed recorder'ı vardır. Dördüncü bir denetlenen
yol, dördüncü bir tablo demektir.

## 3. Kalıcı personel şube değişikliği

**Endpoint:** `POST /personeller/{id}/kalici-sube-degisikligi`
**Roller:** yalnız `GENEL_YONETICI` ve `SISTEM_YONETICISI`.

**Payload:** `beklenen_mevcut_sube_id`, `yeni_sube_id`, `gerekce`.
İsteğe bağlı `Idempotency-Key` başlığı canonical
`OfflineMutationIdempotencyService` sözleşmesine göre işlenir; claim ve complete
adımları taşımanın kendi transaction'ı içinde çalışır.

Tek transaction içinde: `SELECT ... FOR UPDATE`, stale-preimage kontrolü, hedef
şubenin varlığı ve `AKTIF` durumu, actor'ün hem mevcut hem hedef şubede yetkisi,
personelin SGK işvereni ile hedef şubenin şirketinin eşleşmesi, `sube_id`
yazımı, denetim satırı ve readback doğrulaması. Denetim yazılamazsa taşıma geri
alınır.

`calisma_lokasyonu_id` ve `sgk_isveren_id` yazılmaz; readback bu iki alanın
değişmediğini kanıtlar, değişmişse transaction düşer.

**Generic `PUT /personeller/{id}` yasağı korunmuştur.**
`PersonellerController::assertUpdateSubeScope` içindeki
`targetSubeId !== currentSubeId -> forbidden` davranışı olduğu gibi durmaktadır;
kalıcı taşıma yalnız yukarıdaki dedicated endpoint üzerinden yapılabilir.

**Geçici görevlendirme değişmemiştir.** `personel_gecici_gorevlendirmeler`
yalnız tarihli satır ekler, `personeller.sube_id` yazmaz.

## 4. Denetim tabloları ve değişmezlik

Migration `080_organizasyon_audit_owners.sql` üç append-only tablo oluşturur.
Her satır bir `actor_user_id`, bir `request_hash` ve bir `created_at` taşır;
FK'ler `users`, `subeler` ve `personeller`'e bağlıdır.

Değişmezlik uygulama katmanında değil, veritabanında zorlanır: her tabloda
`BEFORE UPDATE` ve `BEFORE DELETE` trigger'ları `SIGNAL SQLSTATE '45000'` ile
yazmayı reddeder (021/024/061 bordro precedent'i). Retention destroy gate
bilinçli olarak yoktur — organizasyon geçmişi bir personel imha kategorisine ait
değildir, bu yüzden koşulsuz değişmezdir.

Tablolarda personel veya credential PII yoktur: ad, TC, telefon, IBAN veya şifre
materyali kopyalanmaz.

## 5. Fail-closed dağıtım sırası

080 uygulanmadan denetlenen yazma yolları çalışmaz: `OrganizasyonAuditWriter`
`ORGANIZASYON_AUDIT_SCHEMA_NOT_READY` (409) döndürür ve şube oluşturma, kalıcı
personel taşıma ve kullanıcı kapsamı değişikliği reddedilir. Bu bilinçlidir —
denetlenemeyen bir organizasyon yazması, denetimsiz başarıya tercih edilmez.
Bu nedenle **İzmir/Sakarya şubelerinin oluşturulmasından önce 080 uygulanmalıdır.**

## 6. Sonraki kapı

PR review + açık merge/deploy onayı. Migration apply, şube oluşturma ve personel
taşıma ayrı ve açık production onaylarıyla yapılır.
