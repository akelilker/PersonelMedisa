# 132 — İK rol ayrımı ve organizasyon kapsam modeli

**Faz:** MG-ORGANIZATION-HR-FINAL-CLOSEOUT-001
**Kapsam:** `IK_SORUMLUSU` / `IK_PERSONELI` rol ayrımı, global read + atanmış şirkette yazma modeli, login erişimi kaldırma owner'ı, İzmir/Sakarya şube-personel planı ve lokasyon kararı. Rol atama kararı için bkz. bölüm 7: bu closeout'ta hiçbir kullanıcı `IK_PERSONELI` yapılmaz.
**Durum:** Kod + migration repoda hazır. Production migration apply ve production business-data mutation **yapılmadı**; ayrı onay kapılarına bağlıdır.

## 1. Rol modeli

| Rol | Okuma | Yazma |
| --- | --- | --- |
| `IK_SORUMLUSU` | Bütün mevcut ve gelecekteki şirket/şube — role'den gelir, `user_subeler`/`user_sirketler` satırı gerektirmez ve bu satırlar kapsamı **daraltmaz** | Rolün izin setindeki bütün İK işlemleri, bütün şirketlerde |
| `IK_PERSONELI` | `IK_SORUMLUSU` ile aynı global salt-okunur kapsam | Yalnız `user_sirketler` ile atanmış şirketlerin şubelerinde; başka şirkette 403 |

Bağlayıcı iş kararı: `sedanurB` ve `zeynepG` aynı görev seviyesindedir ve ikisi de `IK_SORUMLUSU`'dur. `IK_PERSONELI` rolü altyapıda hazır durur fakat bu closeout'ta hiçbir kullanıcıya atanmaz; Seda-approver / Zeynep-maker gibi bir ayrım veya cross-company write kısıtı kurulmaz.

`IK_PERSONELI` izin seti, `IK_SORUMLUSU` izinlerinden yönetim/onay yetkilerinin (`puantaj.donem_reseal`, `sgk_karar_paketi.prepare`, `sirket_parametreleri.manage`, `personel_bordro_kapsam.manage`, `maas_hesaplama.manage`, `maas_hesaplama_adaylari.manage`) çıkarılmasıyla **türetilir**; iki yerde ayrı liste tutulmaz. Aynı türetme frontend `role-permissions.ts` içinde birebir tekrarlanır ve parity testiyle kilitlidir.

Hiçbir rol karar mantığı kullanıcı adına bakmaz; runtime authorization yalnız rol + canonical scope üzerinden çalışır.

## 2. Owner'lar

| Sorumluluk | Owner |
| --- | --- |
| Global read rol listesi + personel org filtresi | `api/src/Scope/OrgScope.php` (`ORGANIZATION_GLOBAL_READ_ROLES`) |
| Yazma şirketi zorunluluğu (fail-closed) | `api/src/Scope/HrWriteScope.php` |
| Request başına scope çözümü (`write_sube_ids`) | `api/src/Auth/AuthMiddleware.php` |
| İzin kataloğu | `api/src/Auth/RolePermissions.php` + `src/lib/authorization/role-permissions.ts` |
| Kullanıcı rol/scope yönetimi | `api/src/Controllers/YonetimController.php` + `YonetimPaneliPage` / `YonetimSubeScopeField` |
| Login erişimi kaldırma | `YonetimController::kullaniciErisimKaldir` (`DELETE /yonetim/kullanicilar/{id}`) |
| Denetim | `OrganizasyonAuditWriter` + `user_erisim_kaldirma_auditleri` |

Yazma yolları (personel create/update, aktiflik, ücret, puantaj/izin, belgeler, geçici görevlendirme, zimmet, import/dry-run, idempotent offline yollar) tek canonical assertion owner'ından geçer; route başına paralel kontrol yoktur. Hedef şirket server-side güvenle türetilemiyorsa istek mutation başlamadan 403 ile reddedilir; ledger, audit veya partial row bırakılmaz.

## 3. Dış şirket kontrol modeli

Ayrı bir approval queue kurulmaz. `IK_PERSONELI` kapsam dışı şirkette doğrudan yazamaz; kaydı `IK_SORUMLUSU` inceler ve uygun bulursa mutation'ı **kendi authenticated hesabıyla** yapar. Audit gerçek actor'ı kaydeder. Credential paylaşımı veya impersonation yoktur.

UI'da kapsam dışı kayıtlarda mutation aksiyonları gizlenir ve `Bu işlem İK sorumlusu tarafından gerçekleştirilmelidir.` açıklaması gösterilir; asıl güvenlik owner'ı her durumda backend 403'tür.

## 4. Gelecek kullanıcılar ve şubeler

- Yeni `IK_SORUMLUSU`: otomatik global read + izin setindeki global yazma.
- Yeni `IK_PERSONELI`: otomatik global read; en az bir yazma şirketi seçilmeden yazma yetkisi kazanmaz (boş seçim = read-only, fail-closed).
- Yeni şube eklendiğinde hiçbir scope satırı kopyalanmaz; şirket kapsamı request başına şubelere çözülür.

## 5. Migration

`081_ik_personeli_rolu.sql` — additive:

