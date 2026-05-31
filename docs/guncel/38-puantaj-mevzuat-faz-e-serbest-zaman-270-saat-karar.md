# 38. Puantaj Mevzuat Faz E — Serbest Zaman ve 270 Saat Karar Dokümanı

**Sürüm:** Faz E karar (kod fazı değil — bilinçli erteleme)  
**Ön koşul / karar zemini:** Faz E teşhis raporu, `docs/guncel/32-puantaj-mevzuat-uyum-review.md` (Faz E)  
**Kapalı üst fazlar:** Faz A — `docs/guncel/33-puantaj-mevzuat-faz-b-kapanis-checkpoint.md` (UBGT çakışması); Faz B/C/D checkpoint belgeleri

---

## 1. Ön koşul

- Faz A, B, C ve D kapanış checkpoint’leri geçerlidir.
- `docs/guncel/32-puantaj-mevzuat-uyum-review.md` Faz E karar zemini geçerlidir; serbest zaman workflow ve 270 saat yıllık takip ayrı teknik faz olarak işaretlenmiştir.
- `docs/guncel/04-hesap-motoru-kurallari.md` §6.5 (270 saat), §7 (serbest zaman) ve §17 (manuel inceleme) hedef davranışı tanımlar; kod henüz bu alanları kapsamaz.
- `docs/guncel/37-puantaj-mevzuat-faz-d1-fazla-calisma-onayi-karar.md` geçerlidir; “veri yoksa false positive üretme” ilkesi Faz E için de bağlayıcıdır.
- Faz E teşhis raporu tamamlanmıştır: haftalık fazla çalışma mevcut; yıllık toplam, kapanış snapshot’ı, range API, serbest zaman event/bakiye modeli ve `odeme_tipi` alanı yoktur.

---

## 2. Karar özeti

**Faz E kapsamında yıllık 270 saat fazla çalışma takibi ve serbest zaman workflow bu aşamada kodlanmayacaktır.**

**Sebep:** Mevcut sistemde güvenilir yıllık fazla çalışma toplamı, kapanmış hafta snapshot’ı, range API, serbest zaman tercih alanı, hak/kullanım event modeli ve bakiye modeli bulunmamaktadır. Bu eksikler giderilmeden uyarı veya hak üretimi yapılması yanlış compliance alarmı ve operasyonel güven kaybı oluşturur.

Bu belge implementasyon dokümanı değildir. Kod, test, UI ve API değişikliği bu karar kapsamında açılmaz.

---

## 3. Mevcut durum

| Alan | Durum |
|------|--------|
| Haftalık fazla çalışma hesabı | Var — `hesaplaHaftalikCalismaOzeti`, hook haftalık özet kartı |
| Günlük puantaj + haftalık ön izleme | Var — `GunlukPuantajPage`, `usePuantaj` cache birleştirme |
| Yıllık toplam fazla çalışma | **Yok** |
| 270 saat takibi | **Yok** |
| Serbest zaman tercihi | **Yok** |
| `odeme_tipi` | **Yok** |
| Serbest zaman hak / kullanım / bakiye modeli | **Yok** |
| Süreç/event tarafında serbest zaman türleri | **Yok** |
| Rapor / personel kartında yıllık FM veya serbest zaman | **Yok** |

Detay:

- Haftalık fazla çalışma, 45 saat (2700 dk) eşiği üzerinden motor katmanında hesaplanır; hook yalnız önbellekte bulunan günleri toplar.
- API yalnız tekil günlük puantaj okur (`GET /gunluk-puantaj/{personelId}/{tarih}`); yıllık veya tarih aralığı endpoint’i yoktur.
- Haftalık kapanış API’si (`POST /haftalik-kapanis`) mevcut olsa da fazla çalışma dakikasını taşıyan güvenilir snapshot sözleşmesi tanımlı değildir.
- `src/types/personel.ts` ve `src/types/surec.ts` içinde serbest zaman veya `odeme_tipi` alanı yoktur.
- `docs/guncel/04-hesap-motoru-kurallari.md` hedefleri ile kod arasında bilinçli boşluk vardır; Faz E bu boşluğu veri altyapısı gelmeden kapatmayacaktır.

---

## 4. 270 saat neden kodlanmadı?

