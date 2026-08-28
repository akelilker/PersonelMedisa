CODE_MIGRATION_TIP: 077
PRODUCTION_MIGRATION_TIP: 077

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

Salt-okunur production envanteri (cron üzerinden `SELECT`; mutation yok):

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

Kod değişikliği canlıdır (CI PASS, Deploy cPanel PASS, authenticated read-only smoke PASS — smoke aktörü yetki yükselmesi olmadan çalışmaya devam ediyor).

Migration `077` production'a **uygulanmıştır**: apply run `33169230032`, request `33169230032-1`, worker `SUCCEEDED`, `deployed_sha = 23cebad69f24aa22fcbbbf621d4cd82372f7cfce`. Worker `APPLY` ve `VERIFY` aşamalarını geçmiştir; migration'ın kendi guard'ları (legacy rol atanmış kullanıcı sayısı ve canonical ENUM readback assert'i) apply sırasında sağlanmıştır. `PRODUCTION_MIGRATION_TIP = 077`.

### Neden dört önceki deneme başarısız oldu

Dört deneme (`33144474181`, `33148682586`, `33152241001`, `33154572612`) `MIGRATION_TIMEOUT_REASON=STATUS_TERMINAL_NOT_OBSERVED` verdi. Bu bir FTP kesintisi değildi; iki ayrı repo kaynaklı kusurun birleşimiydi:

1. **Canlı worker bozulmuştu.** Kaldırılan `ops-readonly-final-inventory` workflow'u, envanter almak için canlı `api/bin/cpanel-migration-cron.php` dosyasını geçici bir wrapper ile değiştiriyor ve orijinali `cpanel-migration-cron.impl.php` olarak yanına kopyalıyordu. Restore adımı `|| true` ile hataları yuttuğu için wrapper canlıda kaldı, `impl` dosyası ise silindi. Wrapper her tick'te var olmayan dosyayı `require` edip PHP fatal veriyordu; bu yüzden hiçbir `status.json`, `worker.lock` veya arşiv üretilemedi. Incremental deploy git'te değişmeyen bu dosyayı yeniden yüklemediği için sonraki deploy'lar da onarmadı.
2. **Teşhis yanlış negatif veriyordu.** Preflight ve final teşhis `mirror --include-glob` kullanıyordu; bu kalıp bu cPanel FTP sunucusunda sessizce boş dönüyor. Sonuç olarak busy guard fail-open kaldı (dört talep talep edilmeden birikti) ve teşhis, dosyalar aslında dururken "hiçbir iz yok" raporladı.

Salt-okunur teşhis (`ops-migration-worker-diagnostics`) her ikisini de kanıtladı: `REMOTE_WORKER_IS_WRAPPER=YES`, `REMOTE_WORKER_BYTES=504`, `STALE_ARTIFACT|cpanel-migration-cron.impl.php=ABSENT` ve dört `request.pending.*` dosyasının hâlâ yerinde durduğu dizin listesi.

### Kalıcı önlemler

- Canlı worker'ı değiştiren envanter workflow'u kaldırıldı.
- Worker her tick'te atomic `worker-heartbeat.json` yayınlıyor: `schema_version`, `updated_at`, `deployed_sha`, `production_migration_tip`, `legacy_role_enum_count`. Şema sorguları salt-okunur ve fail-soft'tur (DB erişilemezse `UNKNOWN` / `-1`); kullanıcı satırı okunmaz.
- Apply workflow'unun preflight ve teşhis adımları dizin listelemesine geçti; busy guard artık fail-closed.
- Birikmiş dört talep kör silinmedi; canonical worker yaşam döngüsüyle `DEPLOY_SHA_MISMATCH` gerekçesiyle `request.failed.*` arşivine taşındı.

## Kapanış anahtarları

```
LEGACY_ROLE_AUTHORIZATION_ACTIVE = HAYIR
LEGACY_ROLE_ASSIGNED_REAL_USER_COUNT = 0
LEGACY_ROLE_SELECTABLE_COUNT = 0
LEGACY_ROLE_ENUM_SCHEMA_COUNT = 0
CANONICAL_AUTH_ROLE_COUNT = 8
SYSTEM_TEST_ROLE_COUNT = 1
LEGACY_ROLE_ENUM_SCHEMA_SHRINK = MIGRATION_077_PRODUCTION_APPLIED
MG_ROLE_ENUM_DEBT_001 = CLOSED_CONFIRMED
```
