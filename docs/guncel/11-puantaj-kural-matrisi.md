# Yoklama, Süreç ve Puantaj Kural Matrisi

**Sürüm:** V1 (ürün karar özeti)  
**İlişkili belgeler:** Teknik hesap motoru ayrıntıları için `docs/guncel/04-hesap-motoru-kurallari.md` ile uyum korunmalıdır. Tam gün eksik gün ve SGK prim günü detay matrisi için `docs/guncel/13-eksik-gun-sgk-prim-gunu-kural-matrisi.md` referans alınmalıdır. Bu belge (11), puantaj ve çalışma ücreti tarafında **ürün + operasyon kararlarının** özet tek kaynağıdır.

---

## 1. Belgenin amacı

- Konuşulup netleşen yoklama, süreç, puantaj ve ücret/kesinti kurallarının dağılmaması.
- Geliştirme, İK ve ürünün aynı tabloyu referans alması.
- Mevzuat dili ile **ürün dilini** karıştırmadan, sistemde neyin nasıl davranacağını netleştirmek.

**Not:** Bu belge uygulama kodu veya API tasarımı içermez.

---

## 2. Kapsam

**Dahil:**

- Yoklama / süreç / puantaj / ön izleme ayrımı.
- Ücret tipi kullanıcı dili ve aylık model varsayılanları.
- Temel ücret formülleri, eşikler, FM, UBGT, hafta tatili, devamsızlık, geç kalma/erken çıkma.
- Rapor / iş kazası ayrımı ve ödeme ilkeleri.
- 18 yaş altı blokları, serbest zaman, 07 puantaj sınırı.
- Roller, kontrol durumları, arşiv ve audit ilkeleri.
- Sonraki uygulama sırası (yüksek seviye).

**Hariç:**

- Ekran tasarımı, bileşen sözleşmesi, endpoint listesi.
- Tam bordro/muhasebe kapanış matematiği ve banka çıktı şeması (ayrı belge / parametre).

---

## 3. Temel kavramlar

### 3.1 Yoklama

| | |
|---|---|
| **Kural** | Ham olay girişidir; birim amiri girer. |
| **Sistem etkisi** | Puantaj ve ücret hesabına giden ham veri katmanının başlangıcıdır. |
| **Not** | Resmî sınıflandırma burada bitmez; süreç katmanına aktarılır. |

### 3.2 Süreç

| | |
|---|---|
| **Kural** | Resmî / idari kayıt katmanıdır; İK veya yönetim bağlar ve sınıflandırır. |
| **Sistem etkisi** | Yoklamanın hukuk ve politika çerçevesindeki “resmi” karşılığını üretir. |
| **Not** | Nihai kapanış ayrı yetki katmanında düşünülür (V1’de detay parametre değil). |

### 3.3 Puantaj

| | |
|---|---|
| **Kural** | Ücret ve çalışma etkisinin hesaplandığı katmandır. |
| **Sistem etkisi** | Kesinti, ek ödeme, FM, tatil etkileri burada parasal/çalışma sonucuna bağlanır. |
| **Not** | Brüt ücret üzerinden hesap prensibi geçerlidir (Bölüm 5). |

### 3.4 Ön izleme / kontrol

| | |
|---|---|
| **Kural** | Zorunlu onay kapısı değildir. |
| **Sistem etkisi** | Birim amirinin ön izlemeyi **kontrol etti / etmedi** durumu görünür olmalıdır. |
| **Not** | Şimdilik: operasyonel şeffaflık; ileride kapanış akışı genişletilebilir. |

---

## 4. Personel ve ücret modeli

| | |
|---|---|
| **Kural** | Ücret tipi kullanıcı yüzünde yalnızca **Aylık** ve **Günlük**; varsayılan **Aylık**. |
| **Sistem etkisi** | Form ve listelerde sade ürün dili; teknik id’ler ayrı tutulabilir. |
| **Not** | **Kısmi süreli çalışma:** şimdilik yok (V1). |

| | |
|---|---|
| **Kural** | Ana çalışma eşiği: **haftalık 45 saat**. |
| **Sistem etkisi** | FM ve süre denkleştirmelerinde referans eşik. |
| **Not** | İstisnai düzenlemeler ileride parametre olabilir. |

---

## 5. Temel ücret formülleri

Tüm fazla çalışma ve kesinti hesapları **brüt ücret** üzerinden.

| Alan | Formül | Sistem etkisi |
|------|--------|----------------|
| Aylık personel günlük ücret | Maaş ÷ **30** | Günlük yevmiye, UBGT ek yevmiye vb. |
| Aylık personel saatlik ücret | Maaş ÷ **225** | FM, geç kalma, erken çıkma kesintisi |
| FM çarpanı | Saatlik ücret × **1,5** | Haftalık 45 saat üstü |

