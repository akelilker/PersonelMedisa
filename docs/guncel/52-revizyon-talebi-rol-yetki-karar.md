# 52 — Revizyon Talebi Rol Yetki Kararı

---

## 1. Doküman Amacı

Bu doküman, 51 numaralı Haftalık Kapanış Revizyon Talebi Kararı ile açılan revizyon workflow'unun hangi roller tarafından hangi seviyede kullanılabileceğini netleştirir. Amaç, kod fazına geçmeden önce talep açma, onaylama, reddetme, iptal etme ve görüntüleme yetkilerini karar altına almaktır.

---

## 2. Önceki Bağlam

- **09** numaralı rol yetki matrisi mevcut V1 rol davranışlarını tanımlar.
- **50** numaralı doküman kapalı dönem / audit workflow kararını verir.
- **51** numaralı doküman haftalık kapanış revizyon talebi state modelini tanımlar.
- Bu doküman **51**'deki workflow'un rol/yetki kararını netleştirir.
- Bu turda **09** dokümanı güncellenmez; yalnız karar dokümanı yazılır.

---

## 3. Mevcut Roller

- `GENEL_YONETICI`
- `BOLUM_YONETICISI`
- `MUHASEBE`
- `BIRIM_AMIRI`

---

## 4. Revizyon Yetki Kavramları

| Kavram | Tanım |
|--------|--------|
| **revizyon görüntüleme** | Revizyon talebi kaydını okuma yetkisi. |
| **revizyon talebi oluşturma** | Yeni revizyon talebi kaydı açma yetkisi. |
| **revizyon talebini gönderme** | `TASLAK` → `ONAY_BEKLIYOR` geçişi. |
| **revizyon talebini iptal etme** | `TASLAK` veya `ONAY_BEKLIYOR` → `IPTAL` geçişi. |
| **revizyon talebini onaylama** | `ONAY_BEKLIYOR` → `ONAYLANDI` geçişi. |
| **revizyon talebini reddetme** | `ONAY_BEKLIYOR` → `REDDEDILDI` geçişi. |
| **bordro etkili revizyonları görme** | `bordro_etki_var_mi` ve finans detaylarını görme yetkisi. |
| **kapsam** | Tüm şirket / bölüm / kendi personeli erişim sınırı. |

---

## 5. Ana Yetki Kararı

- **GENEL_YONETICI** tüm revizyon taleplerini görür, açar, onaylar, reddeder ve iptal edebilir.
- **BOLUM_YONETICISI** kendi bölümündeki personeller için revizyon talebi açabilir ve görebilir.
- **BOLUM_YONETICISI** onay yetkisi ilk V1 kararında sınırlı tutulur; nihai onay **GENEL_YONETICI**'dedir.
- **MUHASEBE** bordro etkili revizyonları görebilir ve talep açabilir; onay/red yetkisi yoktur.
- **BIRIM_AMIRI** kendi personeli için sınırlı talep açabilir; onay/red yetkisi yoktur.
- Onay ve red yetkisi ilk V1'de yalnız **GENEL_YONETICI** üzerinde kilitlenir.
- Yetki kararları **backend** tarafından enforce edilmelidir; UI yalnız görünürlük sağlar.

---

## 6. Yetki Matrisi

| Aksiyon | GENEL_YONETICI | BOLUM_YONETICISI | MUHASEBE | BIRIM_AMIRI |
|---------|----------------|------------------|----------|-------------|
| `revizyon.view` | Tümü | Kendi bölümü | Bordro etkili + kendi erişim kapsamı | Kendi personeli |
| `revizyon.create` | Evet | Kendi bölümü | Bordro etkili gerekçe ile evet | Sınırlı |
| `revizyon.submit` | Evet | Kendi oluşturduğu | Kendi oluşturduğu | Kendi oluşturduğu |
| `revizyon.cancel` | Tümü | Kendi oluşturduğu (`TASLAK` / `ONAY_BEKLIYOR`) | Kendi oluşturduğu | Kendi oluşturduğu |
| `revizyon.approve` | Evet | Hayır | Hayır | Hayır |
| `revizyon.reject` | Evet | Hayır | Hayır | Hayır |
| `revizyon.view_finance_effect` | Evet | Kendi bölümü | Evet | Hayır veya sınırlı |
| `revizyon.view_audit_history` | Evet | Kendi bölümü | Bordro etkili kayıtlar | Kendi personeli için sınırlı |

