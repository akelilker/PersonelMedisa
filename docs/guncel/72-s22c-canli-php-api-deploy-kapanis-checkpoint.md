# S22C Canlı PHP API Deploy Kapanış Checkpoint

---

## 1. Git durumu

| Alan | Değer |
|------|--------|
| Branch | `main` |
| HEAD | `bc83511cc806649731ad79a9cf3dea9aca61a782` |
| Origin/main | `bc83511cc806649731ad79a9cf3dea9aca61a782` |
| Working tree | Temiz (checkpoint yazımı anında) |

---

## 2. Tamamlanan işler

- Deploy workflow `api/` koruması eklendi:
  - `mirror -R --delete --verbose --exclude api/ . .`
- API zip manuel yüklendi.
- `public_html/personelmedisa/api/` canlıda oluştu.
- `config.local.php` canlıda oluşturuldu.
- MySQL DB:
  - DB: `karmotor_medisa`
  - User: `karmotor_medisaapi`
- Migration import edildi.
- Smoke seed import edildi.
- Kullanıcı hash güncellendi.
- Login doğrulandı.

---

## 3. Canlı doğrulamalar

- `/personelmedisa/api/health` → 200 JSON
- `/personelmedisa/api/personeller` token yokken → 401 JSON
- Login:
  - username: `genel_yonetici`
  - şifre: canlı testte doğrulandı
- Kayıt ve Süreç modalı açılıyor.
- Personel Kartı açılıyor.
- Seed personeller görünüyor:
  - Ayse Yilmaz
  - Mehmet Demir
- Mehmet Demir detay açılıyor.
- Raporlar açılıyor.
- S22C son canlı smoke tamamlandı.

---

## 4. S22C commitleri

| Commit | Açıklama |
|--------|----------|
| `9b457f9` | cPanel deploy API klasörünü koru |
| `fe6c10b` | PHP API referans endpointlerini genişlet |
| `bc83511` | PHP API kalan read-only endpointleri ekle |

---

## 5. Canlı dosya notları

- API canlı path:
  - `public_html/personelmedisa/api/`
- Config canlı path:
  - `public_html/personelmedisa/api/config.local.php`
- `config.local.php` repoya alınmayacak.
- API zip upload manuel yapılır.
- Frontend deploy API klasörünü silmemeli; workflow `--exclude api/` içeriyor.

---

## 6. Bilinen durumlar

- Direct browser endpoint testlerinde auth header gitmediği için 401 normaldir.
- UI smoke için endpointler adres çubuğundan değil, uygulama içinden test edilmelidir.
- Bazı write endpointler hâlâ 405 olabilir; bu beklenen read-only faz davranışıdır.
- Maaş bilgisi eksik uyarısı seed veride normaldir.

---

## 7. Sıradaki önerilen faz

S23 için öneri:

- PHP API write endpoint stratejisi netleştirilecek.
- Personel kayıt POST akışı canlı backend'e bağlanacaksa önce dar contract analizi yapılacak.
- Migration gerektiren tablo ihtiyaçları ayrıca karar dokümanı ile açılacak.
