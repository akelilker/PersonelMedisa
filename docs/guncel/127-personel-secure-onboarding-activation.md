# Personel Güvenli Hesap Açma ve Aktivasyon

**Durum:** kaynakta uygulandı / canlıya henüz uygulanmadı
**Migration:** `075_personel_account_activation.sql`
**Sahip servis:** `PersonelAccountOnboardingService`

## Kullanıcı adı kuralı

| Kural | Değer |
|------|--------|
| Biçim | İlk ad (küçük Latin) + soyadın ilk harfi (büyük Latin) |
| Örnek | İlker AKEL → `ilkerA` |
| Çoklu ad | Yalnız `ad` alanının ilk kelimesi kullanılır (Mehmet Ali YILMAZ → `mehmetY`) |
| Türkçe karakter | ç/ğ/ı/İ/ö/ş/ü → Latin eşleri; boşluk/nokta/tire temizlenir |
| Sicil numarası | Kullanıcı adı **değildir** ve üretime katılmaz |
| Sicil değişince | Kullanıcı adı **değişmez** |
| Mevcut hesaplar | Kullanıcı adları **değiştirilmez** (backfill yok) |
| Çakışma | Otomatik sayı eklenmez; yetkiliye “Bu kullanıcı adı zaten kullanılıyor. Farklı bir kullanıcı adı belirleyin.” uyarısı |

## Şifre kuralı

Yönetici / İK / Genel Yönetici personelin şifresini seçmez, görmez, öğrenmez.

Personel, tek kullanımlık aktivasyon bağlantısı üzerinden kendi şifresini belirler.

Sonraki giriş: kullanıcı adı (ör. `ilkerA`) + personelin kendi şifresi.

## Akış (yalnız yeni onboarding)

1. Yönetici/İK: **Personel Hesabı Oluştur** (şifre alanı yok; kullanıcı adı önerisi otomatik)
2. Sunucu `PERSONEL` kullanıcı oluşturur; iç kullanım için rastgele kullanılamaz kimlik bilgisi hash’lenir (dönülmez)
3. `activation_required=1`, `must_change_password=1`
4. Tek kullanımlık aktivasyon daveti: yalnız SHA-256 hash saklanır; ham token bir kez URL olarak döner
5. Personel `/personel-aktivasyon#token=…` açar, şifresini seçer
6. Token tüketilir; `activation_required=0`, `must_change_password=0`

## Aktivasyon güvenliği

- Token entropisi ≥ 256 bit (`random_bytes(32)` → hex)
- TTL sahibi: `medisa_config('personel_activation_ttl_minutes')` (varsayılan **1440**)
- Genel URL sahibi: `medisa_config('app_public_url')` (Host başlığına güvenilmez)
- Fragment taşıma (`#token=`); sayfa fragment’i hemen temizler
- Issue/reissue yanıtları: `Cache-Control: no-store` + `Referrer-Policy: no-referrer`
- Bekleyen kullanıcı başına en fazla bir canlı davet (transactional revoke + insert)
- Eşzamanlı redeem: satır kilitleri → tam olarak bir başarı

Örnek aktivasyon URL şekli:

`https://www.karmotors.com.tr/personelmedisa/personel-aktivasyon#token=<gizli>`

## Mevcut hesaplar

Grandfathered. Migration yalnız güvenli varsayılanlarla sütun/tablo ekler:

- kullanıcı adlarını yeniden adlandırmaz
- şifreleri sıfırlamaz
- aktivasyon beklemeye almaz
- rol/bağlantı değiştirmez
- davet üretmez

## DIS_KAYNAK

Aynı teknik onboarding/aktivasyon serbesttir.
`PersonelMobileCapabilityService` iş yeteneklerini şu mesajla kapalı tutmaya devam eder:

> Yapım Aşamasındadır. Onay Bekleyen Kapsamlar Tamamlandığında Kullanıma Açılacaktır.

## Genel oluşturma bypass

`POST /yonetim/kullanicilar` ile `rol=PERSONEL` + `personel_id` → `PERSONEL_USE_SECURE_ONBOARDING`.
Personel dışı yönetim/sistem kullanıcı oluşturma değişmez.

## API

| Method | Path | Auth |
|--------|------|------|
| POST | `/yonetim/personeller/{id}/hesap-onboarding` | `yonetim-paneli.manage` |
| POST | `/yonetim/kullanicilar/{id}/aktivasyon-yenile` | `yonetim-paneli.manage` |
| GET | `/yonetim/kullanicilar/{id}/aktivasyon-meta` | `yonetim-paneli.manage` |
| POST | `/auth/personel-activation/status` | public (token) |
| POST | `/auth/personel-activation/complete` | public (token) |

İsteğe bağlı gövde alanı: `username` — yalnız çakışma sonrası yetkili alternatif kullanıcı adı.

## Canlıya alma kapısı

- `PRODUCTION_MIGRATION_APPLY = NO` açık ops onayı olmadan
- Mevcut aktif kadroyu toplu yeniden provision etme
- Migration + `app_public_url` hazır olmadan canlıya deploy etme

## Denetim olayları

`PERSONEL_ACCOUNT_CREATED`, `PERSONEL_ACCOUNT_BOUND`, `ACTIVATION_LINK_ISSUED`, `ACTIVATION_LINK_REISSUED`, `ACTIVATION_COMPLETED`, `ACTIVATION_REVOKED`

Düz metin şifre, şifre hash’i, ham token veya tam aktivasyon URL’si denetlenmez.
