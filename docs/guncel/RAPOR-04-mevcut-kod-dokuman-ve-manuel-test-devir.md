# RAPOR-04
# MEDİSA PERSONEL
# Mevcut Kod Durumu, Bildirim Onay Zinciri ve Manuel Test Devir Raporu

**Belge tarihi:** 11.07.2026

**Durum:** S70C-S72 kapanış ve manuel test öncesi güncel devir

## Bağlı kaynaklar

- RAPOR-01 — Ürün Reset ve Operasyon Omurgası
- RAPOR-02 — Mevzuat Uyum Matrisi
- RAPOR-03 — Şirket Parametreleri ve Onay Politikası
- Güncel repo HEAD: `d089929`

RAPOR-01, RAPOR-02 ve RAPOR-03 repo içinde bağımsız dosya halinde bulunmamaktadır. Bu nedenle içerikleri bu belgede yeniden üretilmemiştir; repo dışı ürün referansları olarak sınıflandırılmıştır.

## 1. Raporun amacı

- Güncel çalışan kodu kayıt altına almak.
- S70C-S72 kapsamında tamamlanan hattı belgelemek.
- Çalışan kod, hedef ürün davranışı ve aktif doküman ayrımını netleştirmek.
- Manuel test başlangıç noktasını sabitlemek.
- Sonraki geliştirmelerin yanlış domain'e bağlanmasını önlemek.

## 2. Güncel repo durumu

| Alan | Değer |
|---|---|
| Branch | `main` |
| HEAD | `d089929` |
| origin/main | `d089929` |
| Working tree | Belge yazımı öncesi temiz |
| Son commit | `feat(ui): aylik bildirim onayi panelini ekle` |

Bu doküman seti commit edildiğinde HEAD değişecektir. `d089929`, belge yazımı öncesindeki uygulama kodu referansıdır.

## 3. Tamamlanan fazlar

| Faz | Kapsam | Backend | UI | Canlı doğrulama | Durum |
|---|---|---|---|---|---|
| S70C | Günlük bildirim | Tamam | Tamam | PASS | KAPANDI |
| S71 | Haftalık mutabakat | Tamam | Tamam | PASS | KAPANDI |
| S72 | Aylık bildirim onayı | Tamam | Tamam | PASS | KAPANDI |

Bu kapanış yalnızca fazların kendi kapsamı içindir; ürünün, puantaj motorunun veya bordro zincirinin tamamlandığı anlamına gelmez.

## 4. Çalışan operasyon zinciri

```text
Birim Amiri günlük bildirimi oluşturur
  -> TASLAK
  -> gönderir
  -> GONDERILDI
  -> haftalık mutabakat oluşturur
  -> günlük kayıtlar HAFTALIK_MUTABAKATA_ALINDI
  -> ay içindeki haftalar tamamlanır
  -> aylık bildirim onayı oluşturulur
  -> aylık onay TAMAMLANDI
```

