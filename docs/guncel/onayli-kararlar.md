# Onaylı Görsel Kararlar (karar kaydı)

Bu dosya, kullanıcının onayladığı kalıcı görsel kararları her biri tek satır olacak şekilde tutar; agent görsel bir işe başlamadan önce bu dosyayı okur, yeni karar gelince ilgili satır güncellenir ve son açık karar eskisini geçersiz kılar (geçici durum — SHA, açık PR, deploy — burada tutulmaz).

| Ekran | Karar | Değer | Tarih |
| --- | --- | --- | --- |
| Ortak modal kabuğu (Personel) | Taşıt ile 1:1 kabuk; glow yok, yatay kırmızı çizgi yok | header 60px, çerçeve 10px, başlık 17px (mobil 19px), home/X 15px içeride, Taşıt 24px X | 2026-09-26 |
| Ortak modal | Mobil modal–footer boşluğu | <641px 17px; desktop 14px; kısa yatay telefon 2px | 2026-09-26 |
| Ortak modal başlığı | Home ve X ikonları dikey merkezin biraz altında | `--modal-header-icon-shift-y: 6px` (translateY(-50%) ile birlikte), yatay 8px iç boşluk değişmez | 2026-10-03 |
| Ortak modal başlığı | X üstündeki 1px inset parlaklık yok, başlık altında border yok | box-shadow inset yok, border-bottom yok | 2026-10-04 |
| Footer | Görünür sürüm sabit | v78.2 (deploy ile değişmez) | 2026-10-05 |
| Footer | MEDİSA wordmark boyutu (#507) | #507 sonrası çok az küçültülmüş boyut korunur; eski `+0.5pt` büyütme geri getirilmez; footer iç satır yüksekliği / Sistem Hazır metni değişmez | 2026-10-06 |
| Footer | iOS 27 standalone PWA dikey konum | Taşıt #541: `html.medisa-ios27-pwa` + `100dvh` `.app-shell`; alt safe-area yalnız `#app-footer` (height+padding-bottom); çerçeve `::before` altı footer+inset; login’de inset 0 olsa da footer fiziksel alta; status-bar `black` + `viewport-fit=cover` | 2026-10-07 |
| Ad Soyad (tüm ekranlar) | Yalnız görünüm, veri değişmez | Personel Kartı detay: tamamı BÜYÜK; diğer her yer: Ad Türkçe Title Case + SOYAD BÜYÜK (ör. Berat GÜRBÜZ) | 2026-10-03 |
| Kullanıcı Paneli | İkincil başlık birinciden büyük olamaz | KULLANICI PANELİ font-size ≤ PERSONEL YÖNETİM SİSTEMİ (desktop + mobil) | 2026-10-01 |
| /self | Profil fotoğrafı hizası | fotoğrafın üst kenarı Ad Soyad satırının üstüyle hizalı (desktop öncelik, mobil bozulmaz) | 2026-10-01 |
| /self | Ana uygulamaya geri geçiş bağlantısı | "Kullanıcı Paneli >" ile aynı görsel dil; yeni FAB/kutu/border/gölge yok; oturum ve QR yetkisi korunur | 2026-10-01 |
| Mobil ana sayfa başlığı | İç yükseklik inceltme | mobil ana header iç yüksekliği 4px daha ince; login mobil header buna eşit; üst 4 ikon (takvim, konum, bildirim, ayarlar) hafif büyük; desktop header'a dokunulmaz | 2026-10-03 |
| Ortak modal başlığı | Kırmızı geçiş #484 öncesi tam uzunluğa döner (şu anki %72'de biten görünüm istenmiyor; 2026-10-04 '%60–70' kararını geçersiz kılar) | --modal-header-red-gradient 3. katman: #790000 0%, #700000 30%, #580000 52%, #420205 72%, #32050a 82%, #20080f 90%, #120b14 96%, var(--modal-bg) 100% | 2026-10-06 |
| Anlık Personel Durumu — şube kartı | Şube kartları kare oranlıdır (ürün kararı) | `aspect-ratio: 1 / 1`; içerik/istatistik/veri mantığı değişmez | 2026-10-06 |
| Anlık Personel Durumu — şube kartı | Kolon düzeni | Mobil 3 kolon; masaüstü 5 kolon; son satır dengeli/ortalanmış kalır | 2026-10-06 |
| Anlık Personel Durumu — şube kartı | Çerçeve davranışı | Normal durumda hafif görünür çerçeve vardır; hover/focus sırasında çerçeve daha belirgin olur; hover'da şube adı hafif büyüyebilir | 2026-10-06 |
| Anlık Personel Durumu — şube detayı | Geri dönüş | Geri dönüş kırmızı başlığın ALTINDA ayrı geri satırında bulunur (başlığın içine sıkıştırılmaz); home ikonu başlıkta kendi yerinde kalır; başlık ortada kalır | 2026-10-06 |
| Anlık Personel Durumu — Şube/Lokasyon Detayı | İlk görünüm organizasyon ağacı değil, personel devam özeti olur | Üst satır: Toplam Personel (tek satır). Altında yan yana Gelen / Gelmeyen kartları (etiket üstte, sayı ortada; Gelen=Geldi+Geç Geldi+Erken Çıktı, Gelmeyen=Gelmedi+İzinli+Raporlu+Görevde). Son satır: Henüz Değerlendirilmedi (tek satır). Alt nedenler yalnız Gelen/Gelmeyen seçilince açılır; bölüm/birim ana ekrana otomatik dökülmez. | 2026-10-07 |
| Kayıt ve Süreç > Genel | Personel fotoğraf alanı | Sağ üst/sağ tarafta bulunur; gerçek fotoğraf yoksa placeholder/initial gösterilir; soldaki harf avatarı fotoğraf alanının yerine geçmez; yeni fotoğraf upload API'si icat edilmez | 2026-10-06 |
| Kayıt ve Süreç > Genel | Geri dönüş | `← Personel` kartın içine gömülmez; ayrı geri satırında yer alır; kullanıcı seçim ekranına dönüş davranışı değişmez | 2026-10-06 |
| Kayıt formu | Mobil 2 kolon | Mobilde 2 kolon korunur; orta ayırıcı korunur; PR #490'daki tek kolon davranışına dönülmez | 2026-10-06 |
| Ortak modal | iOS safe-area | #493 sonrası davranış korunur | 2026-10-06 |
| Kullanıcı Paneli / Self | Geçiş bağlantısı | İlk açıldığında normal görünür; ~4 saniye sonra soluklaşır; baştan itibaren sürekli soluk görünüm doğru nihai davranış değildir | 2026-10-06 |
| Mobil ana hero | #498 WebKit ölçümü | İlk #498 WebKit hatası tekrarlanmadı; hero regresyonu olarak kayıt açılmaz; yeni görsel kanıt olmadan değiştirilmez | 2026-10-06 |
| Ortak — Personel Kimliği | Kullanıcıya internal personel kayıt ID'si gösterilmez. | Kullanıcıya dönük personel tanımı Ad Soyad + Sicil No'dur. Internal personel ID yalnız backend/endpoint/DB ilişkilerinde teknik olarak kullanılır; UI, Personel Kartı, arama sonucu, uyarı/metinler, export/rapor kullanıcı alanları ve teknik olmayan kullanıcı raporlarında gösterilmez. Örnek: Saıf Tareq Jasım Al-Gburı — Sicil 197. | 2026-10-06 |
