# Deploy Checklist (cPanel)

Canli klasor: `public_html/personelmedisa`  
Repo klasoru: `public_html` disinda ayri bir dizin

## GitHub Actions ile otomatik deploy

Ana deploy yolu GitHub Actions uzerindendir. cPanel uzerinde `npm build` calistirilmaz; yalnizca GitHub Actions build ciktisi olan `dist/` icerigi yayinlanir.

1. GitHub repo -> `Settings` -> `Secrets and variables` -> `Actions`:
   - `FTP_SERVER` (or. `ftp.domain.com`)
   - `FTP_USERNAME`
   - `FTP_PASSWORD`
   - `FTP_PORT` (genelde `21`; SFTP icin `22`)
2. Workflow dosyasi: `.github/workflows/deploy-cpanel.yml`
3. `main` branch'e push atinca otomatik deploy olur.
4. Istersen `Actions` sekmesinden `Run workflow` ile manuel tetikleyebilirsin.

Workflow sirasi: `npm ci` -> `npm run typecheck` -> `npm run test` -> `npm run build` -> yalnizca `dist/` icerigini `public_html/personelmedisa/` hedefine yukler.

### cPanel Git Deployment (yedek / manual)

- Ana deploy GitHub Actions uzerinden yapilir.
- cPanel Git Version Control / `.cpanel.yml` ana deploy yolu degildir.
- Yedek veya acil durumda cPanel Git Deployment kullanilacaksa wildcard ile tum repo deploy edilmemelidir; yalnizca `dist/` icerigi hedef klasore kopyalanmalidir.

## 1) Build

Repo klasorunde:

```bash
npm ci
npm run build
```

Kontrol:
- `dist/index.html` var
- `dist/assets/` var
- `dist/.htaccess` var

## 2) Sunucuyu temizle

`public_html/personelmedisa` icindeki eski dosyalari sil:
- eski `assets/`
- eski `index.html`
- test/kaynak dosyalari
- deploy zip dosyalari

> Not: Canli klasorde `src`, `.git`, `tests`, `node_modules` olmamali.

## 3) Dist icerigini kopyala

`dist` klasorunun **kendisi degil**, sadece **icerigi** kopyalanir:
- `index.html`
- `assets/`
- `.htaccess`
- diger statik dosyalar (`favicon.svg` vb.)

Hedef: `public_html/personelmedisa/`

## 4) Son kontrol

- Siteyi `/personelmedisa/` ile ac
- Hard refresh yap: `Ctrl + F5`
- DevTools Network'te `index-*.js` ve `index-*.css` 200 donuyor mu kontrol et

## Sorun olursa hizli kontrol

- Beyaz ekran + JS 404: Yanlis klasore kopyalanmis olabilir
- Route 404: `.htaccess` eksik veya yanlis yerde olabilir
- Eski ekran: Tarayici cache temizlenmemis olabilir
- Deploy hatasi: GitHub `Actions` logunda `FTP_SERVER/USERNAME/PASSWORD/PORT` secret adlarini kontrol et