| Adım | Tablo sahibi | Endpoint | Permission | UI sahibi | Duplicate / guard |
|---|---|---|---|---|---|
| Günlük liste | `gunluk_bildirimler` | `GET /bildirimler` | `bildirimler.view` / scope guard | Bildirimler sayfası | Şube ve rol scope uygulanır |
| Günlük detay | `gunluk_bildirimler` | `GET /bildirimler/{id}` | `bildirimler.detail.view` | Bildirim detay sayfası | Sahiplik/scope guard uygulanır |
| Günlük oluşturma | `gunluk_bildirimler` | `POST /bildirimler` | `gunluk_bildirim.create` | BIRIM_AMIRI günlük kayıt formu | Başlangıç state'i `TASLAK` |
| Günlük güncelleme | `gunluk_bildirimler` | `PUT /bildirimler/{id}` | `gunluk_bildirim.update_own_open` | Düzenleme formu | Yalnız kendi açık kaydı |
| Günlük gönderme | `gunluk_bildirimler` | `POST /bildirimler/{id}/submit` | `gunluk_bildirim.submit` | Gönder aksiyonu | Geçersiz state `409` |
| Düzeltme isteme | `gunluk_bildirimler` | `POST /bildirimler/{id}/request-correction` | `gunluk_bildirim.request_correction` | Düzeltme iste aksiyonu | Yalnız uygun gönderilmiş kayıt |
| Günlük iptal | `gunluk_bildirimler` | `POST /bildirimler/{id}/iptal` | `gunluk_bildirim.update_own_open` | İptal aksiyonu | Geçersiz state `409` |
| Haftalık özet | `haftalik_bildirim_mutabakatlari` | `GET /haftalik-bildirim-mutabakatlari/ozet` | `haftalik_mutabakat.view` | Haftalık Mutabakat paneli | Read-only roller yalnız görüntüler |
| Haftalık approve | `haftalik_bildirim_mutabakatlari` | `POST /haftalik-bildirim-mutabakatlari` | `haftalik_mutabakat.approve` | BIRIM_AMIRI paneli | Tekrar approve `409` |
| Haftalık detay | `haftalik_bildirim_mutabakatlari` | `GET /haftalik-bildirim-mutabakatlari/{id}` | `haftalik_mutabakat.view` | Panel/detail verisi | Rol, sahiplik ve scope guard |
| Aylık özet | `aylik_bildirim_onaylari` | `GET /aylik-bildirim-onaylari/ozet` | `aylik_bildirim_onayi.view` | Aylık Bildirim Onayı paneli | Eksik hafta onayı bloklar |
| Aylık approve | `aylik_bildirim_onaylari` | `POST /aylik-bildirim-onaylari` | `aylik_bildirim_onayi.approve` | BIRIM_AMIRI paneli | Tekrar approve `409` |
| Aylık detay | `aylik_bildirim_onaylari` | `GET /aylik-bildirim-onaylari/{id}` | `aylik_bildirim_onayi.view` | Panel/detail verisi | Rol, sahiplik ve scope guard |

## 5. Rol sahipliği

Permission modeli yetkinin sahibidir. UI görünürlüğü tek başına yetki kanıtı değildir; backend permission ve scope guard'ları nihai kontroldür.

### BIRIM_AMIRI

- `gunluk_bildirim.create`
- `gunluk_bildirim.update_own_open`
- `gunluk_bildirim.submit`
- `haftalik_mutabakat.view`
- `haftalik_mutabakat.approve`
- `aylik_bildirim_onayi.view`
- `aylik_bildirim_onayi.approve`

### BOLUM_YONETICISI

- Günlük bildirimleri görür ve uygun gönderilmiş kayıtta düzeltme isteyebilir.
- Haftalık ve aylık bildirim panellerini read-only görür.
- Haftalık veya yeni aylık bildirim approve sahibi değildir.

### GENEL_YONETICI

- Haftalık ve aylık bildirim panellerini read-only görür.
- Yeni aylık bildirim onayını approve etmez.
- Yeni aylık bildirim domain'i ile bağlı Genel Yönetici onayı henüz yoktur.

### MUHASEBE

- Haftalık ve aylık bildirim panellerini read-only görür.
- Bu panellerde approve aksiyonu yoktur.

## 6. Canlı smoke kanıtı

| Kayıt | ID | Kaynak |
|---|---:|---|
| Günlük Bildirim | 1 | Canlı API/UI smoke |
| Haftalık Mutabakat | 1 | Canlı API/UI smoke |
| Aylık Bildirim Onayı | 1 | Canlı API/UI smoke |

Canlı test ayı `2026-07`, tarih aralığı `2026-07-01 / 2026-07-31` olarak doğrulanmıştır. Aylık özette toplam bildirim `1`, mutabakata alınan `1`, eksik hafta `0` görülmüş; canlı UI veya console hatası gözlenmemiştir.

Bu kayıtlar ürün seed'i değil, canlı doğrulama/test kayıtlarıdır. Silme veya arşivleme kararı henüz alınmamıştır. Yetkisiz doğrudan DB silme yapılmamalıdır. ID değerleri repo kodu kanıtı değil, canlı smoke raporuyla doğrulanan test verisidir.

## 7. Tamamlanmayan ürün halkaları

Aşağıdakiler bug değil, henüz geliştirilmemiş sonraki ürün halkalarıdır:

1. Yeni aylık bildirim onayı ile Genel Yönetici onayı bağlantısı.
2. Patron gördü/not/onay katmanı.
3. Bildirim ile gerçek puantaj hesap motoru köprüsü.
4. Aylık onay ile bordro girdisi köprüsü.
5. Merkezi şirket parametreleri.
6. Manuel inceleme kuyruğu.
7. Nihai bordro ön izleme.
8. Bordro kesinleştirme.
9. Gerçek üretim kullanıcı ve rol hazırlığı.
10. Canlı test veri politikası.

