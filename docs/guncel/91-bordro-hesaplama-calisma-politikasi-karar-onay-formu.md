# 91 — Bordro Hesaplama Çalışma Politikası Karar ve Onay Formu

> Bu form `S77-D1-R2` fazındaki `S77_D1_R2_COMPANY_POLICY_BLOCKED` blokajını çözecek resmi karar kaydının şablonudur.
> Form onaylanmadan `NORMAL_AY_GUN_SAYISI`, `GUNLUK_CALISMA_SAATI`, `AYLIK_NORMAL_CALISMA_SAATI`, `HAFTALIK_IS_GUNU_SAYISI`, `HAFTA_TATILI_HESAP_MODU`, `HAFTA_TATILI_CARPANI`, `UBGT_HESAP_MODU`, `UBGT_CARPANI` ve `TATIL_FSC_FM_CAKISMA_HESAP_MODU` parametreleri production sistemine girilmez.

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

## 5. HT/UBGT ile FSC/FM çakışma politikası

Zorunlu sistem kodu: `TATIL_FSC_FM_CAKISMA_HESAP_MODU`

Bu parametrenin varsayılan değeri yoktur. Yetkili hukuk görüşü ve şirket onayı tamamlanana kadar HT/UBGT çalışması haftalık FSC/FM bandıyla çakışırsa sistem `HOLIDAY_OVERTIME_POLICY_REQUIRED` hatası ve `TATIL_FSC_FM_CAKISMA_POLITIKASI_EKSIK` readiness blocker'ı üretir; bordro adayı oluşturmaz.

### Hukuk onayına sunulacak aday: `YARGITAY_7_5_SAAT_AYRIMI`

- HT/UBGT günündeki ilk `450` dakika yalnız tatil çalışması hesabında değerlendirilir.
- Yalnız `450` dakikayı aşan günlük tatil çalışması fazla çalışma adayıdır.
- Tam süreli/kısmi süreli çalışma, TİS veya özel sözleşme, günlük çalışma süresi ve HT ile UBGT'nin aynı güne gelmesi ayrıca değerlendirilmelidir.
- Bu aday henüz authoritative production modu değildir; resmî karar veri tabanı üzerinden kurum hukukçusu doğrulaması ve yetkili şirket politika onayı beklemektedir.
- `TABAN_MAHSUBU` ve `AYRI_HAKLAR` doğrulanmış seçenek değildir ve production hesap modu olarak kullanılamaz.

Hukuk görüşü / karar numarası ve tarihi:

---

Yetkili şirket kararı:

---

Onaylanan `TATIL_FSC_FM_CAKISMA_HESAP_MODU` değeri: ______________________________

## 6. Kullanılan dayanaklar

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

## 7. Karar özeti

| Parametre | Kesin Değer |
| --- | ---: |
| `NORMAL_AY_GUN_SAYISI` | |
| `GUNLUK_CALISMA_SAATI` | |
| `AYLIK_NORMAL_CALISMA_SAATI` | |
| `HAFTALIK_IS_GUNU_SAYISI` | |
| `HAFTA_TATILI_HESAP_MODU` | |
| `HAFTA_TATILI_CARPANI` | |
| `UBGT_HESAP_MODU` | |
| `UBGT_CARPANI` | |
| `TATIL_FSC_FM_CAKISMA_HESAP_MODU` | |

Karar tarihi: ____ / ____ / 2026

Geçerlilik başlangıcı: ____ / ____ / 2026

Geçerlilik bitişi: ____ / ____ / ______ veya süresiz

## 8. Onaylar

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

## 9. Sistem kayıt notu

Bu form onaylanmadan ilgili şirket parametreleri production sistemine girilmeyecek ve gerçek maaş hesaplama adayı oluşturulmayacaktır.

---

## S87 karar kaydı (2026-07-22)

**Onaylanan `TATIL_FSC_FM_CAKISMA_HESAP_MODU`:** `YARGITAY_7_5_SAAT_AYRIMI`