**Not:** Günlük ücretli personelde yuvarlama veya özel anlaşma farkları **firma politikası / ileride parametre** alanıdır (Bölüm 17).

---

## 6. Hafta tatili kuralları

| | |
|---|---|
| **Kural** | Hafta tatili günü: **Pazar**. |
| **Sistem etkisi** | Takvim ve hak ediş motorunda sabit referans günü. |
| **Not** | |

| | |
|---|---|
| **Kural** | Hafta tatili hak edişi, haftalık çalışma koşulu sağlanırsa doğar. |
| **Sistem etkisi** | Koşul sağlanmazsa tatil günü “hak” mantığında düşünülür. |
| **Not** | |

| | |
|---|---|
| **Kural** | Mazeretsiz devamsızlık varsa hafta tatili hakkı kaybı **kontrol edilir**. |
| **Sistem etkisi** | Çalışılmayan gün etkisi **+** hak kaybı varsa **ek etki**; sabit “2 gün kes” gibi genel kural yazılmaz. |
| **Not** | Detaylı SGK kod eşlemesi puantaj motoru belgesinde (04) genişletilebilir. |

---

## 7. UBGT / resmi tatil kuralları

| | |
|---|---|
| **Kural** | Çalışmazsa: normal ücret korunur. |
| **Sistem etkisi** | Ek yevmiye üretilmez. |
| **Not** | |

| | |
|---|---|
| **Kural** | Çalışırsa: **+1 günlük** ekstra yevmiye; formül **günlük ücret × 1**. |
| **Sistem etkisi** | Ana model budur; aylık personelde günlük ücret Bölüm 5’e göre. |
| **Not** | İstisnai sektör/anlaşma farkları Bölüm 17. |

---

## 8. Geç kalma / erken çıkma kuralları

| | |
|---|---|
| **Kural** | Kesinti: eksik çalışılan süre kadar; **saatlik ücret × eksik süre**. |
| **Sistem etkisi** | Geç kalma ve erken çıkma aynı matematikle süre bazlı kesilir. |
| **Not** | Haberli / habersiz ayrımı **ücrette değil**; süreç / disiplin katmanında yaşar. |

| | |
|---|---|
| **Kural** | Gerçek eksik süre korunur; parasal kesinti doğrudan gerçek dakika üzerinden değil, **kesintiye_esas_dakika** üzerinden hesaplanır. |
| **Sistem etkisi** | Geç / erken kayıtlarında iki alan ayrışır: **gercek_eksik_dakika** fiili farkı taşır, **kesintiye_esas_dakika** parasal hesapta kullanılır. |
| **Not** | Yuvarlama kuralı: `kesintiye_esas_dakika = Math.ceil(gercek_eksik_dakika / 30) * 30`. `gercek_eksik_dakika = 0` ise kesinti yoktur. |

| | |
|---|---|
| **Kural** | `beklenen_giris_saati` ve `beklenen_cikis_saati`, günlük puantaj kaydındaki opsiyonel snapshot alanlarıdır. |
| **Sistem etkisi** | Beklenen giriş ve beklenen çıkış ana detay kartında readonly görünür; kullanıcıya hesap girdisi olarak değil, kayıt snapshot'ı olarak sunulur. |
| **Not** | V1 bu alanlar üzerinden vardiya / çalışma planı modeli açmaz. |

### 8.1 Kesintiye Esas Süre Tablosu

| Gerçek eksik süre | Kesintiye esas süre |
|---|---|
| `0 dk` | `0 dk` |
| `1-30 dk` | `30 dk` |
| `31-60 dk` | `60 dk` |
| `61-90 dk` | `90 dk` |

**Uygulama notu:** Bu kural tolerans değildir. Geç / erken durum hesaplanamazsa parasal kesinti gösterilmez; yanlış kesin tutar üretilmez.

### 8.2 Güvenli hesap ve katman prensibi

Parasal özet yalnızca beklenen ve fiili saatler birlikte güvenli okunabiliyorsa üretilir.

- Beklenen giriş yoksa parasal özet üretilmez.
- Beklenen çıkış yoksa parasal özet üretilmez.
- Gerçek giriş yoksa parasal özet üretilmez.
- Gerçek çıkış yoksa parasal özet üretilmez.
- Geçersiz saat formatında parasal tutar üretilmez.
- Eksik dakika `0` ise kart şişirilmez.

Katman prensibi:

- İş kuralı servis katmanında kalır.
- Hook servis sonucunu view model'e taşır.
- Page / UI hesap yapmaz; sadece hook'tan gelen readonly veriyi render eder.

### 8.3 V1 doğrulama kapsamı