---

## 7. Kapsam Kararları

- **GENEL_YONETICI** kapsamı: tüm şirket.
- **BOLUM_YONETICISI** kapsamı: kendi bölümü.
- **MUHASEBE** kapsamı: bordro/finans etkisi olan kayıtlar ve yetkili olduğu rapor kapsamı.
- **BIRIM_AMIRI** kapsamı: kendi personeli veya kendi birimiyle sınırlı sade görünüm.

---

## 8. State Bazlı Yetki

| Durum | Kim ne yapabilir |
|-------|------------------|
| **TASLAK** | Oluşturan kullanıcı düzenleyebilir veya iptal edebilir. `GENEL_YONETICI` görebilir. |
| **ONAY_BEKLIYOR** | `GENEL_YONETICI` onaylar/reddeder. Oluşturan kullanıcı iptal edebilir. Diğer roller sadece kendi kapsamına göre görebilir. |
| **ONAYLANDI** | Kimse düzenleyemez. `GENEL_YONETICI` ve yetkili roller görebilir. Veri etkisi audit/correction layer üzerinden okunur. |
| **REDDEDILDI** | Kimse onaylayamaz. Görüntüleme kapsam bazlıdır. Yeni talep gerekiyorsa yeni kayıt açılır. |
| **IPTAL** | Veri etkisi yoktur. Görüntüleme kapsam bazlıdır. |

---

## 9. Rol Bazlı Sınırlar

**BOLUM_YONETICISI:**

- Kendi bölümü dışındaki personel için revizyon açamaz.
- Onay/red veremez.
- Bordro etkili kayıtları yalnız kendi bölümü kapsamında görür.

**MUHASEBE:**

- Bordro etkisi olan revizyonlarda görünürlük sahibidir.
- Revizyon açabilir ama onaylayamaz.
- Personel operasyonel revizyonlarında genel yönetici gibi davranamaz.

**BIRIM_AMIRI:**

- Sade yüz kullanır.
- Kendi personeli için sınırlı talep açabilir.
- Finans/bordro etkisi detaylarını göremez veya sınırlı görür.
- Onay/red veremez.

---

## 10. Onay Yetkisi Kararı

V1'de onay/red yetkisi **sadece `GENEL_YONETICI`** rolünde tutulacaktır. Bu karar, kapalı dönem ve bordro etkisi olan düzeltmelerde yetki dağılmasını ve sessiz veri değişikliğini önlemek için alınmıştır.

İleride `BOLUM_YONETICISI` için sınırlı onay delegasyonu açılabilir; bu ayrı karar fazı gerektirir.

---

## 11. Backend Enforcement Kararı

Yetki yalnız UI buton görünürlüğü ile sağlanmayacaktır. Backend/API katmanı şu kontrolleri yapmak zorundadır:

- rol kontrolü
- personel kapsam kontrolü
- bölüm kapsam kontrolü
- state transition yetkisi
- bordro etkili kayıt görünürlüğü
- duplicate açık talep kontrolü

---

## 12. UI Görünürlük Kararı

UI fazında:

- Yetkisiz aksiyon butonları gösterilmeyecektir.
- Ancak güvenlik UI'a bırakılmayacaktır.
- Yetkisiz API denemeleri backend tarafından reddedilmelidir.
- `BIRIM_AMIRI` sade yüzünde yalnız talep oluşturma / kendi taleplerini görme akışı bulunabilir.

---

## 13. Validation / Yetki Hata Kodları