**Özet kurallar:**
- Tam gün HT/UBGT fiili çalışmasında ilk 7,5 saat (450 dk) tatil primi havuzunda kalır; FSC/FM değerlendirme havuzuna yalnızca 450 dk aşımı girer.
- HT ve UBGT aynı günde çakışırsa tatil primi HT esas alınır; haftalık asım tek kez sayılır.
- Mod onaylıyken tam gün HT/UBGT + FSC/FM çakışması aday üretimini bloke etmez.
- Mod yok veya geçersizse mevcut fail-closed davranış korunur (tatil + FSC/FM → hesap durur).

**Yarım gün UBGT:** `ONAY_BEKLIYOR` — tatil dönemi net overlap ve yetkili hesap politikası eksik; `YARIM_GUN` + net>0 satırlar fail-closed (`HALF_DAY_UBGT_POLICY_REQUIRED`). Interval karşılaştırma / 0,5–1 yevmiye / 450 dk ayrımı uygulanmaz.

**S87-B (2026-07-22) UBGT gün kapsamı fail-closed:**
- Canonical owner: `resolveUbgtGunKapsami` → yalnız `TAM_GUN` | `YARIM_GUN` | `BILINMIYOR` (`ubgt_gun_kapsami` / `tatil_gun_kapsami`).
- Bilinmeyen kapsam + yalnız UBGT + net>0 → `UBGT_DAY_SCOPE_REQUIRED` (YARGITAY bu blocker’ı açmaz).
- Tam gün algoritması yalnız açık `TAM_GUN` işaretinde.
- HT+UBGT aynı gün: `HAFTA_TATILI_ESAS` korunur; eksik UBGT kapsamı ikinci ödeme doğurmaz.

**S88 (devam):** Canonical `resmi_tatil_takvimi` owner + migration 039 + puantaj/mühür projection. YARIM_GUN ödeme politikası kapalı kalır; tatil dönemi net dakika için güvenilir çoklu interval owner yoksa `TATIL_DONEMI_CALISMA_INTERVALI_EKSIK`. Production seed/policy write/merge/deploy bu fazda yok.

**Production notu:** Bu kayıt yalnızca karar dokümantasyonudur; production policy yazımı yapılmamıştır.

## S91-C2 karar kaydı (2026-07-23)

**Onaylayan:** İlker (Medisa ürün kararı)

**Kapsam:** `YARGITAY_7_5_SAAT_AYRIMI` onaylı modunun canonical bordro anlamı. Hukuk yorumu iddiası değildir; şirket ürün kararıdır.

**FSC/FM değerlendirme havuzu:**
`NORMAL_IS_GUNLERI_NET_DAKIKA + HT_450_DAKIKA_ASIMI + TAM_GUN_UBGT_450_DAKIKA_ASIMI`

**HT/UBGT premium mahsup:**
- İlk 450 dakika HT/UBGT ödeme kaleminde kalır.
- 450 dakikayı aşan bölüm HT/UBGT ödeme kaleminden düşülür.
- Aşan bölüm yalnız FSC/FM değerlendirme havuzuna girer.
- Aynı dakika HT/UBGT ve FSC/FM kalemlerinde çift ücretlendirilmez.

**Sözleşme haftalık süre:**
- `raw = GUNLUK_CALISMA_SAATI × HAFTALIK_IS_GUNU_SAYISI`
- `raw > 2700` → fail-closed (`CONTRACT_WEEKLY_MINUTES_EXCEEDS_LEGAL_LIMIT` / `SOZLESME_HAFTALIK_DAKIKA_YASAL_LIMIT_ASIMI`)
- 2700 kabul; 2701+ reddedilir. Sessiz clamp / otomatik düzeltme / warning ile devam yoktur.

**Engine version:** `S91C2_PAYROLL_ENGINE_V2` (contract version değişmedi).

## S91-D1 — Production Şirket Çalışma Politikası Nihai Değer Onayı

- **Karar tarihi:** `2026-07-23`
- **Karar sahibi:** İlker
- **Geçerlilik başlangıcı:** `2026-08-01`
- **Geçerlilik bitişi:** `null` (açık uçlu)
- **Nitelik:** Medisa şirket ürünü / bordro hesaplama politikasıdır. Bu kayıtta bağımsız hukuk görüşü üretilmemiştir.

### Onaylanan 11 zorunlu parametre

