# Dinamik kullanıcı yetkisi — P3 (yazma + kurallar + bildirim)

Durum: kod main'de (PR #530). Migration 100 canlıda uygulandı (Apply `38222514124`). Şema hazır değilse yazma uçları
`503 YETKI_SEMASI_HAZIR_DEGIL` döner ve rol değişikliği yolu eskisi gibi çalışır. Personel yazma köprüsünde DENY önceliği ve ekran oturum hizası bu çalışma ağacında yereldir; commit/merge/deploy yoktur.

## Uçlar (`kullanici_yetkileri.manage`, yalnız GENEL_YONETICI)
- `POST /yonetim/kullanicilar/{id}/yetkiler` — `{permission, etki: ALLOW|DENY, gerekce, gecerlilik_baslangic?, gecerlilik_bitis?}`
- `POST /yonetim/kullanicilar/{id}/yetkiler/{istisnaId}/iptal` — `{gerekce}` (silme yok, bir kez iptal)

## Kurallar (`KullaniciYetkiYazmaService`, sırayla)
1. Aktörde `kullanici_yetkileri.manage`; verilen iznin aktörde olması aranmaz (karar 2).
2. Hedef ≠ aktör: kendi yetkisi/kısıtı değiştirilemez (`KENDI_YETKISI_DEGISTIRILEMEZ`).
3. Yalnız GLOBAL istisna: `sube_id` gelirse `SUBE_ISTISNASI_KAPALI` (şube istisnaları kapalı kalır).
4. Kırmızı liste: `NON_GRANTABLE_PERMISSIONS` ALLOW ile verilemez; GY hedefte `GY_NON_DENYABLE_PERMISSIONS` DENY'lanamaz.
5. Süre: bitiş boş = kalıcı; doluysa > başlangıç, süreli ALLOW ≤ 365 gün. Geçmişe dönük başlangıç yok.
6. K1 `YetkiKimlikPolitikasi`: Medisa (satır yok) UYARI → kimliksiz veren kabul + `uyari_kodlari` (eski hesap kilitlenmez); ZORUNLU → kimliksiz veren reddedilir. Güvenilir aynı kişi (dolu T.C. + personel bağlantısı veya doğrulanmış kimlik köprüsü) her modda reddedilir. İsim eşleştirmesi yok.
7. K3: hedef GY ve işlem yetkiyi genişletiyorsa (ALLOW ver / DENY kaldır) ve aktör hedefin son GY atayanıysa:
   uygun başka GY (aktif GY, yetki yöneticisi, atayan ve hedef dışında; ZORUNLU'da farklı kimlik) varsa
   `ATAYAN_YETKI_VEREMEZ`; yoksa izin + `K3_ISTISNA_TEK_YONETICI`. Atayan: `user_yetki_auditleri.PROFIL_DEGISTI`
   (P3'ten itibaren oluşturma/rol değişikliğinde yazılır), yoksa 082 erişim audit'i; ikisi de yoksa K3 uygulanmaz.
8. Kilit sırası #523 ile aynı: aktif GY satırları, sonra hedef `users` satırı `FOR UPDATE`
   (Kalıcı Sil aynı satırı kilitler → yarış yok).
9. Yetenek tabanlı son yönetici: işlem sonrası en az bir aktif GY `kullanici_yetkileri.manage` + `yonetim-paneli.manage` taşımalı.
10. Audit: her yazma tek satır (`VER`/`KALDIR`/`ROL_DEGISTI_IPTAL`/`PROFIL_DEGISTI`).
11. K5: hedef GY ise commit sonrası işlemi yapan hariç bütün aktif GY'lere bildirim gider;
    yetkisi değiştirilen yönetici de alıcıdır. `personel_inbox_notifications`
    `kind = GY_YETKI_DEGISIKLIGI`, popup yok. Bildirim hatası işlemi geri almaz (loglanır).

## Karar 8 — rol değişikliği
`YonetimController::kullaniciGuncelle` aynı transaction'da `rolDegisti()` çağırır: etkin istisnalar
`ROL_DEGISTI` ile iptal + her biri audit. GY'ye atama (oluşturma dahil) `PROFIL_DEGISTI` yazar (K3 için).

## K1 — bir kişi, bir hesap
- Karar: bir kişinin farklı görevler için ikinci kullanıcı hesabı yoktur. Görev ve yetki tek hesaptan yönetilir.
- `users.actor_identity_id` 048'den beri 1:1'dir. SGK dört göz ve kullanıcı kimliği kayıtları **dokunulmadı**.
  Ek hesap bağ tablosu, ekranı ve ucu yoktur. Migration 100 şeması main ile aynıdır (canlıda uygulanmadı).
- Aynı kişi yalnız güvenilir mevcut bilgiyle bilinir: personel kaydındaki dolu T.C. + `users.personel_id`,
  veya personel kaydı olmayan yöneticide doğrulanmış `actor_identity` köprüsü (`status = VERIFIED` ve
  `actor_identities.personel_id`). İsim/soyad eşleştirmesi yok. T.C. yeni tabloya kopyalanmaz; listede,
  yetki ekranında, bildirimde, hata mesajında ve denetim kaydında gösterilmez.
- Bu eşleşme varsa kendi lehine yetki verme ve kısıt kaldırma (her yetki yazımı) UYARI modunda da reddedilir.
- Yeni personel hesabı açılırken eşleşme varsa ikinci hesap açılmaz. Pasif hesap varsa otomatik yeni hesap
  yok; mevcut hesap incelenir (`MEVCUT_PASIF_HESAP_INCELENMELI`). Eski hesaplar birleştirilmez, geçmiş
  kayıtları değiştirilmez, kimliği belirsiz hesap kilitlenmez.
- Açık sınır: personel kaydı olmayan ve doğrulanmış kimliği o personele bağlı olmayan eski ikinci hesap
  aynı kişi sayılamaz. Bu hesaplar kilitlenmez; aralarındaki yetki yazımı engellenmez. Koruma bu durumda eksiktir.

## K5 alıcıları
İşlemi yapan hariç bütün aktif Genel Yöneticiler. Yetkisi değiştirilen yönetici de alıcıdır.
Bildirim kullanıcı bazlıdır (`personel_id` NULL);
`/me/inbox-notifications` GY'de `puantaj.view`/`attendance.correction.decide` ile açılır (personel gerekmez).
Not: mevcut UI gelen kutusunu yalnız personel (minimal) kabuğunda gösteriyor; yönetici kabuğunda gösterim P5.

## Bilinen sınır
- UI (yetki ekranı, yönetici gelen kutusu) P5.
- Kimliği personel kaydı veya doğrulanmış kimlik köprüsüyle belirlenemeyen eski ikinci hesaplar
  otomatik birleştirilmez ve kilitlenmez. Bu hesaplarda aynı kişi koruması yoktur.
