# 32. Puantaj Mevzuat Uyum Review

**Sürüm:** Karar dokümanı (kod fazı değil)  
**İlişkili belgeler:** `docs/guncel/04-hesap-motoru-kurallari.md`, `docs/guncel/11-puantaj-kural-matrisi.md`, `docs/guncel/07-is-akislari-ve-senaryolar.md`

---

## 1. Amaç ve Kapsam

Bu belge **kod fazı değildir**. Puantaj mevzuat değerlendirmesinde konuşulan başlıkları, kod veya hesap motoruna geçmeden önce tek yerde karar zemini olarak toplar.

**Amaç:**

- Hangi kuralların doğrudan **hesap motoru**na girebileceğini,
- Hangilerinin **firma / bordro politikası**na bırakılacağını,
- Hangilerinin **hukuk / bordro teyidi** gerektirdiğini,
- Hangilerinin bilinçli olarak **V1 kapsam dışı** bırakıldığını

net ayırmaktır.

Bu doküman mevcut kod davranışını hemen değiştirmez. Sonraki teknik fazlara (compliance uyarıları, parametreleşme, workflow) **karar girdisi** sağlar. Uygulama, test ve deploy bu belgenin dışında ayrı fazlarla açılır.

**Kapsam dışı (bu belge):** `src/`, `tests/`, hook/service/API değişikliği, CI/deploy, eski doküman revizyonu, hesap motoru patch’i, kısmi süreli çalışma guard’ı veya `COMPLIANCE_KISMI_SURELI_FM` benzeri uyarı üretimi.

---

## 2. Kesinleşmiş Mevzuat Motor Kuralları

Aşağıdaki maddeler, mevzuatın asgari çerçevesi ve ürün omurgasıyla uyumlu kabul edilen **hesap motoru / compliance** kurallarıdır. Kod fazında doğrudan veya kontrollü küçük adımlarla uygulanabilir.

| Konu | Karar |
|------|--------|
| Haftalık genel çalışma eşiği | **45 saat** |
| Fazla çalışma | Haftalık 45 saat üstü çalışma; **%50 zamlı** hesaplanır |
| Fazla sürelerle çalışma | Sözleşme haftalık süresi 45 saatin altındaysa, sözleşme süresi ile 45 saat arasındaki çalışma **%25 zamlı** değerlendirilir. **Mevcut V1 işletme varsayımı tam süreli personeldir;** bu katmanın teknik implementasyonu **ayrı faz** olarak ele alınacaktır |
| Günlük çalışma süresi | **11 saat** üzeri **compliance alert** üretir |
| Ara dinlenmesi | Minimum süreler hesap motoru kuralı olarak **korunur** |
| UBGT / resmi tatil | Çalışmazsa **tam ücret korunur**; çalışırsa **+1 günlük ek ödeme** kuralı geçerlidir |
| Serbest zaman — kapsam | Yalnızca **işçi talebiyle**; **fazla çalışma** ve **fazla sürelerle çalışma** karşılığı uygulanabilir |
| Serbest zaman — oranlar | Fazla çalışma: **1 saat = 1 saat 30 dakika**; fazla sürelerle çalışma: **1 saat = 1 saat 15 dakika** |
| Serbest zaman — süre | **6 ay** içinde kullandırılmalıdır |
| Telafi çalışması | **Fazla mesai değildir** |
| Denkleştirme vs telafi | **Ayrı kavramlar**; birbirinin yerine geçmez |
| 18 yaş altı fazla çalışma | **Blok** / yüksek severity **compliance** konusu |
| Yıllık izin | **Yaş ve kıdem** kuralları korunur |

---

## 3. Firma / Bordro Politikasına Bırakılacak Alanlar

Aşağıdaki konular mevzuat motorunun otomatik kesin kuralı değildir; firma, bordro veya operasyon politikasıyla kesinleşir.

| Konu | Karar |
|------|--------|
| Geç kalma toleransı | Tolerans **dakikaları firma politikasıdır** |
| Hastalık raporu — ilk 2 gün | İlk 2 günün işveren tarafından ödenip ödenmeyeceği **firma politikasıdır** |
| Hafta tatili çalışması | **+1,5 ek ödeme / toplam 2,5 yevmiye** anlatımı otomatik kesin motor kuralı değildir; **bordro / firma politikasıyla** kesinleşecek **ek ödeme adayı** olarak ele alınır |
| Prim, ceza, disiplin | Ayrı **politika ve süreç** kararı gerektirir |
| Fazla çalışma yazılı onayı | Nasıl toplanacağı ve hangi **workflow** ile izleneceği **ayrıca kararlaştırılacaktır** |
| 07 Puantaj / SGK eksik gün kod eşleşmesi | Mevzuat motoru kuralı **değildir**; **bordro / SGK kod politikası** olarak ayrıca teyit edilecektir (`docs/guncel/13-eksik-gun-sgk-prim-gunu-kural-matrisi.md`, `docs/guncel/14-sgk-eksik-gun-nedeni-esleme-tablosu.md` ile hizalanır) |

---

## 4. Hukuk / Bordro Teyidi Gerektiren Riskli Başlıklar

Bu başlıklarda sistem **sessiz kesin bordro sonucu** üretmemeli; teyit, manuel inceleme veya kapanışta kesinleşme modeli tercih edilir.

