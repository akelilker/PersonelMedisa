# Dinamik Yetki — P2 (şema + çözücü + okuma)

Plan: dinamik kullanıcı bazlı yetki yönetimi v3. Bu aşama **yazma yapmaz**; yazma uçları,
K1/K3/K5 kuralları ve son-yetki-yöneticisi koruması P3'tedir.

## Kapsam
- `api/migrations/100_user_yetki_istisnalari.sql` — **yalnız repoda; canlıya uygulanmadı.**
  - `user_yetki_istisnalari`: ALLOW/DENY, `sube_id` (NULL = global), `gecerlilik_baslangic`,
    `gecerlilik_bitis` (NULL = kalıcı), `veren_user_id`, `veren_actor_identity_id`,
    `hedef_rol_snapshot`, `gerekce`. Kayıt **silinmez**; yalnız bir kez iptal edilir
    (`iptal_edildi_at`, `iptal_eden_user_id`, `iptal_nedeni`), çekirdek alanlar değişmez (tetikleyici).
  - `user_yetki_auditleri`: append-only (099 deseni: BEFORE UPDATE/DELETE → SIGNAL).
  - Zaman alanları UTC.
- `AuthMiddleware`: istisnalar oturum kullanıcısına **tek sorguyla** yüklenir
  (`UserYetkiIstisnaSchema::loadActive`). Tablo yoksa boş; başka okuma hatası fail-closed.
- `EffectivePermissionResolver` karar sırası: QR self-service → self-service temel →
  (bilinmeyen rol: red) → **DENY** → rol varsayılanı → **ALLOW**.
  - DENY her zaman ALLOW'u ve rol varsayılanını yener; global DENY (şubesiz) tüm şubeleri kapatır.
  - ALLOW kapsamı genişletmez: şubeye özel ALLOW yalnız o şube bağlamında ve şube kullanıcının
    mevcut org kapsamındaysa geçerli.
  - Süre değerlendirme anında kontrol edilir.
  - Kırmızı liste (kalıcı sil, yönetim paneli manage, yetki yönetimi, SGK/bordro final onayı,
    GY onayı, legal hold, imha) ALLOW ile verilemez. GY sistem hakları DENY ile kapatılamaz.
- Okuma uçları: `GET /auth/yetkiler`, `GET /yonetim/kullanicilar/{id}/yetkiler`
  (`kullanici_yetkileri.view`), `GET /yonetim/yetki-auditleri` (`kullanici_yetkileri.audit.view`).
  Login yanıtı `user.effective_permissions` taşır.
- Yeni izinler: `kullanici_yetkileri.view`, `.manage`, `.audit.view` — yalnız GENEL_YONETICI.
  **GENEL_YONETICI 108 → 111 bilinçli artış**; diğer roller değişmedi (eşdeğerlik testi kanıtlar).

## Şube kapsamlı istisnalar (Kayseri örneği)
Bugün 232 `RolePermissions::has/assert` çağrı yerinin hiçbiri şube geçirmez; şube kapsamı
ayrıca `OrgScope` (okuma: `sube_ids`, kayıt filtreleri / `assertPersonelAccess`) ve
`HrWriteScope` (yazma: `write_sube_ids`) ile kayıt düzeyinde uygulanır. İzin kararı
"bu işlemi yapabilir mi", kapsam kararı "bu kayıtta" sorusudur.
- Şube bağlamlı karar `RolePermissions::hasForSube/assertForSube($user, $perm, $subeId)`:
  Kayseri ALLOW yalnız Kayseri'de (ve Kayseri kullanıcının kapsamındaysa) izin verir;
  Kayseri DENY yalnız Kayseri'yi kapatır.
- Şubesiz karar (`has/assert`): Kayseri ALLOW izin VERMEZ, Kayseri DENY ENGELLEMEZ.
  Global (şubesiz) DENY/ALLOW her iki kararda da geçerlidir.
