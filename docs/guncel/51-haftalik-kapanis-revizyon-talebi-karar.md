# 51 — Haftalık Kapanış Revizyon Talebi Kararı

---

## 1. Doküman Amacı

Bu doküman, haftalık kapanışı alınmış bir dönemde sonradan düzeltme ihtiyacı doğduğunda revizyon talebinin nasıl açılacağını, hangi statülerden geçeceğini, kimlerin işlem yapabileceğini ve snapshot immutable kararının nasıl korunacağını karara bağlar.

---

## 2. Önceki Bağlam

- **39 / A1 / A2 / A3** ile haftalık kapanış snapshot hattı kuruldu.
- **50** numaralı dokümanla kapalı dönem / audit workflow kararı alındı.
- Ana karar: snapshot immutable kalır, kapalı dönem overwrite edilmez.
- Bu doküman **50** numaralı kararın revizyon talebi alt akışını netleştirir.

---

## 3. Problem Tanımı

Şu sorulara karar gerekir:

- Kapalı haftada hata fark edilirse kullanıcı ne yapar?
- Kim revizyon talebi açabilir?
- Kim onaylar veya reddeder?
- Talep açıkken aynı kaynak için ikinci talep açılabilir mi?
- Onaylanan revizyon snapshot'ı değiştirir mi?
- Raporlar talebi nasıl gösterir?
- Bordro/finans etkisi nasıl işaretlenir?

---

## 4. Ana Karar

- Kapalı haftaya **doğrudan mutation yapılmaz**.
- Kullanıcı **revizyon talebi** açar.
- Revizyon talebi snapshot'ı **overwrite etmez**.
- Onaylanan talep **audit event veya correction layer** üretir.
- Reddedilen talep **veri etkisi üretmez**.
- Aynı kaynak için açık revizyon varken **ikinci açık revizyon açılamaz**.
- Revizyon workflow'u **yetki kontrollüdür**.

---

## 5. Revizyon Talebi Tanımı

Revizyon talebi, kapalı dönem üzerinde değişiklik ihtiyacını kayıt altına alan audit workflow kaydıdır.

**Minimum alanlar:**

- `id`
- `personel_id`
- `hafta_baslangic`
- `hafta_bitis`
- `etkilenen_tarih`
- `kaynak_tipi`
- `kaynak_id`
- `revizyon_tipi`
- `onceki_deger`
- `talep_edilen_deger`
- `gerekce`
- `talep_eden_kullanici_id`
- `talep_zamani`
- `durum`
- `karar_veren_kullanici_id`
- `karar_zamani`
- `karar_notu`

---

## 6. Revizyon Tipleri

- `PUANTAJ_GIRIS_CIKIS_DUZELTME`
- `MOLA_DUZELTME`
- `DEVAMSIZLIK_DUZELTME`
- `SUREC_GEC_GIRIS`
- `SERBEST_ZAMAN_ETKI_DUZELTME`
- `KAPANIS_HESAP_REVIZYONU`
- `BORDRO_ETKI_NOTU`

---

## 7. Durum Modeli

| Durum | Anlam | Veri etkisi |
|-------|-------|-------------|
| `TASLAK` | Talep hazırlanır, henüz gönderilmemiştir. | Veri etkisi yok. |
| `ONAY_BEKLIYOR` | Talep yetkili onayına gönderilmiştir. | Veri etkisi yok. |
| `ONAYLANDI` | Talep kabul edilmiştir. | Audit/correction etkisi üretir. |
| `REDDEDILDI` | Talep reddedilmiştir. | Veri etkisi yok. |
| `IPTAL` | Talep sahibi veya yetkili talebi iptal etmiştir. | Veri etkisi yok. |

---

## 8. State Geçişleri

**İzin verilen geçişler:**

- `TASLAK` → `ONAY_BEKLIYOR`
- `ONAY_BEKLIYOR` → `ONAYLANDI`
- `ONAY_BEKLIYOR` → `REDDEDILDI`
- `TASLAK` → `IPTAL`
- `ONAY_BEKLIYOR` → `IPTAL`

**Yasak geçişler:**

- `ONAYLANDI` → `TASLAK`
- `REDDEDILDI` → `ONAYLANDI`
- `IPTAL` → `ONAY_BEKLIYOR`
- `ONAYLANDI` → `REDDEDILDI`

---

## 9. Yetki Kararı

| Rol | Talep açar | Onaylar | Reddeder | Görür |
|-----|------------|---------|----------|-------|
| `GENEL_YONETICI` | Evet | Evet | Evet | Tümü |
| `BOLUM_YONETICISI` | Evet | Sınırlı veya hayır | Sınırlı veya hayır | Kendi bölümü |
| `MUHASEBE` | Evet | Hayır | Hayır | Bordro etkili kayıtlar |
| `BIRIM_AMIRI` | Sınırlı | Hayır | Hayır | Kendi personeli |

**Not:** Kesin yetki matrisi kod fazından önce `09-rol-yetki-matrisi.md` ile uyumlandırılmalıdır.

---

## 10. Duplicate Talep Kararı

Aynı `kaynak_tipi` + `kaynak_id` + `etkilenen_tarih` için `ONAY_BEKLIYOR` durumunda açık talep varsa yeni talep açılamaz.