- `tests/unit/puantaj-hesap-motoru.test.ts` içinde güvenli durumlar ve 30 dk sınır değerleri kilitlendi.
- `tests/e2e/smoke.spec.ts` içinde 1 dk geç gelme senaryosunda UI seviyesinde şu değerler assert edildi:
  - `Gerçek Eksik Süre (dk) = 1`
  - `Kesintiye Esas Süre (dk) = 30`

| | |
|---|---|
| **Kural** | Tolerans: firma politikası; netleşmemişse **varsayılan sıfır tolerans** düşünülür. |
| **Sistem etkisi** | V1’de sıfır veya parametre; ürün kararı netleşince parametreleştirilir. |
| **Not** | **Politika alanı** (Bölüm 17). |

---

## 9. Devamsızlık kuralları

| | |
|---|---|
| **Kural** | Mazeretsiz devamsızlık: çalışılmayan gün etkisi üretir; hafta tatili hakkını düşürebilir. |
| **Sistem etkisi** | Bölüm 6 ile birleşik değerlendirme; düz sabit “X gün kes” modeli kullanılmaz. |
| **Not** | SGK eksik gün nedeni kodu bu belgede kesinleştirilmez; kod eşleme tablosu ayrı ürün / bordro kararıyla netleşecektir. Tam gün eksik gün, rapor, ücretsiz izin, yıllık izin, resmi tatil ve SGK prim günü senaryo matrisi `docs/guncel/13-eksik-gun-sgk-prim-gunu-kural-matrisi.md` dosyasındadır. |

| | |
|---|---|
| **Kural** | Geç / erken dakika kesintisi ile tam gün devamsızlık / eksik gün hesabı ayrı tutulur. |
| **Sistem etkisi** | 30 dakikalık yukarı yuvarlama yalnızca geç / erken dakika kesintisi içindir; SGK prim günü hesabı tam gün eksik gün üzerinden yürür. |
| **Not** | Ay sonu prim günü formülü ve Şubat / 31 gün uyarıları detay belgede sabitlenmiştir. |

---

## 10. Rapor / iş kazası kuralları

| | |
|---|---|
| **Kural** | Rapor / istirahat SGK etkisi ile işveren ödeme politikası ayrı değerlendirilir. |
| **Sistem etkisi** | Türler sistemde **ayrı** tutulur; rapor tipi, süre, SGK etkisi ve işveren uygulaması `13-eksik-gun-sgk-prim-gunu-kural-matrisi.md` uyarınca alt karara ayrılır. |
| **Not** | İşveren ödeme politikası ve SGK eksik gün nedeni kodu bu belgede kesinleştirilmez; firma / ürün kararı ve bordro kontrolü gerekir. |

| | |
|---|---|
| **Kural** | Raporun ilk günleri, tamamlayıcı ödeme ve istisnalar firma / bordro politikasıdır. |
| **Sistem etkisi** | Kesin ödeme sonucu bu V2 karar fazında üretilmez; rapor tipi ve süreye göre manuel / ürün kararı bekler. |
| **Not** | **Politika alanı:** gün sayısı, ödeme tercihi ve istisnalar (Bölüm 17 ve `13-eksik-gun-sgk-prim-gunu-kural-matrisi.md`). |

---

## 11. 18 yaş altı kuralları

| | |
|---|---|
| **Kural** | Fazla mesai: **blok** (uyarı değil, işlem yapılmaz). |
| **Sistem etkisi** | Kayıt kabul edilmez veya hesap üretilmez. |
| **Not** | |

| | |
|---|---|
| **Kural** | Gece çalışması: **blok**; gece aralığı **20:00–06:00**. |
| **Sistem etkisi** | Aynı blok mantığı. |
| **Not** | V1: sabit aralık; esneklik ileride parametre olabilir (Bölüm 17). |

---

## 12. Fazla mesai kuralları

| | |
|---|---|
| **Kural** | Ana eşik: haftalık **45 saat** üstü. |
| **Sistem etkisi** | FM saatleri bu eşiğe göre ayrıştırılır. |
| **Not** | |

| | |
|---|---|
| **Kural** | Oran: saatlik ücret × **1,5**. |
| **Sistem etkisi** | Bölüm 5 ile tutarlı. |
| **Not** | |

| | |
|---|---|
| **Kural** | Yıllık üst sınır: **270 saat**; takip edilmeli; limite yaklaşınca **uyarı / onay ihtiyacı** doğmalıdır. |
| **Sistem etkisi** | Şimdilik: izleme + uyarı/onay ihtiyacı; tam onay zinciri sonraki faz. |
| **Not** | Onay matrisi Bölüm 17 ile bağlantılı. |

---

## 13. Serbest zaman kuralları

