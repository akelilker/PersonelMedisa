# 56 — Revizyon Talebi Scope / Backend Enforcement Kararı

---

## 1. Doküman Amacı

Bu doküman, haftalık kapanış revizyon talebi workflow'unda rol permission anahtarlarının ötesinde uygulanacak kapsam ve backend enforcement kurallarını karara bağlar. Amaç, `revizyon.view`, `revizyon.create`, `revizyon.approve` gibi flat permission'ların tek başına yeterli olmadığı bölüm, personel, bordro etkisi ve talep sahipliği kontrollerini netleştirmektir.

---

## 2. Önceki Bağlam

- **50** ile kapalı dönem / audit workflow kararı alındı.
- **51** ile haftalık kapanış revizyon talebi state modeli ve endpoint önerileri tanımlandı.
- **52** ile revizyon talebi rol/yetki kararı verildi.
- **53** ile **09** rol yetki matrisi revizyon permission anahtarlarıyla güncellendi.
- **54** ile revizyon talebi contract kod iskeleti oluşturuldu.
- **55** ile 54 contract kod fazı checkpoint ile kapatıldı.
- **54** fazında permission matrix eklendi fakat bölüm/personel/bordro scope enforcement bilinçli olarak kapsam dışı bırakıldı.

---

## 3. Problem Tanımı

Şu sorulara karar gerekir:

- `revizyon.view` permission'ı olan kullanıcı hangi talepleri görebilir?
- `revizyon.create` permission'ı olan kullanıcı hangi personel için talep açabilir?
- `revizyon.cancel` permission'ı olan kullanıcı hangi talepleri iptal edebilir?
- **MUHASEBE** hangi bordro etkili kayıtları görebilir?
- **BIRIM_AMIRI** hangi personel kapsamıyla sınırlıdır?
- **GENEL_YONETICI** dışındaki roller onay/red veremeyecekse backend bunu nasıl enforce eder?
- Scope kontrolü UI'da mı, backend/API'de mi zorunlu olacak?

---

## 4. Ana Karar

- Flat permission yalnız aksiyon türünü belirler.
- Kapsam kontrolü backend/API tarafından ayrıca yapılacaktır.
- UI görünürlük sağlayabilir ama güvenlik kaynağı değildir.
- Her revizyon talebi için personel, bölüm, bordro etkisi ve talep sahibi kapsamı kontrol edilir.
- Scope dışı erişimler 403 hata ailesiyle reddedilir.
- Onay/red V1'de yalnız **GENEL_YONETICI** tarafından yapılabilir.
- Snapshot, puantaj motoru ve serbest zaman motoru enforcement için mutate edilmez.

---

## 5. Enforcement Katmanları

| Katman | Sorumluluk | Örnek |
|--------|------------|-------|
| **Permission layer** | Kullanıcının aksiyonu yapma hakkı var mı? | `revizyon.approve` var mı? |
| **Scope layer** | Kullanıcı bu personel/bölüm/kayıt üzerinde işlem yapabilir mi? | Bölüm yöneticisi kendi bölümü dışı personel için işlem yapamaz. |
| **State layer** | Talebin durumu bu aksiyona uygun mu? | `ONAYLANDI` talep iptal edilemez. |
| **Period layer** | İlgili dönem kapalı mı? | Revizyon talebi yalnız kapalı dönem için açılır. |
| **Finance visibility layer** | Bordro etkili alanlar bu role gösterilebilir mi? | **BIRIM_AMIRI** bordro etkisi detayını göremez. |

---

## 6. Rol Bazlı Scope Kararları

| Rol | Görme | Talep açma | İptal | Onay/red | Bordro etkisi |
|-----|-------|------------|-------|----------|---------------|
| **GENEL_YONETICI** | Tüm kayıtlar | Tüm kayıtlar | Tüm uygun talepler | Evet | Tam |
| **BOLUM_YONETICISI** | Kendi bölümü | Kendi bölümü | Kendi oluşturduğu veya kendi bölümündeki uygun talepler için sınırlı | Hayır | Kendi bölümüyle sınırlı |
| **MUHASEBE** | Bordro etkili kayıtlar + yetkili rapor kapsamı | Bordro etkili gerekçe ile | Kendi oluşturduğu uygun talepler | Hayır | Tam veya yetkili finans kapsamı |
| **BIRIM_AMIRI** | Kendi personeli veya kendi birimi | Kendi personeli için sınırlı | Kendi oluşturduğu uygun talepler | Hayır | Hayır veya sınırlı özet |

---

## 7. Scope Kaynakları

Backend enforcement için minimum veri kaynakları:

- kullanıcının rolü
- kullanıcının departman/bölüm kapsamı
- kullanıcının birim/personel kapsamı
- revizyon talebindeki `personel_id`
- personelin bölüm/departman bilgisi
- revizyon talebinin `talep_eden_kullanici_id` alanı
- `bordro_etki_var_mi`
- `bordro_etki_notu`
- talebin `durum` alanı
- haftalık kapanış dönemi

