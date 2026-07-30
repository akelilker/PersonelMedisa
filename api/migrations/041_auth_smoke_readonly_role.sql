-- S103: AUTH_SMOKE_READONLY teknik rol enum extension.
-- S103 yalnız role enum extension yapar.
-- Dedicated hesabı oluşturmaz.
-- Secret üretmez.
-- Production write onayı yerine geçmez.
-- Additive ENUM widen. Existing kullanici satirlari / seed / parola YOK.
-- Production'da otomatik calistirilmaz. MariaDB 10.6 / 11.4 uyumlu.
-- 001-040 dokunulmaz. Ikinci apply ayni ENUM tanimiyla hata vermemelidir.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Canonical users.rol: mevcut degerleri koru + PATRON (PHP contract) + AUTH_SMOKE_READONLY.
ALTER TABLE users
  MODIFY COLUMN rol ENUM(
    'GENEL_YONETICI',
    'MUHASEBE',
    'BIRIM_AMIRI',
    'BOLUM_YONETICISI',
    'PATRON',
    'AUTH_SMOKE_READONLY'
  ) NOT NULL;
