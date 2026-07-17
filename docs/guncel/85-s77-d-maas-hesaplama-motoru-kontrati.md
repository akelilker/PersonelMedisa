# 85 — S77-D Deterministik Maaş Hesaplama Motoru Kontratı

## 1. Başarı kapısı

`S77_D_OWNER_MAP_CONFIRMED` — aşağıdaki owner kararları kilitlidir.

## 2. Owner haritası

| Alan | Owner | Karar |
| --- | --- | --- |
| Snapshot girdileri | `MaasHesaplamaSnapshotService` + `maas_hesaplama_*_snapshotlari` | Hesap yalnız `OLUSTURULDU` snapshot + hash doğrulaması |
| Canlı personel/puantaj/ücret/finans | Yasak | Hesap sırasında yeniden okunmaz |
| Mevzuat değerleri | Snapshot `MEVZUAT` girdi payload’ları | Freeze edilmiş kopya; zorunlu katalog eksikse blocker |
| Mevzuat katalog | `MaasHesaplamaLegalParameterCatalog` | Kod listesi merkezi; değer seed edilmez |
| Yasal devir | `personel_bordro_devirleri` (yeni) | Ocak dışı eksik → blocker; adaya kopyalanır |
| Ücret | Snapshot `UCRET` segmentleri | `BRUT` / `NET`; mid-month segment bazlı |
| Puantaj | Snapshot `PUANTAJ` + `IZIN` + `ETKI_ADAYI` | Parasal etki yalnız kesinleşmiş/UYGULANDI |
| Finans | Snapshot `FINANS` + `FinanceKalemCatalog` | Matrah sınıfı katalogda; `MAAS` duplicate blocker |
| Para aritmetiği | `Money` / `Rate` / `RoundingPolicy` | Integer kuruş + ppm; float yasak |
| Motor | `MaasHesaplamaEngine` (saf) | DB sorgusu yok |
| Orkestrasyon | `MaasHesaplamaAdayService` | Preflight, persist, idempotency, audit |
| UI | Raporlar → Maaş Hesaplama Merkezi | Yeni ana menü yok |
| Rol | `maas_hesaplama_adaylari.view/manage` | GY+MUHASEBE; BA/BY yok |
| Son migration | `021` | Yeni: `022`/`023`/`024` |

## 3. Snapshot payload shape’leri (S77-C)

### 3.1 PERSONEL (`personel_snapshot_json`)

```text
personel_id, ad, soyad, ad_soyad, tc_kimlik_no_masked, sicil_no,
sube_id, departman_id/adi, gorev_id/adi, personel_tipi_id/adi,
ucret_tipi_id, prim_kurali_id, bagli_amir_id, aktif_durum,
ise_giris_tarihi, cikis_tarihi, istihdam_baslangic, istihdam_bitis
```

### 3.2 UCRET

```text
id, personel_id, ucret_tutari, ucret_turu (BRUT|NET), para_birimi,
gecerlilik_baslangic/bitis, kaynak, revision_no, virtual_legacy,
etki_baslangic, etki_bitis
```

### 3.3 PUANTAJ

```text
muhur_satir_id, muhur_id, personel_id, tarih, gun_tipi, hareket_durumu,
dayanak, hesap_etkisi, giris/cikis_saati, gec_kalma_dakika, erken_cikis_dakika,
net_calisma_suresi_dakika, gunluk_brut_sure_dakika, hafta_tatili_hak_kazandi_mi,
kontrol_durumu, kaynak, aciklama, created_at
```

`gun_tipi`: `Normal_Is_Gunu` | `Hafta_Tatili_Pazar` | `UBGT_Resmi_Tatil`

### 3.4 IZIN

```text
surec_id, personel_id, surec_turu, alt_tur, baslangic/bitis, ucretli_mi, state, aciklama
```

### 3.5 ETKI_ADAYI

```text
aday_id, personel_id, tarih, bildirim_turu, etki_turu, etki_miktari, etki_birimi,
state (UYGULANDI|YOK_SAYILDI), parasal_uygulanacak_kalem, cakisma_cozumu?
```

Desteklenen etki türleri (mevcut mapper): `DEVAMSIZLIK_GUN`, `GEC_KALMA_DAKIKA`, `ERKEN_CIKIS_DAKIKA`, `IZIN_GUNU`, `RAPOR_GUNU`, `GOREVDE_CALISILMIS_GUN`

### 3.6 FINANS (`ek_odeme_kesinti` kopyası)

```text
kayit_id, personel_id, donem, kalem_turu, tutar, gun_sayisi, aciklama, state, muhur_sonrasi?
```

