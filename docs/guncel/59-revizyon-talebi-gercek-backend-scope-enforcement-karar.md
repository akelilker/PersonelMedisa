# 59 — Revizyon Talebi Gerçek Backend Scope Enforcement Kararı

---

## 1. Doküman Amacı

Bu doküman, 57 fazında mock API katmanında doğrulanan revizyon talebi permission + scope enforcement modelinin gerçek backend/API katmanında nasıl uygulanacağını karara bağlar. Amaç, demo header simülasyonundan gerçek authenticated user, personel/bölüm kapsamı ve finans görünürlüğü modeline geçmeden önce güvenlik sözleşmesini netleştirmektir.

---

## 2. Önceki Bağlam

- 50 ile kapalı dönem / audit workflow kararı alındı.
- 51 ile haftalık kapanış revizyon talebi state modeli tanımlandı.
- 52 ile rol/yetki kararı verildi.
- 53 ile 09 rol yetki matrisi güncellendi.
- 54 ile revizyon talebi contract kod iskeleti oluşturuldu.
- 56 ile scope/backend enforcement kararı alındı.
- 57 ile mock scope enforcement kodlandı.
- 58 ile mock scope enforcement checkpoint kapatıldı.
- Bu doküman, mock davranışını gerçek backend güvenlik sözleşmesine taşır.

---

## 3. Problem Tanımı

Gerçek backend'de şu sorular netleşmelidir:

- Authenticated user hangi kaynaktan alınır?
- Kullanıcının rolü ve kapsamı nereden çözülür?
- Bölüm yöneticisinin departman/personel kapsamı nasıl belirlenir?
- Birim amirinin bağlı personelleri nasıl belirlenir?
- Muhasebenin bordro/finans görünürlüğü nasıl sınırlandırılır?
- Client payload içindeki kullanıcı, rol veya departman bilgisine güvenilecek mi?
- Enforcement sırası ne olacak?
- Scope dışı erişimlerde 403 mü, boş liste mi dönecek?
- Finance alanları maskelenecek mi, hata mı üretilecek?

---

## 4. Ana Karar

- Gerçek backend'de scope enforcement zorunludur.
- Client tarafından gönderilen kullanıcı/rol/departman/personel kapsamı güvenilir kabul edilmez.
- Authenticated user backend session/JWT/context üzerinden çözülür.
- Flat permission aksiyon hakkını belirler; scope kontrolü ayrıca uygulanır.
- List endpoint yetkisiz kayıtları sessizce filtreler.
- Detail ve mutation endpoint'leri scope dışı erişimde 403 `REVISION_SCOPE_DENIED` döner.
- Approve/reject V1'de yalnız `GENEL_YONETICI` tarafından yapılır.
- Finance alanları role göre maske veya 403 politikasıyla korunur.
- Snapshot overwrite yapılmaz.

---

## 5. Backend Actor Context

Gerçek backend'de her istek için oluşturulacak context alanları:

- `user_id`
- `role`
- `departman_ids`
- `sube_ids`
- `linked_personel_id`
- `finance_scope`
- `can_view_finance_effect`
- `authenticated_at`
- `request_id`

Not:
Bu context client body'den alınmaz. Auth/session/JWT + backend kullanıcı/organizasyon verisinden türetilir.

---

## 6. Client Payload Güven Kararı

Client payload içinde aşağıdaki alanlar gönderilse bile backend bunlara güvenmez:

- `talep_eden_kullanici_id`
- `karar_veren_kullanici_id`
- `role`
- `departman_ids`
- `sube_ids`
- `finance_scope`
- `karar_zamani`
- `talep_zamani`

Backend set eder:

- `talep_eden_kullanici_id`
- `talep_zamani`
- `karar_veren_kullanici_id`
- `karar_zamani`
- durum geçişi
- audit metadata

---

## 7. Rol Bazlı Backend Scope Kararı

