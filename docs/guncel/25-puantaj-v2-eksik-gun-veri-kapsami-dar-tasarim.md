# Puantaj V2 Eksik Gün Veri Kapsamı Dar Tasarım Kararı

## Faz adı

Puantaj V2 Eksik Gün Veri Kapsamı Güçlendirme

## Mevcut sorun

Readonly eksik gün özeti ay içi tüm puantajları API'den toplu çekmiyor; sadece cache'teki günlük kayıtlarla çalışıyor. Bu nedenle kapsam eksikse kesin SGK prim günü kararı üretmiyor.

## Korunacak kararlar

- UI hesap yapmaz.
- SGK resmi kod yok.
- Bordro/net maaş yok.
- Finans kalemi yok.
- Dashboard yok.
- `hesaplaSgkPrimGunu` davranışı değişmez.
- `durumu_bildirdi_mi` sadece haberli/habersiz sinyalidir.
- `Raporlu_Hastalik` ve `Raporlu_Is_Kazasi` otomatik SGK düşümü üretmez.

## Owner kararı

İlk kod fazında `src/data/data-manager.ts` büyütülmemeli.

Önerilen dar owner:

- Mevcut `usePuantajEksikGunOzeti` içinde veya yanında puantaj odaklı küçük veri helper'ı.
- Hesaplama yine `src/services/puantaj-hesap-motoru.ts` içinde kalır.
- API katmanı değişmez; mevcut `fetchGunlukPuantaj` kullanılır.

## Hydrate stratejisi

- Ayın tüm günleri render anında kör şekilde fetch edilmez.
- Sadece cache'te eksik olan tarihler aday olur.
- Aynı şube/personel/tarih için dedupe korunur.
- Active sube değişirse eski şube cache'i karışmaz.
- İlk fazda otomatik hydrate yerine kontrollü/dar tetikleme tercih edilir.

## İlk kod fazı önerisi

En dar ilk kod fazı:

- `usePuantajEksikGunOzeti` içinde veri kapsamı durumunu daha açık modellemek.
- Eksik tarih listesini hesaplamak.
- Henüz otomatik fetch başlatmadan hook çıktısına `eksikTarihSayisi` / `eksikTarihListesi` gibi readonly veri kapsamı bilgisi eklemek.
- Unit test ile şube/personel izolasyonunu kilitlemek.

## Sonraki kod fazı önerisi

Bir sonraki fazda kontrollü hydrate:

- Mevcut `fetchGunlukPuantaj` ile eksik tarihleri sınırlandırılmış şekilde doldurma.
- İstek sınırı ve dedupe.
- Hook testleri.
- UI değişikliği minimum veya yok.

## Dokunulmaması gereken dosyalar

- Finans
- Bordro
- Dashboard
- SGK resmi kod sözlüğü
- E2E testler, davranış değişmezse
- `src/services/puantaj-hesap-motoru.ts`, ilk veri kapsamı fazında hesap değişmeyecekse

## Riskler

- 31 günlük otomatik fetch performans riski.
- Şube/personel cache karışması.
- UI içinde hesap kaçışı.
- Raporlu günlerin yanlış otomatik SGK düşümüne dönüşmesi.
- `durumu_bildirdi_mi` alanının yanlış karar alanına dönüşmesi.