---

## 8. Aksiyon Bazlı Enforcement Kararları

| Aksiyon | Gerekli kontroller |
|---------|-------------------|
| `revizyon.view` | permission + personel/bölüm/bordro scope |
| `revizyon.create` | permission + hedef personel scope + kapalı dönem kontrolü |
| `revizyon.submit` | permission + talep sahibi veya yetkili scope + `TASLAK` state |
| `revizyon.cancel` | permission + talep sahibi veya **GENEL_YONETICI** + `TASLAK`/`ONAY_BEKLIYOR` state |
| `revizyon.approve` | `revizyon.approve` permission + **GENEL_YONETICI** + `ONAY_BEKLIYOR` state |
| `revizyon.reject` | `revizyon.reject` permission + **GENEL_YONETICI** + `ONAY_BEKLIYOR` state |
| `revizyon.view_finance_effect` | permission + finance visibility scope |
| `revizyon.view_audit_history` | permission + personel/bölüm/bordro scope |

---

## 9. Hata Kodları Kararı

| Kod | Ne zaman |
|-----|----------|
| `UNAUTHORIZED_REVISION_REQUEST` | Kullanıcının talep açma permission'ı yoksa. |
| `UNAUTHORIZED_REVISION_APPROVAL` | Kullanıcının onay/red permission'ı yoksa veya **GENEL_YONETICI** değilse. |
| `REVISION_SCOPE_DENIED` | Kullanıcı hedef personel/bölüm/kayıt kapsamı dışında işlem yapmak isterse. |
| `FINANCE_EFFECT_ACCESS_DENIED` | Bordro etkili alanı görme yetkisi yoksa. |
| `INVALID_STATE_TRANSITION` | State aksiyon için uygun değilse. |
| `PERIOD_NOT_CLOSED` | Revizyon talebi kapalı olmayan dönem için açılmak istenirse. |
| `REVISION_ALREADY_EXISTS` | Aynı kaynak için açık talep varsa. |
| `TARGET_NOT_FOUND` | Revize edilecek kaynak veya talep bulunamazsa. |

---

## 10. Backend Enforcement Sırası

İstek geldiğinde backend/API şu sırayla kontrol yapmalıdır:

1. Authentication kontrolü
2. Permission kontrolü
3. Kaynak var mı kontrolü
4. Period kapalı mı kontrolü
5. Scope kontrolü
6. State transition kontrolü
7. Duplicate açık talep kontrolü
8. Finance visibility kontrolü
9. İşlem uygulanır
10. Audit izi yazılır

**Not:** Bazı aksiyonlarda sıra optimize edilebilir fakat hiçbir durumda scope ve permission kontrolü atlanamaz.

---

## 11. List Endpoint Enforcement Kararı

`GET /haftalik-kapanis/revizyon-talepleri` endpoint'i role göre filtrelenmiş veri döndürmelidir.

**Karar:**

- **GENEL_YONETICI** tüm talepleri alabilir.
- **BOLUM_YONETICISI** yalnız kendi bölümündeki talepleri alır.
- **MUHASEBE** `bordro_etki_var_mi = true` olan ve finans kapsamına giren talepleri alır.
- **BIRIM_AMIRI** yalnız kendi personeli veya kendi birimiyle sınırlı sade talepleri alır.
- Yetkisiz kayıtlar listede hiç dönmez.

---

## 12. Detail Endpoint Enforcement Kararı

`GET /haftalik-kapanis/revizyon-talepleri/:id` endpoint'i:

- kayıt var mı kontrol eder
- kullanıcının kaydı görme scope'unu kontrol eder
- finance effect alanlarını role göre maskeleyebilir
- scope dışı erişimde 403 `REVISION_SCOPE_DENIED` döner
- kayıt yoksa 404 `TARGET_NOT_FOUND` döner

---

## 13. Create Endpoint Enforcement Kararı

`POST /haftalik-kapanis/revizyon-talepleri`:

- `revizyon.create` permission ister
- hedef personel scope kontrolü yapar
- dönem kapalı değilse `PERIOD_NOT_CLOSED` döner
- aynı kaynak için açık `ONAY_BEKLIYOR` talep varsa `REVISION_ALREADY_EXISTS` döner
- başarılı kayıt `TASLAK` olarak oluşur
- `talep_eden_kullanici_id` authenticated user'dan alınır, client'tan güvenilmez

---

## 14. Submit / Cancel Enforcement Kararı

**Submit:**

- `TASLAK` durumdan `ONAY_BEKLIYOR`'a geçer
- talep sahibi veya yetkili kapsam gerekir

**Cancel:**

- `TASLAK` veya `ONAY_BEKLIYOR` durum için geçerlidir
- talep sahibi veya **GENEL_YONETICI** iptal edebilir
- terminal durumdaki talep iptal edilemez

---

## 15. Approve / Reject Enforcement Kararı

