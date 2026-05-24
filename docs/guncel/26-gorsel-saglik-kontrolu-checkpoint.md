# Görsel Sağlık Kontrolü Checkpoint

## Tarih

2026-05-24

## Kontrol edilen ekranlar

- Login ekranı
- Ana omurga ekranı
- Personel listesi
- Günlük Puantaj ekranı
- Günlük Puantaj mobil görünüm
- Personel Detayı > Puantaj sekmesi

## Sağlıklı görünen alanlar

- Login form hizası stabil
- Ana omurga butonları stabil
- Personel listesi kartları okunabilir
- Günlük Puantaj formu desktop görünümde stabil
- Günlük Puantaj mobil görünümde alanlar taşmıyor
- “Durumu Bildirdi mi?” alanı Gelmedi seçilince doğru görünüyor
- Evet seçilince açıklama alanı doğru açılıyor
- Footer ile form alanları çakışmıyor

## Yakalanan görsel/UX bug

Personel Detayı > Puantaj sekmesinde aşağı kaydırınca İzin / Devamsızlık paneli de görünüyordu.

## Kök sebep

`PersonelIzinDevamsizlikPanel` aktif tab kontrolü almadan her zaman render ediliyordu. Diğer tab panelleri `hidden={activeTab !== ...}` kullanırken bu panel wrapper dışında kalmıştı.

## Fix

Commit:

`a7af095 Fix personel detail tab panel visibility`

Fix özeti:

- `PersonelIzinDevamsizlikPanel` üst seviye tabpanel wrapper içine alındı.
- `hidden={activeTab !== "izin-devamsizlik"}` kontrolü eklendi.
- Component içindeki eski tabpanel wrapper kaldırıldı.
- Duplicate id/role oluşması engellendi.

## Doğrulama

- `npm run typecheck`: OK
- `npx playwright test tests/e2e/personel-dosya.spec.ts`: 7 passed
- Lokal görsel doğrulama: Puantaj sekmesi altında İzin paneli artık görünmüyor.
- GitHub Actions CI: yeşil
- Deploy cPanel: yeşil

## Son durum

Görsel sağlık açısından kritik bloklayıcı sorun görülmedi. Yakalanan tab panel kaçağı giderildi ve deploylandı.

## Sonraki önerilen iş

Puantaj V2 Eksik Gün kontrollü hydrate tasarımına dönülebilir.