**Hata kodu:** `REVISION_ALREADY_EXISTS`

---

## 11. Snapshot Etkisi

Onaylanan revizyon snapshot'ı **overwrite etmez**. Snapshot kapanış anındaki orijinal kayıt olarak korunur. Onaylanan revizyon audit/correction layer üzerinden raporlara yansır. Gerekirse ileride revised snapshot ayrı fazda tasarlanır.

---

## 12. Read Model Etkisi

Kapalı dönem raporları üç katmanlı çalışır:

1. **Orijinal snapshot**
2. **Onaylanmış revizyon/audit etkileri**
3. **Düzeltilmiş görünüm**

Talep `ONAY_BEKLIYOR` ise düzeltilmiş görünümde veri etkisi üretmez; yalnız uyarı veya bekleyen talep olarak gösterilir.

---

## 13. Bordro / Finans Etkisi

Revizyon bordro dönemini etkiliyorsa:

- `bordro_etki_var_mi` alanı taşınmalıdır
- `bordro_etki_notu` zorunlu olabilir
- bordro/finans entegrasyonu ayrı fazda karara bağlanacaktır
- onaylanan revizyon bordro çıktısında ayrı fark/uyarı olarak taşınmalıdır

---

## 14. Validation Kararları

| Kod | Ne zaman |
|-----|----------|
| `PERIOD_NOT_CLOSED` | Revizyon talebi yalnız kapalı dönem için açılır. |
| `PERIOD_LOCKED` | Doğrudan mutation denenirse. |
| `REVISION_ALREADY_EXISTS` | Aynı kaynak için açık talep var. |
| `INVALID_STATE_TRANSITION` | Yasak state geçişi. |
| `UNAUTHORIZED_REVISION_REQUEST` | Yetkisiz talep. |
| `UNAUTHORIZED_REVISION_APPROVAL` | Yetkisiz onay/red. |
| `TARGET_NOT_FOUND` | Revize edilecek kaynak yok. |
| `SNAPSHOT_IMMUTABLE` | Snapshot overwrite denenirse. |

---

## 15. API Kararı

İleride açılabilecek endpoint önerileri:

- `POST /haftalik-kapanis/revizyon-talepleri`
- `GET /haftalik-kapanis/revizyon-talepleri`
- `GET /haftalik-kapanis/revizyon-talepleri/{id}`
- `POST /haftalik-kapanis/revizyon-talepleri/{id}/gonder`
- `POST /haftalik-kapanis/revizyon-talepleri/{id}/onay`
- `POST /haftalik-kapanis/revizyon-talepleri/{id}/red`
- `POST /haftalik-kapanis/revizyon-talepleri/{id}/iptal`

**Not:** Bu doküman endpoint implementasyonu yapmaz.

---

## 16. Test Kararı

İleride kod fazında minimum testler:

- Kapalı dönem için revizyon talebi açılır.
- Açık dönem için revizyon talebi `PERIOD_NOT_CLOSED` döner.
- Aynı kaynak için ikinci açık talep `REVISION_ALREADY_EXISTS` döner.
- `ONAY_BEKLIYOR` → `ONAYLANDI` geçer.
- `REDDEDILDI` → `ONAYLANDI` geçmez.
- `ONAYLANDI` snapshot'ı overwrite etmez.
- Onaylanan talep correction layer üretir.
- Yetkisiz kullanıcı onay veremez.

---

## 17. Kapsam Dışı

Bu dokümanda yapılmayacaklar:

- Kod implementasyonu
- API/mock değişikliği
- UI ekranı
- Snapshot builder değişikliği
- Puantaj motoru değişikliği
- Serbest zaman event motoru değişikliği
- Bordro hesaplama
- Finans entegrasyonu
- Yetki matrisi kod değişikliği

---

## 18. Sonraki Faz Seçenekleri

| Seçenek | Açıklama | Risk |
|---------|----------|------|
| **A. Revizyon talebi contract kod fazı** | Type/API/mock/test iskeleti. | Yeni domain hattı açılır. |
| **B. Rol yetki matrisi güncelleme dokümanı** | Revizyon yetkileri 09 belge ile uyumlandırılır. | Kod fazı öncesi gerekli olabilir. |
| **C. Bordro/finans etki karar dokümanı** | Revizyonun bordro çıktısına etkisi netleşir. | Muhasebe kararı gerektirir. |
| **D. Test hardening fazı** | Mevcut snapshot + audit kararları test güvenliğine hazırlanır. | Yeni ürün yetkinliği eklemez. |

---

## 19. Önerilen Sıradaki Adım

Bu dokümandan sonra en güvenli devam, kod yazmadan önce rol yetki matrisiyle revizyon yetkilerinin uyumlandırılmasıdır.

**Önerilen dosya:**

`docs/guncel/52-revizyon-talebi-rol-yetki-karar.md`

---

## 20. Kapanış Cümlesi

Haftalık kapanış revizyon talebi kararı, kapalı haftalarda doğrudan veri değişikliği yerine kontrollü, yetkili ve audit izli revizyon workflow'u kullanılacağını netleştirir. Snapshot immutable kalır; onaylanan revizyon etkileri ayrı correction layer üzerinden raporlanır.