- `users.rol` ENUM'una `IK_PERSONELI` eklenir (mevcut satırlar remap edilmez; genişletme ihtiyacı yoksa no-op).
- `user_erisim_kaldirma_auditleri` append-only tablosu (UPDATE/DELETE trigger ile engellenir).

`080_organizasyon_audit_owners.sql` dosyası **değiştirilmedi**; içerik, ad ve checksum korunur.

CODE_MIGRATION_TIP = `081`, PRODUCTION_MIGRATION_TIP = `079`. Pending: `080`, `081`.

### 5.1 Migration control-plane round modeli

Canonical apply owner'ı tek bir migration'a pinliydi (`beklenen tip 078`, `pending yalnız 079`), bu yüzden 080/081 turunu tanımıyordu. Owner artık **tur** modeliyle çalışır:

- `MigrationPreflightReport::ROUND_MIGRATIONS` turu sıralı ve isimleriyle pinler (`080`, `081`). Canlı pending set bu turun boş olmayan bir **suffix**'i olmak zorundadır; böylece hem tur başlamadan hem de iki apply arasında preflight PASS verebilir. Tur bittiğinde `ROUND_ALREADY_COMPLETE` ile bloklanır, yani üçüncü bir apply mümkün değildir.
- `apply-cpanel-migrations.yml` artık zorunlu `target_migration` input'u alır. Gate, hedefin **sıradaki** pending migration olduğunu ve checksum'ının o ref'teki dosya ile birebir eşleştiğini doğrular; istek payload'ına `target_version` yazar.
- Worker hedefi tekrar canlı ledger'a karşı doğrular (`TARGET_NOT_NEXT_PENDING`, `TARGET_ALREADY_APPLIED`), dump'ı hedef migration adıyla alır ve `MigrationRunner`'ı yalnız o versiyona kadar çalıştırır. Böylece **her migration kendi doğrulanmış backup'ı ve kendi transaction'ı ile** uygulanır; iki migration tek apply'a çökmez.
- `MigrationRunner::verify()` hedefli çağrıda kalan pending'i kabul eder ama hedefin ötesine geçmiş bir uygulamayı reddeder; hedefsiz çağrıda hâlâ tam drenaj ister.

Bu değişiklik yalnız control-plane sözleşmesidir: `080` ve `081` dosyalarının içeriği, adı ve checksum'ı korunur.

## 6. `042` hesabı kararı

- Yalnız exact `042` login hesabı kapsamdadır.
- Hiçbir `personeller` satırı silinmez; bağlı personel kaydı korunur.
- Hard delete audit/reference bütünlüğünü bozduğu için canonical davranış **fail-closed deactivate/revoke**: hesap pasifleştirilir, parola rotate edilir, organization scope satırları temizlenir, bekleyen davet/aktivasyon token'ları iptal edilir.
- İşlem `user_erisim_kaldirma_auditleri` satırı olmadan tamamlanamaz; audit hatası business mutation'ı rollback eder.
- Idempotent tekrar güvenlidir; credential okunmaz veya raporlanmaz.

## 7. Production business changeset (uygulandı)

- MEDISA altında `MDS-IZM` (İzmir, id **12**) ve `MDS-SAK` (Sakarya, id **13**) şubeleri, durum `AKTIF`, SGK = MEDISA. ID tahmin edilmedi; canonical audited create owner ile oluşturuldu ve exact readback yapıldı.
- Sonuç: production şube sayısı 10 → **12**. Envanter bu iki şubeyi ID listesine eklendiği için değil, `sube_olusturma_auditleri` içindeki canonical create kanıtı sayesinde **audited extension** olarak kabul eder; ayrıntı: `docs/guncel/129-organization-mapping-owners.md` §2.2.
- Personel 112 (sicil 040) → `MDS-IZM`, personel 169 (sicil 463) → `MDS-SAK`. Yalnız `sube_id` değişir; lokasyon, SGK ve diğer alanlar korunur.
- `sedanurB`: rol `IK_SORUMLUSU` **değişmez**; global İK davranışı readback ile doğrulanır.
- `zeynepG`: rol `IK_SORUMLUSU` **değişmez**; `IK_PERSONELI`'ye çevrilmez ve write-company kapsamı tanımlanmaz. Global İK davranışı readback ile doğrulanır.
- Her iki kullanıcı için yalnız erişimi **daraltan** redundant legacy scope satırları canonical auditli update ile temizlenir; başka rol/scope mutation'ı yoktur.
- `042`: yukarıdaki karar uygulanır.

Tüm bu mutation'lar audit zorunludur. Rol değişikliği içermeyen bu changeset'te gerçek mutation actor'ı mevcut audit owner'larıyla kaydedilir.

## 8. Lokasyon kararı (kapalı)

7 `calisma_lokasyonlari` satırının tamamında `sube_id` NULL kalır (Ankara, Giresun, İstanbul, İzmir, Karabük, Kayseri, Sakarya). Karabük çok şirketli ortak çalışma noktasıdır (Medisa Fabrika 109, Şenay Mobilya 2) ve tek şubeye bağlanamaz. İzmir/Sakarya lokasyonları da şubeye bağlanmaz; personelin lokasyon ve şube alanları bağımsız tutulur. Junction table, location split veya mapping geliştirilmez.
