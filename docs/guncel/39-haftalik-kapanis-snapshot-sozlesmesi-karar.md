# Medisa Personel ve Puantaj Yönetim Sistemi

## Haftalık Kapanış Snapshot Sözleşmesi Kararı

**Sürüm:** V1 Karar (kod fazı değil — sözleşme çivileme)  
**Tarih:** 2026-05-31  
**Ön koşul / karar zemini:** Faz E teşhis raporu, `docs/guncel/38-puantaj-mevzuat-faz-e-serbest-zaman-270-saat-karar.md`, haftalık kapanış kod teşhisi (2026-05-31)

---

## 1. Amaç

Faz E sonrası yapılan teşhis, haftalık kapanışın bugün **gerçek bir hesap snapshot’ı üretmediğini** göstermiştir. Mevcut `POST /api/haftalik-kapanis` yanıtı yalnızca metadata (`id`, hafta aralığı, `departman_id`, `state: KAPANDI`, `personel_sayisi`) taşır; personel bazlı fazla çalışma, süre özeti ve compliance bilgisi persist edilmez.

Bu karar dokümanının amacı, **kod fazına geçmeden önce** haftalık kapanış snapshot’ının:

- neyi mühürleyeceğini,
- hangi alanları taşıyacağını,
- hangi alanları bilinçli olarak dışarıda bırakacağını,
- serbest zaman ve yıllık 270 saat fazlarına hangi bağımlılığı sağlayacağını

netleştirmektir.

**Ana mesaj:** Haftalık kapanış yalnızca “KAPANDI” metadata response’u değildir. İleride rapor, mevzuat kontrolü, yıllık fazla çalışma takibi ve serbest zaman altyapısı için **mühürlü hesap snapshot’ı** olmalıdır. Canlı hook cache ve günlük puantaj ekranındaki haftalık ön izleme bu kaynağın yerine geçmez.

Bu belge implementasyon dokümanı değildir. Kod, test, UI ve API değişikliği bu karar kapsamında açılmaz.

---

## 2. Mevcut Durum Teşhisi

| Bulgu | Durum |
|-------|--------|
| Haftalık kapanış UI | Kaldırılmış; `/haftalik-kapanis` rotası ana sayfaya yönlendirilir (`src/app/routes.tsx`) |
| API client | `createHaftalikKapanis` yalnızca `src/api/haftalik-kapanis.api.ts` ve unit testte kullanılır |
| Hook / page / service hattı | Haftalık kapanış API’sini **çağırmıyor** |
| Mock / stub response | Metadata düzeyinde: `id`, `hafta_baslangic`, `hafta_bitis`, `departman_id`, `state`, `personel_sayisi` |
| Haftalık fazla çalışma | `puantaj-hesap-motoru` + `usePuantaj` + `GunlukPuantajPage` hattında **canlı ön izleme** olarak var |
| FM persist | Haftalık `fazla_calisma_dakika` kapanış snapshot’ına **yazılmıyor** |
| Compliance | Motor/hook tarafında üretiliyor; API upsert’e persist edilmiyor; kapanışa **taşınmıyor** |
| Fazla sürelerle çalışma | Kapanış snapshot’ında **yok**; motor V1 tam süreli varsayımıyla yalnızca 45 saat eşiği kullanıyor |
| Personel × hafta mühürlü veri | **Yok** — 270 saat ve serbest zaman için gerekli temel kaynak eksik |
| Tip sözleşmesi | `HaftalikKapanisSonuc` gevşek (`[key: string]: unknown`); snapshot alanları tanımlı değil |

**Sonuç:** Faz E karar belgesindeki “kodlanmayacak” kararı bu teşhisle **desteklenmektedir**. Yıllık 270 saat ve serbest zaman workflow, güvenilir haftalık snapshot olmadan implemente edilemez.

---

## 3. Karar: Snapshot Granularity

**Karar:** Haftalık kapanış snapshot’ı **departman özeti değil**, **`personel_id × hafta` satırı** bazında düşünülecektir.

| Gerekçe | Açıklama |
|---------|----------|
| 270 saat yıllık takip | Personel bazlıdır |
| Serbest zaman hakkı | Personel bazlıdır |
| Compliance uyarıları | Personel bazlı üretilir |
| Rapor ve denetim izi | Personel bazlı satır gerekir |
| Departman özeti | Yalnızca **üst aggregate** olabilir; ana kaynak **olamaz** |

