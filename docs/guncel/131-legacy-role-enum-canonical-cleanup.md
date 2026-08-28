CODE_MIGRATION_TIP: 077
PRODUCTION_MIGRATION_TIP: 076

# 131 — Legacy `users.rol` ENUM canonical cleanup

**Tür:** Kapanış kaydı (authorization rol katmanı).
**Kapsam:** Yalnız `users.rol` authorization rolleri. Personel unvanı, pozisyonu, görevi, bölüm/birim/şube verisi bu çalışmanın **dışındadır** ve değiştirilmemiştir.

## Canonical authorization rolleri

`CANONICAL_AUTH_ROLE_COUNT = 8`

| Rol | Sınıf |
| --- | --- |
| `GENEL_YONETICI` | insan |
| `SISTEM_YONETICISI` | insan |
| `SUBE_YONETICISI` | insan |
| `BOLUM_YONETICISI` | insan |
| `BIRIM_AMIRI` | insan |
| `IK_SORUMLUSU` | insan |
| `MUHASEBE` | insan |
| `PERSONEL` | insan |

Ayrıca `AUTH_SMOKE_READONLY` **teknik/sistem aktörü** olarak korunur: tek izni `ops.auth_smoke.read`, tam 1 şube scope zorunlu, `pm_smoke_ro_` username kontratı, kullanıcı panelinden atanamaz. Bu bir personel authorization rolü değildir ve canonical rol sayımına dahil edilmez (`SYSTEM_TEST_ROLE_COUNT = 1`).

## Legacy değerler ve production envanteri

Salt-okunur production envanteri (`ops-readonly-final-inventory` workflow, cron üzerinden `SELECT`; mutation yok):

| Legacy rol | Toplam kullanıcı | Aktif | Pasif | Personel bağlı |
| --- | ---: | ---: | ---: | ---: |
| `PATRON` | 0 | 0 | 0 | 0 |
| `IK_BORDRO` | 0 | 0 | 0 | 0 |
| `SGK_KARAR_ONAY_YETKILISI` | 0 | 0 | 0 | 0 |
| `IDARI_ISLER` | 0 | 0 | 0 | 0 |

`LEGACY_ROLE_ASSIGNED_REAL_USER_COUNT = 0`, `AMBIGUOUS_USER_MAPPING = YOK`.

Bu değerler yalnız şema ENUM kalıntısı ve normalize sınırındaki geriye dönük uyumluluk sabitleriydi; hiçbiri aktif bir permission matrisi anahtarı değildi. Gerçek kullanıcıya rol mapping'i uygulanmamıştır (`MAPPINGS_APPLIED = NONE`); tarihsel `PATRON`/`IK_BORDRO` remap'leri zaten migration `054` ile yapılmıştı.

## Uygulanan değişiklik

- `api/migrations/077_legacy_role_enum_shrink.sql`: `users.rol` ENUM'unu canonical 8 + `AUTH_SMOKE_READONLY` değerine daraltır. Idempotent (daraltılmış şemada no-op), fail-closed: kolon yoksa, herhangi bir kullanıcı legacy rol taşıyorsa veya readback canonical katalogla eşleşmezse `PACK077_BLOCKER` ile durur. `UPDATE users SET rol`, `INSERT`, `DELETE` ve personel/org tablosu yazımı içermez.
- `api/src/Auth/RolePermissions.php`: `safeAliases` haritası ve legacy özel-durum dalı kaldırıldı. `normalizeRole()` artık yalnız permission matrisindeki canonical rolleri çözer; diğer her değer `''` döner ve `LoginController` `403 ROLE_UNRESOLVED` ile fail-closed davranır.
- `src/lib/authorization/canonicalize-user-role.ts`: `SAFE_LEGACY_ROLE_ALIASES` ve `UNRESOLVED_LEGACY_ROLES` kaldırıldı; yalnız canonical katalog çözülür, diğer her değer `null`.

Yetki kapsamı hiçbir rol için genişletilmemiş veya daraltılmamıştır; frontend rol seçim listesi zaten yalnız `ASSIGNABLE_USER_ROLES` (8 canonical) sunuyordu, `LEGACY_ROLE_SELECTABLE_COUNT = 0`.

## Production apply durumu

Kod değişikliği `d6372deb3a6d83388bb31baf10d753f0acf912fe` ile canlıdır (CI PASS, Deploy cPanel PASS, authenticated read-only smoke PASS — smoke aktörü yetki yükselmesi olmadan çalışmaya devam ediyor).

Migration `077` **production'a uygulanamamıştır**. `Apply cPanel migrations` workflow'u üç ayrı denemede (`33144474181`, `33148682586`, `33152241001`) `MIGRATION_TIMEOUT_REASON=STATUS_TERMINAL_NOT_OBSERVED` ile zaman aşımına uğradı: worker kontrol düzleminde ne `status.json` ne de `request.*` izi gözlemlenebildi. Aynı pencerede salt-okunur envanter workflow'u da `api/bin/cpanel-migration-cron.php` dosyasını FTP üzerinden çekemedi (`max-retries exceeded`, hem explicit-FTPS hem plain-FTP). Bu bir cPanel/FTP ortam sorunudur; migration içeriğinden veya guard'ından kaynaklanmaz.

Bu nedenle `PRODUCTION_MIGRATION_TIP = 076` olarak kalmıştır ve production `users.rol` ENUM'u hâlâ 4 legacy değeri **şema seviyesinde** taşımaktadır. Yetki açısından risk yoktur: bu değerlerin atanmış kullanıcısı yok, hiçbir arayüzden seçilemez ve backend/frontend normalize sınırları bunları fail-closed reddeder.

Kalan tek adım, FTP/cron kontrol düzlemi sağlıklıya döndüğünde `Apply cPanel migrations` workflow'unu `deployed_sha = d6372deb3a6d83388bb31baf10d753f0acf912fe` ve onay `APPLY_CANONICAL_MIGRATIONS` ile yeniden çalıştırmaktır.

## Kapanış anahtarları

```
LEGACY_ROLE_AUTHORIZATION_ACTIVE = HAYIR
LEGACY_ROLE_ASSIGNED_REAL_USER_COUNT = 0
LEGACY_ROLE_SELECTABLE_COUNT = 0
CANONICAL_AUTH_ROLE_COUNT = 8
SYSTEM_TEST_ROLE_COUNT = 1
LEGACY_ROLE_ENUM_SCHEMA_SHRINK = MIGRATION_077_PENDING_PRODUCTION_APPLY
MG_ROLE_ENUM_DEBT_001 = CODE_CLOSED_PRODUCTION_APPLY_PENDING
```