| Parametre | Kesin değer |
| --- | --- |
| `NORMAL_AY_GUN_SAYISI` | `30` |
| `GUNLUK_CALISMA_SAATI` | `7.5` |
| `AYLIK_NORMAL_CALISMA_SAATI` | `225` |
| `HAFTALIK_IS_GUNU_SAYISI` | `6` |
| `HAFTA_TATILI_HESAP_MODU` | `GUNLUK_ILAVE` |
| `HAFTA_TATILI_CARPANI` | `1.5` |
| `FAZLA_MESAI_CARPANI` | `1.5` |
| `FAZLA_SURELERLE_CALISMA_CARPANI` | `1.25` |
| `UBGT_CARPANI` | `1.0` |
| `UBGT_HESAP_MODU` | `GUNLUK_ILAVE` |
| `TATIL_FSC_FM_CAKISMA_HESAP_MODU` | `YARGITAY_7_5_SAAT_AYRIMI` |

### Haftalık sözleşme

```text
7.5 saat × 6 gün = 45 saat
450 dakika × 6 gün = 2700 dakika = SOZLESME_HAFTALIK_DAKIKA
```

- `2700` kabul edilir; `2701+` fail-closed.
- Bu set ile FSC sözleşme bandı kapasitesi `0` dakikadır.
- Değerlendirme havuzunun `2700` dakikayı aşan bölümü FM olur.

### Hafta tatili ödeme semantiği

```text
HAFTA_TATILI_HESAP_MODU = GUNLUK_ILAVE
HAFTA_TATILI_CARPANI = 1.5
```

- İlk `450` dakikalık hafta tatili çalışması için günlük ücretin `1.5` katı ilave ödeme oluşur.
- `450` dakikayı aşan süre hafta tatili premium’una girmez; FSC/FM değerlendirme havuzuna girer.
- Aynı dakika HT ve FSC/FM kalemlerinde çift ücretlendirilmez.

### UBGT / resmî tatil ödeme semantiği

```text
UBGT_HESAP_MODU = GUNLUK_ILAVE
UBGT_CARPANI = 1.0
```

- İlk `450` dakikalık tam gün UBGT çalışması için günlük ücretin `1.0` katı ilave ödeme oluşur.
- `450` dakikayı aşan süre UBGT premium’una girmez; FSC/FM değerlendirme havuzuna girer.
- Aynı dakika UBGT ve FSC/FM kalemlerinde çift ücretlendirilmez.

### Canonical FSC/FM havuz formülü (korunan)

```text
HAVUZ =
  NORMAL_IS_GUNLERI_NET_DAKIKA
  + HT_450_DAKIKA_ASIMI
  + TAM_GUN_UBGT_450_DAKIKA_ASIMI

FSC = MAX(0, MIN(HAVUZ, 2700) - SOZLESME_HAFTALIK_DAKIKA)
FM  = MAX(0, HAVUZ - 2700)
```

Bu politika setinde:

```text
SOZLESME_HAFTALIK_DAKIKA = 2700
FSC = 0
FM  = MAX(0, HAVUZ - 2700)
```

`450` üstü doğrudan ayrı bir “FM algoritması” değildir: `450` üstü → FSC/FM havuzu; havuzun `2700` üstü → FM.

### Bu kararın kapsamı dışında kalanlar

- Yarım gün UBGT ödeme politikası bu kararla çözülmemiştir (`YARIM_GUN_UBGT_HESAP_POLITIKASI_EKSIK` / `HALF_DAY_UBGT_POLICY_REQUIRED` ayrı blocker olarak kalır).
- UBGT gün kapsamı bilinmeyen satırlar (`UBGT_GUN_KAPSAMI_EKSIK`) ayrı blocker olarak kalır.
- Mevzuat parametre seti, SGK snapshot/katalog, personel bordro devri ve dönem mühür/onay eksikleri bu kararla kapanmış sayılmaz.

### Production write notu

Bu kayıt nihai değer onayını belgeler. S91-D1 koşusunda production’a politika taslağı/onayı yazılmamıştır. Exact create-draft payload hazırdır; production write ayrı açık onay gerektirir.