Bir `POST /api/haftalik-kapanis` çağrısı, ilgili hafta ve kapsam (ör. departman) için **N adet personel snapshot satırı** üretir ve tek bir `kapanis_id` altında toplar.

---

## 4. Minimum Snapshot Alanları

### 4.1 Zorunlu çekirdek alanlar

| Alan | Tip (hedef) | Açıklama |
|------|-------------|----------|
| `snapshot_id` | string / number | Satır birincil anahtarı |
| `kapanis_id` | string / number | Kapanış işlemi üst kimliği |
| `personel_id` | number | Personel referansı |
| `departman_id` | number | Snapshot anındaki departman (denormalize) |
| `hafta_baslangic` | YYYY-MM-DD | Pazartesi |
| `hafta_bitis` | YYYY-MM-DD | Pazar |
| `yil` | number | Takvim yılı (dönem kararı netleşene kadar takvim yılı varsayımı) |
| `hafta_no` | number | ISO veya ürün kararı ile hafta numarası |
| `state` | enum | `KAPANDI` (satır mühürlendi) |
| `kaynak_versiyon` | string | Hesap motoru / sözleşme sürümü (ör. `v1-haftalik-snapshot`) |

### 4.2 Süre alanları

| Alan | Tip | Açıklama |
|------|-----|----------|
| `toplam_net_dakika` | number | Haftalık net çalışma toplamı |
| `normal_calisma_dakika` | number | 45 saat eşiğine kadar normal süre |
| `fazla_calisma_dakika` | number | 45 saat üstü fazla çalışma |
| `fazla_surelerle_calisma_dakika` | number | V1 tam süreli modelde **0 olabilir**; alan sözleşmede **zorunlu** (ileride %25 katmanı) |
| `eksik_sure_dakika` | number | Haftalık eksik süre toplamı (geç/erken/devamsızlık kaynaklı) |

### 4.3 Hak / durum alanları

| Alan | Tip | Açıklama |
|------|-----|----------|
| `tam_hafta_verisi` | boolean | 7 günlük puantaj kaynağı tam mı |
| `hafta_tatiline_hak_kazandi_mi` | boolean | Hafta tatili hakkı özeti |
| `eksik_gun_sayisi` | number | Kaynak günlerde eksik / null kayıt sayısı |
| `manuel_inceleme_gerekli_mi` | boolean | Hafta/personel düzeyinde manuel inceleme bayrağı |

### 4.4 Compliance alanları

| Alan | Tip | Açıklama |
|------|-----|----------|
| `compliance_uyarilari` | array | `{ code, message, level? }[]` — kapanış anındaki uyarılar |
| `compliance_uyari_sayisi` | number | Denormalize sayaç |
| `kritik_uyari_var_mi` | boolean | `level === KRITIK` var mı |

### 4.5 Denetim alanları

| Alan | Tip | Açıklama |
|------|-----|----------|
| `hesaplama_zamani` | ISO datetime | Snapshot üretim zamanı |
| `hesaplayan_kullanici_id` | number \| null | Tetikleyen kullanıcı; sistem job ise null + not |
| `kaynak_gun_sayisi` | number | Hesaba dahil edilen günlük kayıt sayısı |
| `notlar` | string \| null | Eksik veri, kısmi hafta vb. açıklamalar |

---

## 5. Bilinçli Olarak Bu Fazda Dışarıda Kalacak Alanlar

Aşağıdaki alanlar **bu karar dokümanında modellenmeyecek** ve snapshot sözleşmesine **dahil edilmeyecektir**:

| Alan / konu | Gerekçe |
|-------------|---------|
| `odeme_tipi` | Serbest zaman vs ücret tercihi — ayrı workflow kararı (Faz E3) |
| `serbest_zaman_hak_olusan_dakika` | Hak oluşumu event/bakiye fazına bırakılır |
| `serbest_zaman_kullanim_event` | Kullanım süreci ayrı event modeli |
| `serbest_zaman_bakiye` | Bakiye servisi henüz yok |
| `serbest_zaman_6_ay_son_tarih` | 6 ay takibi E3 kapsamı |
| `yillik_270_saat_toplami` | Yıllık aggregate ayrı endpoint/faz (E1) |
| `fazla_calisma_onayi_var_mi` | Faz D1 karar belgesi — personel/onay verisi yok |
| Bordro ödeme sonucu | Finans katmanı |
| Ücret tutarı kesin net etkisi | Ön izleme / aday model; snapshot parasal kesinlik taşımaz |

