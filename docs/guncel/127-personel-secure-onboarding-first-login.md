# Personel Güvenli Hesap Açma — First Login

**Durum:** canonical first-login modeli uygulanmış; 125 kişilik production rollout tamamlanmıştır.
**Sahip servis:** `PersonelAccountOnboardingService`

## Canonical model

Yönetici / İK, PERSONEL hesabını oluşturur. Sistem şirket kuralına göre başlangıç
credential'ını hash'ler; düz metin şifre response, log veya audit'e yazılmaz. Personel
ilk girişte şifresini değiştirmek zorundadır.

| Alan | Yeni hesap hedefi |
| --- | --- |
| `activation_required` | `0` |
| `must_change_password` | `1` |
| Credential modeli | `FIRST_LOGIN_TEMPLATE` |

Kullanıcı adı ilk ad (küçük Latin) ve soyadının ilk harfi (büyük Latin) ile üretilir.
Çakışmada otomatik sayı eklenmez; yetkili alternatif kullanıcı adı verir.

## Ürün akışı

1. Yönetici / İK, Personel Kartı'ndan **Personel Hesabı Oluştur** seçer.
2. Hesap `PERSONEL` ve `AKTIF` olarak personele bağlanır.
3. Personel başlangıç credential'ıyla giriş yapar.
4. Uygulama, normal kullanımdan önce zorunlu şifre değiştirme ekranına yönlendirir.

Yönetim ekranı yalnız hesap durumu ile ilk giriş şifre değişimi bilgisini gösterir;
aktivasyon bağlantısı üretme, yenileme veya kopyalama işlevi yoktur.

## API ve sınırlar

| Method | Path | Auth |
| --- | --- | --- |
| POST | `/yonetim/personeller/{id}/hesap-onboarding` | `yonetim-paneli.manage` |

`POST /yonetim/kullanicilar` üzerinden `rol=PERSONEL` + `personel_id` oluşturma,
canonical owner dışına çıkmamak için `PERSONEL_USE_SECURE_ONBOARDING` ile reddedilir.

## Bilinçli olarak bırakılan tarihçe

`075_personel_account_activation.sql` ve
`089_personel_legacy_account_activation.sql` migration geçmişidir; silinmez veya
yeniden yazılmaz. Aynı nedenle `activation_required` ve `activated_at_utc` şema
alanları bu cleanup'ın konusu değildir. Legacy activation-link route/controller/API/UI
artık yoktur; `activation_required=1` gibi beklenmeyen tarihsel bir state login
katmanında fail-closed kalır.

Denetim kayıtları geçmiş olayları korur. Current onboarding olayları
`PERSONEL_ACCOUNT_CREATED`, `PERSONEL_ACCOUNT_BOUND` ve
`PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLIED` üzerinden izlenir.