Enum: `PRIM`, `BONUS`, `IKRAMIYE`, `TESVIK`, `EKSTRA_PRIM`, `CEZA`, `AVANS`, `BES`, `DIGER_KESINTI`, `MAAS`, `MESAI`

### 3.7 MEVZUAT

```text
parametre_id, parametre_kodu, deger_tipi, sayisal_deger, metin_deger, birim,
gecerlilik_baslangic/bitis, kaynak_referansi, revision_no
```

## 4. Zorunlu mevzuat kataloğu

| Kod | Tip | Birim |
| --- | --- | --- |
| ASGARI_UCRET_BRUT | SAYISAL | TRY |
| ASGARI_UCRET_GELIR_VERGISI_ISTISNA_MATRAHI | SAYISAL | TRY |
| ASGARI_UCRET_DAMGA_VERGISI_ISTISNA_MATRAHI | SAYISAL | TRY |
| SGK_ISCI_PRIM_ORANI | SAYISAL | ORAN |
| ISSIZLIK_ISCI_PRIM_ORANI | SAYISAL | ORAN |
| SGK_GUNLUK_TABAN | SAYISAL | TRY |
| SGK_GUNLUK_TAVAN | SAYISAL | TRY |
| DAMGA_VERGISI_ORANI | SAYISAL | ORAN |
| GELIR_VERGISI_DILIM_1_LIMIT | SAYISAL | TRY |
| GELIR_VERGISI_DILIM_1_ORAN | SAYISAL | ORAN |
| GELIR_VERGISI_DILIM_2_LIMIT | SAYISAL | TRY |
| GELIR_VERGISI_DILIM_2_ORAN | SAYISAL | ORAN |
| GELIR_VERGISI_DILIM_3_LIMIT | SAYISAL | TRY |
| GELIR_VERGISI_DILIM_3_ORAN | SAYISAL | ORAN |
| GELIR_VERGISI_DILIM_4_LIMIT | SAYISAL | TRY |
| GELIR_VERGISI_DILIM_4_ORAN | SAYISAL | ORAN |
| GELIR_VERGISI_DILIM_5_ORAN | SAYISAL | ORAN |
| NORMAL_AY_GUN_SAYISI | SAYISAL | GUN |
| GUNLUK_CALISMA_SAATI | SAYISAL | SAAT |
| AYLIK_NORMAL_CALISMA_SAATI | SAYISAL | SAAT |
| FAZLA_MESAI_CARPANI | SAYISAL | CARPAN |
| HAFTA_TATILI_CARPANI | SAYISAL | CARPAN |
| UBGT_CARPANI | SAYISAL | CARPAN |

Eksik kod → `LEGAL_PARAMETER_REQUIRED_MISSING` blocker. Production seed yok.

## 5. Finans matrah sınıflandırması

| kalem_turu | yön | SGK | GV | Damga | net_odeme |
| --- | --- | --- | --- | --- | --- |
| PRIM, BONUS, IKRAMIYE, TESVIK, EKSTRA_PRIM, MESAI | ARTI | evet | evet | evet | hayır |
| CEZA, AVANS, BES, DIGER_KESINTI | EKSI | hayır | hayır | hayır | evet |
| MAAS | — | — | — | — | **blocker** (duplicate salary) |

## 6. Hesap formülü (özet)

Engine version: `S77D_PAYROLL_ENGINE_V1`

1. Segment baz ücret: `tutar * segment_gun / NORMAL_AY_GUN_SAYISI` (kuruş)
2. Günlük = aylık / NORMAL_AY_GUN_SAYISI; saatlik = günlük / GUNLUK_CALISMA_SAATI
3. Puantaj/etki kesinti ve prim kalemleri
4. Finans ek ödeme/kesinti
5. BRUT yol: brüt → SGK/işsizlik → GV matrah → dilimler + istisna → damga + istisna → net
6. NET yol: binary-search solver (max 64 iter, ≤1 kuruş) → aynı brütten-nete motor
7. Kalem toplamları = aday özeti; result_hash yeniden hesaplanabilir

## 7. Veri modeli

- `022_personel_bordro_devirleri.sql`
- `023_maas_hesaplama_adaylari.sql` (calistirmalar, adaylar, kalemler, audit)
- `024_maas_hesaplama_aday_guvenlik_indexleri.sql` (immutability triggers)

## 8. Canlı kabul beklentisi

Mart 2026 snapshot `#1` üzerinde mevzuat boş → blocker kabul; gerçek parametre yoksa `S77_D_ENGINE_READY_LIVE_BLOCKED`.

## 9. Faz dışı

Muhasebe onayı, GY onayı, bordro PDF/Excel, banka dosyası, SGK bildirgesi (S77-E/F/G).