| Kod | Ne zaman |
|-----|----------|
| `UNAUTHORIZED_REVISION_REQUEST` | Yetkisiz kullanıcı revizyon talebi açarsa. |
| `UNAUTHORIZED_REVISION_APPROVAL` | Yetkisiz kullanıcı onay/red verirse. |
| `REVISION_SCOPE_DENIED` | Kullanıcı kapsam dışı personel/bölüm için işlem yaparsa. |
| `FINANCE_EFFECT_ACCESS_DENIED` | Bordro etkili revizyon detayı yetkisiz görüntülenirse. |
| `INVALID_STATE_TRANSITION` | Rol veya state nedeniyle geçiş yasaksa. |
| `REVISION_ALREADY_EXISTS` | Aynı kaynak için açık talep varsa. |

---

## 14. 09 Rol Yetki Matrisi ile İlişki

Bu doküman **09** rol yetki matrisini değiştirmez. Kod fazına geçmeden önce 09 numaralı dokümanın revizyon permission'larıyla güncellenmesi ayrı doküman veya ayrı commit olarak yapılmalıdır.

**Önerilecek yeni permission anahtarları:**

- `revizyon.view`
- `revizyon.create`
- `revizyon.submit`
- `revizyon.cancel`
- `revizyon.approve`
- `revizyon.reject`
- `revizyon.view_finance_effect`
- `revizyon.view_audit_history`

---

## 15. Test Kararı

İleride kod fazında minimum testler:

- `GENEL_YONETICI` tüm revizyonları görebilir.
- `BOLUM_YONETICISI` kendi bölümü dışı revizyon açamaz.
- `MUHASEBE` onay veremez.
- `BIRIM_AMIRI` onay veremez.
- Yetkisiz onay → `UNAUTHORIZED_REVISION_APPROVAL`
- Kapsam dışı personel → `REVISION_SCOPE_DENIED`
- Bordro etkili detaya yetkisiz erişim → `FINANCE_EFFECT_ACCESS_DENIED`
- `ONAYLANDI` durumundaki talep düzenlenemez.

---

## 16. Kapsam Dışı

Bu dokümanda yapılmayacaklar:

- Kod implementasyonu
- 09 rol yetki dokümanını güncelleme
- API/mock değişikliği
- UI ekranı
- Route guard değişikliği
- Permission matrisi kod değişikliği
- Test ekleme
- Bordro/finans hesaplama

---

## 17. Sonraki Faz Seçenekleri

| Seçenek | Açıklama | Risk |
|---------|----------|------|
| **A. 09 Rol Yetki Matrisi Revizyon Permission Güncellemesi** | Doküman 52 kararlarını ana role matrix'e geçirmek. | Kod yok, düşük risk. |
| **B. Revizyon Talebi Contract Kod Fazı** | Type/API/mock/test iskeleti. | Yetki kararları uygulanmadan kod riski var. |
| **C. Revizyon Talebi UI Karar Dokümanı** | Kullanıcı akışı ve buton görünürlüğü. | Backend enforcement netleşmeden UI erken olabilir. |

---

## 18. Önerilen Sıradaki Adım

Bu dokümandan sonra en güvenli devam, **09** rol yetki matrisinin revizyon permission anahtarlarıyla doküman seviyesinde güncellenmesidir. Kod fazına geçmeden önce yetki sözleşmesi ana matrise işlenmelidir.

**Önerilen dosya/doküman aksiyonu:**

- `docs/guncel/09-rol-yetki-matrisi.md` güncellemesi veya yeni checkpoint:
- `docs/guncel/53-revizyon-permission-matrisi-kapanis-checkpoint.md`

---

## 19. Kapanış Cümlesi

Revizyon talebi rol/yetki kararı, kapalı dönem revizyon workflow'unda kimlerin talep açabileceğini, kimlerin onay/red verebileceğini ve hangi kapsamda görüntüleme yapabileceğini netleştirir. V1'de nihai onay/red yetkisi yalnız `GENEL_YONETICI` rolünde tutulur; diğer roller kapsamlarına göre talep ve görünürlük yetkisiyle sınırlandırılır.
