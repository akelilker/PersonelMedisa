# 131 — Unified Minimum 10 Yıl Saklama Politikası (Canonical)

**Tür:** Politika kilidi + teknik kapanış (gerçek imha YOK)
**Branch:** `fix/retention-unified-10-year-policy`
**Baseline main:** `fb507d1895a2dcdac55822ba6a5240ffeeaf63b1`
**Migration:** yeni migration **YOK** (`CODE_MIGRATION_TIP = 076`)
**Gap:** `MG-RET-PHYS-001` → **CLOSED_CONFIRMED**

---

## 1. Kilitlenen kararlar

| Karar | Değer |
| --- | --- |
| `RETENTION_POLICY` | `MINIMUM_10_YEARS` |
| `MIN_RETENTION_YEARS` | `10` |
| `SHORTER_CATEGORY_RETENTION` | `DISABLED` |
| `PERSONNEL_RETENTION_ANCHOR` | `EMPLOYMENT_END_DATE_OR_LATER_APPLICABLE_ANCHOR` |
| `ACTIVE_EMPLOYEE_DESTRUCTION` | `PROHIBITED` |
| `MISSING_ANCHOR` | `FAIL_CLOSED` |
| `LEGAL_HOLD_OVERRIDE` | `ENABLED` |
| `LONGER_LEGAL_RETENTION_WINS` | `YES` |

10 yıl bir **minimum tabandır, maksimum değildir.** Mevzuat veya hukuki durum daha uzun
saklama gerektiriyorsa daha uzun süre önceliklidir ve **kısaltılmaz**.

Kategori bazlı 5 yıl / 6 yıl gibi daha kısa şirket saklama süresi **kullanılmaz**.
"VUK 5 yıl olduğu için 5 yılda imha edilir" türü bir şirket politikası **yoktur**.

---

## 2. Süre hesabının başlangıcı

Kör `created_at + 10 yıl` algoritması **kullanılmaz**.

```
RETENTION_ELIGIBILITY_DATE = LATEST_APPLICABLE_RETENTION_ANCHOR + 10 YEAR
```

- Personel-bağlı veri/belgede effective anchor **en az** personelin işten ayrılış tarihidir.
- Kaydın kendi canonical tarihi (dönem kapanışı, karar tarihi vb.) ayrılış tarihinden
  **daha geç** ise en geç uygulanabilir anchor esas alınır.
- Personelle ilişkili olmayan dönemsel/kurumsal kayıtlarda mevcut canonical dönem/belge
  anchor'ı korunur; süre minimum 10 yıla yükseltilir.
- Anchor belirlenemiyorsa `MISSING_RETENTION_ANCHOR` → **imhaya uygun değildir**.

### Gerçek örnek (canonical test senaryosu)

| Olgu | Değer |
| --- | --- |
| İşe giriş | 2010 |
| İşten ayrılış | 2026-06-30 |
| Eski belge tarihi | 2010 |
| Yalnız belge yaşı ile sahte olgunluk | 2020 (**kullanılmaz**) |
| Canonical `retention_until` | **2036-06-30** |

2010 tarihli işe giriş belgesi dahil personelle ilişkili kayıtlar **2036'dan önce**
yalnız yaş hesabı nedeniyle imhaya uygun hale **gelmez**. Personel aktif olduğu sürece
fiziksel imha **yasaktır**.

---

## 3. Teknik owner'lar

| Sorumluluk | Owner |
| --- | --- |
| Minimum süre tabanı | `RetentionCategories::MIN_RETENTION_YEARS` |
| Kategori bazlı efektif süre (floor + longer-wins) | `RetentionCategories::retentionYearsForCategory()` |
| Takvim bazlı `retention_until` | `RetentionPolicyService::calculateRetentionUntil()` |
| Canonical anchor çözümü | `RetentionPolicyService::resolveTrigger()` + `RetentionPeriodTriggerResolver` |
| Personel-bağlı anchor floor | `RetentionPolicyService::applyPersonnelAnchorFloor()` |
| Rehire-safe ayrılış tarihi | `RetentionPolicyService::resolveTerminationDate()` |
| Legal hold guard | `RetentionPolicyService::hasActiveLegalHold()` |
| Fiziksel imha | `PhysicalDestructionService` + `RetentionDestructionHandlerRegistry` (15/15 typed) |