| Eksik | Risk |
|-------|------|
| Yıl içi tüm hafta/gün verisine güvenilir erişim yok | Toplam eksik hesaplanır → **false negative** |
| Hook cache’i yalnız ziyaret edilen günleri bilir | Kullanıcı tüm yılı gezmeden yıllık toplam üretilemez |
| API tekil günlük okur; yıllık/range endpoint yok | Prod ortamda güvenilir aggregate kaynağı yok |
| Haftalık kapanış snapshot’ı FM toplamı için güvenilir kaynak değil | Kapanmış hafta verisi yıllık hesaba bağlanamaz |
| Eksik veri ile yıllık toplam hesaplamak | Limit altında görünür; mevzuat ihlali kaçırılır |
| Yaklaşma eşiği (240 / 250 / 260 saat) ürün kararı gerektirir | Kodlanmadan eşik sabitlenemez |
| Yıllık dönem tanımı net değil (takvim yılı vs işe giriş yıl dönümü) | Aynı veri farklı dönemlerde farklı sonuç üretir |

Bu koşullarda önerilen `YILLIK_FAZLA_CALISMA_270_SAAT_YAKLASIYOR` ve `YILLIK_FAZLA_CALISMA_270_SAAT_ASILDI` uyarıları ya **yanlış güven** (eksik veriyle düşük toplam) ya da **yanlış alarm** (cache tamamlanmadan yüksek toplam) üretir. Faz D1/D2/D3’te benimsenen “veri yoksa veya güvenilmezse uyarı üretme” ilkesiyle uyum için Faz E 270 saat compliance kodu bu aşamada açılmaz.

---

## 5. Serbest zaman neden kodlanmadı?

| Eksik | Risk |
|-------|------|
| `odeme_tipi` alanı yok | Ücret mi serbest zaman mı tercih edildiği bilinemez |
| Ücret yerine serbest zaman tercihi kaydedilemiyor | Hak oluşumu dayanağı yok |
| Hak oluşum event’i yok | FM → serbest zaman dönüşümü sistemde izlenemez |
| Kullanım event’i yok | Bakiye düşümü yapılamaz |
| Bakiye modeli yok | Kalan hak, son kullanım tarihi hesaplanamaz |
| 6 ay kullanım süresi takibi yok | Mevzuat süresi aşımı tespit edilemez |
| Personel kartı / rapor görünürlüğü yok | Operasyonel kullanım mümkün değil |
| API persist ve süreç formu yok | Workflow tamamlanamaz |

Bu haliyle “serbest zaman hakkı oluşur” uyarısı veya helper çıktısı **yanıltıcı** olur: sistem tercih kaydetmeden hak üretmiş gibi görünür; bordro ve İK süreçleri ile çelişir. Serbest zaman, yalnız puantaj motoru helper’ı ile (E2) kısmen modellenebilir; ancak workflow ve bakiye (E3) olmadan ürün değeri taşımaz ve güvenilir değildir.

---

## 6. Gelecekte 270 saat için minimum veri ihtiyacı

Ürün / İK / teknik karar ile netleştirilmesi gereken asgari alanlar ve kaynaklar:

| Öğe | Amaç |
|-----|------|
| Personel bazlı yıllık fazla çalışma toplamı | 270 saat limitine karşı kümülatif izleme |
| Kapanmış hafta snapshot’larında `fazla_calisma_dakika` | Haftalık FM’nin güvenilir, mühürlü kaynağı |
| Yıl / range bazlı puantaj veya kapanış aggregate endpoint’i | Client cache bağımlılığını kaldırma |
| Eksik hafta / eksik veri bilgisi | False negative önleme; “hesaplanamadı” durumu |
| Yıl dönemi tanımı | Takvim yılı veya işe giriş bazlı dönem |
| Yaklaşma eşiği kararı | Örn. 240 / 250 / 260 saat — ürün parametresi |
| `YILLIK_FAZLA_CALISMA_270_SAAT_YAKLASIYOR` | Limit altında yaklaşma compliance kodu |
| `YILLIK_FAZLA_CALISMA_270_SAAT_ASILDI` | Limit aşımı compliance kodu |

**Kritik:** Yıllık toplam yalnızca **tam ve güvenilir** hafta/gün verisi varken üretilmelidir; eksik hafta varsa uyarı yerine “yetersiz veri” veya uyarı üretmeme tercih edilmelidir (D1 ilkesi).

---

## 7. Gelecekte serbest zaman için minimum veri ihtiyacı

