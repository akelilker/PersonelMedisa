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

## Bilinen sınır
- 048 `uq_users_actor_identity_id` iki hesabın aynı kimliği paylaşmasını engeller; bu yüzden
  K1 "aynı gerçek kişinin ikinci hesabı" kontrolü bugün yalnız kimlik birleştirilirse tetiklenir.
  Tespit için ek eşleştirme (ör. personel/normalize ad) ayrı karar ister.
- K5 bildirimi gelen kutusu bileşeninin GY'de (personel bağı olmayan) görünürlüğü UI tarafında P5'te doğrulanacak.
- UI (yetki düzenleme ekranı) P5.
