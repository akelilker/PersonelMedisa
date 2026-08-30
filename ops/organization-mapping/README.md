# Production organization mapping specs

Bu dizin, üretimdeki ilk şirket/şube/SGK/lokasyon eşlemesi için **onaylanmış**
mapping spec dosyalarını tutar. `apply-organization-mapping.yml` yalnız bu
dizindeki `*.json` dosyalarını kabul eder (`ops/organization-mapping/<ad>.json`).

Bu dizin şu anda **gerçek bir production spec içermez**. Spec ancak salt-okunur
envanter (`ops-organization-inventory.yml`) çalıştırılıp exact satır verisi
görüldükten sonra yazılır; bu paket kodu kurar, kararı doldurmaz.

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
- User scope (`user_sirketler`, `user_sgk_isverenler`) rollout'u bu operasyonun
  **dışındadır** ve bu owner tarafından yazılmaz.
