# 37. Puantaj Mevzuat Faz D1 — Fazla Çalışma Onayı Karar Dokümanı

**Sürüm:** Faz D1 karar (kod fazı değil — bilinçli erteleme)  
**Ön koşul / karar zemini:** Faz D teşhis raporu, `docs/guncel/32-puantaj-mevzuat-uyum-review.md` (Faz D)  
**Kapalı alt fazlar:** Faz D3 — `docs/guncel/35-puantaj-mevzuat-faz-d3-gece-75-kapanis-checkpoint.md`; Faz D2 — `docs/guncel/36-puantaj-mevzuat-faz-d2-18-yas-alti-fm-kapanis-checkpoint.md`

---

## 1. Ön koşul

- Faz D teşhis raporu tamamlanmıştır (18↓ FM, gece 7,5, FM onayı ayrıştırması).
- Faz D3 kapanış checkpoint geçerlidir — gece 7,5 saat compliance (`GECE_CALISMASI_7_5_SAAT_ASIMI`).
- Faz D2 kapanış checkpoint geçerlidir — 18 yaş altı haftalık fazla çalışma compliance (`ONSEKIZ_YAS_ALTI_FAZLA_CALISMA`).
- `docs/guncel/32-puantaj-mevzuat-uyum-review.md` Faz D karar zemini geçerlidir; FM yazılı onayı firma/workflow alanı olarak işaretlenmiştir.

---

## 2. Karar özeti

**D1 kapsamında fazla çalışma onayı eksikliği için otomatik compliance uyarısı bu aşamada kodlanmayacaktır.**

**Sebep:** Personel kartında veya API/veri modelinde güvenilir yazılı fazla çalışma onayı alanı bulunmamaktadır. Sistem **“onay yok”** ile **“onay bilgisi henüz sisteme girilmemiş”** durumunu ayırt edemez. Bu ayrım yapılmadan uyarı üretmek yanlış compliance alarmı ve operasyonel güven kaybı oluşturur.

Bu belge implementasyon dokümanı değildir. Kod, test, UI ve API değişikliği bu karar kapsamında açılmaz.

---

## 3. Mevcut durum

| Alan | Durum |
|------|--------|
| Haftalık fazla çalışma hesabı | Var — `hesaplaHaftalikCalismaOzeti`, hook haftalık özet kartı |
| 18↓ haftalık FM uyarısı (D2) | Kapalı — `ONSEKIZ_YAS_ALTI_FAZLA_CALISMA` |
| Gece 7,5 saat uyarısı (D3) | Kapalı — `GECE_CALISMASI_7_5_SAAT_ASIMI` |
| Fazla çalışma onayı verisi | **Yok** |

Detay:

- `src/types/personel.ts` içinde `fazla_calisma_onayi_var_mi` veya eşdeğeri alan **yok**.
- Personel oluşturma/düzenleme formunda onay alanı **yok**.
- API persist / okuma kontratı **yok**.
- Onay belgesi veya workflow modeli **yok**.
- `docs/guncel/04-hesap-motoru-kurallari.md` §6.6: alan “sonraki faz için düşünülür”; ilk sürümde veri yoksa uyarı üretilir denmiş olsa da, **ürün kararı** veri olmadan false positive kabul edilmemesini gerektirir.

---

## 4. Neden kodlanmadı?

| Eksik | Risk |
|-------|------|
| Onay verisi yok | Her FM’li personelde sürekli “eksik onay” uyarısı |
| Var / yok / bilinmiyor ayrımı yok | Yanlış alarm; kullanıcı uyarıya güvenmez |
| Personel bazlı onay tarihçesi yok | Geçerlilik ve yenileme denetlenemez |
| Dönemsel/yıllık geçerlilik modeli yok | Eski onay ile yeni FM çakışması tespit edilemez |
| Onay belgesi veya audit izi yok | Manuel teyit kanıtı sistemde tutulmaz |

Bu koşullarda önerilen `FAZLA_CALISMA_ONAYI_EKSIK` uyarısı **false positive** üretir; D2/D3’te benimsenen “veri yoksa uyarı üretme” ilkesiyle çelişir.

---

## 5. Gelecekte kodlanması için minimum veri ihtiyacı

Ürün / özlük / İK kararı ile netleştirilmesi gereken asgari alanlar:

| Alan | Amaç |
|------|------|
| `personel.fazla_calisma_onayi_var_mi` | Boolean veya üç durumlu (var / yok / bilinmiyor) |
| `onay_tarihi` | Onayın ne zaman alındığı |
| `onay_gecerlilik_donemi` veya `yil` | Dönemsel geçerlilik |
| `onay_belgesi_var_mi` veya belge referansı | Kanıt / dosya bağlantısı |
| `onay_kaynagi` / audit bilgisi | Kim, ne zaman kaydetti |
| Onay iptal / yenileme state’i | Süresi dolmuş veya iptal edilmiş onay |

**Kritik:** Yalnızca boolean “onay var mı” yetmezse, **“bilinmiyor”** durumu açık modellenmelidir; aksi halde D1 yine false positive üretir.

---

## 6. Gelecek implementasyon önerisi

Veri modeli, personel formu ve API persist kararı alındıktan sonra teknik faz açılabilir:

| Öğe | Öneri |
|-----|--------|
| Compliance kodu | `FAZLA_CALISMA_ONAYI_EKSIK` |
| Tetik | `tam_hafta_verisi === true` **ve** `fazla_calisma_dakika > 0` **ve** personelde geçerli onay **yok** (bilinmiyor → uyarı üretme) |
| Seviye | `UYARI` |
| Mesaj | Haftalık fazla çalışma tespit edildi; yazılı fazla çalışma onayı kaydı bulunamadı. Bordro öncesi manuel teyit gerekir. |
| Üretim yeri | D2 kalıbı: motor saf `birlestirFazlaCalismaOnayiEksikUyari` + hook haftalık/personel merge |
| Persist | Compliance uyarıları API’ye yazılmaz (Faz A/B/D2/D3 ile aynı) |
| Test | Motor helper unit testleri; isteğe bağlı hook merge testi |

Ön koşul sırası: **(1) veri modeli + form/API → (2) motor/hook compliance → (3) checkpoint**.

---

## 7. Kapsam dışı (bu karar belgesi)

- Kod değişikliği
- Personel formu değişikliği
- API persist
- Bordro / SGK
- Serbest zaman / 270 saat (Faz E)
- D2 / D3 davranış değişikliği
- Faz A / B / C davranış değişikliği
- Mevcut checkpoint belgelerinin revizyonu (ayrı commit ile yapılabilir)

---

## 8. Sonraki adım

**Faz D kapatılabilir** — kodlanan alt başlıklar D3 ve D2; D1 bilinçli karar ile ertelenmiştir.

| Seçenek | İçerik |
|---------|--------|
| **Faz E** (önerilen sonraki teknik faz) | Serbest zaman workflow + yıllık 270 saat takip |
| **Faz D1 yeniden açılış** | Yalnızca önce ürün kararı: onay alanı, belge, geçerlilik, audit — ardından compliance kodu |

**Belge durumu:** Faz D1 karar — kod bekliyor (veri/workflow ön koşulu). Faz D üst fazı D3+D2 implementasyonu + D1 karar ile kapatılmış sayılır.
