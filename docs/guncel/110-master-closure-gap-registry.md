CODE_MIGRATION_TIP: 075
PRODUCTION_MIGRATION_TIP: 075

# 110 — Canonical Closure / Gap Registry

**Tür:** Güncel durum kaydı ve sonraki iş seçimi için tek referans.
**Güncelleme:** 2026-08-27
**Kapsam:** Yalnızca dokümantasyon senkronu. Kod, migration, production veri ve feature flag değiştirilmedi.

## Migration durumu

| Alan | Değer | Kanıt / sınır |
| --- | --- | --- |
| CODE_MIGRATION_TIP | **075** | Repodaki son migration: `075_personel_account_activation.sql` |
| PRODUCTION_MIGRATION_TIP | **075** | Canonical production migration ucu `075`; `071`–`075` production’da uygulanmıştır |
| Canlı migration doğrulaması | **AYRI SALT-OKUNUR İŞ** | Yeni migration eklenince tip eşitliği `npm run check:sync` ile fail-closed doğrulanır |

## Durum sözlüğü

| Durum | Anlamı |
| --- | --- |
| **CLOSED** | Karar ve kod owner’ı tamam; bu registry kapsamında yeni kod işi yok |
| **OPS_ROLLOUT** | Kod/şema hazır, fakat canlı operasyon veya yetkili aktivasyon ayrı |
| **USER_GATED** | İnsan onayı, canlı veri veya iş kararı olmadan ilerlemez |
| **READ_ONLY_VERIFY** | Güvenli inceleme işi; yazma yetkisi gerektirmez |
| **DECISION_REQUIRED** | İş/mevzuat kararı olmadan açılmaz; kod hatası değildir |
| **FUTURE** | Bilinçli sonraki ürün çıktısı; bug / production blocker değildir |
| **OUT_OF_SCOPE** | Ürün kapsamı dışında bırakılmıştır |
| **INTENTIONAL_DEFER** | Bilinçli olarak ertelenmiştir; hata veya yarım kod değildir |
| **TECH_DEBT** | Ürün teslimini engellemeyen bakım işi |

## Kapanan teknik başlıklar

| ID | Konu | Durum | Owner / kanıt |
| --- | --- | --- | --- |
| `MG-OT-YEAR-POL-001` | Yıl değişen fazla çalışma 270 saat politikası | **CLOSED** | `ROLLING_12_MONTH_ACTUAL_DATE_V1`; gerçek tarih dağılımı + rolling 12 ay hard guard. Bkz. `117-final-code-gap-pack5.md`. |
| `MG-OT-YEAR-PATH-001` | 270 saat limitinin tek teknik owner’ı | **CLOSED** | `FazlaCalismaYillikLimitService`; ISO hafta yalnız görünüm kimliği. |
| `MG-SZ-6M-001` | Serbest zaman 6 ay deadline hesaplama ve takip yüzeyi | **OPS_ROLLOUT** | `SerbestZamanDeadlineService`, rapor API/UI ve 30 gün operasyon uyarısı hazır. Bordroya otomatik sert blok **yok**. Bkz. `116-serbest-zaman-pack4b-closure.md`. |
| `MG-RET-PHYS-001` | Saklama manifesti ve fiziksel imha workflow’u | **OPS_ROLLOUT** | Typed handler’lar, manifest bütünlüğü ve fail-closed gate’ler var. Gerçek imha varsayılan olarak **kapalıdır**. Bkz. `114-retention-physical-destruction-pack3c-final.md`, `118-production-migration-rollout-059-064.md`. |
| `MG-ORG-ATTR-001` | Bölüm / Birim / Pozisyonun canonical personel modeli | **CLOSED** | Native owner modeli Pack6 ile kilitlendi. Bkz. `120-org-structure-pack6.md`. |
| `MG-IMPORT-MAP-001` | Şube-Departman import kontratı | **CLOSED** | Açık aktif Şube + aktif Departman modeli; eski sparse matrix import bloke etmez. Bkz. `124-personnel-import-open-branch-department.md`. |
| `MG-OPS-QR-001` | QR attendance teknik pipeline | **CLOSED** | Ürün kodu tamam; sonraki uygulama adımları ayrı personel/operasyon rollout’udur. |
| `MG-MIG-071-075` | Org hierarchy / short codes / fixture archive / QR correction / secure activation şeması | **CLOSED** | Production migration ucu `075`; `071`–`075` uygulanmıştır. |

## Gerçekten açık işler