## 8. Legacy yapıların durumu

| Yapı | Domain | Güncel sınır |
|---|---|---|
| `aylik_ozet_satirlari` | Personel x ay puantaj/rapor özeti | Yeni S72 aylık bildirim onayı değildir |
| `aylik_kapanis_state` | Legacy aylık aggregate state | Yeni bildirim onayıyla otomatik bağlı değildir |
| `puantaj_aylik_muhurleri` | Puantaj teknik mühür/snapshot | Bildirim onay zincirinden ayrı state flow'dur |
| `aylik_bildirim_onaylari` | Yeni S72 bildirim onay domain'i | BIRIM_AMIRI aylık bildirim onayıdır |

Bu yapılar arasında otomatik bağ yoktur. Legacy aylık özet, Genel Yönetici onayı, puantaj mührü ve yeni aylık bildirim onayı aynı süreç gibi yorumlanmamalıdır.

## 9. Manuel teste geçiş kararı

**MANUEL TESTE HAZIR — DOKÜMAN SENKRONU TAMAMLANDIKTAN SONRA**

- S70C-S72 role-smoke ve mevcut modüller manuel teste hazırdır.
- Genel Yönetici -> patron -> bordro uçtan uca zinciri hazır değildir.

## 10. Manuel test rol matrisi

| Test başlığı | BIRIM_AMIRI | BOLUM_YONETICISI | GENEL_YONETICI | MUHASEBE |
|---|---|---|---|---|
| Login/yönlendirme | Aktif test | Aktif test | Aktif test | Aktif test |
| Menü görünürlüğü | Aktif test | Aktif test | Aktif test | Aktif test |
| Personel listesi | Read-only test | Aktif test | Aktif test | Aktif test |
| Personel detay | Read-only test | Aktif test | Aktif test | Aktif test |
| Süreçler | Read-only test | Aktif test | Aktif test | Aktif test |
| Günlük bildirim create | Aktif test | Yetkisiz/gizli test | Yetkisiz/gizli test | Yetkisiz/gizli test |
| Günlük bildirim edit | Aktif test | Yetkisiz/gizli test | Yetkisiz/gizli test | Yetkisiz/gizli test |
| Günlük bildirim submit | Aktif test | Yetkisiz/gizli test | Yetkisiz/gizli test | Yetkisiz/gizli test |
| Düzeltme isteği | Yetkisiz/gizli test | Aktif test | Aktif test | Yetkisiz/gizli test |
| Haftalık mutabakat görünümü | Aktif test | Read-only test | Read-only test | Read-only test |
| Haftalık approve | Aktif test | Yetkisiz/gizli test | Yetkisiz/gizli test | Yetkisiz/gizli test |
| Aylık onay görünümü | Aktif test | Read-only test | Read-only test | Read-only test |
| Aylık approve | Aktif test | Yetkisiz/gizli test | Yetkisiz/gizli test | Yetkisiz/gizli test |
| Puantaj | Read-only test | Aktif test | Aktif test | Aktif test |
| Raporlar | Aktif test | Aktif test | Aktif test | Aktif test |
| Finans | Yetkisiz/gizli test | Aktif test | Aktif test | Aktif test |
| Şube scope | Aktif test | Aktif test | Aktif test | Aktif test |
| Console/network hata kontrolü | Aktif test | Aktif test | Aktif test | Aktif test |
| Mobil görünüm | Aktif test | Aktif test | Aktif test | Aktif test |
| Masaüstü görünüm | Aktif test | Aktif test | Aktif test | Aktif test |

## 11. Manuel test sırası

1. BIRIM_AMIRI
2. BOLUM_YONETICISI
3. GENEL_YONETICI
4. MUHASEBE
5. Şube-cross scope kontrolleri
6. Mobil/PWA
7. Masaüstü
8. Console/network
9. Test veri değerlendirmesi

## 12. Sonraki geliştirme kapısı

Yeni geliştirme açılmadan önce aşağıdaki kapılar tamamlanmalıdır:

- RAPOR-04 commit'i.
- Aktif doküman senkronu.
- Manuel test bulgularının kaydı.
- Sonraki halka için açık ürün kararı.

S73 otomatik başlamaz. Önce manuel test sonucu ve ürün kararı gerekir.