| Rol | Backend scope kaynağı | Görme | Mutation |
|-----|------------------------|-------|----------|
| `GENEL_YONETICI` | rol | tüm revizyon talepleri | tüm uygun talepler |
| `BOLUM_YONETICISI` | `user.sube_ids` veya `departman_ids` | kendi bölüm/departman kapsamındaki personellerin talepleri | kendi kapsamındaki personel için create; approve/reject yok |
| `MUHASEBE` | finance permission + bordro/finans kapsamı | `bordro_etki_var_mi` true olan ve finans kapsamına giren talepler | create edebilir; approve/reject yok |
| `BIRIM_AMIRI` | `linked_personel_id` veya amir-personel ilişkisi | kendi bağlı personeli/kendi birimi | sınırlı create/submit/cancel; approve/reject yok |

---

## 8. Endpoint Enforcement Sırası

Backend her revizyon endpoint'inde şu sırayı izler:

1. Authentication
2. Actor context üretimi
3. Permission kontrolü
4. Request body validation
5. Kaynak var mı kontrolü
6. Period kapalı mı kontrolü
7. Scope kontrolü
8. State transition kontrolü
9. Duplicate açık talep kontrolü
10. Finance visibility kontrolü
11. Mutation / response üretimi
12. Audit log

Not:
Hata mesajları bilgi sızdırmayacak şekilde tasarlanmalıdır.

---

## 9. List Endpoint Kararı

`GET /haftalik-kapanis/revizyon-talepleri`

Karar:

- `GENEL_YONETICI` tüm kayıtları filtre parametrelerine göre alır.
- `BOLUM_YONETICISI` kendi departman/personel kapsamındaki kayıtları alır.
- `MUHASEBE` yalnız bordro etkili ve finans kapsamındaki kayıtları alır.
- `BIRIM_AMIRI` yalnız kendi personeli/birimi kapsamındaki sade kayıtları alır.
- Yetkisiz kayıtlar listede dönmez.
- Finance alanları role göre maskelenebilir.

---

## 10. Detail Endpoint Kararı

`GET /haftalik-kapanis/revizyon-talepleri/:id`

Karar:

- Kayıt yoksa `TARGET_NOT_FOUND`.
- Kayıt varsa ama scope dışıysa `REVISION_SCOPE_DENIED`.
- Finance detail yetkisi yoksa:
  - V1 öneri: `bordro_etki_notu` maskelenir.
  - Alternatif: `FINANCE_EFFECT_ACCESS_DENIED`.
- Response hangi alanların maskelendiğini `meta` içinde belirtebilir.

---

## 11. Create Endpoint Kararı

`POST /haftalik-kapanis/revizyon-talepleri`

Karar:

- `revizyon.create` permission gerekir.
- Hedef personel backend'den okunur.
- Hedef personel actor scope içinde olmalıdır.
- Dönem kapalı değilse `PERIOD_NOT_CLOSED`.
- Aynı kaynak için açık talep varsa `REVISION_ALREADY_EXISTS`.
- `talep_eden_kullanici_id` backend `actor.user_id` olur.
- `talep_zamani` backend zamanı olur.
- Başlangıç durumu `TASLAK` olur.

---

## 12. Submit / Cancel Kararı

Submit:

- `revizyon.submit` permission gerekir.
- Talep `TASLAK` olmalıdır.
- Talep sahibi veya `GENEL_YONETICI` submit edebilir.
- Kapsam dışı talep submit edilemez.

Cancel:

- `revizyon.cancel` permission gerekir.
- `TASLAK` veya `ONAY_BEKLIYOR` durum için geçerlidir.
- Talep sahibi veya `GENEL_YONETICI` iptal edebilir.
- Terminal durum iptal edilemez.

---

## 13. Approve / Reject Kararı

- `revizyon.approve` veya `revizyon.reject` permission gerekir.
- Actor role `GENEL_YONETICI` olmalıdır.
- Talep `ONAY_BEKLIYOR` olmalıdır.
- `karar_veren_kullanici_id` backend `actor.user_id` olur.
- `karar_zamani` backend zamanı olur.
- `karar_notu` payload'dan alınabilir.
- Onaylanan talep snapshot'ı overwrite etmez.
- Correction layer üretimi ayrı fazdır.

---

## 14. Finance Visibility Kararı

Backend finance visibility için şu politikayı uygular:

- `GENEL_YONETICI`: tam görünüm
- `MUHASEBE`: tam finance görünümü, finance scope dahilinde
- `BOLUM_YONETICISI`: kendi bölümündeki finance etkisini V1'de görebilir
- `BIRIM_AMIRI`: `bordro_etki_notu` maskelenir, sadece `bordro_etki_var_mi` flag'i kalabilir