| Konu | Karar |
|------|--------|
| UBGT + haftalık 45 saat üstü | UBGT çalışması haftalık 45 saat üstüne çıkıyorsa **otomatik çift ödeme yapılmayacaktır** |
| UBGT + fazla mesai çakışması | `UBGT_FAZLA_MESAI_CAKISMASI` **manuel inceleme uyarısı** sonraki teknik fazda değerlendirilecektir |
| Mazeretsiz devamsızlık — dil | **“1 gün devamsızlık = otomatik 2 gün kesin kesinti”** dili **kullanılmayacaktır** |
| Mazeretsiz devamsızlık — model | **Devamsızlık ücret etkisi adayı** ve **hafta tatili hakkı kaybı adayı** olarak ele alınacaktır; kesin bordro etkisi **hafta / ay kapanışında** veya **yetkili kontrolünde** kesinleşmelidir |
| Hafta tatili günü — hukuk | Hafta tatili günü hukuken **Pazar olmak zorunda değildir** |
| Hafta tatili günü — V1 | **V1 varsayımı Pazar olabilir**; sonraki fazda personel / vardiya / şube bazlı **hafta tatili günü parametresi** planlanacaktır |
| Dijital onay / audit | Dijital onay, audit trail, zaman damgası ve Yargıtay karar referansı **resmi karar numarası olmadan** kesin **hukuki dayanak** yapılmayacaktır |
| 18 yaş altı gece çalışması | Ayrıntılar **ayrıca teyit** edilecektir |
| Gece çalışması 7,5 saat ve FM onayı | **7,5 saat** sınırı ve fazla çalışma onayı eksikliği **sonraki compliance fazlarında** değerlendirilecektir |

---

## 5. Bilinçli V1 Kapsam Dışı Kararı

**Kısmi süreli çalışma modeli V1 kapsam dışıdır.**

Gerekçe ve sınırlar:

- İşletmede kısmi süreli personel **bulunmadığı** için bu modele özel **UI alanı**, **hesap motoru kuralı**, **compliance guard**, **test** ve **bordro / SGK etkisi** geliştirilmeyecektir.
- **“V1 kapsam dışı; guard eklenmeli”** yaklaşımı bu proje için **uygulanmayacaktır** (`COMPLIANCE_KISMI_SURELI_FM` veya benzeri uyarı üretimi dahil).
- İleride kısmi süreli personel çalıştırılması gündeme gelirse: **ayrı karar dokümanı**, **ayrı mevzuat değerlendirmesi** ve **ayrı teknik faz** açılacaktır.

Fazla sürelerle çalışma (%25 katmanı) mevzuat dokümanında yer alır; V1 tam süreli varsayımı nedeniyle implementasyon **bu belgede açılmaz**.

---

## 6. Repo ile Bilinen Uyum / Risk Notları

| Not | Açıklama |
|-----|----------|
| Doküman omurgası | Mevcut doküman omurgası (`04`, `11`, `07` vb.) **genel olarak doğru** kabul edilir |
| Kod vs doküman | Kod davranışı bazı yerlerde doküman dilinden **daha kesin** çalışıyor olabilir; teknik fazlar **küçük ve kontrollü** açılacaktır |
| Pazar varsayımı | Pazar varsayımı kodda **hardcoded** olabilir; bu **V1 varsayımı** olarak kabul edilir, **parametreleşme borcu** olarak işaretlenir |
| Devamsızlık + hafta tatili | Kesin bordro kesintisi gibi değil; **aday / kapanışta kesinleşme** modeliyle hizalanmalıdır |
| UBGT + fazla mesai stacking | Sessiz stacking varsa **otomatik çift ödeme yapılmayacak**; manuel inceleme uyarısına çevrilmesi **sonraki teknik fazdır** |
| Hafta tatili çalışması +1,5 | Mevcutsa kesin bordro kuralı değil; **ek ödeme adayı / firma politikası parametresi** olarak yeniden değerlendirilecektir |
| Fazla sürelerle çalışma %25 | Dokümanda vardır; **kod durumu ayrı teknik fazda** incelenecektir |
| Serbest zaman workflow | Dokümanda vardır; **kod fazı ayrı** açılacaktır |
| 270 saat yıllık takip | Dokümanda hedef olarak vardır; **kod fazı ayrı** açılacaktır |

---

## 7. Sonraki Faz Önerileri

Kod yazılmadan yalnızca faz önerisi; öncelik sırası ürün ihtiyacına göre netleştirilebilir.

| Faz | İçerik |
|-----|--------|
| **Faz A** | UBGT + fazla mesai çakışması → **manuel inceleme uyarısı** (`UBGT_FAZLA_MESAI_CAKISMASI`) |
| **Faz B** | Mazeretsiz devamsızlık ve hafta tatili kaybı → **aday / kesinleşme** dili (otomatik kesin 2 gün kesinti yok) |
| **Faz C** | Hafta tatili günü **Pazar varsayımını** parametre hazırlığına çekme |
| **Faz D** | 18 yaş altı fazla çalışma, gece **7,5 saat**, fazla çalışma onayı → **compliance uyarıları** |
| **Faz E** | Serbest zaman **workflow** ve **270 saat** yıllık takip |

**Kısmi süreli çalışma için faz açılmayacaktır.** Yalnızca ileride ihtiyaç doğarsa ayrı kapsam olarak ele alınacaktır.

---

## 8. Karar Özeti (Tek Bakış)

```text
[Mevzuat motoru]     → 45s, FM %50, ara dinlenme, UBGT, serbest zaman oranları, 18↓ FM blok, yıllık izin yaş/kıdem
[Firma / bordro]     → tolerans, rapor 2 gün, HT +1.5, prim/ceza, FM onay workflow, SGK kod eşlemesi
[Hukuk / teyit]      → UBGT çift ödeme yok, çakışma manuel, devamsızlık aday, HT günü parametre, dijital onay
[V1 kapsam dışı]     → kısmi süreli (guard yok)
```

**Belge durumu:** Karar zemini — implementasyon bekliyor.
