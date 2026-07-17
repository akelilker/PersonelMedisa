# 91 — Bordro Hesaplama Çalışma Politikası Karar ve Onay Formu

> Bu form `S77-D1-R2` fazındaki `S77_D1_R2_COMPANY_POLICY_BLOCKED` blokajını çözecek resmi karar kaydının şablonudur.
> Form onaylanmadan `NORMAL_AY_GUN_SAYISI`, `GUNLUK_CALISMA_SAATI`, `AYLIK_NORMAL_CALISMA_SAATI`, `HAFTALIK_IS_GUNU_SAYISI`, `HAFTA_TATILI_HESAP_MODU`, `HAFTA_TATILI_CARPANI` parametreleri production sistemine girilmez.

## 1. Amaç

Bu form, Medisa Personel maaş hesaplama motorunda kullanılacak şirket çalışma süresi ve hafta tatili ödeme parametrelerinin gerçek şirket uygulamasıyla uyumlu şekilde belirlenmesi amacıyla hazırlanmıştır.

Bu karar yalnız teknik sistem ayarı değildir. Mevcut iş sözleşmeleri, vardiya düzeni, puantaj uygulaması, önceki bordrolar ve yürürlükteki mevzuat birlikte değerlendirilmelidir.

## 2. Kapsam

Karar aşağıdaki çalışan grupları için geçerlidir:

- [ ] Tüm çalışanlar
- [ ] Fabrika personeli
- [ ] Ofis personeli
- [ ] Şube personeli
- [ ] Şoförler
- [ ] Diğer: ______________________________

Farklı çalışan gruplarında farklı uygulama varsa her grup için ayrı karar formu hazırlanacaktır.

## 3. Çalışma süresi parametreleri

| Sistem Parametresi | Karar Verilecek Konu | Teknik Öneri | Onaylanan Değer |
| --- | --- | ---: | ---: |
| `NORMAL_AY_GUN_SAYISI` | Aylık ücretin günlük ücrete bölünmesinde kullanılacak gün sayısı | `30` | |
| `GUNLUK_CALISMA_SAATI` | Ara dinlenmeleri hariç günlük net çalışma süresi | `7.5` yalnız gerçek düzen buysa | |
| `AYLIK_NORMAL_CALISMA_SAATI` | Saatlik ücret hesabında kullanılacak aylık bölen | `225` yalnız bordro uygulaması buysa | |
| `HAFTALIK_IS_GUNU_SAYISI` | Çalışanın normal haftalık iş günü sayısı | Gerçek düzen: `5` veya `6` | |

### Çalışma düzeni

Normal çalışma günleri:

- [ ] Pazartesi–Cuma
- [ ] Pazartesi–Cumartesi
- [ ] Vardiyalı
- [ ] Diğer: ______________________________

Günlük çalışma başlangıcı: __________

Günlük çalışma bitişi: __________

Toplam ara dinlenme: __________ dakika

Net günlük çalışma: __________ saat / __________ dakika

Normal haftalık çalışma: __________ saat

Saatlik ücret böleni: __________ saat/ay

## 4. Hafta tatili çalışma politikası

Sistem aşağıdaki üç teknik hesap modunu desteklemektedir.

### A. `GUNLUK_ILAVE`

Hafta tatilinde çalışma gerçekleştiğinde aylık ücretin içindeki normal ücretin üzerine ilave günlük ücret eklenir.

- [ ] Bu yöntem uygulanıyor.
- İlave günlük ücret katsayısı: __________

### B. `SAAT_CARPAN`

Yalnız çalışılan dakika/saat üzerinden saatlik ücret ve katsayı uygulanır.

- [ ] Bu yöntem uygulanıyor.
- Saat katsayısı: __________
- Hukuki/muhasebesel dayanak: ______________________________

### C. `GUNLUK_ILAVE_VE_SAAT_CARPAN`

İlave günlük ücret ile saat bazlı ödeme birlikte uygulanır.

- [ ] Bu yöntem uygulanıyor.
- Günlük katsayı: __________
- Saat katsayısı: __________
- Birlikte uygulanmasının dayanağı: ______________________________

### Onaylanan sistem değerleri

| Sistem Parametresi | Onaylanan Değer |
| --- | --- |
| `HAFTA_TATILI_HESAP_MODU` | |
| `HAFTA_TATILI_CARPANI` | |

Seçilen yöntemin aynı çalışma için mükerrer ödeme veya eksik ödeme üretmediği Muhasebe ve İK tarafından kontrol edilmiştir:

- [ ] Evet
- [ ] Hayır

## 5. Kullanılan dayanaklar

- [ ] İş sözleşmeleri
- [ ] Vardiya çizelgeleri
- [ ] Puantaj kayıtları
- [ ] Önceki bordrolar
- [ ] Muhasebe bordro programı
- [ ] Fazla mesai talimatı
- [ ] Toplu iş sözleşmesi
- [ ] Hukuk/İK danışmanı görüşü
- [ ] Diğer: ______________________________

İncelenen belgeler ve tarihleri:

---

---

## 6. Karar özeti

| Parametre | Kesin Değer |
| --- | ---: |
| `NORMAL_AY_GUN_SAYISI` | |
| `GUNLUK_CALISMA_SAATI` | |
| `AYLIK_NORMAL_CALISMA_SAATI` | |
| `HAFTALIK_IS_GUNU_SAYISI` | |
| `HAFTA_TATILI_HESAP_MODU` | |
| `HAFTA_TATILI_CARPANI` | |

Karar tarihi: ____ / ____ / 2026

Geçerlilik başlangıcı: ____ / ____ / 2026

Geçerlilik bitişi: ____ / ____ / ______ veya süresiz

## 7. Onaylar

**Genel Yönetici**

Ad Soyad: ______________________________

İmza: ______________________________

Tarih: ____ / ____ / 2026

---

**Muhasebe Yetkilisi**

Ad Soyad: ______________________________

İmza: ______________________________

Tarih: ____ / ____ / 2026

---

**İK / İdari İşler Yetkilisi**

Ad Soyad: ______________________________

İmza: ______________________________

Tarih: ____ / ____ / 2026

## 8. Sistem kayıt notu

Bu form onaylanmadan ilgili şirket parametreleri production sistemine girilmeyecek ve gerçek maaş hesaplama adayı oluşturulmayacaktır.
