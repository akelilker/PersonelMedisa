# 145 — Personel aktif mevcudu source-of-truth (2026-09-21)

## Amaç

Bu kayıt, PERSONEL first-login rollout sayıları ile gerçek çalışan mevcudunun birbirine karıştırılmasını önlemek için oluşturulmuştur.

## İnsan Kaynakları kaynak dosyası

Kaynak: `guncel_personel_agu_temizlenmis_v3.xlsx` / `Personel Veri Bankasi`.

Excel snapshot sonucu:

- toplam kişi satırı: **145**
- işten ayrılmış: **1** — Görkem Vural, 30.08.2026
- aktif personel mevcudu: **144**
- mükerrer ad/TC kaydı: **0**

Aktif personel lokasyon kırılımı:

| Lokasyon | Aktif |
| --- | ---: |
| Karabük | 114 |
| Giresun | 12 |
| Kayseri | 7 |
| İzmir | 5 |
| İstanbul | 4 |
| Ankara | 1 |
| Sakarya | 1 |
| **Toplam** | **144** |

Aktif personel grup kırılımı:

| Grup | Aktif |
| --- | ---: |
| Mavi Yaka | 102 |
| Beyaz Yaka | 40 |
| Diğer | 2 |
| **Toplam** | **144** |

## 125 sayısı neden farklıydı?

**125 bir personel mevcudu değildir.** 19.09.2026 tarihli tek seferlik PERSONEL first-login credential rollout'unun hedef kullanıcı-account cohort sayısıdır.

Tarihsel rollout inventory:

- `PERSONEL` rolündeki kullanıcı hesabı: **134**
- rollout dışında bırakılan: **9**
  - kullanıcı hesabı pasif: 4
  - bağlı personel pasif: 5
- rollout hedefi: **125**

Dolayısıyla aşağıdaki iki kavram birbirine eşitlenmemelidir:

- **Aktif personel mevcudu = 144**
- **Tarihsel first-login rollout target = 125**

Aradaki fark bir headcount hatası değildir; farklı evrenler sayılmıştır. Rollout yalnız `users.rol = PERSONEL` olan, aktif ve aktif personele bağlı hesapları saymıştır. Yönetim rolleri, hesabı olmayan personel, pasif kullanıcı/personel ve snapshot zaman farkları bu cohort dışında kalabilir.

## Canonical kullanım

- Güncel çalışan mevcudu sorularında **125 kullanılmaz**.
- 134/125 değerleri yalnız 19.09.2026 rollout kanıtı/tarihçesi olarak değerlendirilir.
- Runtime veya iş kuralına sabit personel mevcudu yazılmaz; güncel sayı canlı personel verisi / doğrulanmış İK kaynağından üretilir.
- Yeni işe giriş/çıkış oldukça 144 doğal olarak değişebilir; bu dosya tarihli snapshot'tır.

STATUS = LOCKED_HEADCOUNT_SNAPSHOT
ACTIVE_HEADCOUNT_2026_09_21 = 144
HISTORICAL_FIRST_LOGIN_ROLLOUT_TARGET_2026_09_19 = 125
