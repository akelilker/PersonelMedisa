# Seçim Yüzeyi Standardizasyonu — Kapanış

## Tarih

2026-05-28

## Özet

Puantaj, Süreç ve Bildirimler (Günlük Kayıt) modüllerinde kısa referans kümeleri için seçim yüzeyi standardizasyonu tamamlandı. Modal ve form akışlarında native `select` yerine segment/buton (`role="group"`, `aria-pressed`) yüzeyi kullanılıyor; aynı state’i yöneten çift yüzeyler (ör. hızlı buton + select) kaldırıldı.

Bu faz **kapalıdır**.

## Native select → segment/buton dönüşümü yapılan alanlar

### Günlük Puantaj (`GunlukPuantajPage`)

- Gün Tipi
- Hareket Durumu
- Dayanak
- Durumu Bildirdi mi?

Bileşen: `PuantajChoiceGroup` — `.puantaj-choice-group` / `.puantaj-choice-btn`

Detay notu: `docs/guncel/30-puantaj-secim-yuzeyi-buton-kapanis.md`

### Süreç Takip (`SurecTakipPage` modalları)

- Süreç Türü (Yeni / Düzenle modal)
- Ücretli mi? (Yeni / Düzenle modal)

Bileşen: `SurecChoiceGroup` — `.surec-choice-group` / `.surec-choice-btn`

`SurecFormFields` içinde `useOperationControls` ile İşlem Detayı ve Ücretli mi? segment yüzeyi de aynı kalıba bağlıdır (Kayıt / Süreç workspace).

### Bildirimler / Günlük Kayıt (`BildirimlerPage` modalları)

- Kayıt Senaryosu (Yeni Günlük Kayıt / Düzenle modal)

Bileşen: `KayitSenaryosuChoiceGroup` — `.bildirim-kayit-senaryosu-group` / `.bildirim-kayit-senaryosu-btn`

Önceki “Hazır Günlük Kayıt Seç” hızlı butonları ile `FormField as="select"` çifti tek segment yüzeyde birleştirildi.

## Korunan alanlar ve sınırlar

Aşağıdakiler bilinçli olarak **değiştirilmedi**:

- Uzun personel listeleri: modal ve formlarda Personel alanı `FormField as="select"` (veya personel yoksa sayısal ID) olarak kaldı.
- Liste filtreleri: Personel ve Kayıt Senaryosu (Bildirimler), Süreç/Puantaj filtre select’leri native select olarak kaldı.
- API, hook, servis, mock ve veri modeli sözleşmesi.
- `modal.css`, `FormField.tsx` ve ortak global form bileşen sözleşmesi.
- Hesap motoru, submit payload mantığı ve iş kuralları.

## Doğrulama

| Komut | Sonuç |
| --- | --- |
| `npm run typecheck` | Yeşil |
| `npx playwright test tests/e2e/smoke.spec.ts` | Yeşil (4 passed) |

Bildirimler segment dönüşümü sonrası smoke senaryoları `getByRole("group", { name: "Kayıt Senaryosu" })` + buton tıklamasına güncellendi.

## İlgili commit’ler

| Commit | Açıklama |
| --- | --- |
| `a82b6d4` | Günlük Puantaj seçim yüzeyi: `PuantajChoiceGroup`, `puantaj.css`, smoke uyumu |
| `895b584` | Puantaj seçim yüzeyi kapanış notu (`30-puantaj-secim-yuzeyi-buton-kapanis.md`) |
| `d8970a8` | Süreç modal segment dönüşümü: `SurecChoiceGroup`, `SurecTakipPage`, `SurecFormFields` |
| `d595dc3` | Bildirim kayıt senaryosu segment dönüşümü: `KayitSenaryosuChoiceGroup`, `bildirimler.css`, smoke |

## Değişen dosya özeti (üç modül)

- Puantaj: `src/features/puantaj/pages/GunlukPuantajPage.tsx`, `src/styles/modules/puantaj.css`
- Süreç: `src/features/surecler/components/SurecFormFields.tsx`, `src/features/surecler/pages/SurecTakipPage.tsx`, `src/styles/modules/kayit-surec.css` (mevcut choice stilleri)
- Bildirimler: `src/features/bildirimler/pages/BildirimlerPage.tsx`, `src/styles/modules/bildirimler.css`

## Sonraki önerilen faz

**Görsel sağlık turu + canlı kullanım mini checklist**

Amaç: Segment yüzeylerinin mobil/desktop hizasını, aktif durum kontrastını ve modal içi taşmayı gerçek kullanıcı akışında doğrulamak.

Referans dokümanlar:

- `docs/guncel/26-gorsel-saglik-kontrolu-checkpoint.md` — ekran bazlı görsel kontrol listesi
- `docs/guncel/29-canli-kullanim-mini-checklist.md` — IK gözüyle canlı kullanım sırası
- `docs/guncel/28-canli-kullanim-mini-checklist-talimat-ve-rapor.md` — talimat ve rapor şablonu

Kontrol önceliği:

1. Günlük Puantaj formu (dört segment + koşullu alanlar)
2. Süreç Yeni / Düzenle modal (Süreç Türü, Ücretli mi?)
3. Bildirimler Yeni / Düzenle modal (Kayıt Senaryosu segmenti, Personel select korunumu)
4. Liste filtre select’lerinin segment modallarla karışmadığının doğrulanması

Kapsam dışı: yeni API, hook refactor, `FormField` global değişikliği, bordro/finans/SGK resmi kod.

## Son durum

Üç modülde kısa enum/referans seçimleri ortak segment/buton kalıbına alındı; uzun listeler ve filtreler native select’te bırakıldı. Teknik sözleşme ve backend davranışı korunarak faz kapatıldı.
