# S85-C — SGK Katalog Manuel Kanıt Talep Paketi

**Amaç:** Mali müşavir / e-Bildirge operasyonel doğrulama kanıtlarını standart paket halinde toplamak.
**Sınıf:** `OPERASYONEL_DOGRULAMA_KANITI`
**Uyarı:** Bu paket **mevzuat authority değildir**. Alınan kanıtlar tek başına `DOGRULANMIS_TAM` / katalog tamlık kapısını geçirmez.

Resmî kaynak araştırma özeti (repo dışı çalışma paketi; kişisel path canonical değildir): yerel `Downloads/s85c-sgk-kaynak-arastirma/reports/S85C-TAMLIK-KAPISI.md` raporuna bakın. Araştırma dosyalarını repoya kopyalamayın.

## 1) İstenecek altı exact belge

1. **e-Bildirge / e-Beyanname eksik gün nedeni dropdown ekran görüntüsü**
   Dönem: güncel (ör. 2026-07). Tüm kodlar tek veya ardışık görüntülerde görünür olmalı.
2. **Varsa SGK işverene ilettiği güncel kod açıklama eki / genelge PDF**
   Resmî başlık, tarih ve kurum görünür.
3. **Kod 12 (birden fazla) kullanımına dair SGK kabul örneği veya yazılı görüş**
   Set→kod matrisi yoksa bunu açıkça not edin.
4. **Kod bazlı belge zorunluluğu listesi** (varsa ünite yazısı / resmi ek).
5. **Kısmi süreli (06/07) prim günü hesabı için SGK’nın kabul ettiği yazılı yöntem**
   Saat/7,5 varsayımı istenmez; metin ne diyorsa o.
6. **Şirketin bildirim dönemi tercihi (1–son gün vs 15–14) için SGK/vergi uygulaması yazılı teyidi**
   Politikayı aktive etmez; yalnız kanıt toplar.

## 2) Ekran görüntüsü nasıl alınır

- e-Bildirge / e-Beyanname oturumunda eksik gün nedeni alanını açın.
- **Tüm dropdown kodları** görünene kadar kaydırın; gerekirse birden fazla ekran görüntüsü alın.
- Görüntüde **tarih/saat** (OS veya uygulama) görünür olsun.
- **İşveren unvanı, vergi no, kullanıcı adı, sicil** gibi alanları maskeleyin (siyah kutu / blur).
- Tek kare yetmiyorsa `...-01.png`, `...-02.png` şeklinde numaralandırın; set olarak paketleyin.

## 3) Dosya isim standardı

```text
SGK-OP-{DONEM}-{TUR}-{YYYYMMDD}-{NN}.{ext}
```

Örnekler:

- `SGK-OP-2026-07-EBILDIRGE-DROPDOWN-20260722-01.png`
- `SGK-OP-2026-07-SGK-GENELGE-20260722-01.pdf`
- `SGK-OP-2026-07-KOD12-GORUS-20260722-01.pdf`

## 4) SHA256 alma yöntemi

Windows PowerShell:

```powershell
Get-FileHash -Algorithm SHA256 .\SGK-OP-2026-07-EBILDIRGE-DROPDOWN-20260722-01.png
```

Linux/macOS:

```bash
sha256sum SGK-OP-2026-07-EBILDIRGE-DROPDOWN-20260722-01.png
```

Metadata kaydı (kişisel veri içermeden):

| alan | değer |
|---|---|
| kanit_turu | OPERASYONEL_DOGRULAMA_KANITI |
| donem | YYYY-MM |
| alinma_zamani | ISO-8601 |
| ekran_cikti_kaynagi | e-Bildirge / e-Beyanname / PDF |
| dosya_adi | standard isim |
| byte_boyutu | dosya boyutu |
| sha256 | 64 hex |
| yukleyen | ad / rol (kişisel kimlik no yok) |
| dogrulayan | mali müşavir |
| destekledigi_kodlar | görülen kod listesi |
| mevzuat_kaynagi_mi | **false** |
| katalog_tamligi_icin_tek_basina_yeterli_mi | **false** |

Dosya byte’ına erişilemiyorsa sistem `SGK_OPERASYONEL_KANIT_ICERIGI_DOGRULANAMADI` üretir.

## 5) Operasyonel kanıt ne yapabilir / yapamaz

Yapabilir:

- katalog satırını **desteklemek**
- çelişki göstermek
- manuel inceleme başlatmak

Yapamaz:

- `DOGRULANMIS_TAM` üretmek
- mevzuat manifestinin yerine geçmek
- 22–29 gibi üçüncü taraf listelerini resmi kılmak
- import/approve yazmayı açmak

## 6) SGK güncel resmî PDF / genelge talebi

Mali müşavirden ayrıca şunu isteyin:

> “SGK / Resmî Gazete / e-Bildirge birincil kaynaklarından, yürürlük tarihli güncel eksik gün nedeni kod listesi ve varsa kod×belge / birleşik neden matrisini içeren güncel tebliğ, genelge veya EK PDF’lerini paylaşın.”

Üçüncü taraf blog / danışman tabloları kabul edilmez.

## 7) Mali müşavir imza / onay alanı

```text
Kurum / mali müşavir: _______________________________
Ad Soyad: ____________________________________________
İmza: _________________  Tarih: ____ / ____ / ________
Beyan: Yukarıdaki dosyalar e-Bildirge/e-Beyanname veya SGK yazışmasından alınmış operasyonel doğrulama kanıtıdır; mevzuat authority iddiası taşımamaktadır.
```

## 8) Tamlık kapısı notu

`SGK_KATALOG_TAMLIK_KANITI_EKSIK` açıkken:

- katalog seed yapılmaz
- `DOGRULANMIS_TAM` seçilmez
- production import/approve yazılmaz

Bu talep paketi yalnızca kanıt toplama standardıdır.
