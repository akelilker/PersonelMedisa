# Production organization mapping specs

Bu dizin, üretimdeki ilk şirket/şube/SGK/lokasyon eşlemesi için **onaylanmış**
mapping spec dosyalarını tutar. `apply-organization-mapping.yml` yalnız bu
dizindeki `*.json` dosyalarını kabul eder (`ops/organization-mapping/<ad>.json`).

## Historical status (canlı truth değil)

İlk production mapping **uygulanmıştır** (`MG_SIRKET_SUBE_PROD_MAPPING_001` =
`CLOSED_CONFIRMED`, apply run `33342644722`). Bu dizindeki
`mg-sirket-sube-initial-mapping-001.json` (ve benzeri) dosyalar **historical
preimage / apply artifact**tır: o anki envanter checksum + satır preimage'ını
pinler. Bunları bugünkü production truth sanıp yeniden apply etmek yasaktır;
canlı şube adları / şirket bağları DB + `SubeReadModel` üzerinden okunur.

Canlı özet (display modeli): `subeler.ad` = kısa ad; global görünen ad =
`SubeReadModel.tam_ad` (DB kolonu değildir).

**Medisa çalışma lokasyonu → şube map = APPLIED** (apply run `34037103819`;
fresh inventory `34062358628` exact: 1→5, 2→2, 3→6, 4→12, 5→1, 6→4, 7→13).
İlk şirket/şube/SGK mapping historical preimage yeniden uygulanmaz.

**Medisa user scope (`user_sirketler` / `user_sgk_isverenler`) = APPLIED**
(PR #271; fresh totals sirket=3 / sgk=3). Karyapı / Şenay grants **DEFERRED**.
Organization-mapping owner user scope yazmaz; Yönetim API ayrıdır.

## Publication boundary önkoşulu

Bu akışın gizliliği tamamen repository visibility'sine bağlıdır ve aşağıdaki
adımlar **yalnız private repository'de** çalıştırılabilir. Envanter
artifact'ı ve bu dizindeki spec dosyası production satır preimage'ları taşır;
public repository'de hem artifact hem repo dosyası signed-in herhangi bir
kullanıcıya açıktır. Bu yüzden her iki workflow da repository private değilse
control-plane request'i bırakmadan önce fail-closed durur
(`PUBLIC_REPOSITORY_ARTIFACT_EXPOSURE`,
`PUBLIC_REPOSITORY_SPEC_TRANSPORT_UNSAFE`). Guard'ı input ile aşmak mümkün
değildir ve `retention-days` düşürmek bu koşulun yerine geçmez.

## Spec nasıl üretilir

1. `ops-organization-inventory.yml` çalıştır (mutation yok). Artifact
   `organization-inventory.json` ve log'daki `INVENTORY_CHECKSUM` alınır.
2. Artifact'taki exact `id`/`kod`/`ad`/`durum`/relation değerleri spec'in
   `expected_*` alanlarına **birebir** yazılır. Tahmin edilen bir değer yoktur:
   preimage eşleşmezse operasyon fail-closed durur.
3. `metadata.inventory_checksum` alanına adım 1'deki checksum yazılır.
4. `ORGANIZATION_MAPPING_PREFLIGHT` ile salt-okunur kapı çalıştırılır.
5. Preflight PASS ise ve delta review edilmişse `ORGANIZATION_MAPPING_APPLY`
   çalıştırılır (zorunlu backup + tek transaction + postcheck).

Şema, alan allowlist'i ve tüm kurallar
`api/src/Services/Organizasyon/OrganizationMappingSpec.php` sahibindedir.
Örnek şekil için `tests/fixtures/organization-mapping-spec.test-only.json`
dosyasına bakın — o dosyadaki kodlar **yalnız testlik**tir, production değeri
değildir.

## Bağlayıcı iş kararları (spec doldurulurken uygulanacak)

Şirketler: **Medisa**, **Karyapı**, **Şenay Mobilya**.

Teknik şirket kodları gerçek envanter görülmeden production spec'e
kilitlenmemiştir; repo konvansiyonu kısa, büyük harf, ASCII koddur.

| sube_id | Şirket        | Onaylı kısa ad                 |
| ------- | ------------- | ------------------------------ |
| 1       | Medisa        | `Fabrika`                      |
| 2       | Medisa        | `Giresun`                      |
| 4       | Medisa        | `Kayseri`                      |
| 5       | Medisa        | `Ankara`                       |
| 6       | Medisa        | `İstanbul`                     |
| 7       | Karyapı       | onaylı kısa ad yok → mevcut ad |
| 8       | Karyapı       | `Ankara`                       |
| 9       | Karyapı       | `Kayseri`                      |
| 10      | Karyapı       | `İstanbul`                     |
| 11      | Şenay Mobilya | onaylı kısa ad yok → mevcut ad |

Kurallar:

- ID 3 **oluşturulmaz**; spec'te ID 3 mapping'i reddedilir.
- ID 1 korunur, duplicate `Fabrika` satırı üretilmez.
- ID 7 ve 11 için kısa ad **tahmin edilmez**: `approved_ad` alanı `null`
  bırakılır ve mevcut ad korunur.
- Şube `kod` değeri hiçbir koşulda değişmez.
- SGK işvereni → şirket eşlemesi yalnız envanterdeki exact `kod`/`ad` ve
  doğrulanmış iş anlamı ile yapılır; "üç kayıt var, üç şirket var" gerekçesiyle
  otomatik bire bir eşleme yapılmaz.
- Fiziksel şube, SGK/bordro işverenini belirlemez; personelin fiziksel şubesi bu
  operasyonda değişmez.
- Çalışma lokasyonu ilişkisi kanıtlanamıyorsa `target_sube_id: null` ile deferred
  bırakılır. Lokasyonun NULL kalması `data_ready`'yi bloke etmez.
  **Medisa 7 lokasyon map’i production’da APPLIED’dır** (yukarıdaki poststate);
  bu madde historical preimage kurallarını anlatır, canlı deferred iddiası değildir.
- User scope (`user_sirketler`, `user_sgk_isverenler`) rollout'u bu operasyonun
  **dışındadır** ve bu owner tarafından yazılmaz. Medisa grants PR #271 ile
  ayrı owner’dan APPLIED; Karyapı/Şenay DEFERRED.