| | |
|---|---|
| **Kural** | Kullanım yalnızca **çalışan talep ederse**; işveren tek taraflı dayatamaz. |
| **Sistem etkisi** | Talep kaydı olmadan serbest zaman düşülmez. |
| **Not** | |

| | |
|---|---|
| **Kural** | Dönüşüm: **1 saat fazla mesai = 1,5 saat serbest zaman**. |
| **Sistem etkisi** | Bakiye birikimi bu oranla. |
| **Not** | |

| | |
|---|---|
| **Kural** | **6 ay** içinde kullanım takibi; bakiye ve son kullanım tarihi tutulmalıdır. |
| **Sistem etkisi** | Süre aşımında düşüm veya uyarı politikası firma ile netleşir. |
| **Not** | Aşım davranışı **politika alanı** (Bölüm 17). |

---

## 14. 07 puantaj kullanım sınırı

| | |
|---|---|
| **Kural** | **07 puantaj** ana model değildir. |
| **Sistem etkisi** | Varsayılan akış **aylık model** ve bu matristeki formüller. |
| **Not** | 07 kullanımı **istisnai / opsiyonel**; ürün önceliği düşük. |

---

## 15. Roller ve kontrol akışı

### 15.1 Roller

| Rol | Yetki özeti |
|-----|-------------|
| **Birim amiri** | Ham yoklama girer; ön izlemeyi kontrol etti / etmedi durumunu verir. |
| **İK / yönetim** | Resmî süreç sınıflandırması. |
| **Nihai kapanış** | Ayrı yetki katmanı; V1’de tam matris açık değil (Bölüm 17). |

### 15.2 Sistemde görünmesi gereken kontrol durumları

- **Bekliyor**
- **Birim Amiri Kontrol Etti**

**Sonraki faz (plan):**

- **Kapanışa Hazır**
- **Mühürlendi**

---

## 16. Arşiv / audit kuralları

| | |
|---|---|
| **Kural** | Puantaj, bordro, özlük, izin ve süreç kayıtları en az **10 yıl** saklanmalıdır. |
| **Sistem etkisi** | Saklama politikası ve erişim logları ürün gereksinimi. |
| **Not** | Dijital kayıt yeterlidir; mevzuat değişiminde süre güncellenir. |

| | |
|---|---|
| **Kural** | Audit: **kim** kontrol etti, **ne zaman**, **hangi veri** üzerinden. |
| **Sistem etkisi** | Ön izleme ve onay adımlarında iz kaydı zorunlu tutulmalıdır. |
| **Not** | Teknik şema `04` ve API belgeleri ile hizalanır. |

---

## 17. Açık olmayan veya firma politikasına bağlı alanlar

Aşağıdakiler **kesin firma politikası** veya **ileride parametreleştirilebilir** olarak işaretlenmiştir:

1. Geç kalma / erken çıkma **tolerans süreleri** (varsayılan: sıfır).
2. Günlük ücretlilerde **yuvarlama** ve özel anlaşma farkları.
3. **Kapanış / mühür** akışının son yetki matrisi ve onay zinciri.
4. Nihai **bordro etkisi** ve muhasebe kapanışı ayrıntıları.
5. Serbest zaman bakiyesinin **6 ay sonrası** davranışı (düşüm, iptal, uyarı).
6. FM **270 saat** limitinde onay seviyesi ve istisna prosedürü.
7. 18 yaş altı gece aralığının sektörel istisnaları (varsa, parametre).

---

## 18. Sonraki uygulama sırası (yüksek seviye)

1. Yaş blokları ve 18 yaş altı kurallarının kodlanması.  
2. Saatlik / günlük ücret ve kesinti motorunun kurulması (`04` ile uyum).  
3. UBGT, devamsızlık ve hafta tatili etkilerinin parasal motora bağlanması.  
4. Ön izleme / kontrol durumu ekranı ve audit alanlarının tasarlanması.  

**Şimdilik / V1:** Ön izleme zorunlu kapı değil; kontrol edildi bilgisi görünür olmalıdır.  
**Sonraki faz:** Kapanışa hazır, mühürlendi ve genişletilmiş onay.

---

## Belge geçmişi

| Tarih | Not |
|-------|-----|
| V1 | İlk konsolidasyon; kod değişikliği yok. |
| 2026-05-15 | Geç kalma / erken çıkma için 30 dakikalık yukarı yuvarlama ve `kesintiye_esas_dakika` kuralı eklendi. |
| 2026-05-15 | Geç / Erken Kesinti V1 kapanış davranışı, güvenli hesap prensibi, katman sınırı ve doğrulama kapsamı sabitlendi. |
| 2026-05-15 | Puantaj V2 eksik gün ve SGK prim günü detay matrisi için `13-eksik-gun-sgk-prim-gunu-kural-matrisi.md` referansı eklendi. |
