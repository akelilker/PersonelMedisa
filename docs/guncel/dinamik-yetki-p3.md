# Dinamik kullanıcı yetkisi — P3 (yazma + kurallar + bildirim)

Durum: PR (MERGE=NO). Migration yok; migration 100 canlıya uygulanmadan yazma uçları
`503 YETKI_SEMASI_HAZIR_DEGIL` döner ve rol değişikliği yolu eskisi gibi çalışır.

## Uçlar (`kullanici_yetkileri.manage`, yalnız GENEL_YONETICI)
- `POST /yonetim/kullanicilar/{id}/yetkiler` — `{permission, etki: ALLOW|DENY, gerekce, gecerlilik_baslangic?, gecerlilik_bitis?}`
- `POST /yonetim/kullanicilar/{id}/yetkiler/{istisnaId}/iptal` — `{gerekce}` (silme yok, bir kez iptal)

## Kurallar (`KullaniciYetkiYazmaService`, sırayla)
1. Aktörde `kullanici_yetkileri.manage`; verilen iznin aktörde olması aranmaz (karar 2).
2. Hedef ≠ aktör: kendi yetkisi/kısıtı değiştirilemez (`KENDI_YETKISI_DEGISTIRILEMEZ`).
3. Yalnız GLOBAL istisna: `sube_id` gelirse `SUBE_ISTISNASI_KAPALI` (şube istisnaları kapalı kalır).
4. Kırmızı liste: `NON_GRANTABLE_PERMISSIONS` ALLOW ile verilemez; GY hedefte `GY_NON_DENYABLE_PERMISSIONS` DENY'lanamaz.
5. Süre: bitiş boş = kalıcı; doluysa > başlangıç, süreli ALLOW ≤ 365 gün. Geçmişe dönük başlangıç yok.
6. K1 `YetkiKimlikPolitikasi`: Medisa (satır yok) UYARI → kabul + `uyari_kodlari`; ZORUNLU → kimliksiz veren reddedilir.
7. K3: hedef GY ve işlem yetkiyi genişletiyorsa (ALLOW ver / DENY kaldır) ve aktör hedefin son GY atayanıysa:
   uygun başka GY (aktif GY, yetki yöneticisi, atayan ve hedef dışında; ZORUNLU'da farklı kimlik) varsa
   `ATAYAN_YETKI_VEREMEZ`; yoksa izin + `K3_ISTISNA_TEK_YONETICI`. Atayan: `user_yetki_auditleri.PROFIL_DEGISTI`
   (P3'ten itibaren oluşturma/rol değişikliğinde yazılır), yoksa 082 erişim audit'i; ikisi de yoksa K3 uygulanmaz.
8. Kilit sırası #523 ile aynı: aktif GY satırları, sonra hedef `users` satırı `FOR UPDATE`
   (Kalıcı Sil aynı satırı kilitler → yarış yok).
9. Yetenek tabanlı son yönetici: işlem sonrası en az bir aktif GY `kullanici_yetkileri.manage` + `yonetim-paneli.manage` taşımalı.
10. Audit: her yazma tek satır (`VER`/`KALDIR`/`ROL_DEGISTI_IPTAL`/`PROFIL_DEGISTI`).
11. K5: hedef GY ise commit sonrası diğer aktif GY'lere (aktör/hedef hariç) `personel_inbox_notifications`
    `kind = GY_YETKI_DEGISIKLIGI`, popup yok. Bildirim hatası işlemi geri almaz (loglanır).

## Karar 8 — rol değişikliği
`YonetimController::kullaniciGuncelle` aynı transaction'da `rolDegisti()` çağırır: etkin istisnalar
`ROL_DEGISTI` ile iptal + her biri audit. GY'ye atama (oluşturma dahil) `PROFIL_DEGISTI` yazar (K3 için).

## K1 — aynı gerçek kişinin iki hesabı (isim eşleştirmesi yok)
- `users.actor_identity_id` 048'den beri 1:1 (`uq_users_actor_identity_id`); SGK dört göz ve
  `SgkKararPaketiAuthz` bu varsayıma dayandığı için **dokunulmadı** (unique kaldırılmadı).
- Yeni tablo `user_gercek_kisi_baglari` (migration 100 içinde; 100 hiçbir ortamda uygulanmadı, 101 D2'ye ayrılmış):
  bir EK hesabı açık `actor_identity_id` + gerekçe ile bir gerçek kişiye bağlar. Hesap başına tek satır,
  değiştirilemez/silinemez (tetikleyici), `user_id` FK'sız (Kalıcı Sil'i kilitlemez). Audit `KISI_BAGLANDI`.
- Kişi(hesap) = bağ ?? `users.actor_identity_id`. `YetkiKimlikPolitikasi` ve K3 bunu kullanır.
- Uç: `POST /yonetim/kullanicilar/{id}/gercek-kisi` `{actor_identity_id, gerekce}` (kendine bağ yasak).
- Neden: personel bağı (`uq_users_personel_id`) ve TC özeti de hesap başına tekil olduğundan iki hesabı
  ilişkilendiremez; isim tabanlı eşleştirme güvenilir değil. Açık bağ deterministik ve geri uyumlu.
- Testle kanıtlı: UYARI'da kabul + `YETKI_AYNI_GERCEK_KISI`; ZORUNLU'da engel; farklı kişi verebilir.
- Sınır: bağ yalnız beyanla oluşur; bağlanmamış ikinci hesap tespit edilemez (UI P5'te bağ ekranı).

## K5 alıcıları
Hedef GY + aktör dışındaki tüm aktif GY'ler. Bildirim kullanıcı bazlıdır (`personel_id` NULL);
`/me/inbox-notifications` GY'de `puantaj.view`/`attendance.correction.decide` ile açılır (personel gerekmez).
Not: mevcut UI gelen kutusunu yalnız personel (minimal) kabuğunda gösteriyor; yönetici kabuğunda gösterim P5.

## Bilinen sınır
- UI (yetki ve gerçek kişi bağı ekranları, yönetici gelen kutusu) P5.
