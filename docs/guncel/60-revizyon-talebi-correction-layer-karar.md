# 60 — Revizyon Talebi Correction Layer Kararı

---

## 1. Doküman Amacı

Bu doküman, ONAYLANDI durumuna geçen revizyon taleplerinin kapalı dönem, snapshot ve rapor/read model üzerinde nasıl etki üreteceğini karara bağlar. Amaç, kapanmış haftanın ham snapshot verisini overwrite etmeden, audit edilebilir correction layer mantığını tanımlamaktır.

---

## 2. Önceki Bağlam

- **50** ile kapalı dönem / audit workflow kararı alındı.
- **51** ile haftalık kapanış revizyon talebi state modeli tanımlandı.
- **52** ile rol/yetki kararı verildi.
- **53** ile rol yetki matrisi revizyon permission'larıyla güncellendi.
- **54** ile revizyon talebi contract kod iskeleti oluşturuldu.
- **56** ile scope/backend enforcement kararı alındı.
- **57** ile mock scope enforcement kodlandı.
- **58** ile mock scope enforcement checkpoint kapandı.
- **59** ile gerçek backend scope enforcement kararı alındı.
- Bu doküman ONAYLANDI sonrası veri etkisini tanımlar.

---

## 3. Problem Tanımı

Revizyon talebi ONAYLANDI olduğunda sistem şu sorulara cevap vermelidir:

- Kapanmış haftanın snapshot kaydı değiştirilecek mi?
- Correction etkisi ayrı event/read model olarak mı tutulacak?
- Rapor ekranı ham snapshot'ı mı, correction uygulanmış görünümü mü gösterecek?
- Bordro etkili revizyonlarda finans/audit izi nasıl korunacak?
- Revizyon iptal edilirse correction etkisi geri alınacak mı?
- Birden fazla correction aynı kaynak için nasıl sıralanacak?
- Correction layer puantaj motorunu yeniden çalıştıracak mı, yoksa delta etkisi mi taşıyacak?

---

## 4. Ana Karar

- Kapanmış hafta snapshot'ı overwrite edilmez.
- ONAYLANDI revizyon, ayrı bir correction event/read model etkisi üretir.
- Snapshot immutable kalır.
- Rapor/read model katmanı "snapshot + correction overlay" mantığıyla çalışır.
- Correction layer, ham puantaj kaydını doğrudan değiştirmez.
- Correction etkisi audit edilebilir, geri izlenebilir ve gerekirse iptal edilebilir olmalıdır.
- İlk fazda correction layer yalnız karar ve contract seviyesinde ele alınır; bordro matematiği bu dokümanda uygulanmaz.

---

## 5. Correction Event Tanımı

Önerilen correction event alanları:

| Alan | Açıklama |
|------|----------|
| `id` | Correction event benzersiz kimliği |
| `revizyon_talebi_id` | Kaynak revizyon talebi |
| `personel_id` | Etkilenen personel |
| `hafta_baslangic` | Hafta başlangıç tarihi |
| `hafta_bitis` | Hafta bitiş tarihi |
| `etkilenen_tarih` | Düzeltmenin uygulandığı gün |
| `kaynak_tipi` | Kaynak varlık tipi (ör. giriş/çıkış, mola, devamsızlık) |
| `kaynak_id` | Kaynak kayıt kimliği |
| `correction_tipi` | Correction tipi enum |
| `onceki_deger` | Düzeltme öncesi değer (serileştirilmiş) |
| `yeni_deger` | Düzeltme sonrası değer (serileştirilmiş) |
| `delta_dakika` | Dakika cinsinden net etki |
| `delta_gun` | Gün cinsinden net etki |
| `bordro_etki_var_mi` | Bordro/finans etkisi var mı |
| `bordro_etki_tipi` | Bordro etki sınıflandırması |
| `aciklama` | İnsan okunur açıklama |
| `olusturan_kullanici_id` | Correction üreten kullanıcı veya sistem aktörü |
| `olusturma_zamani` | Correction üretim zamanı |
| `iptal_edildi_mi` | İptal durumu |
| `iptal_zamani` | İptal zamanı |
| `iptal_eden_kullanici_id` | İptal eden kullanıcı |
| `audit_ref` | Audit zinciri referansı |

---

## 6. Correction Tipleri

| Correction tipi | Açıklama | Etki |
|-----------------|----------|------|
| `GIRIS_CIKIS_DUZELTME` | Kapanmış gün giriş/çıkış bilgisinin revizyon etkisi. | Net süre delta etkisi üretebilir. |
| `MOLA_DUZELTME` | Mola dakikasının revizyon etkisi. | Net çalışma süresini değiştirir. |
| `DEVAMSIZLIK_DUZELTME` | Devamsızlık statüsünün revizyon etkisi. | Gün, hafta tatili ve ücret etkisi doğurabilir. |
| `SERBEST_ZAMAN_ETKI_DUZELTME` | Serbest zaman hakkı/kullanım etkisi. | Serbest zaman read modeline overlay gerekir. |
| `KAPANIS_HESAP_REVIZYONU` | Haftalık kapanış özet değerlerinin düzeltme etkisi. | Rapor/read model seviyesinde görünür. |
| `BORDRO_ETKI_NOTU` | Bordro/finans etkisinin not/audit düzeyinde kayıt altına alınması. | Hesap üretmez, görünürlük ve audit etkisi taşır. |