**Gerekçe:** Bu alanlar haftalık snapshot’ın **üzerine kurulacak** sonraki karar fazlarıdır. Bu doküman yalnızca snapshot sözleşmesini çiviler; serbest zaman ve 270 saat implementasyonu açmaz.

---

## 6. Fazla Çalışma Akış Kararı

### 6.1 Canlı ön izleme vs mühürlü snapshot

| Katman | Rol | Mevzuat / rapor kaynağı mı? |
|--------|-----|----------------------------|
| `usePuantaj` + cache | Canlı haftalık ön izleme | **Hayır** |
| `GunlukPuantajPage` haftalık kart | Operasyonel görünürlük | **Hayır** |
| Haftalık kapanış snapshot | Mühürlü personel × hafta kaydı | **Evet** |

**Karar:** Canlı hook cache sonucu tek başına mevzuat takibi için kaynak **kabul edilmeyecektir**. Mevzuat ve rapor kaynağı **mühürlü snapshot** olacaktır.

### 6.2 Hedef akış

```text
Günlük puantaj verisi
  → hesap motoru (net süre, HT hak, FM dakika)
  → haftalık personel özeti (personel_id × hafta)
  → haftalık kapanış snapshot (persist, read-only)
  → rapor / yıllık toplam (E1) / serbest zaman fazı (E2/E3)
```

Motor bugün `hesaplaHaftalikCalismaOzeti` ile haftalık FM üretebilir; kapanış fazında bu hesap **aynı motor kurallarıyla** çalıştırılıp snapshot satırına yazılacaktır. Elle girilen FM değeri **kabul edilmeyecektir**.

---

## 7. Compliance Uyarıları Kararı

**Karar:** Kapanış anında ilgili hafta/personel için üretilen compliance uyarıları snapshot’a **taşınmalıdır**.

Kapsam (örnek — mevcut kod kalıbı):

- Günlük: `MAX_DAILY_LIMIT`, `GECE_MESAI`, `GECE_CALISMASI_7_5_SAAT_ASIMI`
- Kayıt: `DEVAMSIZLIK_UCRET_ETKISI_ADAYI`, `HAFTA_TATILI_HAK_KAYBI_ADAYI`
- Haftalık merge: `UBGT_FAZLA_MESAI_CAKISMASI`, `ONSEKIZ_YAS_ALTI_FAZLA_CALISMA`

**Sınırlar:**

- Uyarıların parasal sonucu **kesin bordro etkisi** olarak yorumlanmayacaktır.
- Aday / uyarı / manuel inceleme ayrımı korunacaktır (Faz B ilkesi).
- `compliance_uyarilari[].code` string olarak taşınabilir; ileride typed enum’a sertleştirilecektir.
- Günlük puantaj API upsert’inde compliance persist kuralı **değişmez**; snapshot ayrı yazım katmanıdır.

`tam_hafta_verisi === false` iken haftalık compliance uyarıları (ör. 18↓ FM, UBGT çakışması) snapshot’a **yazılmamalı** veya `notlar` ile “eksik veri” işaretlenmelidir (D2/D1 false-positive ilkesi).

---

## 8. Kilit ve Mühür Ayrımı

Haftalık kapanış ile aylık mühür **aynı şey değildir**.

| Kavram | Kapsam | Mevcut kod |
|--------|--------|------------|
| **Haftalık kapanış** | Haftalık hesap özetini mühürler; personel × hafta snapshot | Stub API; gerçek kilit yok |
| **Aylık mühür** | `muhurleAylikPuantaj` — puantaj `MUHURLENDI` state | `GunlukPuantajPage` + mock |

**Karar:**

- Bu doküman **haftalık snapshot sözleşmesini** tanımlar.
- Aylık mühür davranışını **değiştirmez**.
- İleride haftalık kapanış sonrası ilgili hafta günlük puantaj düzenlemesi `409 PERIOD_LOCKED` ile engellenecektir (`docs/guncel/05-state-flow-api-kontrati.md` hedefi); bu karar dokümanı o kilidi **tanımlar**, implemente etmez.

---

## 9. API Kararı

Bu fazda kod yazılmayacaktır. Hedef sözleşme aşağıdadır.

### 9.1 POST — haftayı kapat

**Endpoint:** `POST /api/haftalik-kapanis`

**İstek (mevcut ile uyumlu):**

```json
{
  "hafta_baslangic": "2026-04-06",
  "hafta_bitis": "2026-04-12",
  "departman_id": 3
}
```

