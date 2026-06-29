# S23 Personel POST Write Endpoint Kapanış Checkpoint

---

## 1. Faz Özeti

S23 fazında canlı PHP API üzerinde ilk dar write endpoint açılmıştır.

Açılan endpoint:

- `POST /personeller`

Bu endpoint ile yeni personel kaydı backend üzerinden oluşturulabilir hale getirilmiştir.

---

## 2. Kapsam

Bu fazda yapılan işler:

- `POST /personeller` route'u açıldı.
- `PersonellerController::create()` akışı eklendi.
- Backend validasyonları eklendi.
- Rol/yetki kontrolü eklendi.
- Şube scope kontrolü eklendi.
- Duplicate T.C. Kimlik No kontrolü eklendi.
- Insert sonrası created row yeniden okunarak `201` response dönmesi sağlandı.
- `GET /personeller?search=` canlı 500 hatası analiz edilip düzeltildi.
- Canlı PHP API dosyaları manuel upload ile güncellendi.
- Canlı API smoke tamamlandı.
- Frontend canlıda yeni kayıt ve personel detay görüntüleme doğrulandı.

Kapsam dışı bırakılanlar:

- Migration yok.
- Frontend kod değişikliği yok.
- Diğer write endpointler (`PUT /personeller/{id}`, `POST /surecler` vb.) 405 olarak kaldı.
- `config.local.php` repoya alınmadı.

---

## 3. Commitler

S23 kapsamında remote'a geçen commitler:

| Commit | Açıklama |
|--------|----------|
| `2031fa4` | feat: personel kayit php api write endpointini ac |
| `02bc08a` | fix: personel liste search parametre bind hatasini duzelt |

Checkpoint yazımı anında `main` ile `origin/main` senkron; HEAD: `02bc08a`.

---

## 4. Canlı Manuel Upload

Canlı API path:

```text
public_html/personelmedisa/api/
```

Manuel yüklenen dosyalar:

| Lokal kaynak | Canlı hedef |
|--------------|-------------|
| `api/src/Router.php` | `public_html/personelmedisa/api/src/Router.php` |
| `api/src/Controllers/PersonellerController.php` | `public_html/personelmedisa/api/src/Controllers/PersonellerController.php` |

Alınan backup örnekleri:

- `Router.php.bak-s23c-2031fa4`
- `PersonellerController.php.bak-s23c-2031fa4`
- `PersonellerController.php.bak-s23d-search-2031fa4` (search fix öncesi)

Not:

- Frontend GitHub Actions deploy yalnızca `dist/` gönderir; `api/` exclude edilir.
- PHP API canlı güncellemeleri manuel upload ile yapılır.
- `config.local.php` canlıda kalır; repoya commit edilmez.

---

## 5. Canlı API Doğrulamalar

Base URL:

```text
https://www.karmotors.com.tr/personelmedisa/api
```

### Public / regression

| Test | Beklenen | Sonuç |
|------|----------|--------|
| Authsuz `POST /personeller` | 401 | Geçti |
| Geçersiz token `POST /personeller` | 401 | Geçti |
| `POST /surecler` | 405 | Geçti |
| `PUT /personeller/1` | 405 | Geçti |

### Login

- `POST /auth/login` → 200
- Kullanıcı: `genel_yonetici`
- Rol: `GENEL_YONETICI`

### POST /personeller tam smoke (S23C-2)

| Senaryo | Beklenen | Sonuç |
|---------|----------|--------|
| Eksik `tc_kimlik_no` | 422 | Geçti |
| 10 haneli TC | 422 | Geçti |
| Geçersiz `departman_id` | 422 | Geçti |
| Header/body `sube_id` çakışması | 403 | Geçti |
| Geçerli create | 201 | Geçti |
| Duplicate TC | 409 `DUPLICATE_TC_KIMLIK_NO` | Geçti |
| `GET /personeller?limit=10` | 200 | Geçti |

Canlı test kaydı (silme endpointi yok; bilinçli bırakıldı):

| Alan | Değer |
|------|-------|
| id | 3 |
| tc_kimlik_no | 99982775050 |
| ad | S23B |
| soyad | Canli Test |
| sicil_no | S23B-1782775050 |
| sube_id | 2 (Giresun) |

### Search fix smoke (S23D-2)

| Test | Beklenen | Sonuç |
|------|----------|--------|
| `GET /personeller?search=99982775050` | 200 | Geçti |
| `GET /personeller?search=S23B` | 200 | Geçti |
| `GET /personeller?search=Mehmet` | 200 | Geçti |
| `GET /personeller/3` | 200 | Geçti |

### Frontend canlı

- Login açılıyor.
- Kayıt ve Süreç modalı açılıyor.
- Personel Kartı listesinde **S23B Canli Test** görünüyor.
- `/personeller/3` detay ekranı açılıyor.

---

## 6. S23D Search 500 Düzeltmesi

### Kök neden

`PersonellerController::list()` search branch'inde aynı named placeholder (`:search`) tek SQL içinde 3 kez kullanılıyordu. `Connection.php` içinde `PDO::ATTR_EMULATE_PREPARES => false` olduğu için native prepare `HY093 / Invalid parameter number` üretebiliyordu.

Search'siz list çalışıyordu; search parametresi varken count/data query execute aşamasında 500 (boş body) oluşuyordu.

### Dar fix

Search branch'te 3 ayrı bind adı kullanıldı:

- `:search_ad`
- `:search_soyad`
- `:search_tc`

Commit: `02bc08a`

`POST /personeller` create akışına dokunulmadı.

---

## 7. Değişen Repo Dosyaları (S23 kod)

| Dosya | S23B | S23D |
|-------|------|------|
| `api/src/Router.php` | POST route açıldı | — |
| `api/src/Controllers/PersonellerController.php` | `create()` + `mapPersonelRow` genişletmesi | search bind fix |

---

## 8. Bilinen Durumlar

- Canlı DB'de S23 smoke test personeli (id=3) kalıcıdır; bu fazda silme endpointi yok.
- Sicil no benzersizliği bu fazda garanti edilmez.
- Diğer write endpointler hâlâ 405 döner; bu beklenen davranıştır.
- Maaş bilgisi eksik uyarısı test/seed kayıtlarında normaldir.
- Direct browser adres çubuğundan API çağrısında auth header gitmez; UI smoke uygulama içinden yapılmalıdır.

---

## 9. GitHub Actions Notu

Push sonrası CI tetiklenir; başarılı CI sonrası Deploy cPanel frontend `dist/` yayınlar. PHP API dosyaları deploy workflow tarafından güncellenmez.

---

## 10. Sıradaki Önerilen Faz

- `PUT /personeller/{id}` güncelleme endpoint contract + dar implementasyon.
- Frontend `POST /personeller` entegrasyonu (Kayıt ve Süreç formu → gerçek API).
- İsteğe bağlı: canlı test personeli için operasyonel temizlik kararı (manuel DB veya ileride soft-delete/pasif akış).