| Öğe | Amaç |
|-----|------|
| `odeme_tipi = ucret \| serbest_zaman` | FM sonrası tercih kaydı |
| `SERBEST_ZAMAN_OLUSUM` event’i | Hak oluşumu (FM dönüşümü) |
| `SERBEST_ZAMAN_KULLANIM` event’i | Hak tüketimi (izin benzeri) |
| `hak_dakika` | Oluşan toplam serbest zaman |
| `kullanilan_dakika` | Kullanılan süre |
| `kalan_dakika` | Bakiye |
| `olusum_tarihi` | 6 ay süre başlangıcı |
| `son_kullanim_tarihi` | Kullanım veya süre aşımı takibi |
| 6 ay kullanım takibi | Mevzuat süre limiti uyarısı |
| Personel kartı bakiye görünürlüğü | Operasyonel erişim |
| Rapor görünürlüğü | Yönetim / İK özeti |
| API persist | Süreç kayıtlarının kalıcı saklanması |

**Kritik:** Hak oluşumu ile kullanım **ayrı event türleri** olarak modellenmelidir (izin bakiye pattern’i ile uyumlu). Tercih (`odeme_tipi`) kaydı olmadan hak oluşum event’i üretilmemelidir.

---

## 8. Gelecek implementasyon önerisi

### 8.1 Önce veri altyapısı

1. Haftalık kapanış snapshot’ı `fazla_calisma_dakika` (ve isteğe bağlı `fazla_surelerle_calisma_dakika`) alanını güvenilir taşımalıdır.
2. Yıllık aggregate endpoint veya servis kurulmalıdır (personel + yıl → toplam FM, eksik hafta sayısı).
3. Serbest zaman event türleri ve bakiye servisi tasarlanmalıdır (`izin-hesap-motoru` pattern’i referans alınabilir).

### 8.2 Sonra alt fazlar

| Alt faz | İçerik | Ön koşul |
|---------|--------|----------|
| **E1** | 270 saat yıllık fazla çalışma compliance | Yıllık aggregate + kapanış snapshot + dönem/eşik kararı |
| **E2** | Serbest zaman dönüşüm helper’ı (saf motor) | FM tipi ayrımı kararı (V1 tam süreli varsayımı) |
| **E3** | Serbest zaman hak / kullanım / bakiye workflow | `odeme_tipi`, event türleri, API persist, süreç formu |
| **E4** | Personel kartı ve rapor görünürlüğü | E1 ve/veya E3 çıktıları |

Ön koşul sırası: **(1) veri modeli + API/kapanış snapshot → (2) motor/hook compliance veya bakiye → (3) UI/rapor → (4) checkpoint**.

Compliance kodları (E1) D2 kalıbıyla üretilmelidir: motor saf `birlestir*` helper + hook merge; compliance API’ye yazılmaz (Faz A/B/D ile aynı persist sınırı).

---

## 9. Kapsam dışı (bu karar belgesi)

- Kod değişikliği
- Test değişikliği
- UI değişikliği
- API değişikliği
- Bordro / SGK
- Net maaş / matrah
- Banka ödeme dosyası
- Faz A / B / C / D davranış değişikliği
- Faz D1 fazla çalışma onayı (ayrı karar belgesi — `docs/guncel/37-*`)
- Fazla sürelerle çalışma %25 katmanı (V1 tam süreli varsayımı; ayrı faz)

---

## 10. Sonraki adım

**Faz E, veri modeli kararı ve API / kapanış snapshot kararı gelene kadar bekletilecektir.**

Teknik sıradaki en doğru iş (Faz E kodu açmadan):

| Seçenek | İçerik |
|---------|--------|
| **Haftalık kapanış snapshot sözleşmesini güçlendirmek** | `HaftalikKapanisSonuc` ve backend akışında personel/hafta bazlı `fazla_calisma_dakika` snapshot’ı; yıllık aggregate için temel kaynak |
| **Serbest zaman süreç/event modeli için ayrı ürün karar dokümanı** | `odeme_tipi`, event türleri, 6 ay politikası, bakiye görünürlüğü — E3 ön koşulu |

Faz E yeniden açıldığında önerilen sıra: **veri altyapısı → E1 → E3 → E2+E4** (E2 helper E3 öncesi yalnızca test/doküman amaçlı tutulabilir; UI’da hak gösterilmez).

**Belge durumu:** Faz E karar — kod bekliyor (yıllık aggregate + serbest zaman veri/workflow ön koşulu). Faz E üst fazı bilinçli erteleme ile bekletilmiş sayılır.