---

## 7. Snapshot İlişkisi

- Snapshot kapanış anındaki ham ve mühürlü görünümü temsil eder.
- Correction event snapshot'ı değiştirmez.
- Snapshot id veya hafta/personel/tarih referansı correction event içinde tutulur.
- Raporlar snapshot'ı temel alır, correction overlay'i ayrıca uygular.
- Eski snapshot ile correction uygulanmış görünüm ayrıştırılabilir olmalıdır.
- Audit ekranında "kapanıştaki değer" ve "düzeltilmiş değer" ayrı gösterilmelidir.

---

## 8. Read Model Kararı

Rapor ve detay ekranları için iki görünüm gerekir:

### A. Ham kapanış görünümü

- Sadece snapshot verisini gösterir.
- Denetim ve karşılaştırma için kullanılır.

### B. Düzeltilmiş operasyon görünümü

- Snapshot + aktif correction event'leri uygulanmış değeri gösterir.
- Yönetim raporu ve bordro hazırlık görünümü için kullanılır.

### V1 önerisi

- Varsayılan rapor görünümü correction uygulanmış operasyon görünümü olabilir.
- Detayda correction badge/etiketi gösterilmelidir.
- Ham snapshot görünümü audit sekmesinde erişilebilir olmalıdır.

---

## 9. Correction Sıralama Kararı

Aynı kaynak üzerinde birden fazla correction varsa:

- Sadece ONAYLANDI ve iptal edilmemiş correction event'leri uygulanır.
- Aynı kaynak + alan için son onaylanan correction kazanır.
- Farklı alanlara ait correction'lar birlikte uygulanabilir.
- İptal edilen correction etkisiz sayılır.
- Correction üretim zamanı ve karar zamanı ayrı tutulmalıdır.
- Sıralama `karar_zamani` / correction `olusturma_zamani` üzerinden yapılır.

---

## 10. İptal / Geri Alma Kararı

Correction fiziksel silinmez.

Geri alma için:

- correction event `iptal_edildi_mi = true` yapılır
- iptal eden kullanıcı ve zaman kaydedilir
- read model aktif correction setinden çıkarır
- orijinal snapshot yine değişmez
- yeni düzeltme gerekiyorsa yeni correction event açılır

---

## 11. Puantaj Motoru ile İlişki

Correction layer doğrudan puantaj motorunu yeniden çalıştırmaz.

İlk karar:

- Kapanmış haftanın hesap motoru çıktısı snapshot'ta kalır.
- Correction layer delta/read model overlay üretir.
- Geniş fazda yeniden hesaplama gerekirse explicit recompute workflow tasarlanmalıdır.
- Motor owner dosyalarına bu karar dokümanında dokunulmaz.

---

## 12. Serbest Zaman ile İlişki

Serbest zaman etkili correction'lar:

- serbest zaman oluşum/kullanım event'lerini doğrudan overwrite etmez
- gerekirse ayrı correction tipi ile read modelde görünür
- serbest zaman event zinciri E3a/E3b/E3c kurallarını korur
- correction layer ile serbest zaman motoru doğrudan bağlanmaz
- ileride ayrı "serbest zaman correction adapter" gerekebilir

---

## 13. Bordro / Finans Etkisi

Bordro etkili correction'larda:

- `bordro_etki_var_mi` true tutulur
- `bordro_etki_tipi` ayrıca sınıflanır
- net maaş/SGK/vergi hesabı bu dokümanın kapsamı değildir
- finans görünürlüğü **56**/**59** kararlarına göre maskelenir
- correction event bordro hazırlık raporuna kaynak olabilir
- kesin bordro entegrasyonu ayrı fazdır

---

## 14. API Contract Kararı

Önerilen endpoint ailesi:

| Metot | Endpoint | Açıklama |
|-------|----------|----------|
| `GET` | `/haftalik-kapanis/revizyon-corrections` | Correction listesi |
| `GET` | `/haftalik-kapanis/revizyon-corrections/:id` | Correction detayı |
| `POST` | `/haftalik-kapanis/revizyon-talepleri/:id/correction-uret` | Correction üretimi |
| `POST` | `/haftalik-kapanis/revizyon-corrections/:id/iptal` | Correction iptali |

Not:

- V1'de correction üretimi approve işlemi içinde otomatik tetiklenebilir.
- Ancak idempotency için aynı `revizyon_talebi_id` için ikinci correction üretimi engellenmelidir.

---

## 15. State İlişkisi

Revizyon talebi state'leri ile correction ilişkisi:

| State | Correction |
|-------|------------|
| `TASLAK` | correction yok |
| `ONAY_BEKLIYOR` | correction yok |
| `REDDEDILDI` | correction yok |
| `IPTAL` | correction yok |
| `ONAYLANDI` | correction üretilebilir |

Correction üretildikten sonra:

- `revizyon_talebi.correction_event_id` set edilebilir
- `correction_event_id` null kalması "henüz correction üretilmedi" anlamına gelir
- correction üretim hatası ayrı hata kodu ile dönmelidir

---

## 16. Hata Kodları

| Kod | Açıklama |
|-----|----------|
| `CORRECTION_ALREADY_EXISTS` | Aynı revizyon talebi için correction zaten üretilmiş |
| `CORRECTION_NOT_ALLOWED_FOR_STATE` | Talep durumu correction üretimine uygun değil |
| `CORRECTION_TARGET_NOT_FOUND` | Hedef kaynak/snapshot bulunamadı |
| `CORRECTION_SCOPE_DENIED` | Kullanıcı kapsamı dışı correction erişimi |
| `CORRECTION_IMMUTABLE_SNAPSHOT` | Snapshot mutate girişimi reddedildi |
| `CORRECTION_RECOMPUTE_REQUIRED` | İşlem için yeniden hesaplama gerekli (ileri faz) |
| `CORRECTION_FINANCE_SCOPE_DENIED` | Finans kapsamı dışı bordro etki erişimi |
| `INVALID_CORRECTION_PAYLOAD` | Geçersiz correction istek gövdesi |
| `CORRECTION_NOT_FOUND` | Correction kaydı bulunamadı |

---

## 17. Audit Kararı

Her correction event audit izi taşımalıdır:

- revizyon talebi referansı
- onaylayan kullanıcı
- correction üreten sistem/kullanıcı
- önceki değer
- yeni değer
- delta
- kaynak snapshot
- iptal bilgisi
- `request_id`
- timestamp

Audit ekranında görünebilmelidir:

- kim talep etti
- kim onayladı
- hangi değer değişti
- kapanıştaki değer neydi
- düzeltilmiş değer ne oldu
- bordro etkisi var mı

---

## 18. Test Kararı

İleride kod fazında minimum testler:

- ONAYLANDI olmayan talep correction üretemez.
- Aynı `revizyon_talebi_id` için ikinci correction engellenir.
- Snapshot mutate edilmez.
- Aktif correction read modelde uygulanır.
- İptal edilmiş correction read modelden düşer.
- Aynı alan için son correction kazanır.
- Finance scope olmayan kullanıcı `bordro_etki_notu` göremez.
- Correction event audit alanları boş olamaz.

---

## 19. Kapsam Dışı

Bu dokümanda yapılmayacaklar:

- Kod implementasyonu
- API/mock değişikliği
- UI ekranı
- Snapshot builder değişikliği
- Puantaj motoru değişikliği
- Serbest zaman motoru değişikliği
- Bordro hesaplama
- Finans entegrasyonu
- Gerçek recompute workflow

---

## 20. Sonraki Faz Seçenekleri

| Seçenek | Amaç | Risk | Öneri |
|---------|------|------|-------|
| **A. Correction Layer Contract Kod Fazı Ön Teşhis** | type/API/mock/read model owner dosyalarını netleştirmek. | snapshot ve motor sınırına dikkat gerekir. | Güçlü aday. |
| **B. Revizyon UI Karar Dokümanı** | liste/detail/correction badge/audit görünümü belirlenir. | backend/correction contract netleşmeden UI eksik kalabilir. | Correction kararından sonra yapılabilir. |
| **C. Backend Enforcement Kod Fazı** | gerçek auth/scope enforcement uygulanır. | gerçek backend/auth katmanı hazır değilse erken olur. | backend hazırlığına bağlı. |

---

## 21. Önerilen Sıradaki Adım

Önerilen sonraki adım **"Correction Layer Contract Kod Fazı Ön Teşhis"**tir. Bu teşhiste correction type owner dosyası, API client, mock store, read model overlay ve test kapsamı netleştirilmelidir. Koddan önce snapshot/motor sınırı tekrar doğrulanmalıdır.

**Önerilen faz:** **61 — Revizyon Talebi Correction Layer Contract Ön Teşhis**

---

## 22. Kapanış Cümlesi

Revizyon talebi correction layer kararı, ONAYLANDI revizyon taleplerinin kapanmış snapshot'ı overwrite etmeden ayrı correction event/read model overlay olarak etki üretmesini karara bağlar. Snapshot immutable kalır; rapor ve audit katmanı snapshot ile correction uygulanmış görünümü ayrıştırır.
