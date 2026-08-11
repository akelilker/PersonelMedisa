# PersonelMedisa — Güncel Ürün Durumu

Bu dosya ürünün **tek güncel durum kaynağıdır**. Eski S-serisi kapanış raporları, ops paketleri ve `.tmp-ops/` altındaki karar çıktıları tarihsel kanıttır; bu dosyayla çelişirlerse güncel ürün durumu olarak kullanılamazlar.

## Karar

- **Ürün beyni:** `FROZEN`
- **Görsel düzenleme aşaması:** `GO`
- **Beyin baseline:** `c6e75fbce2c0eec5b0c13eb69ac2d1494fb014ba`
- **Karar tarihi:** 2026-08-06

Baseline sonrasında doküman, test çalıştırma kararlılığı ve yayın hattı düzeltmeleri yapılabilir. Yeni domain owner, paralel hesap motoru veya yeni özellik yalnız ayrı teşhis ve açık onayla açılır.

## Doğrulanmış teknik temel

- `main`, `origin/main` ve GitHub `main` baseline commitinde eşleşti.
- TypeScript kontrolü, production build, API parity ve cPanel deploy güvenlik kontrolü geçti.
- Kontrollü Vitest koşusunda 207 dosya / 1.570 test geçti.
- Güncel baseline üzerinde Playwright koşusunda 380 senaryo geçti, 1 senaryo bilinçli atlandı, hata oluşmadı.
- Canlı anonim smoke; API health, auth guard, frontend ve hashed asset kontrollerini geçti.
- Authenticated Genel Yönetici arayüzü read-only olarak doğrulandı.
- Dedicated `AUTH_SMOKE_READONLY` hesabı login + `GET /api/auth/smoke-read` sözleşmesinde `PASS` verdi; tek şube ve salt-okuma rolü doğrulandı. Credential yalnız GitHub Actions secret store'dadır.
- Canlı veritabanında 041–050 migration imzaları read-only sorguyla doğrulandı.
- SGK, şirket politikası kanıtı, bordro preflight, personel importu, revizyon ve dual-control owner'ları mevcut ve fail-closed çalışıyor.

## Birbirine karıştırılmaması gereken durumlar

| Katman | Durum | Görsel aşamayı engeller mi? |
| --- | --- | --- |
| Ürün/domain beyni | Frozen | Hayır |
| Unit/integration/E2E sözleşmeleri | Yeşil | Hayır |
| Canlı şema owner'ları | 041–050 doğrulandı | Hayır |
| Exact-SHA cPanel yayın kanıtı | Sunucu/FTP sorunu nedeniyle manuel upload owner'ına devredildi | Tasarımı engellemez; canlıya çıkışı engeller |
| Dedicated authenticated smoke | PASS; Actions secret store hazır | Hayır |
| SGK/UBGT/hukuki kanıtlar | Operasyon ve insan kararı | Hayır |
| Dönem onayı, mühür ve bordro kapsamı | Canlı işletme verisi | Hayır |

## Freeze kuralı

Görsel aşamada beyin kapsamı yalnız şu hallerde yeniden açılır:

1. Tekrarlanabilir P0 veri bütünlüğü hatası.
2. Yetki veya güvenlik açığı.
3. Mevcut owner sözleşmesini bozan doğrulanmış regresyon.
4. Yasal olarak zorunlu ve kanıtı tamamlanmış kural değişikliği.

Performans, görsel tutarlılık, erişilebilirlik ve responsive düzenlemeler mevcut component/owner yapısı içinde yapılır. Bunlar gerekçe gösterilerek yeni paralel domain sistemi kurulmaz.

## Yayın kabul kapıları

Bir commitin canlıya kabulü için ürün freeze kararından bağımsız olarak şunlar kanıtlanır:

1. `HEAD`, `origin/main` ve remote `main` eşit.
2. Aynı SHA için CI başarılı.
3. Aynı SHA için cPanel deploy başarılı; otomatik hat sunucu/FTP nedeniyle kullanılamıyorsa manuel upload sonrası build asset/SHA eşliği ayrıca kanıtlanmış.
4. Anonim `smoke:live` başarılı.
5. Dedicated `AUTH_SMOKE_READONLY` hesabıyla authenticated smoke başarılı.
6. Gerekli production write varsa ayrıca backup, insan onayı ve read-back kanıtı mevcut.

Bu kapılardan birinin açık olması yeni ürün özelliği gerektiği anlamına gelmez.

2026-08-06 tarihli `c6e75fb` otomatik deploy yeniden denemesi sunucu/FTP bağlantı sorunu nedeniyle kullanıcı kararıyla durduruldu. Bu durum ürün beyni açığı değildir; mevcut yayın için manuel upload kullanılacaktır.

## Tarihsel belgelerin kullanımı

- `docs/guncel/95-s96-release-ops-runbook.md`: güncel operasyon protokolüdür; ürün tamamlanma listesi değildir.
- `docs/guncel/99-payroll-compliance-critical-gaps-kapanis.md`: kapanmış kritik payroll owner'larının teknik kanıtıdır.
- `.tmp-ops/**`: yerel ve tarihsel operasyon çıktısıdır; silinmez, fakat güncel backlog veya ürün durumu sayılmaz.
- Eski S-numaralı checkpoint belgeleri yalnız ait oldukları commit/dönem için kanıttır.

## Okuma — hesaplama cevap haritası

Kodun *neyi nasıl hesapladığını* tek bakışta görmek için:

- `docs/guncel/102-hesaplama-cevap-haritasi.md`

Bu dosya ürün freeze’i açmaz; backlog değildir. Toplantı / denetim / yeni gelen okuma haritasıdır. Canlı parametre ve yayın kapıları yine bu `CURRENT_STATE.md` dosyasına bağlıdır.

## Sonraki ürün aşaması

Görsel sistem çalışmaları başlayabilir. Öncelik; ortak tasarım tokenları, layout, tipografi, component tutarlılığı, responsive davranış ve erişilebilirliktir. Canlı yayın yine bu dosyadaki yayın kabul kapılarından geçer.