- V1'de approve/reject yalnız **GENEL_YONETICI** içindir.
- **BOLUM_YONETICISI**, **MUHASEBE**, **BIRIM_AMIRI** approve/reject endpoint'lerinde 403 `UNAUTHORIZED_REVISION_APPROVAL` alır.
- Talep `ONAY_BEKLIYOR` değilse `INVALID_STATE_TRANSITION` döner.
- Onaylanan talep snapshot'ı overwrite etmez.
- Correction layer üretimi ayrı fazdır.
- Red edilen talep veri etkisi üretmez.

---

## 16. Finance Visibility Kararı

Bordro etkili revizyonlarda:

- **GENEL_YONETICI** tam detay görür.
- **MUHASEBE** bordro etkisi detayını görür.
- **BOLUM_YONETICISI** kendi bölümü için sınırlı veya tam kararına göre görür; V1'de kendi bölümüyle sınırlı görür.
- **BIRIM_AMIRI** bordro etkisi detayını görmez; gerekirse sadece "bordro etkisi var" uyarısı görebilir.
- Yetkisiz detay erişimi `FINANCE_EFFECT_ACCESS_DENIED` üretir veya alan maskelenir.

---

## 17. UI ile İlişki

UI fazında:

- Butonlar role göre gizlenebilir.
- Liste role göre sadeleşebilir.
- Bordro etkisi alanları role göre maskelenebilir.
- Ancak backend enforcement olmadan UI güvenlik sayılmaz.
- UI route guard sonraki fazdır; bu doküman kod değişikliği yapmaz.

---

## 18. Mock ile İlişki

**54** fazında mock role/scope enforcement bilinçli kapsam dışı bırakılmıştır.

Bu doküman sonrası mock enforcement kod fazı açılırsa:

- demo kullanıcı rolü veya request header ile rol simülasyonu yapılabilir.
- personel/bölüm kapsamı demo data üzerinden çözülebilir.
- permission + scope + state testleri eklenebilir.
- snapshot mutate edilmeden devam edilir.

---

## 19. Test Kararı

İleride kod fazında minimum testler:

- **BOLUM_YONETICISI** kendi bölümü dışı talebi göremez.
- **MUHASEBE** approve endpoint'inde `UNAUTHORIZED_REVISION_APPROVAL` alır.
- **BIRIM_AMIRI** finance effect detayında `FINANCE_EFFECT_ACCESS_DENIED` alır.
- **GENEL_YONETICI** `ONAY_BEKLIYOR` talebi onaylayabilir.
- `TASLAK` talep approve edilemez.
- `ONAYLANDI` talep cancel edilemez.
- List endpoint role göre filtrelenir.
- Detail endpoint scope dışı erişimi reddeder.
- create client'tan gelen `talep_eden_kullanici_id` değerine güvenmez.

---

## 20. Kapsam Dışı

Bu dokümanda yapılmayacaklar:

- Kod implementasyonu
- API/mock değişikliği
- UI ekranı
- Route guard
- Snapshot builder değişikliği
- Puantaj motoru değişikliği
- Serbest zaman motoru değişikliği
- Correction layer
- Bordro hesaplama
- Finans entegrasyonu

---

## 21. Sonraki Faz Seçenekleri

| Seçenek | Amaç | Risk | Öneri |
|---------|------|------|-------|
| **A. Revizyon Talebi Mock Scope Enforcement Kod Fazı** | 54 mock handler'larına role/scope enforcement eklemek. | Demo data kapsamı dikkatli kurulmalı. | Güçlü aday. |
| **B. Revizyon Talebi Correction Layer Kararı** | `ONAYLANDI` sonrası rapor/read model etkisi netleşir. | Snapshot ve bordro etkisi doğurur. | Koddan önce karar gerekir. |
| **C. Revizyon Talebi UI Karar Dokümanı** | Liste/detail/aksiyon görünürlüğü tanımlanır. | Backend enforcement kodlanmadan erken olabilir. | Bekleyebilir. |

---

## 22. Önerilen Sıradaki Adım

Önerilen sonraki adım, **「Revizyon Talebi Mock Scope Enforcement Kod Fazı Ön Teşhis」**tir. Çünkü 54 contract fazı ve bu 56 karar dokümanı sonrasında mock/API katmanda permission + scope + state kontrollerinin uygulanacağı owner dosyalar netleştirilmelidir.

**Önerilen dosya/kod fazı:**

57 — Revizyon Talebi Mock Scope Enforcement Ön Teşhis

---

## 23. Kapanış Cümlesi

Revizyon talebi scope / backend enforcement kararı, flat permission anahtarlarının tek başına yeterli olmadığını ve kapalı dönem revizyon workflow'unda personel, bölüm, bordro etkisi, talep sahipliği ve state kontrollerinin backend/API katmanında zorunlu olarak uygulanacağını netleştirir. UI görünürlük sağlayabilir ancak güvenlik kaynağı değildir.