`retentionYearsForCategory()` daima `max(floor, declared)` döner; bu yüzden 10 yıldan
kısa bir kategori süresi **yapısal olarak erişilemezdir**, daha uzun bir süre ise korunur.

Personel-bağlı anchor floor **yalnız imha uygunluk yolunda** uygulanır; arşiv/manifest
lifecycle anchor'ı kendi canonical davranışını korur (aktif personel için manifest
üretimi bozulmaz).

---

## 4. Root cause (bu turda düzeltilen)

Süre politikası zaten tek ve 10 yıldı; 5 yıl veya <10 kategori değeri **hiç yoktu** ve
`created_at + 10` kısayolu **yoktu**. `TERMINATION_DATE` kategorilerinde anchor doğru
şekilde ayrılış tarihiydi.

**Gerçek boşluk:** `PERIOD_CLOSURE` anchor'ı ile çözülen fakat personel-bağlı olan
kayıtlarda (ör. `ONAY_AUDIT` QR puantaj karar ledger'ı, `personel_id` taşıyan dönem
kayıtları) anchor yalnız dönem/karar tarihiydi. Bu nedenle **aktif** bir personelin
personel-bağlı kaydı, dönem tarihi 10 yılı doldurduğu anda imha adayı olabiliyordu ve
işten ayrılış tarihi hesaba katılmıyordu.

Düzeltme: uygunluk değerlendirmesinde personel-bağlı bağlam için
`effective_anchor = latest(canonical anchor, employment_end_date)`; ayrılış tarihi yoksa
veya personel aktifse `TERMINATION_DATE_MISSING` ile **fail-closed**.

---

## 5. Test matrisi

| # | Senaryo | Beklenen | Kanıt |
| --- | --- | --- | --- |
| A | 2010 giriş / 2026 ayrılış / 2010 belge, 2026–2027'de | candidate **HAYIR** | `RetentionPolicyPureTestRunner` |
| B | Aynı kişi 2035'te | candidate **HAYIR** | `RetentionPolicyPureTestRunner` |
| C | Ayrılış + tam 10 yıl dolduktan sonra, legal hold yok | değerlendirmeye girebilir | `RetentionPhysicalPack3bMysqlTestRunner` |
| D | Aktif çalışan, 10 yıldan eski kayıt | candidate **HAYIR** | `RetentionPhysicalPack3bMysqlTestRunner` (`ONAY_AUDIT active employee fail-closed`) |
| E | Personel-bağlı + ayrılış tarihi NULL | **FAIL_CLOSED** | `RetentionPolicy053MysqlTestRunner` (`20 TERMINATION_DATE_MISSING`) |
| F | Legal hold, süre dolmuş | candidate **HAYIR** | `RetentionPolicy053MysqlTestRunner` |
| G | Kategori eski policy 5 yıl | effective minimum **10 yıl** | `RetentionPolicyPureTestRunner` |
| H | Kategori canonical >10 yıl | **kısaltılmaz** | `RetentionPolicyPureTestRunner` |
| I | Daha geç başka canonical anchor | latest applicable anchor kullanılır | `RetentionPhysicalPack3bMysqlTestRunner` (`latest applicable anchor = termination date`) |
| J | typed handler / plan hash / concurrency / dual control / idempotency | bozulmaz | Pack2 / Pack3B / Pack3C / concurrency runner'ları |

---

## 6. Bu turda yapılmayanlar

| Konu | Durum |
| --- | --- |
| Gerçek production DELETE / purge | **YOK** |
| `RETENTION_DESTRUCTION_EXECUTED` | **HAYIR** |
| `PRODUCTION_DESTRUCTION_MUTATION` | **0** |
| Yeni migration | **YOK** (`076` korunur) |
| Feature flag aktivasyonu | **YOK** (varsayılan OFF) |

---

## 7. Kapanış anlamı

`MG-RET-PHYS-001` = **CLOSED_CONFIRMED**: saklama politikası ve teknik imha mekanizması
hazır ve canonicaldır. Bu, bugün gerçek veri silindiği anlamına **gelmez**.

Gelecekte 10 yılını dolduran adayların mevcut güvenli request/approval/execution
operasyonundan (GM çift kontrol + plan hash + güncel yedek kanıtı) geçirilmesi **normal
işletim işidir; bug veya backlog değildir.** Bugün eligible gerçek aday bulunmadığı için
kullanıcıdan imha onayı bekleyen sürekli açık kayıt tutulmaz.
