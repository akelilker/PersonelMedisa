# Günlük Puantaj Seçim Yüzeyi — Buton/Segment Kapanış

## Tarih

2026-05-27

## Özet

Günlük Puantaj ekranında aşağıdaki alanlar native `select` yerine buton/segment seçim yüzeyine taşındı:

- Gün Tipi
- Hareket Durumu
- Dayanak
- Durumu Bildirdi mi?

Referans mantık: Kayıt / Süreç ekranındaki buton seçim yaklaşımı (`SurecFormFields` içindeki `renderSegmentedButtons`, `.surec-choice-group` / `.surec-choice-btn` davranışı).

## Korunan davranışlar

- Hareket Durumu boş başlayabilir.
- Hareket Durumu = **Gelmedi** seçilince **Durumu Bildirdi mi?** alanı görünür.
- **Durumu Bildirdi mi?** = **Evet** seçilince **Açıklama** input/textarea görünür.
- **Hayır** seçilince veya **Gelmedi** dışı bir hareket durumuna geçilince açıklama temizlenir ve gizlenir; **Gelmedi** dışına geçişte `durumu_bildirdi_mi` de temizlenir.
- Saat alanlarının zorunluluk ve görünürlük davranışı değişmedi.
- Submit payload davranışı değişmedi.
- Hesap motoru, hook iş mantığı ve API davranışı değişmedi.

## Değişen dosyalar

- `src/features/puantaj/pages/GunlukPuantajPage.tsx`
- `src/styles/modules/puantaj.css`
- `tests/e2e/smoke.spec.ts`

## Doğrulama

- `npm run typecheck`: OK
- `npm run test`: OK, 361 passed
- `npx playwright test tests/e2e/smoke.spec.ts`: OK, 4 passed
- `npx playwright test tests/e2e/personel-dosya.spec.ts`: OK, 7 passed
- GitHub Actions CI ve Deploy cPanel son görülen durumda yeşil

## Kapsam dışı

- `FormField` global refactor yok
- API / hook / service / hesap motoru değişmedi
- Maaş / bordro / finans / SGK resmi kod yok

## Son durum

Günlük Puantaj formunda dört seçim alanı Kayıt/Süreç ekranına yakın buton/segment yüzeyiyle sunuluyor; iş kuralı ve backend sözleşmesi aynı kaldı.