---

## 15. Hata Kodları

- `UNAUTHENTICATED`
- `UNAUTHORIZED_REVISION_REQUEST`
- `UNAUTHORIZED_REVISION_APPROVAL`
- `REVISION_SCOPE_DENIED`
- `FINANCE_EFFECT_ACCESS_DENIED`
- `PERIOD_NOT_CLOSED`
- `REVISION_ALREADY_EXISTS`
- `INVALID_STATE_TRANSITION`
- `TARGET_NOT_FOUND`
- `INVALID_BODY`
- `SNAPSHOT_IMMUTABLE`

---

## 16. Audit Log Kararı

Her mutation için backend audit log üretmelidir:

- create
- submit
- approve
- reject
- cancel

Minimum audit alanları:

- `actor_user_id`
- `action`
- `target_revizyon_talebi_id`
- `previous_state`
- `next_state`
- `request_id`
- `ip_address` (opsiyonel)
- `user_agent` (opsiyonel)
- `timestamp`

---

## 17. Mock ile Gerçek Backend Farkı

| Alan | Mock | Gerçek Backend |
|------|------|----------------|
| Actor | `X-Demo-User-Id` / `X-Demo-Role` | Auth session/JWT/context |
| Scope | demo data | organizasyon/personel yetki tabloları |
| Finance | `bordro_etki_var_mi` | finance permission + bordro scope |
| Audit | sınırlı | zorunlu audit log |
| Security | demo doğrulama | zorunlu güvenlik enforcement |

---

## 18. Test Kararı

Gerçek backend kod fazında minimum testler:

- Client `talep_eden_kullanici_id` gönderse bile backend override eder.
- `BOLUM_YONETICISI` başka departman için create edemez.
- `BOLUM_YONETICISI` başka departman detail erişiminde `REVISION_SCOPE_DENIED` alır.
- `MUHASEBE` approve endpoint'inde `UNAUTHORIZED_REVISION_APPROVAL` alır.
- `BIRIM_AMIRI` finance note göremez.
- `GENEL_YONETICI` approve edebilir.
- Terminal durumdaki talep mutation kabul etmez.
- List endpoint role göre filtrelenir.
- Audit log create/submit/approve/reject/cancel için oluşur.

---

## 19. Kapsam Dışı

Bu dokümanda yapılmayacaklar:

- Kod implementasyonu
- Mock değişikliği
- UI ekranı
- Route guard
- Snapshot builder değişikliği
- Puantaj motoru değişikliği
- Serbest zaman motoru değişikliği
- Correction layer
- Bordro hesaplama
- Finans entegrasyonu

---

## 20. Sonraki Faz Seçenekleri

| Seçenek | Amaç | Risk | Öneri |
|---------|------|------|-------|
| A. Gerçek Backend Enforcement Contract Kod Fazı | API/backend servislerinde actor context + scope enforcement. | Backend persist ve auth katmanı gerektirir. | Backend hazırsa güçlü aday. |
| B. Correction Layer Karar Dokümanı | `ONAYLANDI` sonrası rapor/read model etkisi netleşir. | Snapshot/rapor/bordro etkisi doğurur. | Backend yoksa önce karar dokümanı. |
| C. Revizyon Talebi UI Karar Dokümanı | Liste/detail/action görünürlüğünü tanımlar. | Backend enforcement olmadan UI erken olabilir. | Bekleyebilir. |

---

## 21. Önerilen Sıradaki Adım

Backend persist/auth katmanı hazır değilse, doğrudan gerçek backend koduna geçilmemelidir. Bu durumda en güvenli sonraki adım "Revizyon Talebi Correction Layer Kararı"dır. Çünkü `ONAYLANDI` durumunun rapor/read model etkisi henüz net değildir.

Önerilen dosya:

`docs/guncel/60-revizyon-talebi-correction-layer-karar.md`

---

## 22. Kapanış Cümlesi

Gerçek backend scope enforcement kararı, mock katmanda doğrulanan permission + scope + finance visibility modelinin gerçek backend'de auth context, organizasyon kapsamı, state transition ve audit log ile enforce edilmesini karara bağlar. Client payload güvenilir kabul edilmez; güvenlik backend/API katmanında uygulanır.