| Öncelik | ID | Konu | Durum | Sonraki güvenli adım |
| --- | --- | --- | --- | --- |
| P0 | `MG-OPS-PERSONEL-001` | Canonical iç personel import reconciliation | **USER_GATED** | **SON KULLANICI VERİLERİYLE YENİDEN RECONCILE EDİLECEK.** Eski dry-run sayıları kesin gerçek diye yeniden üretilmez; insan girdileri tamamlanmadan apply yok. Fuzzy eşleme yok. |
| P0 | `MG-EXT-ORG-DATA-001` | Dış kaynak organizasyon / görev kararları | **USER_GATED** | Kalan exact Departman, Bölüm, Birim ve Görev eşleşmelerini insan onayıyla tamamla. Tahmin/fuzzy eşleme yoktur. |
| P1 | `MG-OPS-SGK-CAT-001` | SGK canlı katalog/politika salt-okunur doğrulaması | **READ_ONLY_VERIFY** | Resmî kaynak etkinlik tarihleri ve canlı katalog durumunu salt-okunur doğrula; kanıtsız seed/aktivasyon yapma. Kod gap değildir. |
| P1 | `MG-OPS-UBGT-001` | UBGT canlı takvim/projeksiyon salt-okunur doğrulaması | **READ_ONLY_VERIFY** | Canonical resmî takvim girdileri, yarım gün politikası ve canlı projeksiyonu salt-okunur karşılaştır. Kod gap değildir. |
| P1 | `MG-RET-PHYS-001` | Gerçek fiziksel imha aktivasyonu | **USER_GATED** | Ayrı bakım penceresi, güncel yedek/geri dönüş kanıtı, çift kontrol ve açık feature flag yetkisi olmadan etkinleştirme yok. |
| P1 | `MG-SZ-6M-001` | Serbest zaman 6 aylık operasyon sahipliği | **USER_GATED** | İK’nın takip sahipliği ve aksiyon akışı belirlenir; mevcut sistem uyarı/rapor yüzeyiyle işletilir. Yeni payroll hard block varsayılmaz. |
| P2 | `MG-OPS-ORG-001` | Gerçek personel organizasyon FK eşlemesi | **USER_GATED** | Import verisi tamamlanmadan toplu personel yazımı yapılmaz. |
| P2 | `MG-SUBE-YONETICI-001` | `SUBE_YONETICISI` gerçek kullanıcı ataması | **USER_GATED** | Hata değildir; hangi kullanıcıların hangi şubeye atanacağı iş sahibi tarafından belirlenirse ele alınır. |
| P3 | `MG-ROLE-ENUM-DEBT-001` | Eski rol ENUM teknik borcu | **TECH_DEBT** | Kullanım envanteri ve migration etkisi çıkarılmadan daraltma yapılmaz. |

## Bilinçli ertelenen / kapsam dışı / karar bekleyen (bug değil)

| ID | Konu | Durum | Not |
| --- | --- | --- | --- |
| `MG-EXC-WORK-001` | Zorunlu / olağanüstü çalışma istisna modeli | **DECISION_REQUIRED** / **USER_GATED** | Kod hatası değildir; iş/mevzuat kararı olmadan açılmaz. |
| `MG-PAY-PDF-001` | Bordro PDF çıktısı | **FUTURE** | Üretim blocker değildir. |
| `MG-BANK-FILE-001` | Banka ödeme dosyası | **FUTURE** | Üretim blocker değildir. |
| `MG-SGK-BILDIRGE-001` | SGK bildirgesi çıktısı | **FUTURE** | Üretim blocker değildir. |
| `MG-SELF-PAY-001` | PERSONEL maaş/bordro self-view | **OUT_OF_SCOPE** | S3A kapsamı dışında. |
| `MG-QR-REV-UX-001` | QR anomaly → kontrollü revizyon UX | **INTENTIONAL_DEFER** | Hint only; bilinçli erteleme. |
| `MG-FSC-025-001` | FSC %25 aktif bandı | **INTENTIONAL_DEFER** | S87 ile kapalı. |

## Özellikle yanlış önceliklendirilmemesi gerekenler

1. **Saklama manifesti, fiziksel imha ve serbest zaman 6 ay takibi yeni code-gap değildir.** Kod ve güvenlik gate’leri vardır; açık olan taraf canlı işletim / yetkilendirmedir.
2. **Gerçek imhayı açmak hızlı iş değildir.** Bu bir veri silme operasyonudur; feature flag’in kapalı olması beklenen güvenlik durumudur.
3. **270 saat yıl değişimi için yeni iş kararı aranmaz.** Mevcut karar rolling 12 ay gerçek tarih modelidir.
4. **`DIS_KAYNAK` operasyonlarını açmak mevcut directory-only sınırını genişletir.** SGK, bordro, puantaj, izin, fazla çalışma ve serbest zaman taraflarına kapı açılması ayrı ürün/iş kuralı onayı ister.
5. **Personel importu teknik bir toplu yazma değildir.** Son kullanıcı verileriyle yeniden reconcile edilmeden apply yapılmaz; fuzzy eşleme yoktur.
6. **Bordro PDF / banka dosyası / SGK bildirgesi / FSC / QR-revizyon UX bug değildir.** FUTURE / INTENTIONAL_DEFER / OUT_OF_SCOPE / DECISION_REQUIRED olarak izlenir.

## Hızlı, güvenli çalışma sırası

1. Canlı SGK katalog/politika salt-okunur doğrulaması.
2. Canlı UBGT takvim/projeksiyon salt-okunur doğrulaması.
3. Personel import’un son kullanıcı verileriyle yeniden reconcile edilmesi.
4. Yeni dry-run; yalnız sonuç tamamen geçerliyse import apply için ayrı onay talebi.

## Referans dokümanlar

- `114-retention-physical-destruction-pack3c-final.md`
- `116-serbest-zaman-pack4b-closure.md`
- `117-final-code-gap-pack5.md`
- `118-production-migration-rollout-059-064.md`
- `120-org-structure-pack6.md`
- `124-personnel-import-open-branch-department.md`
- `125-pack7b-production-dry-run.md`
- `126-pack7d-identity-recovery.md`
- `127-external-worker-directory-only.md`
- `129-pack7h-full-reconciliation.md`