**Hedef davranış:**

1. İlgili hafta günlük puantajlarını toplar.
2. Personel bazlı snapshot satırlarını üretir (§4 alanları).
3. `kapanis_id` döner.
4. Üst düzey özet aggregate döner (ör. `personel_sayisi`, `toplam_fazla_calisma_dakika`, `kritik_uyari_iceren_personel_sayisi`).

**Hedef yanıt (özet):**

```json
{
  "kapanis_id": 99,
  "hafta_baslangic": "2026-04-06",
  "hafta_bitis": "2026-04-12",
  "departman_id": 3,
  "state": "KAPANDI",
  "personel_sayisi": 24,
  "snapshot_satir_sayisi": 24
}
```

### 9.2 GET — kapanış detayı

**Endpoint:** `GET /api/haftalik-kapanis/{kapanisId}`

**Hedef davranış:**

- Kapanış metadata döner.
- Personel snapshot satırları listesi döner (§4 alanları).

### 9.3 Sonraki faz adayı — sorgu / yıllık aggregate

Aşağıdakiler **bu karar kapsamında açılmaz**; E1 ön koşulu olarak işaretlenir:

- `GET /api/haftalik-kapanis?personel_id=&yil=`
- veya ayrı yıllık aggregate endpoint (personel + yıl → toplam FM, eksik hafta sayısı)

---

## 10. Test Kararı

Kod fazına geçildiğinde eklenecek testler (bu belgede **yazılmaz**, yalnızca hedef listelenir):

| Test | Amaç |
|------|------|
| Personel bazlı snapshot satırı üretiliyor | Granularity (§3) |
| `fazla_calisma_dakika` snapshot’a yazılıyor | FM persist |
| `fazla_surelerle_calisma_dakika` sıfır bile olsa alan mevcut | Sözleşme alanı |
| Compliance uyarıları snapshot’a taşınıyor | §7 |
| Eksik hafta verisinde `tam_hafta_verisi=false` | False-positive önleme |
| Kapanış sonrası snapshot read-only | Kilit davranışı |
| API normalize testi tipli contract’a yaklaşacak | `HaftalikKapanisSonuc` gevşek tipten çıkış |

Mevcut `tests/unit/haftalik-kapanis.api.test.ts` yalnızca metadata normalize eder; kod fazında genişletilecektir.

---

## 11. Faz E ile İlişki

| Soru | Yanıt |
|------|--------|
| Bu karar Faz E’yi kodlar mı? | **Hayır** |
| Bu karar ne yapar? | Faz E’nin ön koşulu olan **güvenilir haftalık fazla çalışma snapshot’ını** tanımlar |
| Serbest zaman | Sonraki karar (event/bakiye) bu snapshot üzerine kurulacak |
| 270 saat yıllık takip | E1; kapanmış hafta `fazla_calisma_dakika` satırlarının yıllık toplamı |

`docs/guncel/38-puantaj-mevzuat-faz-e-serbest-zaman-270-saat-karar.md` geçerliliğini korur: Faz E kodu açılmaz. Bu belge, Faz E sonrası **sıradaki teknik adayın** (snapshot sözleşmesi) karar zemini olarak okunur.

**Önerilen sıra (değişmedi):**

1. Snapshot sözleşmesi kararı (bu belge) ✓
2. Snapshot implementasyon kod fazı (ayrı checkpoint)
3. Yıllık aggregate / E1 compliance
4. Serbest zaman event modeli kararı + E2/E3

---

## 12. Sonuç

**B seçeneği kabul edilmiştir:** Haftalık kapanış snapshot sözleşmesi eksiktir; serbest zaman event modeli kararından **önce** dar snapshot sözleşmesi karar dokümanı hazırlanmıştır.

| Karar | Özet |
|-------|------|
| Granularity | `personel_id × hafta` satırı |
| Minimum alanlar | §4 — süre, hak, compliance, denetim |
| Dışarıda | §5 — serbest zaman, 270 yıllık toplam, bordro, onay |
| Kaynak ayrımı | Canlı ön izleme ≠ mühürlü snapshot |
| API hedefi | POST kapat + GET detay; yıllık sorgu sonraki faz |
| Faz E | Kodlanmaz; ön koşul tanımı |

**Belge durumu:** V1 karar — kod bekliyor (snapshot implementasyon fazı). Sonraki adım: bu sözleşmeye göre dar kod fazı planı ve owner ataması (`types`, `api`, motor entegrasyonu, mock, test).