- Sonuç: P3 çağrı yerlerini taşıyana kadar şube kapsamlı istisna yazımı **açılmaz**
  (P3 yazma ucu yalnız global istisna kabul eder; şube istisnası, ilgili işlem yolu
  `hasForSube`'a geçtikçe o izin için açılır).
- P3 geçiş sırası: (1) personel kaydı yazma yolları (`HrWriteScope` kullanan
  PersonelController/Surec/Izin/Zimmet create-update), (2) puantaj/haftalık kapanış
  şube işlemleri (aktif şube `OrgScope::resolveActiveSubeId`), (3) finans/ücret, (4) okuma
  listeleri (şube filtresiyle birlikte). Her geçiş kendi testleriyle.

## Kalıcı Sil etkisi
- `user_yetki_istisnalari` / `user_yetki_auditleri` kullanıcı kolonları FK'sızdır ve kullanıcı
  adı anlık görüntüsü taşır (099 `user_kalici_silme_auditleri` deseni). Bu yüzden yetki
  vermiş/almış/audit'te geçen bir hesap, eski ilker.akel durumundaki gibi kalıcı olarak
  kilitlenmez; geçmiş silinmez, yeniden atanmaz.
- `KullaniciKaliciSilService.HISTORY_SNAPSHOT_TABLES` bu tabloları envanterde engel saymaz
  (FK zorunluluğu kontrolü de bunları hariç tutar). Tek istisna: hedefin **aktif** yetki
  istisnası (iptal edilmemiş, süresi dolmamış, ileri tarihli dahil) → ENGELLENDİ; önce iptal.
  Böylece silinmiş bir kullanıcı id'sine bağlı geçerli istisna kalmaz.
- P3 notu: yetki yazma ucu hedef kullanıcı satırını `FOR UPDATE` kilitlemeli (Kalıcı Sil ile yarış).

## Yeni kurulum (K1)
- `yetki_politikasi` tablosu; satır yoksa mod UYARI (Medisa canlı, migration satır yazmaz).
- `ilk-yonetici-olustur.php` (yalnız boş DB'de çalışır): ilk yöneticiye gerçek-kişi kimliği
  (`actor_identities`, `USER-{id}`, VERIFIED, kurulum beyanı audit'i `BOOTSTRAP_VERIFY`) oluşturup
  bağlar ve `K1_KIMLIK_MODU = ZORUNLU` yazar. İlk yönetici kilitlenmez; kimliği doğrulanmamış
  hesaplar başkasına kişiye özel yetki veremez (`YetkiKimlikPolitikasi::degerlendir`, P3 yazma
  uçları çağırır). Kendine yetki her modda yasak.

## Davranış değişmezliği
Tablolar boşken (ya da 100 uygulanmamışken) her kullanıcının etkin izinleri PR #528 ile aynı:
`EffectivePermissionResolverEquivalenceTestRunner` eski `has()` ile 786.600 kararı karşılaştırır
(istisna listesi yok / boş / yalnız etkisiz satırlar varyantları) ve GY'nin 1f0513f1'deki 108 izninin
korunduğunu hash ile doğrular.

## K1 (kimlik kontrolü) modu — karar
- Kod varsayılanı **UYARI**: kimse kilitlenmez; eksik `actor_identity` yalnız uyarı/audit üretir.
- **ZORUNLU'ya otomatik/zaman tabanlı geçiş YOK.** Geçiş yalnız şu sırayla olur:
  1. Canlıda salt okunur kontrol: her aktif GENEL_YONETICI'nin doğrulanmış `actor_identity` kaydı var.
  2. Sonuç İlker'e raporlanır, **açık onay** alınır.
  3. Bayrak değişikliği kendi PR'ı olarak (ayrı onay) yapılır.
- Yeni kurulumlar da varsayılan UYARI ile başlar; ZORUNLU aynı doğrulama + açık onay ile açılır.
- Kimliği eksik hesabın kilitlenmemesi için yönetim ekranında kimlik bağlama yolu (mevcut
  actor-identity ucu) korunur.
